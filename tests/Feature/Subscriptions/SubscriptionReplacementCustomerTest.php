<?php

use App\Models\MarketingProfile;
use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingCycle;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Mobile\ModernForestryMobileAccountService;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Mobile\ModernForestryMobileCustomerSessionService;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBraintreeExecutionService;
use App\Services\Subscriptions\SubscriptionReplacementCustomerAccess;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Subscriptions\BraintreeScenario;

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Queue::fake();
    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com']);
    \Illuminate\Support\Facades\URL::forceRootUrl('https://app.theeverbranch.com');
    \Illuminate\Support\Facades\URL::forceScheme('https');
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0)->utc());
    $this->scenario = new BraintreeScenario;
    $this->scenario->store->update(['store_key' => 'retail']);
    $this->scenario->activate();
    $this->profile = MarketingProfile::factory()->create(['tenant_id' => $this->scenario->tenant->id, 'first_name' => 'Fictional']);
    $this->customer = new ModernForestryMobileCustomerSession($this->profile, 'fictional-authenticated-token', [
        'source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456',
    ]);
    $this->access = app(SubscriptionReplacementCustomerAccess::class);
    $this->service = app(SubscriptionBillingAgreementService::class);
});

afterEach(function () {
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

function replacementCustomerChange($test, string $kind, array $options = []): SubscriptionBillingAction
{
    return $test->service->customerChange($test->customer, $kind, (string) Str::uuid(), $test->scenario->agreement->fresh()->revision, $options);
}

test('replacement account read uses the original Shopify identity without payment setup or new provider reads', function () {
    $reads = count(Http::recorded());
    $payload = app(ModernForestryMobileAccountService::class)->candleClub($this->customer);
    expect($payload['action_mode'])->toBe('everbranch_replacement')->and($payload['status'])->toBe('active')
        ->and($payload['actions']['can_manage_subscription'])->toBeTrue()->and($payload['contract'])->toBeNull()
        ->and($payload['everbranch_subscription']['next_billing_date'])->toBe('2027-01-31')
        ->and(json_encode($payload))->not->toContain('private_method_token', 'source_txn', 'fictional_private_key')
        ->and(count(Http::recorded()))->toBe($reads)->and($this->scenario->sales)->toBe([]);
});

test('matching email profile demo or another customer never selects the replacement agreement', function (string $defect) {
    $identity = ['source_type' => 'shopify_customer', 'shopify_customer_id' => '456'];
    $profile = $this->profile;
    if ($defect === 'demo') {
        $identity['source_type'] = 'app_review_demo';
    } elseif ($defect === 'customer') {
        $identity['shopify_customer_id'] = '999';
    } elseif ($defect === 'email_only') {
        unset($identity['shopify_customer_id']);
        $identity['email'] = $profile->email;
    } else {
        $other = Tenant::create(['name' => 'Other member workspace', 'slug' => 'other-member-workspace']);
        $profile = MarketingProfile::factory()->create(['tenant_id' => $other->id]);
    }
    $session = new ModernForestryMobileCustomerSession($profile, 'fictional', $identity);
    expect($this->access->agreement($session))->toBeNull()
        ->and($this->access->payload($session, ['legacy' => 'unchanged']))->toBe(['legacy' => 'unchanged']);
})->with(['demo', 'customer', 'email_only', 'tenant']);

test('the existing authenticated manage endpoint issues a short private page with no new customer authorization', function () {
    $sessions = Mockery::mock(ModernForestryMobileCustomerSessionService::class);
    $sessions->shouldReceive('resolveFromRequest')->andReturn($this->customer);
    app()->instance(ModernForestryMobileCustomerSessionService::class, $sessions);
    $reads = count(Http::recorded());
    $response = $this->postJson('/api/mobile/v1/modern-forestry/account/subscriptions/manage', ['tenant_id' => 999, 'shopify_customer_id' => '999'])->assertOk();
    $url = $response->json('url');
    $page = $this->get($url)->assertOk()->assertSeeText('Skip the next cycle')->assertSeeText('Your existing saved payment method')
        ->assertDontSee('Authorize recurring payments')->assertDontSee('private_method_token')->assertHeader('Referrer-Policy', 'no-referrer');
    expect($page->headers->get('Cache-Control'))->toContain('no-store')->toContain('private')
        ->and(count(Http::recorded()))->toBe($reads)->and($this->scenario->sales)->toBe([]);
    $this->get(str_replace('app.theeverbranch.com', 'unknown.example.test', $url))->assertNotFound();
    $this->travel(16)->minutes();
    $this->get($url)->assertNotFound();
});

test('customer skip replay changes one future cycle once and rejects stale or conflicting action ids', function () {
    $revision = $this->scenario->agreement->revision;
    $request = (string) Str::uuid();
    $one = $this->service->customerChange($this->customer, 'skip', $request, $revision);
    $retry = $this->service->customerChange($this->customer, 'skip', $request, $revision);
    expect($retry->id)->toBe($one->id)->and($one->actor_type)->toBe('shopify_customer')
        ->and($one->actor_reference)->toBe('gid://shopify/Customer/456')
        ->and($this->scenario->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-02-28');
    expect(fn () => $this->service->customerChange($this->customer, 'cancel', $request, $revision))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $this->service->customerChange($this->customer, 'skip', (string) Str::uuid(), $revision))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->scenario->sales)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
});

test('a finite customer pause skips a full original-anchor cycle and automatically resumes only on its accepted date', function () {
    replacementCustomerChange($this, 'pause', ['duration_months' => 1]);
    $row = $this->scenario->agreement->fresh();
    expect($row->status)->toBe('paused')->and($row->resume_on->toDateString())->toBe('2027-02-28');
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $this->artisan('subscriptions:replacement-process', ['--collect' => true])->assertSuccessful();
    expect($this->scenario->sales)->toBe([]);
    $this->travelTo(now('America/New_York')->setDate(2027, 2, 28)->setTime(10, 0)->utc());
    $this->artisan('subscriptions:replacement-process', ['--collect' => true])->assertSuccessful();
    expect($this->scenario->sales)->toHaveCount(1)->and($row->fresh()->status)->toBe('active')
        ->and($row->fresh()->next_billing_date->toDateString())->toBe('2027-03-31')
        ->and(SubscriptionBillingAction::where('kind', 'scheduled_resume')->count())->toBe(1);
});

test('an indefinite pause stays paused and manual resumption preserves a previously skipped cycle', function () {
    replacementCustomerChange($this, 'skip');
    replacementCustomerChange($this, 'pause');
    expect($this->scenario->agreement->fresh()->resume_on)->toBeNull();
    replacementCustomerChange($this, 'resume');
    expect($this->scenario->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-02-28')
        ->and($this->scenario->sales)->toBe([]);
});

test('a manually assigned resume date without the original customer pause action cannot activate collection', function () {
    $row = $this->scenario->agreement->fresh();
    $row->update(['status' => 'paused', 'resume_on' => '2027-01-31']);
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    expect(fn () => app(SubscriptionBraintreeExecutionService::class)->collect($row))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($row->fresh()->status)->toBe('paused')->and($this->scenario->sales)->toBe([]);
});

test('stop access survives a merchant rollout freeze or lost entitlement while resumption stays disabled', function () {
    config()->set('subscriptions.replacement_servicing_enabled', false);
    config()->set('subscriptions.replacement_member_ids', []);
    TenantModuleEntitlement::where('tenant_id', $this->scenario->tenant->id)->update(['enabled_status' => 'disabled']);
    app()->forgetInstance(\App\Services\Tenancy\TenantModuleAccessResolver::class);
    replacementCustomerChange($this, 'pause');
    expect(fn () => replacementCustomerChange($this, 'resume'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    replacementCustomerChange($this, 'cancel');
    expect($this->scenario->agreement->fresh()->status)->toBe('cancelled')->and($this->scenario->sales)->toBe([]);
});

test('an original unknown payment is retained after customer pause or cancellation and cannot be resubmitted', function (string $kind) {
    $this->scenario->lostSaleResponse = true;
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $execution = app(SubscriptionBraintreeExecutionService::class);
    $cycle = $execution->collect($this->scenario->agreement);
    $dispatch = $cycle->reconciliation['dispatch_started_at'];
    $action = replacementCustomerChange($this, $kind);
    expect($action->after_state['original_payment_requires_review'])->toBeTrue()
        ->and($cycle->fresh()->reconciliation['dispatch_started_at'])->toBe($dispatch);
    expect(fn () => $execution->collect($this->scenario->agreement))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->scenario->sales)->toHaveCount(1);
})->with(['pause', 'cancel']);

test('a started billing day cannot be skipped and schedule changes never rewrite an existing paid cycle', function () {
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    expect(fn () => replacementCustomerChange($this, 'skip'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $cycle = app(SubscriptionBraintreeExecutionService::class)->collect($this->scenario->agreement);
    $terms = $cycle->terms;
    replacementCustomerChange($this, 'skip');
    expect($cycle->fresh()->terms)->toBe($terms)->and($cycle->fresh()->shopify_order_id)->toBe('222')
        ->and($this->scenario->sales)->toHaveCount(1)->and($this->scenario->orderCreates)->toHaveCount(1);
});

test('private page posts apply the reviewed customer action once and cannot switch customer or agreement from form data', function () {
    $url = $this->access->issue($this->customer);
    $data = ['action' => 'skip', 'request_id' => (string) Str::uuid(), 'revision' => $this->scenario->agreement->revision,
        'tenant_id' => 999, 'agreement_id' => 999, 'shopify_customer_id' => '999'];
    $this->post($url, $data)->assertRedirect($url);
    $this->post($url, $data)->assertRedirect($url);
    $this->post($url, array_replace($data, ['action' => 'cancel']))->assertRedirect($url)->assertSessionHasErrors('subscription');
    expect($this->scenario->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-02-28');
    $this->get($url)->assertOk()->assertSeeText('February 28, 2027');
    $this->scenario->store->update(['shop_domain' => 'changed.myshopify.com']);
    $this->get($url)->assertStatus(409);
});

test('the private member page uses Modern Forestry branding only in its own workspace', function (bool $modernForestry) {
    if ($modernForestry) {
        $this->scenario->tenant->update(['slug' => 'modern-forestry', 'name' => 'Modern Forestry']);
    }
    $url = $this->access->issue($this->customer);
    $page = $this->get($url)->assertOk();
    if ($modernForestry) {
        $page->assertSee('modern-forestry-logo-white.png')->assertSeeText('Your Candle Club');
        if ($output = getenv('SUBSCRIPTIONS_REPLACEMENT_CUSTOMER_RENDER_PATH')) {
            file_put_contents($output, $page->getContent());
        }
    } else {
        $page->assertDontSee('modern-forestry-logo-white.png')->assertDontSeeText('CANDLE CLUB');
    }
})->with([true, false]);
