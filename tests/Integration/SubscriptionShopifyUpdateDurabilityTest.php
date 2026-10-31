<?php

use App\Models\SubscriptionBillingAction;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionShopifyBillingTransport;
use App\Services\Subscriptions\SubscriptionShopifyUpdateService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\Subscriptions\ShopifyUpdateScenario;

uses(Tests\TestCase::class);

beforeEach(function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Durable native update claims require disposable MySQL without outer test transactions.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Refusing native update tests outside disposable local MySQL.');
    }
    expect(DB::transactionLevel())->toBe(0);
    $this->travelTo(CarbonImmutable::parse('2027-01-15T15:00:00Z'));
    $this->s = new ShopifyUpdateScenario;
    $this->updates = app(SubscriptionShopifyUpdateService::class);
    $this->uuid = (string) Str::uuid();
    $this->revision = $this->s->agreement->fresh()->revision;
    $this->calculate = fn () => $this->updates->calculate($this->s->tenant->id, $this->s->actor, $this->s->agreement->id,
        $this->s->change(), $this->uuid, $this->revision);
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
});

function nativeJournal($test, string $phase): ?SubscriptionBillingAction
{
    return SubscriptionBillingAction::query()->where('agreement_id', $test->s->agreement->id)->where('kind', 'shopify_update_'.$phase)->first();
}

test('native calculation has a durably visible signed send record before HTTP and leaves billing unchanged', function () {
    $original = $this->s->agreement->fresh()->getAttributes();
    $result = ($this->calculate)();
    expect($result['status'])->toBe('quote_ready')->and($result['quote']['terms']['amount_cents'])->toBe(2675)
        ->and($result['commit_available'])->toBeFalse()->and($result['charges_customer'])->toBeFalse()->and($result['changes_contract'])->toBeFalse()
        ->and($this->s->updateInputs)->toHaveCount(1)->and($this->s->updateInputs[0]['actor'])->toBe('MERCHANT')
        ->and($this->s->updateInputs[0]['id'])->toBe($this->s->contract['id'])
        ->and($this->s->agreement->fresh()->getAttributes())->toBe($original)->and($this->s->bills)->toBe([])->and($this->s->commits)->toBe(1)
        ->and(nativeJournal($this, 'requested')->request['intent']['request_uuid'])->toBe($this->uuid)
        ->and(nativeJournal($this, 'calculate_sent')->after_state)->toHaveKey('authorization_hash')
        ->and(nativeJournal($this, 'received')->after_state['calculation']['id'])->toBe($result['quote']['calculation_id']);
    $reads = count(Http::recorded());
    expect(($this->calculate)())->toBe($result)->and(count(Http::recorded()))->toBe($reads);
});

test('pending native calculation polls only its retained ID and never submits another update', function () {
    $this->s->updatePending = true;
    expect(($this->calculate)()['status'])->toBe('calculation_pending');
    $this->s->updatePending = false;
    expect(($this->calculate)()['status'])->toBe('quote_ready')->and($this->s->updateInputs)->toHaveCount(1)->and($this->s->updatePolls)->toBe(1)
        ->and(nativeJournal($this, 'quoted')->after_state['calculation']['id'])->toBe(nativeJournal($this, 'received')->after_state['calculation']['id']);
});

test('lost initial native response stays unknown across retries without a replacement calculation', function () {
    $this->s->loseUpdateResponse = true;
    expect(($this->calculate)()['status'])->toBe('calculation_unknown');
    $this->s->loseUpdateResponse = false;
    config()->set('subscriptions.replacement_shopify_amendments_enabled', false);
    $reads = count(Http::recorded());
    $result = ($this->calculate)();
    expect($result['status'])->toBe('calculation_unknown')->and($result['resend_allowed'])->toBeFalse()
        ->and($this->s->updateInputs)->toHaveCount(1)->and($this->s->updatePolls)->toBe(0)->and(count(Http::recorded()))->toBe($reads)
        ->and(nativeJournal($this, 'calculate_sent'))->not->toBeNull()->and(nativeJournal($this, 'received'))->toBeNull();
});

test('a warning or user error cannot roll back the known native calculation ID or authorize a quote', function (string $defect) {
    $this->s->{$defect} = true;
    expect($this->calculate)->toThrow(HttpException::class);
    expect(nativeJournal($this, 'received')->after_state['calculation']['id'])->toBe('gid://shopify/SubscriptionContractCalculation/original_update')
        ->and(nativeJournal($this, 'quoted'))->toBeNull()->and($this->s->updateInputs)->toHaveCount(1);
    if ($defect === 'updateUserError') {
        $this->s->updateUserError = false;
        expect($this->calculate)->toThrow(HttpException::class);
    } else {
        $this->s->updateWarning = false;
        expect(($this->calculate)()['status'])->toBe('quote_ready');
    }
    expect($this->s->updateInputs)->toHaveCount(1);
})->with(['updateWarning', 'updateUserError']);

test('native terminal calculation failure is retained without another provider submission', function () {
    $this->s->updateFailure = true;
    expect(($this->calculate)()['status'])->toBe('calculation_failed');
    $this->s->updateFailure = false;
    expect(($this->calculate)()['status'])->toBe('calculation_failed')->and($this->s->updateInputs)->toHaveCount(1)->and($this->s->updatePolls)->toBe(0);
});

test('a native pending calculation can fail without later reviving its rejected result', function () {
    $this->s->updatePending = true;
    ($this->calculate)();
    $this->s->updatePending = false;
    $this->s->updateFailure = true;
    expect(($this->calculate)()['status'])->toBe('calculation_failed')->and(nativeJournal($this, 'failed'))->not->toBeNull();
    $this->s->updateFailure = false;
    expect(($this->calculate)()['status'])->toBe('calculation_failed')->and($this->s->updatePolls)->toBe(1)->and($this->s->updateInputs)->toHaveCount(1);
});

test('native dispatch authority reads current credential revocation beyond an older MySQL snapshot', function () {
    $name = 'native_update_revoker';
    config()->set('database.connections.'.$name, config('database.connections.mysql'));
    try {
        DB::transaction(function () use ($name) {
            $row = $this->s->agreement->fresh();
            app(SubscriptionShopifyBillingTransport::class)->context($row);
            DB::connection($name)->table('subscription_credential_revisions')->where('id', $this->s->revision->id)->update(['revoked_at' => now()]);
            expect(fn () => app(SubscriptionShopifyBillingTransport::class)->context($row, true))->toThrow(HttpException::class);
        });
    } finally {
        DB::purge($name);
    }
    expect($this->s->updateInputs)->toBe([])->and(nativeJournal($this, 'calculate_sent'))->toBeNull();
});

test('a native review must be closed before a different calculation request can be prepared', function () {
    ($this->calculate)();
    $next = (string) Str::uuid();
    expect(fn () => $this->updates->calculate($this->s->tenant->id, $this->s->actor, $this->s->agreement->id,
        $this->s->change(), $next, $this->revision))->toThrow(HttpException::class);
    expect($this->s->updateInputs)->toHaveCount(1);
    $this->updates->abort($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid);
    expect($this->updates->calculate($this->s->tenant->id, $this->s->actor, $this->s->agreement->id,
        $this->s->change(), $next, $this->revision)['status'])->toBe('quote_ready')->and($this->s->updateInputs)->toHaveCount(2);
});

test('native update refuses an enclosing transaction before any request or journal write', function () {
    $reads = count(Http::recorded());
    DB::transaction(function () {
        expect($this->calculate)->toThrow(HttpException::class);
    });
    expect(count(Http::recorded()))->toBe($reads)->and(nativeJournal($this, 'requested'))->toBeNull()->and($this->s->updateInputs)->toBe([]);
});

test('current rollout and owner revocation stop native update preparation before dispatch', function (string $defect) {
    if ($defect === 'gate') {
        config()->set('subscriptions.replacement_shopify_amendments_enabled', false);
    } else {
        $this->s->beforeUpdateCatalog = fn () => DB::table('tenant_user')->where('tenant_id', $this->s->tenant->id)
            ->where('user_id', $this->s->actor->id)->update(['membership_active' => false]);
    }
    expect($this->calculate)->toThrow(HttpException::class);
    expect(nativeJournal($this, 'requested'))->toBeNull()->and($this->s->updateInputs)->toBe([]);
})->with(['gate', 'owner']);

test('a pause committed during native preparation wins over the older review', function () {
    $this->s->beforeUpdateCatalog = fn () => app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'pause', (string) Str::uuid(), $this->revision);
    expect($this->calculate)->toThrow(HttpException::class);
    expect($this->s->agreement->fresh()->status)->toBe('paused')->and($this->s->agreement->fresh()->revision)->toBe($this->revision + 1)
        ->and(nativeJournal($this, 'requested'))->toBeNull()->and($this->s->updateInputs)->toBe([]);
});

test('later stops and an editing freeze survive native quote replay and pending-result reconciliation', function (bool $pending) {
    $this->s->updatePending = $pending;
    $before = ($this->calculate)();
    app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'cancel', (string) Str::uuid(), $this->revision);
    $this->s->updatePending = false;
    config()->set('subscriptions.replacement_shopify_amendments_enabled', false);
    config()->set('subscriptions.replacement_servicing_enabled', false);
    $result = ($this->calculate)();
    expect($result['status'])->toBe($pending ? 'review_stale' : 'quote_ready')->and($this->s->agreement->fresh()->status)->toBe('cancelled')
        ->and($this->s->agreement->fresh()->revision)->toBe($this->revision + 1)->and($this->s->agreement->fresh()->next_billing_date)->toBeNull()
        ->and($this->s->updateInputs)->toHaveCount(1)->and($this->s->bills)->toBe([])->and($this->s->commits)->toBe(1);
    if (! $pending) {
        expect($result)->toBe($before);
    }
})->with([false, true]);

test('native pre-commit review can be closed after a freeze without changing billing or permitting a replay', function () {
    $this->s->loseUpdateResponse = true;
    ($this->calculate)();
    config()->set('subscriptions.replacement_shopify_amendments_enabled', false);
    $before = $this->s->agreement->fresh()->getAttributes();
    $closed = $this->updates->abort($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid);
    expect($closed['status'])->toBe('aborted')->and(($this->calculate)()['status'])->toBe('aborted')
        ->and($this->updates->abort($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid))->toBe($closed)
        ->and($this->s->agreement->fresh()->getAttributes())->toBe($before)->and($this->s->updateInputs)->toHaveCount(1);
});

test('changed or corrupted native request evidence cannot be replayed', function (string $defect) {
    ($this->calculate)();
    if ($defect === 'input') {
        $input = $this->s->change();
        $input['policy_reference'] = 'different-policy';
        expect(fn () => $this->updates->calculate($this->s->tenant->id, $this->s->actor, $this->s->agreement->id,
            $input, $this->uuid, $this->revision))->toThrow(HttpException::class);
    } else {
        $action = nativeJournal($this, 'requested');
        $request = $action->request;
        $request['intent']['variables']['actor'] = 'CUSTOMER';
        DB::table('subscription_billing_actions')->where('id', $action->id)->update(['request' => encrypt(json_encode($request))]);
        expect($this->calculate)->toThrow(HttpException::class);
    }
    expect($this->s->updateInputs)->toHaveCount(1)->and($this->s->bills)->toBe([]);
})->with(['input', 'evidence']);

test('independent native update workers dispatch one original calculation', function () {
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $marker = tempnam(sys_get_temp_dir(), 'native-update-');
    $this->s->updateMarkerPath = $marker;
    $children = [];
    try {
        DB::disconnect('mysql');
        for ($i = 0; $i < 2; $i++) {
            $pid = pcntl_fork();
            if ($pid === 0) {
                DB::purge('mysql');
                file_put_contents($marker.'.ready.'.$i, 'ready');
                $deadline = microtime(true) + 5;
                while (! is_file($marker.'.start') && microtime(true) < $deadline) {
                    usleep(1000);
                }
                try {
                    $result = ($this->calculate)()['status'];
                } catch (Throwable $error) {
                    $result = 'error:'.get_class($error);
                }
                file_put_contents($marker.'.result.'.$i, $result);
                exit(0);
            }
            if ($pid < 0) {
                throw new RuntimeException('Could not start disposable native update worker.');
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
        expect(array_filter(explode("\n", file_get_contents($marker))))->toHaveCount(1)
            ->and(SubscriptionBillingAction::query()->where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_update_requested')->count())->toBe(1)
            ->and(SubscriptionBillingAction::query()->where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_update_calculate_sent')->count())->toBe(1);
        foreach ([0, 1] as $i) {
            expect(file_get_contents($marker.'.result.'.$i))->toBeIn(['quote_ready', 'calculation_unknown']);
        }
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
    }
});
