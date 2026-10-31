<?php

use App\Models\MarketingProfile;
use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingCycle;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use App\Services\Subscriptions\SubscriptionCustomerAmendmentService;
use App\Services\Subscriptions\SubscriptionDeliveryPolicyService;
use App\Services\Subscriptions\SubscriptionReplacementCustomerAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\Support\Subscriptions\AmendmentScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Queue::fake();
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 15)->setTime(10, 0)->utc());
    config(['app.url' => 'https://app.theeverbranch.com', 'tenancy.landlord.primary_host' => 'app.theeverbranch.com', 'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com'],
        'subscriptions.replacement_customer_delivery_enabled' => true]);
    \Illuminate\Support\Facades\URL::forceRootUrl('https://app.theeverbranch.com');
    \Illuminate\Support\Facades\URL::forceScheme('https');
    $this->s = new AmendmentScenario;
    $this->s->store->update(['store_key' => 'retail']);
    $this->profile = MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id, 'first_name' => 'Fictional']);
    $this->customer = new ModernForestryMobileCustomerSession($this->profile, 'fictional-verified-session', ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456']);
    $this->policies = app(SubscriptionDeliveryPolicyService::class);
    $this->delivery = app(SubscriptionCustomerAmendmentService::class);
    $this->policyInput = ['enabled' => true, 'provinces' => ['SC', 'NC'], 'allow_scent_swaps' => true,
        'scents' => [['variant_id' => '888', 'label' => 'Forest Rain · 16oz']], 'reference' => 'merchant-policy:fictional',
        'fixed_shipping_reviewed' => true, 'continuing_authorization_reviewed' => true];
    $this->change = collect($this->s->change())->only(['shipping_address', 'swaps'])->all();
});

afterEach(function () {
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

function recordCustomerDeliveryPolicy($test, array $changes = []): SubscriptionBillingAction
{
    $input = array_replace($test->policyInput, $changes);
    $preview = $test->policies->preview($test->s->tenant->id, $test->s->actor, $test->s->agreement->id, $input);

    return $test->policies->record($test->s->tenant->id, $test->s->actor, $test->s->agreement->id, $input,
        $preview['review_hash'], (string) Str::uuid(), $preview['revision']);
}

function reviewCustomerDelivery($test): array
{
    return $test->delivery->review($test->customer, $test->change, (string) Str::uuid(), $test->s->agreement->fresh()->revision);
}

function applyCustomerDelivery($test, array $review): SubscriptionBillingAction
{
    $e = $review['evidence'];

    return $test->delivery->apply($test->customer, $e['request_uuid'], $e['revision'], $e['review_hash'], true);
}

test('merchant delivery policy preview is offline and one separate signed review preserves billing and original authority', function () {
    $row = $this->s->agreement->fresh();
    $before = $row->getAttributes();
    $reads = count(Http::recorded());
    $preview = $this->policies->preview($row->tenant_id, $this->s->actor, $row->id, $this->policyInput);
    expect($preview['provider_requests'])->toBeFalse()->and($row->fresh()->getAttributes())->toBe($before);
    $uuid = (string) Str::uuid();
    $one = $this->policies->record($row->tenant_id, $this->s->actor, $row->id, $this->policyInput, $preview['review_hash'], $uuid, $row->revision);
    $two = $this->policies->record($row->tenant_id, $this->s->actor, $row->id, $this->policyInput, $preview['review_hash'], $uuid, $row->revision);
    $fresh = $row->fresh();
    expect($two->id)->toBe($one->id)->and($fresh->terms)->toBe($row->terms)->and($fresh->revision)->toBe($row->revision)
        ->and($fresh->method_binding)->toBe($row->method_binding)->and(SubscriptionBillingAuthorization::original($fresh))->toBe(SubscriptionBillingAuthorization::original($row))
        ->and(count(Http::recorded()))->toBe($reads)->and($this->s->sales)->toBe([])->and($this->s->orderCreates)->toBe([]);
});

test('customer review and confirmation retain real customer actor original method and merchant approval separately', function () {
    $policy = recordCustomerDeliveryPolicy($this);
    $original = SubscriptionBillingAuthorization::original($this->s->agreement->fresh());
    $review = reviewCustomerDelivery($this);
    expect($review['quote']['terms']['amount_cents'])->toBe(2675)->and(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0);
    $one = applyCustomerDelivery($this, $review);
    $two = applyCustomerDelivery($this, $review);
    expect($two->id)->toBe($one->id)->and($one->actor_type)->toBe('shopify_customer')->and($one->actor_reference)->toBe('gid://shopify/Customer/456')
        ->and($one->request['accepted_by'])->toBe('gid://shopify/Customer/456')->and($one->request['continuing_authorization_reviewed_by'])->toBe($this->s->actor->id)
        ->and($one->request['customer_review']['policy_action_id'])->toBe($policy->id)
        ->and($this->s->agreement->fresh()->terms['shipping_address']['address1'])->toBe('456 New Example Road')
        ->and($this->s->agreement->fresh()->next_billing_date->toDateString())->toBe('2027-01-31')
        ->and(SubscriptionBillingAuthorization::original($this->s->agreement->fresh()))->toBe($original)
        ->and($this->s->sales)->toBe([])->and($this->s->orderCreates)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
    SubscriptionBillingAuthorization::assertCurrent($this->s->agreement->fresh());
});

test('unreviewed or disabled merchant delivery never becomes customer approval', function (string $defect) {
    if ($defect !== 'missing') {
        recordCustomerDeliveryPolicy($this, $defect === 'disabled' ? ['enabled' => false] : []);
    }
    if ($defect === 'gate') {
        config(['subscriptions.replacement_customer_delivery_enabled' => false]);
    }
    expect($this->delivery->options($this->customer))->toBeNull();
    expect(fn () => reviewCustomerDelivery($this))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0)->and($this->s->sales)->toBe([]);
})->with(['missing', 'disabled', 'gate']);

test('customer cannot choose unreviewed shipping regions scents or impersonate merchant policy approval', function (string $defect) {
    recordCustomerDeliveryPolicy($this);
    if ($defect === 'province') {
        $this->change['shipping_address']['provinceCode'] = 'CA';
    } elseif ($defect === 'scent') {
        $this->change['swaps'][0]['variant_id'] = '12345';
    } else {
        $this->change['fixed_shipping_reviewed'] = true;
    }
    expect(fn () => reviewCustomerDelivery($this))->toThrow($defect === 'merchant_flag' ? \Illuminate\Validation\ValidationException::class : \Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0)->and($this->s->sales)->toBe([]);
})->with(['province', 'scent', 'merchant_flag']);

test('customer confirmation requires exact unexpired review and real approval', function (string $defect) {
    recordCustomerDeliveryPolicy($this);
    $review = reviewCustomerDelivery($this);
    $e = $review['evidence'];
    if ($defect === 'expired') {
        $this->travel(11)->minutes();
    } elseif ($defect === 'hash') {
        $e['review_hash'] = str_repeat('a', 64);
    } elseif ($defect === 'revision') {
        $e['revision']++;
    }
    expect(fn () => $this->delivery->apply($this->customer, $e['request_uuid'], $e['revision'], $e['review_hash'], $defect !== 'unconfirmed'))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0)->and($this->s->sales)->toBe([]);
})->with(['expired', 'hash', 'revision', 'unconfirmed']);

test('policy revocation or provider tax drift after review cannot silently change customer future terms', function (string $defect) {
    recordCustomerDeliveryPolicy($this);
    $review = reviewCustomerDelivery($this);
    if ($defect === 'policy') {
        recordCustomerDeliveryPolicy($this, ['enabled' => false]);
    } else {
        $this->s->taxCents = 200;
    }
    expect(fn () => applyCustomerDelivery($this, $review))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->s->agreement->fresh()->terms['amount_cents'])->toBe(2500)->and($this->s->sales)->toBe([]);
})->with(['policy', 'tax']);

test('past customer confirmation remains valid after policy revocation and replay never restores an older state', function () {
    recordCustomerDeliveryPolicy($this);
    $review = reviewCustomerDelivery($this);
    $one = applyCustomerDelivery($this, $review);
    recordCustomerDeliveryPolicy($this, ['enabled' => false]);
    config(['subscriptions.replacement_customer_delivery_enabled' => false]);
    $two = applyCustomerDelivery($this, $review);
    expect($two->id)->toBe($one->id)->and($this->s->agreement->fresh()->terms['amount_cents'])->toBe(2675);
    SubscriptionBillingAuthorization::assertCurrent($this->s->agreement->fresh());
});

test('accepted customer future delivery produces one original-method payment and exact Shopify order', function () {
    recordCustomerDeliveryPolicy($this);
    $review = reviewCustomerDelivery($this);
    applyCustomerDelivery($this, $review);
    recordCustomerDeliveryPolicy($this, ['enabled' => false]);
    config(['subscriptions.replacement_customer_delivery_enabled' => false]);
    $this->travelTo(now('America/New_York')->setDate(2027, 1, 31)->setTime(10, 0)->utc());
    $cycle = app(\App\Services\Subscriptions\SubscriptionBraintreeExecutionService::class)->collect($this->s->agreement->fresh());
    expect($cycle->amount_cents)->toBe(2675)->and($cycle->handoff_status)->toBe('confirmed')
        ->and($this->s->sales)->toHaveCount(1)->and($this->s->sales[0]['paymentMethodToken'])->toBe('private_method_token')
        ->and($this->s->orderCreates)->toHaveCount(1)->and($cycle->terms['lines'][0]['variant_id'])->toBe('888');
});

test('private member page reviews and confirms future delivery without payment entry or provider calls on view', function () {
    $this->s->tenant->update(['slug' => 'modern-forestry', 'name' => 'Modern Forestry']);
    recordCustomerDeliveryPolicy($this);
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($this->customer);
    $reads = count(Http::recorded());
    $page = $this->get($url)->assertOk()->assertSeeText('Review future delivery')->assertSeeText('Forest Rain · 16oz')
        ->assertDontSee('private_method_token')->assertHeader('Referrer-Policy', 'no-referrer');
    expect(count(Http::recorded()))->toBe($reads)->and($page->headers->get('Cache-Control'))->toContain('no-store');
    $uuid = (string) Str::uuid();
    $revision = $this->s->agreement->fresh()->revision;
    $response = $this->post($url.'/delivery/review', $this->change + ['request_id' => $uuid, 'revision' => $revision,
        'tenant_id' => 999, 'agreement_id' => 999, 'shopify_customer_id' => '999', 'amount_cents' => 1])->assertOk()
        ->assertSeeText('Review your next delivery.')->assertSeeText('USD 26.75')->assertSeeText('January 31, 2027')
        ->assertSeeText('You won’t be charged when you save these changes.')->assertDontSee('Authorize recurring payments')
        ->assertDontSee('fictional_private_key')->assertHeader('X-Frame-Options', 'DENY');
    $review = $response->viewData('review');
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0);
    $confirm = ['request_id' => $uuid, 'revision' => $revision, 'review_hash' => $review['evidence']['review_hash'], 'confirm_future_delivery' => '1'];
    $this->post($url.'/delivery/confirm', $confirm)->assertRedirect($url)->assertSessionHas('subscription_status');
    $this->post($url.'/delivery/confirm', $confirm)->assertRedirect($url);
    $this->get($url)->assertOk()->assertSeeText('456 New Example Road')->assertSeeText('USD 26.75');
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(1)->and($this->s->sales)->toBe([])->and($this->s->orderCreates)->toBe([]);
    if ($directory = getenv('SUBSCRIPTION_DELIVERY_PREVIEW_DIR')) {
        file_put_contents($directory.'/customer-delivery-edit.html', $page->getContent());
        file_put_contents($directory.'/customer-delivery-review.html', $response->getContent());
    }
});

test('confirmation endpoint cannot bypass review or its required customer checkbox', function () {
    recordCustomerDeliveryPolicy($this);
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($this->customer);
    $review = reviewCustomerDelivery($this);
    $e = $review['evidence'];
    $this->post($url.'/delivery/confirm', ['request_id' => $e['request_uuid'], 'revision' => $e['revision'], 'review_hash' => $e['review_hash']])
        ->assertRedirect($url)->assertSessionHasErrors('confirm_future_delivery');
    $this->post($url.'/delivery/confirm', ['request_id' => (string) Str::uuid(), 'revision' => $e['revision'], 'review_hash' => $e['review_hash'], 'confirm_future_delivery' => '1'])
        ->assertRedirect($url)->assertSessionHasErrors('subscription');
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0)->and($this->s->sales)->toBe([]);
});

test('another customer demo or profile tenant cannot use the private customer delivery review', function (string $defect) {
    recordCustomerDeliveryPolicy($this);
    $review = reviewCustomerDelivery($this);
    $identity = $this->customer->identity;
    $profile = $this->profile;
    if ($defect === 'customer') {
        $identity['shopify_customer_id'] = '999';
    } elseif ($defect === 'demo') {
        $identity['source_type'] = 'app_review_demo';
    } else {
        $profile = MarketingProfile::factory()->create();
    }
    $other = new ModernForestryMobileCustomerSession($profile, 'fictional', $identity);
    $e = $review['evidence'];
    expect(fn () => $this->delivery->apply($other, $e['request_uuid'], $e['revision'], $e['review_hash'], true))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0)->and($this->s->sales)->toBe([]);
})->with(['customer', 'demo', 'tenant']);

test('private delivery endpoints reject another host and an expired private grant', function () {
    recordCustomerDeliveryPolicy($this);
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($this->customer);
    $this->post(str_replace('app.theeverbranch.com', 'unknown.example.test', $url).'/delivery/review', [])->assertNotFound();
    $this->travel(16)->minutes();
    $this->post($url.'/delivery/confirm', [])->assertNotFound();
    expect($this->s->sales)->toBe([]);
});

test('customer delivery review does not treat optional empty address fields as a change', function () {
    recordCustomerDeliveryPolicy($this);
    $this->change = ['shipping_address' => $this->s->address() + ['company' => null, 'address2' => null, 'phone' => null]];
    expect(fn () => reviewCustomerDelivery($this))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Choose a different');
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0);
});

test('paused old-date delivery edits stay paused and identical replay after cancellation or expiry cannot undo a stop', function () {
    recordCustomerDeliveryPolicy($this);
    $schedule = app(\App\Services\Subscriptions\SubscriptionBillingAgreementService::class);
    $schedule->customerChange($this->customer, 'pause', (string) Str::uuid(), $this->s->agreement->fresh()->revision);
    $this->travelTo(now('America/New_York')->setDate(2027, 2, 15)->setTime(10, 0)->utc());
    $review = reviewCustomerDelivery($this);
    $one = applyCustomerDelivery($this, $review);
    expect($one->after_state['effective_after_resume'])->toBeTrue()->and($one->after_state['effective_from'])->toBeNull()
        ->and($this->s->agreement->fresh()->status)->toBe('paused');
    $schedule->customerChange($this->customer, 'cancel', (string) Str::uuid(), $this->s->agreement->fresh()->revision);
    $this->travel(11)->minutes();
    expect(applyCustomerDelivery($this, $review)->id)->toBe($one->id)->and($this->s->agreement->fresh()->status)->toBe('cancelled')
        ->and($this->s->sales)->toBe([])->and(SubscriptionBillingCycle::count())->toBe(0);
});

test('accepted customer history cannot outlive corrupted merchant or customer evidence', function (string $defect) {
    $policy = recordCustomerDeliveryPolicy($this);
    $review = reviewCustomerDelivery($this);
    $action = applyCustomerDelivery($this, $review);
    if ($defect === 'policy') {
        $request = $policy->request;
        $request['policy']['provinces'][] = 'CA';
        DB::table('subscription_billing_actions')->where('id', $policy->id)->update(['request' => encrypt(json_encode($request), false)]);
    } elseif ($defect === 'customer') {
        $request = $action->request;
        $request['customer_review']['confirmed_total_cents'] = 1;
        DB::table('subscription_billing_actions')->where('id', $action->id)->update(['request' => encrypt(json_encode($request), false)]);
    } else {
        DB::table('subscription_billing_actions')->where('id', $policy->id)->delete();
    }
    expect(fn () => SubscriptionBillingAuthorization::assertCurrent($this->s->agreement->fresh()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect($this->s->sales)->toBe([]);
})->with(['policy', 'customer', 'missing_policy']);

test('merchant policy rollback and owner revocation stop new customer delivery confirmation', function (string $defect) {
    $policy = recordCustomerDeliveryPolicy($this);
    $review = reviewCustomerDelivery($this);
    if ($defect === 'owner') {
        $this->s->actor->tenants()->updateExistingPivot($this->s->tenant->id, ['membership_active' => false]);
    } else {
        recordCustomerDeliveryPolicy($this, ['enabled' => false]);
        $row = $this->s->agreement->fresh();
        $e = $row->authorization_evidence;
        $e['customer_delivery_policy'] = ['action_id' => $policy->id, 'request_uuid' => $policy->request_uuid, 'policy_hash' => $policy->request['reviewed_hash']];
        $row->update(['authorization_evidence' => $e]);
    }
    expect(fn () => applyCustomerDelivery($this, $review))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0)->and($this->s->sales)->toBe([]);
})->with(['owner', 'pointer']);

test('review cache is encrypted and altered cached delivery cannot replace the customer reviewed address', function () {
    recordCustomerDeliveryPolicy($this);
    $review = reviewCustomerDelivery($this);
    $row = $this->s->agreement->fresh();
    $key = 'subscription-customer-delivery-review:'.hash('sha256', implode('|', [$row->tenant_id, $row->id, 'gid://shopify/Customer/456', $this->profile->id, $review['evidence']['request_uuid']]));
    $value = \Illuminate\Support\Facades\Cache::get($key);
    expect($value)->toBeString()->not->toContain('456 New Example Road');
    $tampered = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($value), true);
    $tampered['quote']['terms']['shipping_address']['address1'] = 'Another address';
    \Illuminate\Support\Facades\Cache::put($key, \Illuminate\Support\Facades\Crypt::encryptString(json_encode($tampered)), 600);
    expect(fn () => applyCustomerDelivery($this, $review))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(SubscriptionBillingAction::where('kind', 'terms_amended')->count())->toBe(0)->and($this->s->sales)->toBe([]);
});

test('one review uuid retains its original customer choice while another choice needs a new review', function () {
    recordCustomerDeliveryPolicy($this);
    $uuid = (string) Str::uuid();
    $revision = $this->s->agreement->fresh()->revision;
    $one = $this->delivery->review($this->customer, $this->change, $uuid, $revision);
    $again = $this->delivery->review($this->customer, $this->change, $uuid, $revision);
    expect($again)->toBe($one);
    $this->change['shipping_address']['address1'] = '789 Other Road';
    expect(fn () => $this->delivery->review($this->customer, $this->change, $uuid, $revision))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    applyCustomerDelivery($this, $one);
    expect($this->s->agreement->fresh()->terms['shipping_address']['address1'])->toBe('456 New Example Road');
});

test('merchant policy command previews offline and records only the exact explicit review from a private file', function () {
    $path = tempnam(sys_get_temp_dir(), 'fictional-delivery-policy-');
    file_put_contents($path, json_encode($this->policyInput));
    chmod($path, 0600);
    try {
        $args = ['--tenant' => $this->s->tenant->slug, '--actor' => $this->s->actor->id, '--agreement' => $this->s->agreement->id, '--file' => $path];
        $reads = count(Http::recorded());
        $preview = $this->policies->preview($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->policyInput);
        $this->artisan('subscriptions:delivery-policy', $args)->assertSuccessful();
        expect(SubscriptionBillingAction::where('kind', 'delivery_policy_reviewed')->count())->toBe(0);
        $this->artisan('subscriptions:delivery-policy', $args + ['--record' => true, '--reviewed-hash' => $preview['review_hash'], '--revision' => $preview['revision'], '--request-id' => (string) Str::uuid()])->assertSuccessful();
        expect(SubscriptionBillingAction::where('kind', 'delivery_policy_reviewed')->count())->toBe(1)->and(count(Http::recorded()))->toBe($reads)
            ->and($this->s->agreement->fresh()->revision)->toBe(2)->and($this->s->sales)->toBe([]);
        chmod($path, 0644);
        $this->artisan('subscriptions:delivery-policy', $args)->assertFailed();
    } finally {
        unlink($path);
    }
});
