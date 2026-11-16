<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\TenantAccessProfile;
use App\Models\TenantMarketingSetting;
use App\Models\TenantModuleState;
use App\Models\User;
use App\Services\Marketing\CustomerProfileAccess;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareCustomerIdentityService;
use App\Services\Marketing\SquareSourceRefresh;
use App\Services\Pos\PosStore;
use App\Services\Team\Studio\EventCustomerDependencies;
use App\Services\Team\Studio\EventCustomerReadView;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/EventSquareCoverageFixtures.php';

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_events_test'], true)
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing read-only witness fixtures outside isolated disposable MySQL.');
    }
    legacyEventCoverageFixture($this);
    $this->configuration = TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'square_config',
        'value' => ['connection_id' => 'independent-fixture-config', 'generation' => 1, 'status' => 'active']]);
    $this->module = TenantModuleState::updateOrCreate(['tenant_id' => $this->tenant->id, 'module_key' => 'customers'], ['enabled_override' => true]);
    $this->accessProfile = TenantAccessProfile::updateOrCreate(['tenant_id' => $this->tenant->id], [
        'plan_key' => config('module_catalog.defaults.plan_key', 'starter'), 'operating_mode' => 'direct', 'source' => 'manual', 'metadata' => [],
    ]);
    $this->view = app(EventCustomerReadView::class);
    config(['database.connections.read_only_witness_writer' => $db->getConfig()]);
    $this->writer = DB::connection('read_only_witness_writer');
    $this->writer->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(function () {
    if (! isset($this->tenant)) {
        return;
    }
    foreach ([DB::connection(), $this->writer] as $connection) {
        while ($connection->transactionLevel() > 0) {
            $connection->rollBack();
        }
        if ($connection->getPdo()->inTransaction()) {
            $connection->getPdo()->rollBack();
        }
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::purge('read_only_witness_writer');
    foreach (['marketing_profile_links', 'square_payments', 'square_orders', 'marketing_profiles', 'team_operation_records', 'team_areas',
        'marketing_import_runs', 'tenant_marketing_settings', 'tenant_module_entitlements', 'tenant_module_states', 'tenant_access_addons',
        'tenant_access_profiles', 'tenant_user', 'pos_live_registers', 'pos_live_connections'] as $table) {
        DB::table($table)->where('tenant_id', $this->tenant->id)->delete();
    }
    $this->tenant->delete();
    $this->owner->delete();
    if (isset($this->profileMember)) {
        $this->profileMember->delete();
    }
});

function readOnlyWitnessAdmit($test): void
{
    app(SquareConnectionContext::class)->assertCurrent($test->tenant->id, $test->binding);
    $config = app(SquareSourceRefresh::class)->configForPolicy($test->tenant->id, $test->policy['version'], $test->binding);
    expect($config['location_ids'])->toBe($test->binding['location_ids']);
}

test('the owned read-only witness frame does not block a configuration or authority writer', function () {
    Cache::spy();
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        if ($query->connection === DB::connection() && $this->view->owns($this->tenant->id)) {
            $queries[] = strtolower($query->sql);
        }
    });
    $locked = false;
    $result = $this->view->run($this->tenant->id, function () use (&$locked) {
        expect($this->view->owns($this->tenant->id))->toBeTrue()
            ->and($this->view->owns($this->tenant->id + 1000000))->toBeFalse()
            ->and($this->view->owns($this->tenant->id, $this->writer))->toBeFalse();
        readOnlyWitnessAdmit($this);
        $this->writer->transaction(function () use (&$locked) {
            $this->writer->table('tenant_marketing_settings')->where('tenant_id', $this->tenant->id)->lockForUpdate()->get();
            $this->writer->table('pos_live_connections')->where('tenant_id', $this->tenant->id)->lockForUpdate()->get();
            $this->writer->table('tenants')->where('id', $this->tenant->id)->lockForUpdate()->first();
            $this->writer->table('tenant_access_profiles')->where('tenant_id', $this->tenant->id)->lockForUpdate()->get();
            $this->writer->table('tenant_module_states')->where('tenant_id', $this->tenant->id)->lockForUpdate()->get();
            $this->writer->table('users')->where('id', $this->owner->id)->lockForUpdate()->first();
            $this->writer->table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->owner->id)->lockForUpdate()->first();
            $locked = true;
        });

        return 'original admitted witness';
    });
    expect($result)->toBe('original admitted witness')->and($locked)->toBeTrue()->and($queries)->not->toBeEmpty()
        ->and(collect($queries)->contains(fn ($sql) => str_contains($sql, 'for update') || str_contains($sql, 'lock in share mode')))->toBeFalse()
        ->and(collect($queries)->contains(fn ($sql) => preg_match('/^\s*(insert|update|delete|replace)\b/', $sql)))->toBeFalse()
        ->and($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0);
    foreach (['put', 'add', 'forever', 'remember', 'rememberForever', 'lock'] as $method) {
        Cache::shouldNotHaveReceived($method);
    }
    Http::assertNothingSent();
});

test('each Square authority change committed during a witness view is rejected after that view', function (string $change) {
    $changed = false;
    try {
        $this->view->run($this->tenant->id, function () use ($change, &$changed) {
            readOnlyWitnessAdmit($this);
            if ($change === 'connection_revoked' || $change === 'setting_generation') {
                $value = $this->configuration->value;
                $value[$change === 'connection_revoked' ? 'status' : 'generation'] = $change === 'connection_revoked' ? 'disconnected' : 2;
                $this->writer->table('tenant_marketing_settings')->where('id', $this->configuration->id)->update(['value' => json_encode($value, JSON_THROW_ON_ERROR)]);
            } elseif ($change === 'original_generation') {
                $this->writer->table('pos_live_connections')->where('tenant_id', $this->tenant->id)->update([
                    'credentials' => app(PosStore::class)->encrypt(['access_token' => 'changed-original-reader', 'expires_at' => now()->addDay()->toIso8601String()]),
                ]);
            } elseif ($change === 'policy_disabled' || $change === 'policy_changed') {
                $policy = $this->policy;
                $policy[$change === 'policy_disabled' ? 'enabled' : 'lookback_days'] = $change === 'policy_disabled' ? false : 15;
                $this->writer->table('tenant_marketing_settings')->where('tenant_id', $this->tenant->id)->where('key', SquareSourceRefresh::KEY)
                    ->update(['value' => json_encode($policy, JSON_THROW_ON_ERROR)]);
            } elseif ($change === 'actor_inactive' || $change === 'actor_unverified') {
                $this->writer->table('users')->where('id', $this->owner->id)->update([$change === 'actor_inactive' ? 'is_active' : 'email_verified_at' => $change === 'actor_inactive' ? false : null]);
            } elseif ($change === 'membership_removed' || $change === 'membership_role') {
                $this->writer->table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->owner->id)
                    ->update([$change === 'membership_removed' ? 'membership_active' : 'role' => $change === 'membership_removed' ? false : 'member']);
            } elseif ($change === 'module_disabled') {
                $this->writer->table('tenant_module_states')->where('id', $this->module->id)->update(['enabled_override' => false]);
            } else {
                $this->writer->table('tenant_access_profiles')->where('id', $this->accessProfile->id)->update(['metadata' => json_encode(['workspace_focus' => 'soccer'], JSON_THROW_ON_ERROR)]);
            }
            $changed = true;

            // The open RR view intentionally retains the admitted original. Only
            // the required fresh post-commit authority fence can reject its result.
            readOnlyWitnessAdmit($this);

            return 'must not escape';
        });
        $this->fail('A changed Square context must discard the earlier witness.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBeIn([403, 409])->and($changed)->toBeTrue();
    }
    expect($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0);
    Http::assertNothingSent();
})->with(['connection_revoked', 'setting_generation', 'original_generation', 'policy_disabled', 'policy_changed', 'actor_inactive',
    'actor_unverified', 'membership_removed', 'membership_role', 'module_disabled', 'workspace_focus']);

test('the database rejects accidental DML and read-only ownership is cleaned up before a normal writable transaction', function () {
    $original = $this->tenant->name;
    try {
        $this->view->run($this->tenant->id, function () {
            readOnlyWitnessAdmit($this);
            DB::table('tenants')->where('id', $this->tenant->id)->update(['name' => 'Forbidden write']);
        });
        $this->fail('A reporting read view must be database-enforced READ ONLY.');
    } catch (QueryException $error) {
        expect((int) ($error->errorInfo[1] ?? 0))->toBe(1792);
    }
    expect($this->tenant->fresh()->name)->toBe($original)->and($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0);
    DB::transaction(function () {
        DB::table('tenants')->where('id', $this->tenant->id)->update(['name' => 'Normal writer remains writable']);
    });
    expect($this->tenant->fresh()->name)->toBe('Normal writer remains writable');
    Http::assertNothingSent();
});

test('ordinary Square mutation authority keeps its current locking reads', function () {
    DB::beginTransaction();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        app(SquareConnectionContext::class)->assertCurrent($this->tenant->id, $this->binding);
        app(SquareCustomerIdentityService::class)->authorize($this->tenant->id, $this->owner);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => strtolower($sql));
    } finally {
        DB::disableQueryLog();
        DB::rollBack();
    }
    foreach (['tenant_marketing_settings', 'pos_live_connections', 'tenants', 'tenant_access_profiles', 'tenant_module_states', 'users', 'tenant_user'] as $table) {
        expect($queries->contains(fn ($sql) => str_contains($sql, '`'.$table.'`') && str_contains($sql, 'for update')))->toBeTrue($table.' must retain writer locks');
    }
    Http::assertNothingSent();
});

test('reporting admission does not reuse an unrelated resolved module instance between read views', function () {
    $stale = Mockery::mock(TenantModuleAccessResolver::class);
    $stale->shouldNotReceive('canAccess');
    app()->instance(TenantModuleAccessResolver::class, $stale);
    $instances = [];
    foreach ([1, 2] as $attempt) {
        $this->view->run($this->tenant->id, function () use (&$instances) {
            $instances[] = $this->view->modules($this->tenant->id);
            readOnlyWitnessAdmit($this);
        });
    }
    expect($instances[0])->toBeInstanceOf(TenantModuleAccessResolver::class)->not->toBe($stale)
        ->and($instances[1])->not->toBe($instances[0])->and($this->view->modules($this->tenant->id))->toBeNull();
    Http::assertNothingSent();
});

test('a nested reporting request cannot replace or end its owning view', function () {
    $result = $this->view->run($this->tenant->id, function () {
        try {
            $this->view->run($this->tenant->id, fn () => 'nested result');
            $this->fail('A nested read scope must be rejected.');
        } catch (HttpException $error) {
            expect($error->getStatusCode())->toBe(409)->and($this->view->owns($this->tenant->id))->toBeTrue();
        }
        readOnlyWitnessAdmit($this);

        return 'original view';
    });
    expect($result)->toBe('original view')->and($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0);
});

test('a preexisting caller transaction is rejected without reads and stays owned by its caller', function (bool $raw) {
    $raw ? DB::connection()->getPdo()->beginTransaction() : DB::beginTransaction();
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $this->view->run($this->tenant->id, fn () => 'must not run');
        $this->fail('A reporting view cannot borrow a caller transaction.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and(DB::getQueryLog())->toBeEmpty()
            ->and(DB::connection()->getPdo()->inTransaction())->toBeTrue()->and(DB::transactionLevel())->toBe($raw ? 0 : 1);
    } finally {
        DB::disableQueryLog();
        $raw ? DB::connection()->getPdo()->rollBack() : DB::rollBack();
    }
    expect($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0);
})->with(['Laravel' => [false], 'raw PDO' => [true]]);

test('direct customer-read authority keeps member access and rejects a later member revocation', function (bool $revoke) {
    $this->profileMember = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->tenant->users()->attach($this->profileMember->id, ['role' => 'member', 'membership_active' => true]);
    $allowedInside = false;
    try {
        $result = $this->view->run($this->tenant->id, function () use ($revoke, &$allowedInside) {
            $allowedInside = app(CustomerProfileAccess::class)->allows($this->profileMember, $this->tenant);
            expect($allowedInside)->toBeTrue();
            if ($revoke) {
                $this->writer->table('tenant_user')->where('tenant_id', $this->tenant->id)->where('user_id', $this->profileMember->id)
                    ->update(['membership_active' => false]);
                expect(app(CustomerProfileAccess::class)->allows($this->profileMember, $this->tenant))->toBeTrue();
            }

            return 'member read';
        });
        expect($revoke)->toBeFalse()->and($result)->toBe('member read');
    } catch (HttpException $error) {
        expect($revoke)->toBeTrue()->and($allowedInside)->toBeTrue()->and($error->getStatusCode())->toBeIn([403, 409]);
    }
    expect($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0);
    Http::assertNothingSent();
})->with(['unchanged member' => [false], 'revoked member' => [true]]);

test('a stable unavailable Square source may stay omitted but changes to its negative admission are fenced', function (bool $change) {
    $store = app(PosStore::class);
    $connection = bin2hex(random_bytes(16));
    $store->rows('connections', $this->tenant->id)->insert($store->values($this->tenant->id, ['id' => $connection,
        'merchant_id' => 'unavailable-original-merchant', 'provider_location_id' => 'unavailable-location', 'environment' => 'production',
        'credentials' => $store->encrypt(['access_token' => 'expired-source-reader', 'expires_at' => now()->subDay()->toIso8601String()]), 'active' => true]));
    $reader = collect(app(SquareConnectionContext::class)->available($this->tenant->id))->firstWhere('id', $connection);
    $binding = ['connection_id' => $connection, 'connection_generation' => $reader['connection_generation'], 'runtime_id' => $store->runtime(),
        'environment' => 'production', 'merchant_id' => 'unavailable-original-merchant', 'location_ids' => ['unavailable-location']];
    $omitted = false;
    try {
        $result = $this->view->run($this->tenant->id, function () use ($binding, $connection, $store, $change, &$omitted) {
            readOnlyWitnessAdmit($this);
            try {
                app(SquareConnectionContext::class)->assertCurrent($this->tenant->id, $binding);
                $this->fail('An expired original source cannot be financial evidence.');
            } catch (HttpException $error) {
                expect($error->getStatusCode())->toBe(409);
                $omitted = true;
            }
            if ($change) {
                $this->writer->table('pos_live_connections')->where('tenant_id', $this->tenant->id)->where('id', $connection)
                    ->update(['credentials' => $store->encrypt(['access_token' => 'different-expired-source-reader', 'expires_at' => now()->subDay()->toIso8601String()])]);
            }

            return 'independently admitted original purchase';
        });
        expect($change)->toBeFalse()->and($result)->toBe('independently admitted original purchase');
    } catch (HttpException $error) {
        expect($change)->toBeTrue()->and($omitted)->toBeTrue()->and($error->getStatusCode())->toBe(409);
    }
    expect($omitted)->toBeTrue()->and($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0);
    Http::assertNothingSent();
})->with(['stable omitted source' => [false], 'changed omitted source' => [true]]);

test('a commit and restart cannot replace the owned physical read-only transaction', function (bool $raw) {
    try {
        $this->view->run($this->tenant->id, function () use ($raw) {
            readOnlyWitnessAdmit($this);
            if ($raw) {
                DB::connection()->getPdo()->commit();
                DB::connection()->getPdo()->beginTransaction();
            } else {
                DB::commit();
                DB::beginTransaction();
            }

            return 'must not escape a replacement transaction';
        });
        $this->fail('The owned physical transaction must survive to the frame boundary.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409);
    }
    expect($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0)
        ->and(DB::connection()->getPdo()->inTransaction())->toBeFalse();
    DB::transaction(fn () => DB::table('tenants')->where('id', $this->tenant->id)->update(['name' => 'Writable after replacement rejection']));
    expect($this->tenant->fresh()->name)->toBe('Writable after replacement rejection');
    Http::assertNothingSent();
})->with(['Laravel replacement' => [false], 'raw PDO replacement' => [true]]);

test('the real full dependency capture keeps corroborated purchases while omitting a stable invalid source', function (bool $invalidScope) {
    $area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Read-only events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Original customer']);
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $area->id,
        'kind' => 'event', 'title' => 'Reviewed historical event', 'source_key' => hash('sha256', Str::uuid()), 'version' => 1,
        'status' => 'accepted', 'starts_on' => $this->scope['starts_on'], 'ends_on' => $this->scope['ends_on'], 'data' => []]);
    $mapping = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $area->id,
        'kind' => 'event_link', 'title' => 'Reviewed source window', 'source_key' => hash('sha256', Str::uuid()), 'version' => 1, 'status' => 'accepted',
        'data' => ['occurrence_id' => $event->id, 'starts_on' => $this->scope['starts_on'], 'ends_on' => $this->scope['ends_on'],
            'timezone' => 'America/New_York', 'square_scopes' => [$this->scope], 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String()]]);
    $order = 'read-only-order-'.Str::uuid();
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $order, 'location_id' => 'retired-location', 'state' => 'COMPLETED',
        'total_money_amount' => 1100, 'total_money_currency' => 'USD', 'closed_at' => '2025-11-01 16:00:00',
        'raw_payload' => ['_source_binding' => $this->binding, 'total_tax_money' => ['amount' => 100, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'payment-'.$order, 'square_order_id' => $order, 'location_id' => 'retired-location',
        'status' => 'COMPLETED', 'amount_money' => 1100, 'currency' => 'USD', 'created_at_source' => '2025-11-01 16:00:00',
        'raw_payload' => ['_source_binding' => $this->binding, 'refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => $order, 'source_meta' => []]);
    if ($invalidScope) {
        $binding = [...$this->binding, 'connection_generation' => 'revoked-source-generation', 'location_ids' => ['invalid-source-location']];
        $scope = [...$this->scope, 'location_id' => 'invalid-source-location'];
        foreach (['orders', 'payments'] as $kind) {
            $run = \App\Models\MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_'.$kind.'_sync', 'status' => 'completed',
                'started_at' => '2026-01-10', 'finished_at' => '2026-01-10', 'summary' => ['source_binding' => $binding, 'mode' => 'live-sync', 'errors' => 0,
                    'since' => '2020-01-01T00:00:00Z', 'initial_cursor' => null, 'checkpoint' => ['cursor' => null, 'errors' => 0]]]);
            $scope[$kind.'_run_id'] = $run->id;
        }
        $mapping->update(['data' => [...$mapping->data, 'square_scopes' => [$this->scope, $scope]]]);
        SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'invalid-'.$order, 'location_id' => 'invalid-source-location', 'state' => 'COMPLETED',
            'total_money_amount' => 2200, 'total_money_currency' => 'USD', 'closed_at' => '2025-11-01 16:00:00',
            'raw_payload' => ['_source_binding' => $binding, 'total_tax_money' => ['amount' => 200, 'currency' => 'USD']]]);
        SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'invalid-payment-'.$order, 'square_order_id' => 'invalid-'.$order, 'location_id' => 'invalid-source-location',
            'status' => 'COMPLETED', 'amount_money' => 2200, 'currency' => 'USD', 'created_at_source' => '2025-11-01 16:00:00',
            'raw_payload' => ['_source_binding' => $binding, 'refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => 'invalid-'.$order, 'source_meta' => []]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    try {
        $frame = app(EventCustomerDependencies::class)->capture($this->tenant, $area, null, true);
        $queries = collect(DB::getQueryLog())->pluck('query')->map(fn ($sql) => strtolower($sql));
    } finally {
        DB::disableQueryLog();
    }
    $purchase = $frame['scoped_witnesses'][$event->id]['square_order:'.$order][$profile->id] ?? null;
    expect($purchase)->not->toBeNull()->and($purchase['net_sales_minor'])->toBe(1000)->and($purchase['amount_minor'])->toBe(1100)
        ->and($frame['scoped_witnesses'][$event->id])->toHaveCount(1)
        ->and($frame['scoped_witnesses'][$event->id]['square_order:invalid-'.$order] ?? null)->toBeNull()
        ->and($queries->contains(fn ($sql) => str_contains($sql, 'for update')))->toBeFalse()
        ->and($queries->filter(fn ($sql) => str_contains($sql, 'read only'))->count())->toBeGreaterThanOrEqual(2)
        ->and($this->view->owns($this->tenant->id))->toBeFalse()->and(DB::transactionLevel())->toBe(0);
    Http::assertNothingSent();
})->with(['corroborated source only' => [false], 'corroborated and stable invalid sources' => [true]]);
