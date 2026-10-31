<?php

use App\Models\SubscriptionBillingAction;
use App\Services\Subscriptions\SubscriptionBillingAgreementService;
use App\Services\Subscriptions\SubscriptionBillingAuthorization;
use App\Services\Subscriptions\SubscriptionShopifyBillingTransport;
use App\Services\Subscriptions\SubscriptionShopifyExecutionService;
use App\Services\Subscriptions\SubscriptionShopifyUpdateHistory;
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
        'subscription_installations', 'shopify_stores', 'marketing_profiles', 'tenant_module_entitlements'] as $table) {
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

test('a native original pending ID can resolve to a documented concrete success ID without rewriting either reference', function () {
    $this->s->concreteCalculationIds = true;
    $this->s->updatePending = true;
    expect(($this->calculate)()['status'])->toBe('calculation_pending');
    $initial = nativeJournal($this, 'received')->after_state['calculation']['id'];
    $this->s->updatePending = false;
    $quote = ($this->calculate)();
    expect($quote['status'])->toBe('quote_ready')->and($initial)->toBe('gid://shopify/SubscriptionContractCalculationPending/original_update')
        ->and($quote['quote']['calculation_id'])->toBe('gid://shopify/SubscriptionContractCalculationSuccess/original_update')
        ->and(nativeJournal($this, 'received')->after_state['calculation']['id'])->toBe($initial);
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    expect(nativeCommit($this, $quote)['status'])->toBe('applied')->and($this->s->updateInputs)->toHaveCount(1)->and($this->s->updateCommits)->toBe(1);
});

test('a changed terminal native calculation ID cannot authorize an unrelated amendment marker', function () {
    $this->s->concreteCalculationIds = true;
    $this->s->updatePending = true;
    ($this->calculate)();
    $this->s->updatePending = false;
    $this->s->wrongUpdateMarker = true;
    expect($this->calculate)->toThrow(HttpException::class);
    expect(nativeJournal($this, 'quoted'))->toBeNull()->and($this->s->updateInputs)->toHaveCount(1)->and($this->s->updateCommits)->toBe(0);
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

function nativeApproval(): array
{
    return ['reference' => 'reviewed-native-delivery:fictional', 'quoted_total_reviewed' => true, 'continuing_authorization_reviewed' => true];
}

function nativeCommit($test, array $quote): array
{
    return $test->updates->commit($test->s->tenant->id, $test->s->actor, $test->s->agreement->id, $test->uuid,
        $test->revision, $quote['quote']['review_hash'], nativeApproval());
}

test('native commit applies one signed exact-quote future amendment and retains the original mandate and schedule', function () {
    $before = $this->s->agreement->fresh();
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $result = nativeCommit($this, $quote);
    $current = $before->fresh();
    expect($result['status'])->toBe('applied')->and($current->revision)->toBe($this->revision + 1)
        ->and($current->terms)->toBe($quote['quote']['terms'])->and($current->terms)->not->toHaveKey('tax_lines')
        ->and($current->authorization_evidence['original_authorization'])->toBe($before->authorization_evidence['original_authorization'])
        ->and($current->next_billing_date)->toEqual($before->next_billing_date)->and($current->status)->toBe($before->status)
        ->and($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([])->and($this->s->commits)->toBe(1);
    SubscriptionBillingAuthorization::assertCurrent($current);
    expect(SubscriptionShopifyUpdateHistory::attributes($current))->toContain(['key' => 'everbranch_amendment', 'value' => $this->uuid]);
    $reads = count(Http::recorded());
    config()->set('subscriptions.replacement_amendment_apply_enabled', false);
    expect(nativeCommit($this, $quote))->toBe($result)->and(count(Http::recorded()))->toBe($reads);
});

test('lost native commit response recovers the original contract and preserves later signed stops', function (string $stop) {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->loseUpdateCommitResponse = true;
    expect(nativeCommit($this, $quote)['status'])->toBe('commit_unknown')->and($this->s->updateCommits)->toBe(1);
    expect(fn () => app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'skip', (string) Str::uuid(), $this->revision))->toThrow(HttpException::class);
    app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, $stop, (string) Str::uuid(), $this->revision);
    $stopped = $this->s->agreement->fresh();
    config()->set('subscriptions.replacement_amendment_apply_enabled', false);
    config()->set('subscriptions.replacement_shopify_amendments_enabled', false);
    $result = $this->updates->reconcile($this->s->tenant->id, $this->s->actor, $stopped->id, $this->uuid);
    $current = $stopped->fresh();
    expect($result['status'])->toBe('applied')->and($current->status)->toBe($stopped->status)
        ->and($current->next_billing_date)->toEqual($stopped->next_billing_date)->and($current->resume_on)->toEqual($stopped->resume_on)
        ->and($current->revision)->toBe($this->revision + 2)->and($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
    SubscriptionBillingAuthorization::assertCurrent($current);
    $this->updates->reconcile($this->s->tenant->id, $this->s->actor, $current->id, $this->uuid);
    expect($current->fresh()->revision)->toBe($current->revision)->and($this->s->updateCommits)->toBe(1);
})->with(['pause', 'cancel']);

test('a deferred native commit cannot be resent or closed and pause then cancellation wins over its eventual result', function () {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->updateCommitDeferred = true;
    expect(nativeCommit($this, $quote)['status'])->toBe('commit_unknown');
    expect(nativeCommit($this, $quote)['status'])->toBe('commit_unknown')->and($this->s->updateCommits)->toBe(1);
    expect(fn () => $this->updates->abort($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid))->toThrow(HttpException::class);
    app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'pause', (string) Str::uuid(), $this->revision);
    expect(fn () => app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'resume', (string) Str::uuid(), $this->revision + 1))->toThrow(HttpException::class);
    app(SubscriptionShopifyExecutionService::class)->syncSchedule($this->s->agreement->fresh(), force: true);
    expect($this->s->contract['status'])->toBe('PAUSED');
    app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'cancel', (string) Str::uuid(), $this->revision + 1);
    $this->s->contract = $this->s->deferredUpdateContract;
    $this->s->contract['status'] = 'CANCELLED';
    $this->s->contract['nextBillingDate'] = null;
    $result = $this->updates->reconcile($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid);
    expect($result['status'])->toBe('applied')->and($this->s->agreement->fresh()->status)->toBe('cancelled')
        ->and($this->s->agreement->fresh()->next_billing_date)->toBeNull()->and($this->s->agreement->fresh()->revision)->toBe($this->revision + 3)
        ->and($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
});

test('native commit refuses a changed provider version fresh quote hash permission or source before dispatch', function (string $defect) {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    if ($defect === 'version') {
        $this->s->contract['updatedAt'] = '2027-01-15T14:30:00Z';
    } elseif ($defect === 'hash') {
        $quote['quote']['review_hash'] = str_repeat('a', 64);
    } elseif ($defect === 'gate') {
        config()->set('subscriptions.replacement_amendment_apply_enabled', false);
    } elseif ($defect === 'owner') {
        $this->s->beforeUpdateCalculationRead = fn () => DB::table('tenant_user')->where('tenant_id', $this->s->tenant->id)
            ->where('user_id', $this->s->actor->id)->update(['membership_active' => false]);
    } else {
        $this->s->sourceCollects = true;
    }
    expect(fn () => nativeCommit($this, $quote))->toThrow(HttpException::class);
    expect(nativeJournal($this, 'commit_sent'))->toBeNull()->and($this->s->updateCommits)->toBe(0)->and($this->s->bills)->toBe([]);
})->with(['version', 'hash', 'gate', 'owner', 'source']);

test('native provider commit rejection is terminal and can close without applying local terms', function () {
    $quote = ($this->calculate)();
    $before = $this->s->agreement->fresh()->getAttributes();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->updateCommitError = 'STALE_CONTRACT';
    nativeCommit($this, $quote);
    expect(nativeJournal($this, 'commit_rejected'))->not->toBeNull()->and(nativeCommit($this, $quote)['status'])->toBe('commit_closed')
        ->and($this->s->agreement->fresh()->getAttributes())->toBe($before)->and($this->s->updateCommits)->toBe(1);
    expect($this->updates->abort($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid)['status'])->toBe('aborted');
});

test('accepted native history verifies offline after credential withdrawal and detects changed evidence', function () {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    expect(nativeCommit($this, $quote)['status'])->toBe('applied');
    $this->s->revision->update(['revoked_at' => now()]);
    config()->set('subscriptions.provider_reads_enabled', false);
    $reads = count(Http::recorded());
    SubscriptionBillingAuthorization::assertCurrent($this->s->agreement->fresh());
    expect(count(Http::recorded()))->toBe($reads);
    $action = SubscriptionBillingAction::query()->where('agreement_id', $this->s->agreement->id)->where('kind', 'terms_amended')->firstOrFail();
    $evidence = $action->request;
    $evidence['native_update']['commit_result']['after_state']['contract']['lines']['nodes'][0]['currentPrice']['amount'] = '1.00';
    DB::table('subscription_billing_actions')->where('id', $action->id)->update(['request' => encrypt(json_encode($evidence))]);
    expect(fn () => SubscriptionBillingAuthorization::assertCurrent($this->s->agreement->fresh()))->toThrow(HttpException::class);
    app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'cancel', (string) Str::uuid(), $this->revision + 1);
    expect($this->s->agreement->fresh()->status)->toBe('cancelled')->and($this->s->bills)->toBe([]);
});

test('a freeze after the native commit claim records a proven unsent result and never invokes HTTP', function () {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    DB::listen(function ($query) {
        if (str_starts_with($query->sql, 'insert into `subscription_billing_actions`') && in_array('shopify_update_commit_sent', $query->bindings, true)) {
            config()->set('subscriptions.replacement_amendment_apply_enabled', false);
        }
    });
    expect(nativeCommit($this, $quote)['status'])->toBe('commit_closed')->and(nativeJournal($this, 'commit_sent'))->not->toBeNull()
        ->and(nativeJournal($this, 'commit_not_sent'))->not->toBeNull()->and($this->s->updateCommits)->toBe(0);
    expect($this->updates->abort($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid)['status'])->toBe('aborted');
});

test('successive native amendments retain the complete signed chain and an older replay cannot restore prior terms', function () {
    $first = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    expect(nativeCommit($this, $first)['status'])->toBe('applied');
    $firstUuid = $this->uuid;
    $firstRevision = $this->revision;
    $this->s->proposedChange = $this->s->change();
    $this->s->proposedChange['shipping_address']['address1'] = '789 Next Example Street';
    $this->s->proposedChange['swaps'][0]['variant_id'] = '999';
    $this->uuid = (string) Str::uuid();
    $this->revision = $this->s->agreement->fresh()->revision;
    $second = ($this->calculate)();
    expect(nativeCommit($this, $second)['status'])->toBe('applied');
    $current = $this->s->agreement->fresh();
    expect($current->terms['shipping_address']['address1'])->toBe('789 Next Example Street')
        ->and($current->terms['lines'][0]['variant_id'])->toBe('999')->and($this->s->updateCommits)->toBe(2);
    SubscriptionBillingAuthorization::assertCurrent($current);
    $this->updates->commit($this->s->tenant->id, $this->s->actor, $current->id, $firstUuid, $firstRevision, $first['quote']['review_hash'], nativeApproval());
    expect($current->fresh()->terms_hash)->toBe($current->terms_hash)->and($current->fresh()->revision)->toBe($current->revision)
        ->and($this->s->updateCommits)->toBe(2);
});

test('a native review reserves its original UUID without preventing a fresh safe-stop request', function () {
    ($this->calculate)();
    expect(fn () => app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'pause', $this->uuid, $this->revision))->toThrow(HttpException::class);
    app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'pause', (string) Str::uuid(), $this->revision);
    expect($this->s->agreement->fresh()->status)->toBe('paused');
});

test('native accepted terms cannot collect before fresh amended-tax preflight is connected', function () {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    expect(nativeCommit($this, $quote)['status'])->toBe('applied');
    expect(fn () => app(SubscriptionShopifyExecutionService::class)->ready($this->s->agreement->fresh(), $this->s->actor))->toThrow(HttpException::class);
    expect($this->s->bills)->toBe([])->and(DB::table('subscription_billing_cycles')->where('agreement_id', $this->s->agreement->id)->count())->toBe(0);
});

test('independent native commit workers send only the one reviewed calculation', function () {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $real = app(SubscriptionShopifyBillingTransport::class);
    $transport = Mockery::mock(SubscriptionShopifyBillingTransport::class)->makePartial();
    $transport->shouldReceive('updateCommit')->once()->andThrow(new Illuminate\Http\Client\ConnectionException('Fictional stop after durable approval, before dispatch.'));
    app()->instance(SubscriptionShopifyBillingTransport::class, $transport);
    expect(fn () => nativeCommit($this, $quote))->toThrow(Illuminate\Http\Client\ConnectionException::class);
    app()->instance(SubscriptionShopifyBillingTransport::class, $real);
    expect(nativeJournal($this, 'commit_requested'))->not->toBeNull()->and(nativeJournal($this, 'commit_sent'))->toBeNull();
    $authority = app(SubscriptionShopifyExecutionService::class)->amendmentAuthority($this->s->agreement->fresh(), $this->s->actor);
    expect(function_exists('pcntl_fork'))->toBeTrue();
    $marker = tempnam(sys_get_temp_dir(), 'native-commit-');
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
                    $real->updateCommit($this->s->agreement->fresh(), $this->uuid, $authority);
                    $result = 'sent';
                } catch (HttpException $error) {
                    $result = $error->getStatusCode() === 409 ? 'blocked' : 'unexpected_http';
                } catch (Throwable) {
                    $result = 'unexpected_error';
                }
                file_put_contents($marker.'.result.'.$i, $result);
                exit(0);
            }
            if ($pid < 0) {
                throw new RuntimeException('Could not start disposable native commit worker.');
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
        sort($results);
        expect($results)->toBe(['blocked', 'sent'])->and(array_filter(explode("\n", file_get_contents($marker))))->toHaveCount(1)
            ->and(SubscriptionBillingAction::query()->where('agreement_id', $this->s->agreement->id)->where('kind', 'shopify_update_commit_sent')->count())->toBe(1);
    } finally {
        DB::purge('mysql');
        foreach (glob($marker.'*') ?: [] as $file) {
            unlink($file);
        }
    }
});

test('native amendment CLI stays offline without explicit apply even when commit is requested', function () {
    $reads = count(Http::recorded());
    $before = $this->s->agreement->fresh()->getAttributes();
    $this->artisan('subscriptions:shopify-amend', ['--tenant' => $this->s->tenant->slug, '--actor' => $this->s->actor->id,
        '--agreement' => $this->s->agreement->id, '--action' => 'commit'])->expectsOutputToContain('"provider_requests":false')->assertSuccessful();
    expect(count(Http::recorded()))->toBe($reads)->and($this->s->agreement->fresh()->getAttributes())->toBe($before)
        ->and(nativeJournal($this, 'requested'))->toBeNull()->and($this->s->updateCommits)->toBe(0);
});

test('native amendment CLI requires a private change file and exact explicit approval before one commit', function () {
    $path = tempnam(sys_get_temp_dir(), 'native-review-');
    $options = ['--tenant' => $this->s->tenant->slug, '--actor' => $this->s->actor->id, '--agreement' => $this->s->agreement->id,
        '--apply' => true, '--file' => $path, '--request-id' => $this->uuid, '--revision' => $this->revision];
    try {
        file_put_contents($path, json_encode(['change' => $this->s->change()]));
        chmod($path, 0644);
        $this->artisan('subscriptions:shopify-amend', $options + ['--action' => 'calculate'])->expectsOutputToContain('private regular 0600')->assertFailed();
        expect($this->s->updateInputs)->toBe([]);
        chmod($path, 0600);
        clearstatcache(true, $path);
        $this->artisan('subscriptions:shopify-amend', $options + ['--action' => 'calculate'])->expectsOutputToContain('quote_ready')->assertSuccessful();
        $hash = nativeJournal($this, 'quoted')->after_state['quote']['review_hash'];
        config()->set('subscriptions.replacement_amendment_apply_enabled', true);
        file_put_contents($path, json_encode(['approval' => array_replace(nativeApproval(), ['continuing_authorization_reviewed' => false])]));
        $this->artisan('subscriptions:shopify-amend', $options + ['--action' => 'commit', '--reviewed-hash' => $hash])->assertFailed();
        expect($this->s->updateCommits)->toBe(0);
        file_put_contents($path, json_encode(['approval' => nativeApproval()]));
        $this->artisan('subscriptions:shopify-amend', $options + ['--action' => 'commit', '--reviewed-hash' => $hash])->expectsOutputToContain('applied')->assertSuccessful();
        expect($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
    } finally {
        unlink($path);
    }
});

test('replacement reconciliation worker recovers a previously approved native commit without sending another', function () {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->loseUpdateCommitResponse = true;
    expect(nativeCommit($this, $quote)['status'])->toBe('commit_unknown');
    config()->set('subscriptions.replacement_amendment_apply_enabled', false);
    config()->set('subscriptions.replacement_shopify_amendments_enabled', false);
    $this->artisan('subscriptions:replacement-process', ['--reconcile' => true])->assertSuccessful();
    expect($this->s->agreement->fresh()->terms)->toBe($quote['quote']['terms'])->and($this->s->agreement->fresh()->revision)->toBe($this->revision + 1)
        ->and($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([])
        ->and(DB::table('subscription_billing_cycles')->where('agreement_id', $this->s->agreement->id)->count())->toBe(0);
});

test('native CLI reports an uncertain commit as failure and its retry only recovers the original result', function () {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->loseUpdateCommitResponse = true;
    $path = tempnam(sys_get_temp_dir(), 'native-unknown-');
    try {
        chmod($path, 0600);
        file_put_contents($path, json_encode(['approval' => nativeApproval()]));
        $options = ['--tenant' => $this->s->tenant->slug, '--actor' => $this->s->actor->id, '--agreement' => $this->s->agreement->id,
            '--apply' => true, '--action' => 'commit', '--file' => $path, '--request-id' => $this->uuid, '--revision' => $this->revision,
            '--reviewed-hash' => $quote['quote']['review_hash']];
        $this->artisan('subscriptions:shopify-amend', $options)->expectsOutputToContain('commit_unknown')->assertFailed();
        $this->artisan('subscriptions:shopify-amend', $options)->expectsOutputToContain('applied')->assertSuccessful();
        expect($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
    } finally {
        unlink($path);
    }
});

test('a missing native approval cannot erase a retained commit claim or permit a new positive billing action', function () {
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->loseUpdateCommitResponse = true;
    expect(nativeCommit($this, $quote)['status'])->toBe('commit_unknown');
    DB::table('subscription_billing_actions')->where('id', nativeJournal($this, 'commit_requested')->id)->delete();
    expect(fn () => app(SubscriptionBillingAgreementService::class)->change($this->s->tenant->id, $this->s->actor,
        $this->s->agreement->id, 'skip', (string) Str::uuid(), $this->revision))->toThrow(HttpException::class);
    expect(fn () => app(SubscriptionShopifyExecutionService::class)->ready($this->s->agreement->fresh(), $this->s->actor))->toThrow(HttpException::class);
    expect($this->s->updateCommits)->toBe(1)->and($this->s->bills)->toBe([]);
});

test('an accepted native delivery amendment preserves the exact finite customer pause authorization', function (string $timing, bool $unexplainedRevision) {
    Illuminate\Support\Facades\Mail::fake();
    Illuminate\Support\Facades\Queue::fake();
    $profile = App\Models\MarketingProfile::factory()->create(['tenant_id' => $this->s->tenant->id]);
    $customer = new App\Services\Mobile\ModernForestryMobileCustomerSession($profile, 'fictional-original-customer',
        ['source_type' => 'shopify_customer', 'shopify_customer_id' => 'gid://shopify/Customer/456']);
    $pause = fn () => app(SubscriptionBillingAgreementService::class)->customerChange($customer, 'pause', (string) Str::uuid(),
        $this->s->agreement->fresh()->revision, ['duration_months' => 1]);
    if ($timing === 'before_review') {
        $pause();
        app(SubscriptionShopifyExecutionService::class)->syncSchedule($this->s->agreement->fresh(), force: true);
        $this->revision = $this->s->agreement->fresh()->revision;
    }
    $quote = ($this->calculate)();
    config()->set('subscriptions.replacement_amendment_apply_enabled', true);
    $this->s->loseUpdateCommitResponse = $timing === 'after_dispatch';
    $result = nativeCommit($this, $quote);
    if ($timing === 'after_dispatch') {
        expect($result['status'])->toBe('commit_unknown');
        $pause();
        $result = $this->updates->reconcile($this->s->tenant->id, $this->s->actor, $this->s->agreement->id, $this->uuid);
    }
    expect($result['status'])->toBe('applied')->and($this->s->agreement->fresh()->resume_on->toDateString())->toBe('2027-02-28');
    if ($unexplainedRevision) {
        DB::table('subscription_billing_agreements')->where('id', $this->s->agreement->id)->increment('revision');
    }
    $this->travelTo(CarbonImmutable::parse('2027-02-28T15:00:00Z'));
    if ($unexplainedRevision) {
        expect(fn () => app(SubscriptionBillingAgreementService::class)->resumeScheduled($this->s->agreement->fresh()))->toThrow(HttpException::class);
        expect($this->s->agreement->fresh()->status)->toBe('paused');
    } else {
        $resumed = app(SubscriptionBillingAgreementService::class)->resumeScheduled($this->s->agreement->fresh());
        expect($resumed->status)->toBe('active')->and($resumed->resume_on)->toBeNull()->and($resumed->next_billing_date->toDateString())->toBe('2027-02-28');
    }
    expect($this->s->bills)->toBe([]);
    Illuminate\Support\Facades\Mail::assertNothingSent();
    Illuminate\Support\Facades\Queue::assertNothingPushed();
})->with(['before_review', 'after_dispatch'])->with([false, true]);
