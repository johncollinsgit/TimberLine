<?php

use App\Models\MarketingProfile;
use App\Models\SubscriptionBillingAction;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionDeliveryPolicyService;
use App\Services\Subscriptions\SubscriptionShopifyReviewService;
use App\Services\Subscriptions\SubscriptionShopifyUpdateService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyUpdateScenario;

uses(Tests\TestCase::class, Tests\Support\Subscriptions\UsesSubscriptionMySqlRuntime::class);

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->s = new ShopifyUpdateScenario;
    $this->operator = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->operator->tenants()->attach($this->s->tenant, ['role' => 'admin', 'membership_active' => true]);
    $this->updates = app(SubscriptionShopifyUpdateService::class);
    $this->reviews = app(SubscriptionShopifyReviewService::class);
    $this->uuid = (string) Str::uuid();
    $this->revision = $this->s->agreement->fresh()->revision;
    $this->closure = ['reference' => 'support-case:fictional-123', 'reason' => 'Customer abandoned this delivery review; retain the existing delivery terms.', 'no_provider_change_reviewed' => true];
    $this->calculate = fn () => $this->updates->calculate($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->s->change(), $this->uuid, $this->revision);
    $this->inspect = fn () => $this->reviews->inspect($this->s->tenant->id, $this->operator, $this->s->agreement->id, $this->uuid);
    $this->close = fn ($plan) => $this->reviews->close($this->s->tenant->id, $this->operator, $this->s->agreement->id,
        $this->uuid, $plan['revision'], $plan['review_hash'], $this->closure);
    $this->commit = fn ($quote) => $this->updates->commit($this->s->tenant->id, $this->s->actor, $this->s->agreement->id,
        $this->uuid, $this->revision, $quote['quote']['review_hash'], ['reference' => 'merchant-review:fictional',
            'quoted_total_reviewed' => true, 'continuing_authorization_reviewed' => true]);
});

test('a different current admin inspects and closes an abandoned native review without provider or agreement changes', function () {
    $quote = ($this->calculate)();
    $before = $this->s->agreement->fresh()->getAttributes();
    $journal = SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->get()->mapWithKeys(fn ($a) => [$a->id => $a->getAttributes()])->all();
    $reads = count(Http::recorded());
    $plan = ($this->inspect)();
    expect($plan['status'])->toBe('review_open')->and($plan['close_allowed'])->toBeTrue()->and($plan['integrity_valid'])->toBeTrue()
        ->and($plan['original_actor_type'])->toBe('user')->and($plan['provider_requests'])->toBeFalse();
    $text = json_encode($plan);
    expect($text)->not->toContain('New Example Road', 'ORIGINAL_SUBSCRIPTION_TOKEN', 'gid://shopify/Customer/', 'gid://shopify/CustomerPaymentMethod/');
    $result = ($this->close)($plan);
    $closure = SubscriptionBillingAction::findOrFail($result['closure_action_id']);
    expect($result['status'])->toBe('aborted')->and($closure->actor_reference)->toBe((string) $this->operator->id)
        ->and($closure->request['original_actor_reference'])->toBe((string) $this->s->actor->id)
        ->and($closure->request['closure'])->toBe($this->closure)->and($this->s->agreement->fresh()->getAttributes())->toBe($before)
        ->and(count(Http::recorded()))->toBe($reads)->and($this->s->updateCommits)->toBe(0)->and($this->s->bills)->toBe([]);
    foreach ($journal as $id => $attributes) {
        expect(SubscriptionBillingAction::findOrFail($id)->getAttributes())->toBe($attributes);
    }
    expect(($this->inspect)()['close_allowed'])->toBeFalse();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    expect(fn () => ($this->commit)($quote))->toThrow(HttpException::class);
});

function operatorCustomerReview($test): array
{
    config(['subscriptions.replacement_amendment_apply_enabled' => true, 'subscriptions.replacement_customer_delivery_enabled' => true]);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $test->s->tenant->id]);
    $session = new ModernForestryMobileCustomerSession($profile, 'fictional-customer',
        ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456']);
    $policies = app(SubscriptionDeliveryPolicyService::class);
    $input = ['enabled' => true, 'provinces' => ['SC'], 'allow_scent_swaps' => true,
        'scents' => [['variant_id' => '888', 'label' => 'Forest Rain · 16oz']], 'reference' => 'merchant-policy:fictional',
        'fixed_shipping_reviewed' => true, 'continuing_authorization_reviewed' => true];
    $preview = $policies->preview($test->s->tenant->id, $test->s->actor, $test->s->agreement->id, $input);
    $policies->record($test->s->tenant->id, $test->s->actor, $test->s->agreement->id, $input, $preview['review_hash'], (string) Str::uuid(), $test->revision);
    $quote = $test->updates->calculateCustomer($session, collect($test->s->change())->only(['shipping_address', 'swaps'])->all(), $test->uuid, $test->revision);

    return [$profile, $session, $quote];
}

test('operator closure remains available after customer profile reviewer credentials and positive rollout are withdrawn', function () {
    [$profile] = operatorCustomerReview($this);
    $profile->delete();
    $this->s->actor->tenants()->updateExistingPivot($this->s->tenant->id, ['membership_active' => false]);
    $this->s->revision->update(['revoked_at' => now()]);
    config(['subscriptions.replacement_setup_enabled' => false, 'subscriptions.replacement_servicing_enabled' => false,
        'subscriptions.replacement_amendments_enabled' => false, 'subscriptions.replacement_shopify_amendments_enabled' => false,
        'subscriptions.replacement_amendment_apply_enabled' => false, 'subscriptions.replacement_customer_delivery_enabled' => false,
        'subscriptions.replacement_collection_enabled' => false, 'subscriptions.replacement_member_ids' => [], 'subscriptions.provider_reads_enabled' => false]);
    $reads = count(Http::recorded());
    $plan = ($this->inspect)();
    expect($plan['original_actor_type'])->toBe('shopify_customer')->and($plan['close_allowed'])->toBeTrue();
    $result = ($this->close)($plan);
    expect(SubscriptionBillingAction::findOrFail($result['closure_action_id'])->request['original_actor_type'])->toBe('shopify_customer')
        ->and(count(Http::recorded()))->toBe($reads)->and($this->s->updateCommits)->toBe(0);
});

test('competing customer confirmation and operator closure cannot close a retained native send', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    [, $session, $quote] = operatorCustomerReview($this);
    $plan = ($this->inspect)();
    $marker = tempnam(sys_get_temp_dir(), 'native-review-race-');
    $this->s->updateMarkerPath = $marker;
    $children = [];
    DB::disconnect('mysql');
    try {
        foreach ([0, 1] as $index) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Could not start native review worker.');
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
                    $status = $index === 0 ? ($this->close)($plan)['status']
                        : $this->updates->confirmCustomer($session, $this->uuid, $this->revision, $quote['quote']['review_hash'], true)['status'];
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
        expect(is_file($marker.'.ready.0') && is_file($marker.'.ready.1'))->toBeTrue();
        file_put_contents($marker.'.start', 'start');
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0)->toBeTrue();
        }
        DB::purge('mysql');
        $results = [file_get_contents($marker.'.result.0'), file_get_contents($marker.'.result.1')];
        foreach ($results as $result) {
            expect($result)->toBeIn(['aborted', 'applied', 'commit_unknown', 'blocked']);
        }
        $actions = SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->get()->keyBy('kind');
        $sent = $actions->get('shopify_update_commit_sent');
        if ($actions->has('shopify_update_aborted')) {
            expect($results[0])->toBe('aborted')->and($sent)->toBeNull()->and(trim(file_get_contents($marker)))->toBe('')
                ->and($actions->has('terms_amended'))->toBeFalse();
        } else {
            expect($results[0])->toBe('blocked')->and($sent)->not->toBeNull()
                ->and(trim(file_get_contents($marker)))->toBe('commit:'.$sent->request_uuid);
        }
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
    }
});

test('inspection rechecks current verified owner authority and canonical access instead of cached entitlement', function (string $defect) {
    ($this->calculate)();
    expect(app(TenantModuleAccessResolver::class)->module($this->s->tenant->id, 'subscriptions')['has_access'])->toBeTrue();
    match ($defect) {
        'inactive' => $this->operator->update(['is_active' => false]),
        'unverified' => $this->operator->forceFill(['email_verified_at' => null])->save(),
        'membership' => $this->operator->tenants()->updateExistingPivot($this->s->tenant->id, ['membership_active' => false]),
        'manager' => $this->operator->tenants()->updateExistingPivot($this->s->tenant->id, ['role' => 'manager']),
        'tenant' => $this->operator->tenants()->detach($this->s->tenant->id),
        'entitlement' => TenantModuleEntitlement::where('tenant_id', $this->s->tenant->id)->update(['enabled_status' => 'disabled']),
    };
    $reads = count(Http::recorded());
    expect($this->inspect)->toThrow(HttpException::class);
    expect(count(Http::recorded()))->toBe($reads)->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_update_aborted')->count())->toBe(0);
})->with(['inactive', 'unverified', 'membership', 'manager', 'tenant', 'entitlement']);

test('a later pause invalidates the close plan and an exact closure retry never restores an older state', function () {
    ($this->calculate)();
    $old = ($this->inspect)();
    app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, 'pause', (string) Str::uuid(), $this->revision);
    expect(fn () => ($this->close)($old))->toThrow(HttpException::class);
    $plan = ($this->inspect)();
    $result = ($this->close)($plan);
    app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, 'cancel', (string) Str::uuid(), $plan['revision']);
    $newer = $this->s->agreement->fresh()->getAttributes();
    expect(($this->close)($plan))->toBe($result)->and($this->s->agreement->fresh()->getAttributes())->toBe($newer);
    $this->closure['reason'] = 'Another reason cannot overwrite the originally accepted closure.';
    expect(fn () => ($this->close)($plan))->toThrow(HttpException::class);
    $this->operator->tenants()->updateExistingPivot($this->s->tenant->id, ['membership_active' => false]);
    expect(fn () => ($this->close)($plan))->toThrow(HttpException::class);
});

test('closure requires an exact inspected hash explicit acknowledgement and a recorded reason', function (string $defect) {
    ($this->calculate)();
    $plan = ($this->inspect)();
    match ($defect) {
        'reason' => $this->closure['reason'] = '          ',
        'acknowledgement' => $this->closure['no_provider_change_reviewed'] = false,
        'extra' => $this->closure['approved'] = true,
        'hash' => $plan['review_hash'] = str_repeat('a', 64),
    };
    expect(fn () => ($this->close)($plan))->toThrow(in_array($defect, ['reason', 'extra'], true) ? \Illuminate\Validation\ValidationException::class : HttpException::class);
    expect(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_update_aborted')->count())->toBe(0);
})->with(['reason', 'acknowledgement', 'extra', 'hash']);

test('uncertain sent native commits stay on their recovery path and invalidate an earlier operator plan', function () {
    $quote = ($this->calculate)();
    $old = ($this->inspect)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->loseUpdateCommitResponse = true;
    expect(($this->commit)($quote)['status'])->toBe('commit_unknown');
    $reads = count(Http::recorded());
    $plan = ($this->inspect)();
    expect($plan['status'])->toBe('recovery_required')->and($plan['requires_provider_recovery'])->toBeTrue()->and($plan['close_allowed'])->toBeFalse();
    expect(fn () => ($this->close)($old))->toThrow(HttpException::class);
    expect(fn () => ($this->close)($plan))->toThrow(HttpException::class);
    expect(count(Http::recorded()))->toBe($reads)->and($this->s->updateCommits)->toBe(1);
    config()->set('subscriptions.replacement_shopify_amendments_enabled', false);
    expect($this->updates->reconcile($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid)['status'])->toBe('applied')
        ->and($this->s->updateCommits)->toBe(1)->and(($this->inspect)()['close_allowed'])->toBeFalse();
});

test('a signed definite rejection or known pre HTTP failure permits reasoned operator closure', function (string $outcome) {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    if ($outcome === 'rejected') {
        $this->s->updateCommitError = 'STALE_CONTRACT';
    } else {
        DB::listen(function ($query) {
            if (str_starts_with($query->sql, 'insert into `subscription_billing_actions`') && in_array('shopify_update_commit_sent', $query->bindings, true)) {
                config()->set('subscriptions.replacement_amendment_apply_enabled', false);
            }
        });
    }
    expect(($this->commit)($quote)['status'])->toBe('commit_closed');
    $reads = count(Http::recorded());
    $plan = ($this->inspect)();
    expect($plan['integrity_valid'])->toBeTrue()->and($plan['close_allowed'])->toBeTrue()->and($plan['requires_provider_recovery'])->toBeFalse();
    expect(($this->close)($plan)['status'])->toBe('aborted')->and(count(Http::recorded()))->toBe($reads)
        ->and($this->s->updateCommits)->toBe($outcome === 'rejected' ? 1 : 0);
})->with(['rejected', 'not_sent']);

test('missing claim parents or tampered signatures cannot hide a possible native send', function (string $defect) {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->loseUpdateCommitResponse = $defect !== 'terms_amended';
    ($this->commit)($quote);
    $action = SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', $defect === 'terms_amended' ? $defect : 'shopify_update_'.$defect)->firstOrFail();
    if ($defect === 'commit_requested') {
        DB::table('subscription_billing_actions')->where('id', $action->id)->delete();
    } else {
        DB::table('subscription_billing_actions')->where('id', $action->id)->update(['actor_reference' => '999999']);
    }
    $plan = ($this->inspect)();
    expect($plan['status'])->toBe('review_invalid')->and($plan['integrity_valid'])->toBeFalse()->and($plan['close_allowed'])->toBeFalse()
        ->and($plan['requires_provider_recovery'])->toBeTrue();
    expect(fn () => ($this->close)($plan))->toThrow(HttpException::class);
})->with(['commit_requested', 'commit_sent', 'terms_amended']);

test('native operator CLI previews closure without opening its file and applies only the exact private review', function () {
    ($this->calculate)();
    $args = ['--tenant' => $this->s->tenant->slug, '--actor' => $this->operator->id, '--agreement' => $this->s->agreement->id,
        '--request-id' => $this->uuid, '--action' => 'close-review', '--file' => '/missing-file.json'];
    $reads = count(Http::recorded());
    expect(Artisan::call('subscriptions:shopify-amend', $args))->toBe(0);
    $plan = json_decode(trim(Artisan::output()), true, 32, JSON_THROW_ON_ERROR);
    expect($plan['close_allowed'])->toBeTrue()->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_update_aborted')->exists())->toBeFalse();
    $file = tempnam(sys_get_temp_dir(), 'native-operator-review-');
    try {
        file_put_contents($file, json_encode(['closure' => $this->closure], JSON_THROW_ON_ERROR));
        chmod($file, 0644);
        $apply = array_replace($args, ['--apply' => true, '--file' => $file, '--revision' => $plan['revision'], '--reviewed-hash' => $plan['review_hash']]);
        expect(Artisan::call('subscriptions:shopify-amend', $apply))->toBe(1);
        chmod($file, 0600);
        clearstatcache(true, $file);
        expect(Artisan::call('subscriptions:shopify-amend', $apply))->toBe(0);
        expect(json_decode(trim(Artisan::output()), true)['status'])->toBe('aborted')->and(count(Http::recorded()))->toBe($reads);
    } finally {
        unlink($file);
    }
});
