<?php

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingCycle;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use App\Services\Subscriptions\SubscriptionShopifyTaxService;
use App\Services\Subscriptions\SubscriptionShopifyUpdateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyUpdateScenario;

uses(Tests\TestCase::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Native tax claim durability requires disposable MySQL without outer transactions.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing native tax tests outside disposable local MySQL.');
    }
    expect(DB::transactionLevel())->toBe(0);
    Mail::fake();
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->s = new ShopifyUpdateScenario;
    $service = app(SubscriptionShopifyUpdateService::class);
    $row = $this->s->agreement->fresh();
    $uuid = (string) Str::uuid();
    $quote = $service->calculate($row->tenant_id, $this->s->actor, $row->id, $this->s->change(), $uuid, $row->revision);
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    expect($service->commit($row->tenant_id, $this->s->actor, $row->id, $uuid, $row->revision, $quote['quote']['review_hash'],
        ['reference' => 'fictional-tax-preflight-owner-review', 'quoted_total_reviewed' => true, 'continuing_authorization_reviewed' => true])['status'])->toBe('applied');
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z'));
    $this->execution = app(SubscriptionShopifyExecutionService::class);
    $this->collect = fn () => $this->execution->collect($this->s->agreement->fresh());
});

afterEach(function () {
    if (! isset($this->s)) {
        return;
    }
    DB::purge('mysql');
    DB::table('subscription_recharge_connections')->where('tenant_id', $this->s->tenant->id)->update(['active_credential_revision_id' => null]);
    DB::table('subscription_installations')->where('tenant_id', $this->s->tenant->id)->update(['active_credential_revision_id' => null]);
    foreach (['subscription_billing_actions', 'subscription_billing_cycles', 'subscription_billing_agreements', 'subscription_pilot_events',
        'subscription_pilot_members', 'subscription_recharge_credential_revisions', 'subscription_recharge_connections', 'subscription_credential_revisions',
        'subscription_installations', 'shopify_stores', 'tenant_module_entitlements'] as $table) {
        DB::table($table)->where('tenant_id', $this->s->tenant->id)->delete();
    }
    $this->s->actor->tenants()->detach($this->s->tenant->id);
    DB::table('users')->where('id', $this->s->actor->id)->delete();
    DB::table('tenants')->where('id', $this->s->tenant->id)->delete();
    Mail::assertNothingSent();
    Queue::assertNothingPushed();
});

test('fresh native tax preflight bills accepted terms once without another contract commit', function () {
    $contract = $this->s->contract;
    $cycle = ($this->collect)();
    if ($path = getenv('SUBSCRIPTION_NATIVE_TAX_VALIDATION_FILE')) {
        file_put_contents($path, json_encode(array_values($this->s->taxInputs)[0], JSON_PRETTY_PRINT));
    }
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->amount_cents)->toBe(2675)
        ->and($cycle->terms['tax_cents'])->toBe(175)->and($this->s->bills)->toHaveCount(1)
        ->and($this->s->taxInputs)->toHaveCount(1)->and($this->s->updateCommits)->toBe(1)
        ->and($this->s->contract['customAttributes'])->toBe($contract['customAttributes'])
        ->and($cycle->reconciliation['dispatch_native_tax_preflight']['quote']['amount_cents'])->toBe(2675)
        ->and($cycle->reconciliation['latest_shopify_receipt']['status'])->toBe('succeeded');
    $attempt = $this->s->attempts[$cycle->provider_reference];
    expect($attempt['state']['order']['shippingAddress']['address1'])->toBe('456 New Example Road')
        ->and($attempt['state']['order']['lineItems']['nodes'][0]['variant']['id'])->toBe('gid://shopify/ProductVariant/888');
    expect(fn () => $cycle->update(['reconciliation' => array_replace($cycle->reconciliation, ['dispatch_native_tax_preflight' => null])]))->toThrow(LogicException::class);
});

test('changed native tax stops before a billing cycle or payment is created', function () {
    $this->s->calculatedTaxCents = 200;
    expect($this->collect)->toThrow(HttpException::class);
    expect($this->s->bills)->toBe([])->and(SubscriptionBillingCycle::where('agreement_id', $this->s->agreement->id)->count())->toBe(0)
        ->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_tax_received')->count())->toBe(1)
        ->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_tax_proof')->count())->toBe(0);
});

test('pending native tax uses only its original handle before permitting payment', function () {
    $this->s->taxPending = true;
    expect($this->collect)->toThrow(HttpException::class);
    expect($this->s->taxInputs)->toHaveCount(1)->and($this->s->bills)->toBe([]);
    $this->s->taxPending = false;
    expect(($this->collect)()->payment_status)->toBe('succeeded')->and($this->s->taxInputs)->toHaveCount(1)
        ->and($this->s->taxPolls)->toBeGreaterThanOrEqual(1)->and($this->s->bills)->toHaveCount(1);
});

test('lost native tax response is not resent and an expired tax-only generation renews without duplicate payment', function () {
    $this->s->taxLoseResponse = true;
    expect($this->collect)->toThrow(HttpException::class);
    expect($this->collect)->toThrow(HttpException::class);
    expect($this->s->taxInputs)->toHaveCount(1)->and($this->s->bills)->toBe([]);
    $first = array_key_first($this->s->taxInputs);
    $this->travelTo(now()->addSeconds(SubscriptionShopifyTaxService::FRESH_SECONDS + 1));
    $this->s->taxLoseResponse = false;
    expect(($this->collect)()->payment_status)->toBe('succeeded')->and($this->s->taxInputs)->toHaveCount(2)
        ->and(array_key_last($this->s->taxInputs))->not->toBe($first)->and($this->s->bills)->toHaveCount(1)
        ->and(SubscriptionBillingAction::where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_tax_expired')->count())->toBe(1);
});

test('a completed late native tax calculation cannot become a fresh payment proof', function () {
    $this->s->taxPending = true;
    expect($this->collect)->toThrow(HttpException::class);
    $first = array_key_first($this->s->taxInputs);
    $this->travelTo(now()->addSeconds(SubscriptionShopifyTaxService::FRESH_SECONDS + 1));
    $this->s->taxPending = false;
    $cycle = ($this->collect)();
    expect($cycle->payment_status)->toBe('succeeded')->and($this->s->taxInputs)->toHaveCount(2)
        ->and($cycle->reconciliation['dispatch_native_tax_preflight']['request_uuid'])->not->toBe($first)
        ->and($this->s->bills)->toHaveCount(1);
});

test('native tax warning or provider error retains evidence but cannot authorize collection', function (string $defect) {
    $this->s->{$defect} = true;
    expect($this->collect)->toThrow(HttpException::class);
    expect($this->s->bills)->toBe([])->and($this->s->taxInputs)->toHaveCount(1)
        ->and(SubscriptionBillingCycle::where('agreement_id', $this->s->agreement->id)->count())->toBe(0);
})->with(['taxWarning', 'taxUserError']);

test('current pause owner withdrawal or credential revocation wins over a pending native tax result', function (string $change) {
    $this->s->taxPending = true;
    $this->s->beforeTaxPoll = function () use ($change) {
        $this->s->taxPending = false;
        if ($change === 'pause') {
            $row = $this->s->agreement->fresh();
            app(SubscriptionBillingAgreementService::class)->change($row->tenant_id, $this->s->actor, $row->id, 'pause', (string) Str::uuid(), $row->revision);
        } elseif ($change === 'owner') {
            DB::table('tenant_user')->where('tenant_id', $this->s->tenant->id)->where('user_id', $this->s->actor->id)->update(['membership_active' => false]);
        } else {
            $this->s->revision->update(['revoked_at' => now()]);
        }
    };
    expect($this->collect)->toThrow(HttpException::class);
    expect($this->s->bills)->toBe([])->and(SubscriptionBillingCycle::where('agreement_id', $this->s->agreement->id)->count())->toBe(0);
})->with(['pause', 'owner', 'credential']);

test('prepared native cycle refreshes an expired proof while retaining frozen amount and payment request', function () {
    $this->s->beforeBillingCapabilities = fn () => $this->travelTo(now()->addSeconds(SubscriptionShopifyTaxService::FRESH_SECONDS + 1));
    $prepared = ($this->collect)();
    expect($prepared->payment_status)->toBe('prepared')->and($prepared->payment_started_at)->toBeNull()->and($this->s->bills)->toBe([]);
    $first = $prepared->reconciliation['native_tax_preflight']['request_uuid'];
    $request = $prepared->provider_request;
    $terms = $prepared->terms;
    $paid = ($this->collect)();
    expect($paid->id)->toBe($prepared->id)->and($paid->payment_status)->toBe('succeeded')->and($paid->provider_request)->toBe($request)
        ->and($paid->terms)->toBe($terms)->and($paid->reconciliation['dispatch_native_tax_preflight']['request_uuid'])->not->toBe($first)
        ->and($this->s->taxInputs)->toHaveCount(2)->and($this->s->bills)->toHaveCount(1);
});

test('withdrawn editing gates do not stop separately authorized accepted native billing', function () {
    config(['subscriptions.replacement_amendments_enabled' => false, 'subscriptions.replacement_shopify_amendments_enabled' => false,
        'subscriptions.replacement_amendment_apply_enabled' => false, 'subscriptions.replacement_servicing_enabled' => false]);
    expect(($this->collect)()->payment_status)->toBe('succeeded')->and($this->s->bills)->toHaveCount(1);
});

test('already-started native attempt reconciliation never starts another tax calculation or payment', function () {
    $this->s->attemptState = 'processing';
    $cycle = ($this->collect)();
    expect($cycle->payment_status)->toBe('processing');
    $this->travelTo(now()->addDay());
    $this->s->calculatedTaxCents = 200;
    $again = ($this->collect)();
    expect($again->id)->toBe($cycle->id)->and($this->s->taxInputs)->toHaveCount(1)->and($this->s->bills)->toHaveCount(1);
    DB::transaction(function () use ($cycle) {
        expect(($this->collect)()->id)->toBe($cycle->id);
    });
    expect($this->s->taxInputs)->toHaveCount(1)->and($this->s->bills)->toHaveCount(1);
});

test('native amended collection refuses an enclosing transaction before any provider request', function () {
    $requests = count(Http::recorded());
    DB::transaction(function () {
        expect($this->collect)->toThrow(HttpException::class);
    });
    expect(count(Http::recorded()))->toBe($requests)->and($this->s->taxInputs)->toBe([])->and($this->s->bills)->toBe([]);
});

test('contract version or owner authority changing after tax preflight stops before payment dispatch', function (string $change) {
    $this->s->beforeBillingCapabilities = function () use ($change) {
        if ($change === 'version') {
            $this->s->contract['updatedAt'] = now()->addSecond()->toISOString();
        } else {
            DB::table('tenant_user')->where('tenant_id', $this->s->tenant->id)->where('user_id', $this->s->actor->id)->update(['membership_active' => false]);
        }
    };
    $cycle = ($this->collect)();
    expect($cycle->payment_status)->toBe('prepared')->and($cycle->payment_started_at)->toBeNull()
        ->and($this->s->taxInputs)->toHaveCount(1)->and($this->s->bills)->toBe([]);
})->with(['version', 'owner']);

test('tax-only calculation cannot be committed through the separate native amendment workflow', function () {
    $row = $this->s->agreement->fresh();
    $summary = app(SubscriptionShopifyTaxService::class)->review($row, $this->s->actor, '2027-01-31');
    $reads = count(Http::recorded());
    expect(fn () => app(SubscriptionShopifyUpdateService::class)->commit($row->tenant_id, $this->s->actor, $row->id,
        $summary['request_uuid'], $row->revision, $summary['proof_hash'],
        ['reference' => 'fictional-wrong-purpose', 'quoted_total_reviewed' => true, 'continuing_authorization_reviewed' => true]))->toThrow(HttpException::class);
    expect(count(Http::recorded()))->toBe($reads)->and($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
});

test('a prepared native cycle cannot acquire a tax proof for a later agreement revision', function () {
    $this->s->beforeBillingCapabilities = fn () => config()->set('subscriptions.replacement_collection_enabled', false);
    $cycle = ($this->collect)();
    expect($cycle->payment_status)->toBe('prepared')->and($cycle->payment_started_at)->toBeNull();
    $proof = $cycle->reconciliation['native_tax_preflight'];
    config()->set('subscriptions.replacement_collection_enabled', true);
    DB::table('subscription_billing_agreements')->where('id', $this->s->agreement->id)->increment('revision');
    expect($this->collect)->toThrow(HttpException::class);
    expect($cycle->fresh()->reconciliation['native_tax_preflight'])->toBe($proof)->and($this->s->taxInputs)->toHaveCount(1)->and($this->s->bills)->toBe([]);
});

test('minute tax-only work completes delayed calculations for the unchanged five-minute collection cadence', function () {
    $this->s->taxDelaySeconds = 30;
    $this->artisan('subscriptions:replacement-process', ['--collect' => true])->assertSuccessful();
    expect($this->s->taxInputs)->toHaveCount(1)->and($this->s->bills)->toBe([]);
    for ($minute = 1; $minute <= 4; $minute++) {
        $this->travelTo(CarbonImmutable::parse('2027-01-31T15:00:00Z')->addMinutes($minute));
        $this->artisan('subscriptions:replacement-process', ['--tax-preflight' => true])->assertSuccessful();
        expect($this->s->bills)->toBe([]);
    }
    $this->travelTo(CarbonImmutable::parse('2027-01-31T15:05:00Z'));
    $this->artisan('subscriptions:replacement-process', ['--collect' => true])->assertSuccessful();
    $cycle = SubscriptionBillingCycle::where('agreement_id', $this->s->agreement->id)->firstOrFail();
    expect($cycle->payment_status)->toBe('succeeded')->and($cycle->amount_cents)->toBe(2675)
        ->and($this->s->taxInputs)->toHaveCount(2)->and($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toHaveCount(1);
    $taxes = count($this->s->taxInputs);
    $this->artisan('subscriptions:replacement-process', ['--tax-preflight' => true])->assertSuccessful();
    expect($this->s->taxInputs)->toHaveCount($taxes)->and($this->s->bills)->toHaveCount(1);
});

test('tax-only worker never requests a provider calculation outside allowed active Everbranch collection', function (string $withdrawal) {
    if ($withdrawal === 'allowlist') {
        config()->set('subscriptions.replacement_member_ids', []);
    } elseif ($withdrawal === 'collection') {
        config()->set('subscriptions.replacement_collection_enabled', false);
    } else {
        DB::table('subscription_pilot_members')->where('id', $this->s->agreement->member_id)->update(['billing_owner' => 'recharge']);
    }
    $requests = count(Http::recorded());
    $this->artisan('subscriptions:replacement-process', ['--tax-preflight' => true])->assertSuccessful();
    expect($this->collect)->toThrow(HttpException::class);
    expect(count(Http::recorded()))->toBe($requests)->and($this->s->taxInputs)->toBe([])->and($this->s->bills)->toBe([]);
})->with(['allowlist', 'collection', 'recharge_owner']);

test('independent native collection workers calculate and bill once for the accepted amended cycle', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $marker = tempnam(sys_get_temp_dir(), 'native-tax-race-');
    $this->s->taxMarkerPath = $marker.'.tax';
    $this->s->billingMarkerPath = $marker.'.bill';
    file_put_contents($marker.'.tax', '');
    file_put_contents($marker.'.bill', '');
    $children = [];
    try {
        DB::disconnect('mysql');
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                pcntl_alarm(25);
                DB::purge('mysql');
                file_put_contents($marker.'.ready.'.$i, 'ready');
                $deadline = microtime(true) + 5;
                while (! is_file($marker.'.start') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                try {
                    ($this->collect)();
                    $result = 'processed';
                } catch (HttpException) {
                    $result = 'blocked';
                } catch (Throwable $error) {
                    $result = 'error:'.get_class($error);
                }
                file_put_contents($marker.'.result.'.$i, $result);
                exit(0);
            }
            if ($pid < 0) {
                throw new RuntimeException('Could not start disposable native tax worker.');
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
        expect(array_filter(explode("\n", file_get_contents($marker.'.tax'))))->toHaveCount(1)
            ->and(array_filter(explode("\n", file_get_contents($marker.'.bill'))))->toHaveCount(1)
            ->and(SubscriptionBillingCycle::where('agreement_id', $this->s->agreement->id)->count())->toBe(1);
        foreach ([0, 1] as $i) {
            expect(file_get_contents($marker.'.result.'.$i))->toBeIn(['processed', 'blocked']);
        }
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
    }
});
