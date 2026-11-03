<?php

use App\Models\MarketingProfile;
use App\Models\SubscriptionBillingAction;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use App\Services\Subscriptions\SubscriptionCustomerAmendmentService;
use App\Services\Subscriptions\SubscriptionDeliveryPolicyService;
use App\Services\Subscriptions\SubscriptionReplacementCustomerAccess;
use App\Services\Subscriptions\SubscriptionShopifyBillingTransport;
use App\Services\Subscriptions\SubscriptionShopifyCustomerActor;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use App\Services\Subscriptions\SubscriptionShopifyUpdateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyUpdateScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    $this->withoutVite();
    config(['app.url' => 'https://app.theeverbranch.com', 'tenancy.landlord.primary_host' => 'app.theeverbranch.com',
        'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com']]);
    \Illuminate\Support\Facades\URL::forceRootUrl('https://app.theeverbranch.com');
    \Illuminate\Support\Facades\URL::forceScheme('https');
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->s = new ShopifyUpdateScenario;
    config(['subscriptions.replacement_amendment_apply_enabled' => true, 'subscriptions.replacement_customer_delivery_enabled' => true]);
    $this->profile = MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id]);
    $this->customer = new ModernForestryMobileCustomerSession($this->profile, 'fictional-authenticated-session',
        ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456']);
    $this->updates = app(SubscriptionShopifyUpdateService::class);
    $this->policyInput = ['enabled' => true, 'provinces' => ['SC', 'NC'], 'allow_scent_swaps' => true,
        'scents' => [['variant_id' => '888', 'label' => 'Forest Rain · 16oz']], 'reference' => 'merchant-policy:fictional',
        'fixed_shipping_reviewed' => true, 'continuing_authorization_reviewed' => true];
    $this->policy = nativeCustomerPolicy($this);
    $this->uuid = (string) Str::uuid();
    $this->revision = $this->s->agreement->fresh()->revision;
    $this->change = collect($this->s->change())->only(['shipping_address', 'swaps'])->all();
    $this->calculate = fn () => $this->updates->calculateCustomer($this->customer, $this->change, $this->uuid, $this->revision);
});

function nativeCustomerPolicy($test, array $changes = []): SubscriptionBillingAction
{
    $s = $test->s;
    $row = $s->agreement->fresh();
    $policy = app(SubscriptionDeliveryPolicyService::class);
    $input = array_replace($test->policyInput, $changes);
    $review = $policy->preview($s->tenant->id, $s->actor, $row->id, $input);

    return $policy->record($s->tenant->id, $s->actor, $row->id, $input, $review['review_hash'], (string) Str::uuid(), $row->revision);
}

function nativeCustomerJournal($test, string $phase): ?SubscriptionBillingAction
{
    return SubscriptionBillingAction::query()->where('agreement_id', $test->s->agreement->id)->where('kind', 'shopify_update_'.$phase)->first();
}

test('native customer calculation and exact confirmation retain the real customer and merchant policy separately', function () {
    $original = SubscriptionBillingAuthorization::original($this->s->agreement);
    $binding = $this->s->agreement->method_binding;
    $quote = ($this->calculate)();
    expect($quote['status'])->toBe('quote_ready')->and($this->s->updateInputs[0]['actor'])->toBe('CUSTOMER')
        ->and(nativeCustomerJournal($this, 'requested')->actor_type)->toBe('shopify_customer')
        ->and(nativeCustomerJournal($this, 'requested')->actor_reference)->toBe('gid://shopify/Customer/456')
        ->and($this->s->updateCommits)->toBe(0)->and($this->s->bills)->toBe([]);
    $reads = count(Http::recorded());
    expect(fn () => $this->updates->confirmCustomer($this->customer, $this->uuid, $this->revision, $quote['quote']['review_hash'], false))->toThrow(HttpException::class);
    expect(count(Http::recorded()))->toBe($reads)->and($this->s->updateCommits)->toBe(0);
    $result = $this->updates->confirmCustomer($this->customer, $this->uuid, $this->revision, $quote['quote']['review_hash'], true);
    $action = SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'terms_amended')->firstOrFail();
    expect($result['status'])->toBe('applied')->and($action->actor_type)->toBe('shopify_customer')
        ->and($action->request['accepted_by'])->toBe('gid://shopify/Customer/456')
        ->and($action->request['continuing_authorization_reviewed_by'])->toBe($this->s->actor->id)
        ->and($action->request['customer_confirmation']['confirmed_total_cents'])->toBe(2675)
        ->and($action->request['customer_confirmation']['policy_action_id'])->toBe($this->policy->id)
        ->and($this->s->agreement->fresh()->method_binding)->toBe($binding)
        ->and(SubscriptionBillingAuthorization::original($this->s->agreement->fresh()))->toBe($original)
        ->and($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
    SubscriptionBillingAuthorization::assertCurrent($this->s->agreement->fresh());
    if ($path = getenv('SUBSCRIPTION_NATIVE_CUSTOMER_INPUT_FILE')) {
        file_put_contents($path, json_encode($this->s->updateInputs[0], JSON_THROW_ON_ERROR));
    }
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $cycle = app(SubscriptionShopifyExecutionService::class)->collect($this->s->agreement);
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->amount_cents)->toBe(2675)->and($this->s->bills)->toHaveCount(1);
});

test('a merchant cannot use owner confirmation to approve a customer request', function () {
    $quote = ($this->calculate)();
    expect(fn () => $this->updates->commit($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid, $this->revision,
        $quote['quote']['review_hash'], ['reference' => 'delivery-policy:'.$this->policy->request_uuid,
            'quoted_total_reviewed' => true, 'continuing_authorization_reviewed' => true]))->toThrow(HttpException::class);
    expect($this->s->updateCommits)->toBe(0)->and(nativeCustomerJournal($this, 'commit_requested'))->toBeNull();
});

test('customer confirmation is bound to the original authenticated profile and customer identity', function (string $defect) {
    $quote = ($this->calculate)();
    $profile = $this->profile;
    $identity = ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456'];
    if ($defect === 'profile') {
        $profile = MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id]);
    } elseif ($defect === 'customer') {
        $identity['shopify_customer_id'] = 'gid://shopify/Customer/999';
    } else {
        $identity['source_type'] = 'app_review_demo';
    }
    $session = new ModernForestryMobileCustomerSession($profile, 'fictional-other-session', $identity);
    expect(fn () => $this->updates->confirmCustomer($session, $this->uuid, $this->revision, $quote['quote']['review_hash'], true))->toThrow(HttpException::class);
    expect($this->s->updateCommits)->toBe(0);
})->with(['profile', 'customer', 'demo']);

test('customer commit rechecks current policy gates reviewer profile and exact quote', function (string $defect) {
    $quote = ($this->calculate)();
    $hash = $quote['quote']['review_hash'];
    match ($defect) {
        'customer_gate' => config()->set('subscriptions.replacement_customer_delivery_enabled', false),
        'apply_gate' => config()->set('subscriptions.replacement_amendment_apply_enabled', false),
        'policy' => nativeCustomerPolicy($this, ['enabled' => false]),
        'reviewer' => $this->s->actor->tenants()->updateExistingPivot($this->s->tenant->id, ['membership_active' => false]),
        'profile' => $this->profile->delete(),
        'expired' => $this->travel(11)->minutes(),
        'hash' => $hash = str_repeat('f', 64),
    };
    expect(fn () => $this->updates->confirmCustomer($this->customer, $this->uuid, $this->revision, $hash, true))->toThrow(HttpException::class);
    expect($this->s->updateCommits)->toBe(0)->and(nativeCustomerJournal($this, 'commit_sent'))->toBeNull();
})->with(['customer_gate', 'apply_gate', 'policy', 'reviewer', 'profile', 'expired', 'hash']);

test('lost customer commit recovery retains a later pause and historical policy after editing withdrawal', function () {
    $quote = ($this->calculate)();
    $this->s->loseUpdateCommitResponse = true;
    expect($this->updates->confirmCustomer($this->customer, $this->uuid, $this->revision, $quote['quote']['review_hash'], true)['status'])->toBe('commit_unknown');
    app(SubscriptionBillingAgreementService::class)->customerChange($this->customer, 'pause', (string) Str::uuid(), $this->revision, ['duration_months' => 1]);
    $paused = $this->s->agreement->fresh();
    config(['subscriptions.replacement_customer_delivery_enabled' => false, 'subscriptions.replacement_shopify_amendments_enabled' => false]);
    $result = $this->updates->reconcileCustomer($this->customer, $this->uuid);
    expect($result['status'])->toBe('applied')->and($this->s->agreement->fresh()->status)->toBe('paused')
        ->and($this->s->agreement->fresh()->next_billing_date)->toEqual($paused->next_billing_date)
        ->and($this->s->agreement->fresh()->resume_on)->toEqual($paused->resume_on)->and($this->s->updateCommits)->toBe(1);
    $reads = count(Http::recorded());
    SubscriptionBillingAuthorization::assertCurrent($this->s->agreement->fresh());
    expect($this->updates->confirmCustomer($this->customer, $this->uuid, $this->revision, $quote['quote']['review_hash'], true)['status'])->toBe('applied')
        ->and(count(Http::recorded()))->toBe($reads)->and($this->s->updateCommits)->toBe(1);
});

test('retained customer dispatch evidence cannot become a new interactive request or be forged', function () {
    ($this->calculate)();
    $request = nativeCustomerJournal($this, 'requested');
    $actor = SubscriptionShopifyCustomerActor::fromJournal($this->s->agreement->fresh(), $request);
    expect(fn () => $this->updates->calculate($this->s->tenant->id, $actor, $this->s->agreement->id, $request->request['input'], (string) Str::uuid(), $this->revision))->toThrow(HttpException::class);
    $fake = clone $request;
    $input = $fake->request;
    $input['customer_actor']['profile_id']++;
    $fake->request = $input;
    expect(fn () => SubscriptionShopifyCustomerActor::fromJournal($this->s->agreement->fresh(), $fake))->toThrow(HttpException::class);
    expect($this->s->updateInputs)->toHaveCount(1)->and($this->s->updateCommits)->toBe(0);
});

test('a customer can close their unapproved calculation after an editing freeze without a contract mutation', function () {
    ($this->calculate)();
    config()->set('subscriptions.replacement_customer_delivery_enabled', false);
    expect($this->updates->abortCustomer($this->customer, $this->uuid)['status'])->toBe('aborted')->and($this->s->updateCommits)->toBe(0);
});

test('the branded native portal follows pending calculation exact review and customer confirmation without card entry', function () {
    $this->s->tenant->update(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($this->customer);
    $reads = count(Http::recorded());
    $page = $this->get($url)->assertOk()->assertSeeText('Review future delivery')->assertHeader('Referrer-Policy', 'no-referrer');
    expect(count(Http::recorded()))->toBe($reads);
    $this->s->updatePending = true;
    $pending = $this->post($url.'/delivery/review', $this->change + ['request_id' => $this->uuid, 'revision' => $this->revision,
        'tenant_id' => 999, 'agreement_id' => 999, 'amount_cents' => 1])->assertOk()->assertSeeText('Your delivery review is on its way.')
        ->assertDontSee('original_method')->assertDontSee('ORIGINAL_SUBSCRIPTION_TOKEN')->assertHeader('X-Frame-Options', 'DENY');
    expect($pending->headers->get('Cache-Control'))->toContain('no-store');
    $this->get($url)->assertOk()->assertSeeText('Open delivery review');
    $this->s->updatePending = false;
    $review = $this->get($url.'/delivery/'.$this->uuid)->assertOk()->assertSeeText('Review your next delivery.')->assertSeeText('USD 26.75')
        ->assertSeeText('Your existing saved payment method stays on this membership.')->assertDontSee('Authorize recurring payments');
    $data = $review->viewData('review');
    $confirmation = ['request_id' => $this->uuid, 'revision' => $this->revision, 'review_hash' => $data['evidence']['review_hash']];
    $this->post($url.'/delivery/confirm', $confirmation)->assertRedirect($url)->assertSessionHasErrors('confirm_future_delivery');
    expect($this->s->updateCommits)->toBe(0);
    $this->post($url.'/delivery/confirm', $confirmation + ['confirm_future_delivery' => '1'])->assertRedirect($url)->assertSessionHas('subscription_status');
    $this->get($url)->assertOk()->assertSeeText('USD 26.75')->assertSeeText('456 New Example Road');
    $this->get($url.'/delivery/'.$this->uuid)->assertRedirect($url);
    expect($this->s->updateInputs)->toHaveCount(1)->and($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
    if ($directory = getenv('SUBSCRIPTION_NATIVE_CUSTOMER_PREVIEW_DIR')) {
        file_put_contents($directory.'/native-customer-delivery-edit.html', $page->getContent());
        file_put_contents($directory.'/native-customer-delivery-pending.html', $pending->getContent());
        file_put_contents($directory.'/native-customer-delivery-review.html', $review->getContent());
    }
});

test('a native portal uncertain commit cannot be discarded or resent and later shows the applied choice', function () {
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($this->customer);
    $review = app(SubscriptionCustomerAmendmentService::class)->review($this->customer, $this->change, $this->uuid, $this->revision);
    $this->s->loseUpdateCommitResponse = true;
    $this->post($url.'/delivery/confirm', ['request_id' => $this->uuid, 'revision' => $this->revision,
        'review_hash' => $review['evidence']['review_hash'], 'confirm_future_delivery' => '1'])->assertOk()
        ->assertSeeText('We’re checking your delivery update.')->assertDontSee('Close this delivery review');
    $this->post($url.'/delivery/'.$this->uuid.'/discard')->assertRedirect($url)->assertSessionHasErrors('subscription');
    $this->get($url.'/delivery/'.$this->uuid)->assertRedirect($url);
    expect($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
});

test('a stale native portal review can be explicitly closed before starting a fresh delivery choice', function () {
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($this->customer);
    app(SubscriptionCustomerAmendmentService::class)->review($this->customer, $this->change, $this->uuid, $this->revision);
    $this->travel(11)->minutes();
    $this->get($url.'/delivery/'.$this->uuid)->assertOk()->assertSeeText('this review expired')->assertSeeText('Close this delivery review');
    $this->post($url.'/delivery/'.$this->uuid.'/discard')->assertRedirect($url);
    $this->uuid = (string) Str::uuid();
    expect(($this->calculate)()['status'])->toBe('quote_ready')->and($this->s->updateCommits)->toBe(0)->and($this->s->updateInputs)->toHaveCount(2);
});

test('a different profile cannot open an old delivery draft but retains its own membership controls', function () {
    ($this->calculate)();
    $profile = MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id]);
    $session = new ModernForestryMobileCustomerSession($profile, 'fictional-new-profile-session',
        ['source_type' => 'shopify_customer', 'shopify_customer_id' => '456']);
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($session);
    $this->get($url)->assertOk()->assertSeeText('Pause')->assertDontSeeText('Open delivery review');
    $this->get($url.'/delivery/'.$this->uuid)->assertNotFound();
    expect($this->s->updateCommits)->toBe(0);
});

test('a private status GET never starts a prepared customer calculation after a pre-dispatch failure', function () {
    $transport = \Mockery::mock(SubscriptionShopifyBillingTransport::class)->makePartial();
    $transport->shouldReceive('updateCalculate')->once()->andThrow(new HttpException(423, 'Fictional interruption before the original dispatch.'));
    app()->instance(SubscriptionShopifyBillingTransport::class, $transport);
    expect($this->calculate)->toThrow(HttpException::class);
    app()->forgetInstance(SubscriptionShopifyBillingTransport::class);
    $url = app(SubscriptionReplacementCustomerAccess::class)->issue($this->customer);
    $reads = count(Http::recorded());
    $this->get($url.'/delivery/'.$this->uuid)->assertOk()->assertSeeText('Your delivery review is on its way.');
    expect(count(Http::recorded()))->toBe($reads)->and($this->s->updateInputs)->toBe([])
        ->and(nativeCustomerJournal($this, 'calculate_sent'))->toBeNull()->and($this->s->updateCommits)->toBe(0);
});

test('native customers cannot submit unoffered regions scents or merchant review fields', function (string $defect) {
    if ($defect === 'province') {
        $this->change['shipping_address']['provinceCode'] = 'CA';
    } elseif ($defect === 'scent') {
        $this->change['swaps'][0]['variant_id'] = '12345';
    } else {
        $this->change['continuing_authorization_reviewed'] = true;
    }
    expect($this->calculate)->toThrow($defect === 'merchant_fields' ? \Illuminate\Validation\ValidationException::class : HttpException::class);
    expect($this->s->updateInputs)->toBe([])->and($this->s->updateCommits)->toBe(0);
})->with(['province', 'scent', 'merchant_fields']);

test('two MySQL customer confirmations share one original update commit and one accepted history action', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $quote = ($this->calculate)();
    $marker = tempnam(sys_get_temp_dir(), 'native-customer-commit-');
    $this->s->updateMarkerPath = $marker;
    $children = [];
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start isolated customer confirmation worker.');
            }
            if ($pid === 0) {
                pcntl_alarm(15);
                DB::purge('mysql');
                DB::statement('SET SESSION innodb_lock_wait_timeout=3');
                file_put_contents($marker.'.ready.'.$index, 'ready');
                while (! is_file($marker.'.start')) {
                    usleep(1000);
                }
                try {
                    $status = $this->updates->confirmCustomer($this->customer, $this->uuid, $this->revision, $quote['quote']['review_hash'], true)['status'];
                } catch (HttpException $error) {
                    $status = in_array($error->getStatusCode(), [409, 423], true) ? 'blocked' : 'unexpected_http';
                } catch (Throwable) {
                    $status = 'unexpected_error';
                }
                file_put_contents($marker.'.result.'.$index, $status);
                exit(0);
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 5;
        while ((! is_file($marker.'.ready.0') || ! is_file($marker.'.ready.1')) && microtime(true) < $deadline) {
            usleep(1000);
        }
        file_put_contents($marker.'.start', 'start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
        }
        DB::purge('mysql');
        $results = [file_get_contents($marker.'.result.0'), file_get_contents($marker.'.result.1')];
        expect($results)->toContain('applied');
        foreach ($results as $result) {
            expect($result)->toBeIn(['applied', 'commit_unknown', 'blocked']);
        }
        expect(array_values(array_filter(explode("\n", file_get_contents($marker)))))->toBe(['commit:'.nativeCustomerJournal($this, 'commit_sent')->request_uuid])
            ->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'terms_amended')->count())->toBe(1)
            ->and(nativeCustomerJournal($this, 'commit_sent')->actor_reference)->toBe('gid://shopify/Customer/456');
        SubscriptionBillingAuthorization::assertCurrent($this->s->agreement->fresh());
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
    }
});
