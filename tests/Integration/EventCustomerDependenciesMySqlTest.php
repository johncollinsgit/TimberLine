<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Team\Studio\EventCustomerDependencies;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! in_array($db->getDatabaseName(), ['everbranch_ci', 'codex_events_test'], true)
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing customer dependency fixtures outside isolated disposable MySQL.');
    }
    Http::preventStrayRequests();
    config(['pos_live.runtime_id' => 'dependency_mysql_fixture']);
    $this->tenant = Tenant::create(['name' => 'Fictional dependency', 'slug' => 'dependency-'.Str::lower(Str::random(16))]);
    $this->area = TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'name' => 'Dependency events', 'slug' => 'events', 'profile' => 'events', 'settings' => []]);
    $this->first = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Original']);
    $this->second = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Replacement']);
    $this->sourceId = 'dependency-original-'.Str::uuid();
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'kind' => 'event', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'title' => 'Fictional event',
        'data' => ['_teams_enrichment' => ['square_orders' => ['ids' => [$this->sourceId]]]]]);
    $this->order = SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $this->sourceId, 'location_id' => 'fixture-location',
        'state' => 'COMPLETED', 'total_money_amount' => 1100, 'total_money_currency' => 'USD', 'closed_at' => '2026-11-01 16:00:00', 'raw_payload' => ['value' => 1]]);
    $this->payment = SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'payment-'.$this->sourceId,
        'square_order_id' => $this->sourceId, 'location_id' => 'fixture-location', 'status' => 'COMPLETED', 'amount_money' => 1100, 'currency' => 'USD',
        'created_at_source' => '2026-11-01 16:00:00', 'raw_payload' => ['value' => 1]]);
    $this->link = MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->first->id,
        'source_type' => 'square_order', 'source_id' => $this->sourceId, 'source_meta' => ['retained' => ['all_bytes' => true]]]);
    $this->dependencies = app(EventCustomerDependencies::class);
});

afterEach(function () {
    if (! isset($this->tenant)) {
        return;
    }
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    DB::purge('event_dependencies_writer');
    $operations = DB::table('payment_core_operations')->where('tenant_id', $this->tenant->id)->pluck('id');
    DB::table('payment_core_events')->whereIn('operation_id', $operations)->delete();
    foreach (['payment_core_operations', 'payment_core_bindings', 'payment_core_admissions', 'tenant_user'] as $table) {
        DB::table($table)->where('tenant_id', $this->tenant->id)->delete();
    }
    if (isset($this->reportingOwner)) {
        $this->reportingOwner->delete();
    }
    foreach (['pos_live_events', 'pos_live_sales', 'pos_candle_cash_reservations', 'commerce_stock_event_links', 'commerce_stock_pools', 'marketing_profile_links', 'square_payments', 'square_orders', 'marketing_profiles', 'team_operation_records', 'team_areas', 'tenants'] as $table) {
        DB::table($table)->where($table === 'tenants' ? 'id' : 'tenant_id', $this->tenant->id)->delete();
    }
});

test('a fresh dependency frame establishes RR without changing an RC session or mixing concurrent identities', function () {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');
    config(['database.connections.event_dependencies_writer' => DB::connection()->getConfig()]);
    $writer = DB::connection('event_dependencies_writer');
    $changed = false;
    DB::listen(function ($query) use ($writer, &$changed) {
        if (! $changed && $query->connectionName !== 'event_dependencies_writer' && str_contains($query->sql, 'marketing_profile_links')
            && str_contains($query->sql, 'source_id')) {
            $changed = true;
            $writer->table('marketing_profile_links')->where('id', $this->link->id)->update(['marketing_profile_id' => $this->second->id]);
            $writer->table('marketing_profiles')->where('id', $this->first->id)->update(['first_name' => 'Intermediate']);
        }
    });
    $before = $this->dependencies->capture($this->tenant, $this->area);
    expect($changed)->toBeTrue()->and(DB::transactionLevel())->toBe(0)
        ->and($before['claims']['square_order:'.$this->sourceId])->toBe($this->first->id)
        ->and($before['labels'][$this->first->id])->toBe('Original')
        ->and(DB::selectOne('SELECT @@transaction_isolation AS level')->level)->toBe('READ-COMMITTED');
    $after = $this->dependencies->capture($this->tenant, $this->area, $before['scope']);
    expect($after['signature'])->not->toBe($before['signature'])
        ->and($after['claims']['square_order:'.$this->sourceId])->toBe($this->second->id);
    Http::assertNothingSent();
});

test('preexisting transactions cannot lend an old snapshot to a dependency admission frame', function (string $isolation) {
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL '.$isolation);
    DB::beginTransaction();
    try {
        $this->dependencies->capture($this->tenant, $this->area);
        $this->fail('A nested dependency frame must fail closed.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and(DB::transactionLevel())->toBe(1);
    }
})->with(['REPEATABLE READ', 'READ COMMITTED']);

test('original source revisions use covering indexed hashes and retain typed and same-second mutations', function () {
    DB::enableQueryLog();
    $before = $this->dependencies->capture($this->tenant, $this->area);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    $hashes = $queries->filter(fn ($query) => str_contains($query['query'], 'HEX(`event_content_hash`)'));
    expect($hashes)->toHaveCount(2);
    foreach ($hashes as $query) {
        expect($query['query'])->toContain('force index')->not->toContain('raw_payload');
        $plan = DB::select('EXPLAIN '.$query['query'], $query['bindings']);
        expect(collect($plan)->contains(fn ($row) => str_contains($row->Extra ?? '', 'Using index')))->toBeTrue();
    }
    DB::table('square_payments')->where('id', $this->payment->id)->update(['raw_payload' => '{"value":"1"}']);
    $after = $this->dependencies->capture($this->tenant, $this->area, $before['scope']);
    expect($after['signature'])->not->toBe($before['signature']);
    DB::table('square_orders')->where('id', $this->order->id)->update(['closed_at' => '2026-11-01 16:00:01']);
    expect($this->dependencies->capture($this->tenant, $this->area, $before['scope'])['signature'])->not->toBe($after['signature']);
});

test('missing and incompatible optional read indexes retain the exact committed customer dependency signature', function () {
    $baseline = $this->dependencies->capture($this->tenant, $this->area);
    $original = Schema::getFacadeRoot();
    foreach ([[], [['name' => 'mpl_tenant_source_unique_idx', 'columns' => ['tenant_id']],
        ['name' => 'mpl_profile_source_type_idx', 'columns' => ['tenant_id']],
        ['name' => 'marketing_profiles_merged_into_profile_id_foreign', 'columns' => ['tenant_id']]]] as $indexes) {
        $schema = Mockery::mock($original)->makePartial();
        $schema->shouldReceive('getIndexes')->andReturn($indexes);
        Schema::swap($schema);
        try {
            $fallback = (new EventCustomerDependencies)->capture($this->tenant, $this->area, $baseline['scope']);
            expect($fallback['signature'])->toBe($baseline['signature'])
                ->and($fallback['claims'])->toBe($baseline['claims'])->and($fallback['labels'])->toBe($baseline['labels']);
        } finally {
            Schema::swap($original);
        }
    }
    Http::assertNothingSent();
});

test('selector admission and original rows share one read view even across a restored selector ABA', function () {
    $baseline = $this->dependencies->capture($this->tenant, $this->area);
    $original = $this->event->getRawOriginal('data');
    $replacement = $this->event->replicate();
    $replacement->data = ['_teams_enrichment' => ['square_orders' => ['ids' => ['intermediate-unreviewed-source']]]];
    $intermediate = $replacement->getAttributes()['data'];
    config(['database.connections.event_dependencies_writer' => DB::connection()->getConfig()]);
    $writer = DB::connection('event_dependencies_writer');
    $phase = 0;
    DB::listen(function ($query) use ($writer, $original, $intermediate, &$phase) {
        if ($query->connectionName === 'event_dependencies_writer') {
            return;
        }
        if ($phase === 0 && str_contains($query->sql, 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ')) {
            $phase = 1;
            $writer->table('team_operation_records')->where('id', $this->event->id)->update(['data' => $intermediate]);
        } elseif ($phase === 1 && str_contains($query->sql, 'SHA2(`data`,256) AS content_revision')) {
            $phase = 2;
            $writer->table('team_operation_records')->where('id', $this->event->id)->update(['data' => $original]);
        }
    });
    try {
        $this->dependencies->capture($this->tenant, $this->area, $baseline['scope']);
        $this->fail('A selector from outside the read view must not be mixed with intermediate source rows.');
    } catch (HttpException $error) {
        expect($error->getStatusCode())->toBe(409)->and($phase)->toBe(2);
    }
    expect($this->dependencies->capture($this->tenant, $this->area, $baseline['scope'])['signature'])->toBe($baseline['signature']);
});

test('new unlinked native mirror conflicts and native refund evidence are fresh scoped dependencies', function () {
    $this->payment->update(['raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    $before = $this->dependencies->capture($this->tenant, $this->area, null, true);
    expect($before['witnesses']['square_order:'.$this->sourceId][$this->first->id]['confirmed'])->toBeTrue();
    $store = app(\App\Services\Pos\PosStore::class);
    $id = bin2hex(random_bytes(16));
    $register = bin2hex(random_bytes(16));
    $binding = (new \App\Services\PaymentsCore\MerchantBinding($this->tenant->id, $register, 'square', 'fixture-merchant', 'fixture-location', 'live', $store->runtime()))->snapshot();
    $lines = [['line_id' => 'original-line', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 1000, 'tax_amount_minor' => 100]];
    $snapshot = ['binding' => $binding, 'sale' => (new \App\Services\PaymentsCore\SaleSnapshot($id, 'usd', $lines))->snapshot(),
        'customer' => ['id' => $this->second->id], 'terms' => ['stock_pool_id' => 'another-unrelated-event-pool']];
    $store->rows('sales', $this->tenant->id)->insert($store->values($this->tenant->id, [
        'id' => $id, 'register_id' => $register, 'actor_id' => 1, 'location_id' => 'fixture-location', 'request_id' => (string) Str::uuid(),
        'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(),
        'snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot),
        'amount_minor' => 1100, 'currency' => 'usd', 'commerce_source' => \App\Services\Pos\StockPosCommerce::VERSION,
        'provider_order_id' => $this->sourceId, 'state' => 'pending', 'created_at' => now(), 'expires_at' => now()->addHour(),
    ]));
    $after = $this->dependencies->capture($this->tenant, $this->area, $before['scope'], true);
    expect($after['signature'])->not->toBe($before['signature'])
        ->and($after['witnesses']['square_order:'.$this->sourceId] ?? [])->toBeEmpty()
        ->and($after['canonical'][$this->second->id])->toBe($this->second->id);
    $store->event($this->tenant->id, $id, 'square_refund_observed', ['payment_id' => 'original-source-payment', 'refund_id' => 'retained-refund', 'amount_minor' => 100]);
    expect($this->dependencies->capture($this->tenant, $this->area, $before['scope'])['signature'])->not->toBe($after['signature']);
    Http::assertNothingSent();
});

test('real native card witnesses share validated journal reads while ordinary recovery retains its transaction boundary', function () {
    $this->reportingOwner = \App\Models\User::factory()->create(['role' => 'member', 'is_active' => true]);
    $this->tenant->users()->attach($this->reportingOwner->id, ['role' => 'owner', 'membership_active' => true]);
    $pool = bin2hex(random_bytes(16));
    DB::table('commerce_stock_pools')->insert(['id' => $pool, 'tenant_id' => $this->tenant->id, 'name' => 'Fictional native pool', 'kind' => 'show', 'event_key' => 'event:321']);
    DB::table('commerce_stock_event_links')->insert(['tenant_id' => $this->tenant->id, 'event_id' => 321, 'provider' => 'manual', 'external_id' => 'native-'.Str::uuid(),
        'metadata' => json_encode(['timezone' => 'UTC', 'start_on' => '2026-11-01', 'end_on' => '2026-11-01']), 'reviewed_by' => $this->reportingOwner->id]);
    $this->event->update(['data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 321], 'square_orders' => [
        'ids' => [$this->sourceId], 'reviewed_by' => $this->reportingOwner->id, 'reviewed_at' => now()->toIso8601String(),
    ]]]]);
    $store = app(\App\Services\Pos\PosStore::class);
    $id = bin2hex(random_bytes(16));
    $register = bin2hex(random_bytes(16));
    $binding = new \App\Services\PaymentsCore\MerchantBinding($this->tenant->id, $register, 'square', 'fixture-merchant', 'fixture-location', 'live', $store->runtime());
    $lines = [['line_id' => 'original-line', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 1000, 'tax_amount_minor' => 100]];
    $sale = new \App\Services\PaymentsCore\SaleSnapshot($id, 'usd', $lines);
    $request = (string) Str::uuid();
    $journal = app(\App\Services\PaymentsCore\PaymentJournal::class);
    $operation = $journal->reservePayment($binding, $sale, $request);
    $journal->claim($this->tenant->id, $operation['id']);
    $operation = $journal->apply($this->tenant->id, $operation['id'], new \App\Services\PaymentsCore\ProviderEvidence($request, $binding->fingerprint(), 'native-source-'.$id, 1100, 'usd', 'succeeded', 1));
    expect($operation['state'])->toBe('succeeded');
    $snapshot = ['binding' => $binding->snapshot(), 'sale' => $sale->snapshot(), 'customer' => ['id' => $this->first->id],
        'register_name' => 'Original native register', 'location_name' => 'Original location', 'configuration' => [],
        'terms' => ['stock_pool_id' => $pool, 'display' => [], 'subtotal_minor' => 1000, 'tax_minor' => 100, 'total_minor' => 1100]];
    $store->rows('sales', $this->tenant->id)->insert($store->values($this->tenant->id, [
        'id' => $id, 'register_id' => $register, 'actor_id' => $this->reportingOwner->id, 'location_id' => 'fixture-location', 'request_id' => (string) Str::uuid(),
        'payment_request_id' => $request, 'sdk_attempt_id' => (string) Str::uuid(), 'attempt_id' => $operation['id'],
        'snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot),
        'amount_minor' => 1100, 'currency' => 'usd', 'commerce_source' => \App\Services\Pos\StockPosCommerce::VERSION,
        'provider_order_id' => $this->sourceId, 'state' => 'succeeded', 'created_at' => '2026-11-01 16:00:00', 'expires_at' => now()->addHour(),
    ]));
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->first->id,
        'source_type' => 'pos_sale', 'source_id' => $store->runtime().':'.$id, 'source_meta' => []]);
    $frame = $this->dependencies->capture($this->tenant, $this->area, null, true);
    $purchase = ['source' => 'pos_sale', 'source_id' => $id, 'amount_minor' => 1100, 'refunded_minor' => 0,
        'currency' => 'USD', 'occurred_at' => '2026-11-01T16:00:00+00:00'];
    $this->dependencies->assertPurchase($frame, (string) $this->event->id, $this->first, $purchase);
    expect($frame['witnesses']['pos_sale:'.$store->runtime().':'.$id][$this->first->id]['confirmed'])->toBeTrue();
    DB::beginTransaction();
    try {
        $journal->get($this->tenant->id, $operation['id']);
        $this->fail('Ordinary journal recovery must still reject caller transactions.');
    } catch (DomainException $error) {
        expect($error->getMessage())->toContain('transaction');
    } finally {
        DB::rollBack();
    }
    $before = $this->dependencies->capture($this->tenant, $this->area, $frame['scope']);
    DB::table('payment_core_bindings')->where('id', $operation['binding_id'])->update(['fingerprint' => str_repeat('0', 64)]);
    $invalid = $this->dependencies->capture($this->tenant, $this->area, $frame['scope'], true);
    expect($invalid['signature'])->not->toBe($before['signature'])
        ->and($invalid['witnesses']['pos_sale:'.$store->runtime().':'.$id] ?? [])->toBeEmpty();
    Http::assertNothingSent();
});

test('dependency rewards evidence uses a consistent nonlocking read while wallet callers retain their row lock', function () {
    $store = app(\App\Services\Pos\PosStore::class);
    $id = bin2hex(random_bytes(16));
    $reservation = (string) Str::uuid();
    $binding = (new \App\Services\PaymentsCore\MerchantBinding($this->tenant->id, 'fixture-register', 'square', 'fixture-merchant', 'fixture-location', 'live', $store->runtime()))->snapshot();
    $snapshot = ['reward_only' => true, 'binding' => $binding, 'customer' => ['id' => $this->first->id], 'terms' => ['pos_candle_cash' => ['amount_minor' => 1000]]];
    $sale = (object) ['id' => $id, 'tenant_id' => $this->tenant->id, 'state' => 'succeeded', 'amount_minor' => 0, 'currency' => 'usd',
        'attempt_id' => null, 'descriptor_issued_at' => null, 'payment_request_id' => (string) Str::uuid(),
        'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot)];
    DB::table('pos_candle_cash_reservations')->insert(['id' => $reservation, 'runtime_id' => $store->runtime(), 'tenant_id' => $this->tenant->id,
        'sale_id' => $id, 'marketing_profile_id' => $this->first->id, 'amount_minor' => 1000, 'policy_version' => 'original-fixture-policy',
        'authorization_reference' => 'original-authorization', 'state' => 'spent']);
    $store->event($this->tenant->id, $id, 'reward_only_sale_completed', ['snapshot_hash' => $sale->snapshot_hash, 'reservation_id' => $reservation]);
    $reader = app(\App\Services\Pos\PosRewardOnlySale::class);
    DB::beginTransaction();
    DB::enableQueryLog();
    $readOnly = $reader->evidence($sale, $snapshot, true);
    $readQueries = collect(DB::getQueryLog());
    DB::flushQueryLog();
    $ordinary = $reader->evidence($sale, $snapshot);
    $ordinaryQueries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    DB::rollBack();
    expect($readOnly)->toBe($ordinary)->and($readOnly['state'])->toBe('succeeded')
        ->and($readQueries->contains(fn ($query) => str_contains(strtolower($query['query']), 'for update')))->toBeFalse()
        ->and($ordinaryQueries->contains(fn ($query) => str_contains(strtolower($query['query']), 'for update')))->toBeTrue();
    Http::assertNothingSent();
});
