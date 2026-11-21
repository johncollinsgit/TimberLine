<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CustomerMergeService;
use App\Services\Marketing\SquarePurchaseEffects;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\PosProcessRace;

uses(Tests\TestCase::class);
require_once __DIR__.'/../Support/SquarePurchaseFixtures.php';
require_once __DIR__.'/../Support/EventSquareCoverageFixtures.php';

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Explicit disposable MySQL only.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Unsafe database.');
    }
    squarePurchaseFixture($this);
});

afterEach(function () {
    if (isset($this->rankingCase->tenant)) {
        DB::table('pos_live_connections')->where('tenant_id', $this->rankingCase->tenant->id)->delete();
    }
});

test('concurrent original purchase retries award once under the shared tenant fence', function () {
    $tenant = $this->tenant->id;
    $results = PosProcessRace::run(
        fn () => app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order')['status'],
        fn () => app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order')['status'],
        fn ($query) => str_contains($query->sql, 'customer_account_fences') && str_contains($query->sql, 'for update')
    );
    expect($results)->toBe(['processed', 'processed'])
        ->and(CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'square_purchase')->count())->toBe(1)
        ->and(app(CandleCashService::class)->currentBalance($this->profile))->toBe(1.8);
});

test('Core merge HTTP review survives native MySQL JSON storage and preserves rewards on replay', function () {
    $donor = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Reviewed duplicate']);
    app(CandleCashService::class)->addRewardAmountIdempotent($donor, 5, 'gift', 'reviewed-gift');
    $base = '/workspace/customers/merges';
    $preview = $this->actingAs($this->owner)->postJson($base.'/preview?tenant='.$this->tenant->slug, [
        'profile_ids' => [$this->profile->id, $donor->id], 'survivor_profile_id' => $this->profile->id,
        'request_id' => (string) Str::uuid(),
    ])->assertOk()->json();
    $url = $base.'/'.$preview['operation_id'].'/apply?tenant='.$this->tenant->slug;
    $approval = ['expected_version' => $preview['version'], 'identity_confirmed' => true];
    $this->postJson($url, $approval)->assertOk()->assertJsonPath('status', 'completed');
    $this->postJson($url, $approval)->assertOk()->assertJsonPath('profile_id', $this->profile->id);
    expect($donor->fresh()->merged_into_profile_id)->toBe($this->profile->id)
        ->and(app(CandleCashService::class)->currentBalance($this->profile))->toBe(5.0)
        ->and(CandleCashTransaction::where('marketing_profile_id', $this->profile->id)->where('source', 'gift')->count())->toBe(1);
});

test('event customer read views can resolve receipt pools with a cold or expired database cache', function (bool $expired) {
    config(['pos_live.runtime_id' => 'fictional_event_cache', 'cache.default' => 'database', 'cache.prefix' => 'event-read-'.Str::uuid().':']);
    \Illuminate\Support\Facades\Cache::purge('database');
    $store = app(\App\Services\Pos\PosStore::class);
    $key = 'pay-event-receipt-index-v1:'.hash('sha256', implode(':', [$store->db->getName(), $store->db->getDatabaseName(), $store->runtime(), $this->tenant->id]));
    if ($expired) {
        \Illuminate\Support\Facades\Cache::put($key, ['entries' => [], 'watermark' => null], now()->addMinute());
        DB::table('cache')->where('key', config('cache.prefix').$key)->update(['expiration' => now()->subMinute()->timestamp]);
    }
    $before = DB::table('cache')->where('key', config('cache.prefix').$key)->first();
    $result = app(\App\Services\Team\Studio\EventCustomerReadView::class)->run($this->tenant->id,
        fn () => app(\App\Services\Reporting\PayEventReceiptIndex::class)->forPools($this->tenant->id, ['reviewed-pool']));
    expect($result)->toBe(['ids' => [], 'review_items' => []])
        ->and(DB::table('cache')->where('key', config('cache.prefix').$key)->first())->toEqual($before);
})->with([false, true]);

test('native MySQL rebuild serves show and lifetime rankings over the same reviewed buyer cohort', function () {
    app()->forgetInstance(\App\Services\Marketing\SquareConnectionContext::class);
    $case = $this->rankingCase = new stdClass;
    legacyEventCoverageFixture($case);
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true,
        'team_work.tenant_allowlist' => [(string) $case->tenant->id]]);
    \App\Models\TenantAccessProfile::create(['tenant_id' => $case->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    \App\Models\TenantModuleState::create(['tenant_id' => $case->tenant->id, 'module_key' => 'customers', 'enabled_override' => true]);
    \App\Models\TenantModuleEntitlement::create(['tenant_id' => $case->tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    $area = \App\Models\TeamArea::create(['id' => (string) Str::uuid(), 'tenant_id' => $case->tenant->id, 'name' => 'Reviewed shows', 'slug' => 'shows', 'profile' => 'events', 'settings' => ['source_authority' => 'teams']]);
    $event = \App\Models\TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $case->tenant->id, 'area_id' => $area->id,
        'kind' => 'event', 'title' => 'Reviewed show', 'source_key' => (string) Str::uuid(), 'version' => 1, 'status' => 'accepted',
        'starts_on' => $case->scope['starts_on'], 'ends_on' => $case->scope['ends_on'], 'data' => []]);
    \App\Models\TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $case->tenant->id, 'area_id' => $area->id,
        'kind' => 'event_link', 'title' => 'Reviewed original source window', 'source_key' => (string) Str::uuid(), 'version' => 1, 'status' => 'accepted',
        'data' => ['occurrence_id' => $event->id, 'starts_on' => $case->scope['starts_on'], 'ends_on' => $case->scope['ends_on'],
            'timezone' => 'America/New_York', 'square_scopes' => [$case->scope], 'reviewed_by' => $case->owner->id, 'reviewed_at' => now()->toIso8601String(),
            'reviewed_source_versions' => [$event->id => 1]]]);
    $loyal = MarketingProfile::create(['tenant_id' => $case->tenant->id, 'first_name' => 'Longtime buyer']);
    $large = MarketingProfile::create(['tenant_id' => $case->tenant->id, 'first_name' => 'Large show buyer']);
    $outsider = MarketingProfile::create(['tenant_id' => $case->tenant->id, 'first_name' => 'Absent buyer']);
    foreach ([[$loyal, 1100, '2025-11-01 16:00:00'], [$large, 5500, '2025-11-01 17:00:00'],
        [$loyal, 11000, '2025-10-01 16:00:00'], [$outsider, 55000, '2025-10-01 16:00:00']] as [$profile, $amount, $date]) {
        $order = 'rank-'.Str::uuid();
        \App\Models\SquareOrder::create(['tenant_id' => $case->tenant->id, 'square_order_id' => $order, 'location_id' => 'retired-location',
            'state' => 'COMPLETED', 'total_money_amount' => $amount, 'total_money_currency' => 'USD', 'closed_at' => $date,
            'raw_payload' => ['_source_binding' => $case->binding, 'total_tax_money' => ['amount' => intdiv($amount, 11), 'currency' => 'USD']]]);
        \App\Models\SquarePayment::create(['tenant_id' => $case->tenant->id, 'square_payment_id' => 'payment-'.$order, 'square_order_id' => $order, 'location_id' => 'retired-location',
            'status' => 'COMPLETED', 'amount_money' => $amount, 'currency' => 'USD', 'created_at_source' => $date,
            'raw_payload' => ['_source_binding' => $case->binding, 'refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
        MarketingProfileLink::create(['tenant_id' => $case->tenant->id, 'marketing_profile_id' => $profile->id,
            'source_type' => 'square_order', 'source_id' => $order, 'source_meta' => []]);
    }
    // A retained unresolved native attempt is not a purchase, but remains an
    // original dependency of this reviewed show and its canonical buyer.
    $stock = app(\App\Services\Inventory\StockLedger::class);
    $pool = $stock->createPool($case->tenant->id, 'pending-report-fixture', $case->owner->id, 'Reviewed show', 'show', 'event:123')['pool_id'];
    $stock->rows('event_links', $case->tenant->id)->insert(['tenant_id' => $case->tenant->id, 'event_id' => 123, 'provider' => 'manual', 'external_id' => 'pending-report-fixture',
        'metadata' => json_encode(['name' => 'Reviewed show', 'timezone' => 'America/New_York', 'start_on' => $case->scope['starts_on'], 'end_on' => $case->scope['ends_on']]),
        'reviewed_by' => $case->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $event->update(['data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 123]]]]);
    $store = app(\App\Services\Pos\PosStore::class);
    $saleId = bin2hex(random_bytes(16));
    $register = str_repeat('a', 32);
    $binding = (new \App\Services\PaymentsCore\MerchantBinding($case->tenant->id, $register, 'square', 'fictional-merchant', 'original-location', 'live', $store->runtime()))->snapshot();
    $lines = [['line_id' => 'line1', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 1000, 'tax_amount_minor' => 70]];
    $snapshot = ['binding' => $binding, 'sale' => (new \App\Services\PaymentsCore\SaleSnapshot($saleId, 'usd', $lines))->snapshot(),
        'terms' => ['stock_pool_id' => $pool, 'tax_minor' => 70, 'total_minor' => 1070, 'subtotal_minor' => 1000], 'customer' => ['id' => $loyal->id]];
    $store->rows('sales', $case->tenant->id)->insert($store->values($case->tenant->id, [
        'id' => $saleId, 'register_id' => $register, 'actor_id' => $case->owner->id, 'location_id' => 'original-location',
        'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(), 'attempt_id' => bin2hex(random_bytes(16)),
        'snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot), 'amount_minor' => 1070, 'currency' => 'usd',
        'commerce_source' => \App\Services\Pos\StockPosCommerce::VERSION, 'state' => 'unknown', 'stock_state' => 'reserved',
        'created_at' => '2025-11-01 16:00:00', 'expires_at' => now()->addHour(), 'reconciled_at' => now()->subMinute(),
    ]));
    MarketingProfileLink::create(['tenant_id' => $case->tenant->id, 'marketing_profile_id' => $loyal->id,
        'source_type' => 'pos_sale', 'source_id' => $store->runtime().':'.$saleId, 'source_meta' => []]);
    $dependencies = app(\App\Services\Team\Studio\EventCustomerDependencies::class);
    $frame = $dependencies->capture($case->tenant, $area);
    $reports = app(\App\Services\Team\Studio\EventPerformance::class);
    $reports->rebuild($case->tenant, $case->owner, $area);
    $filters = ['group_by' => 'customer', 'event_id' => $event->id, 'from' => '2025-11-01', 'to' => '2025-11-02', 'sort' => 'net_sales'];
    // The scheduled reconciliation poll writes this even if no financial or
    // identity evidence changed. Both anchor and lifetime revisions must survive.
    $store->rows('sales', $case->tenant->id)->where('id', $saleId)->update(['reconciled_at' => now()]);
    expect($dependencies->capture($case->tenant, $area)['signature'])->toBe($frame['signature']);
    $show = $reports->read($case->tenant, $case->owner, $area, $filters);
    $life = $reports->read($case->tenant, $case->owner, $area, $filters + ['rank_basis' => 'lifetime']);
    expect($show['status'])->toBe('ready')->and($life['status'])->toBe('ready')
        ->and(array_column($show['rows'], 'id'))->toBe(array_map('strval', [$large->id, $loyal->id]))
        ->and(array_column($life['rows'], 'id'))->toBe(array_map('strval', [$loyal->id, $large->id]))
        ->and(array_column($life['rows'], 'paid_total_minor'))->toBe([12100, 5500])
        ->and(CandleCashTransaction::whereIn('marketing_profile_id', [$loyal->id, $large->id, $outsider->id])->count())->toBe(0);
    $changed = $snapshot;
    $changed['customer']['id'] = $outsider->id;
    $store->rows('sales', $case->tenant->id)->where('id', $saleId)->update([
        'snapshot' => $store->encrypt($changed), 'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($changed),
    ]);
    expect($dependencies->capture($case->tenant, $area)['signature'])->not->toBe($frame['signature']);
    $store->rows('sales', $case->tenant->id)->where('id', $saleId)->update([
        'snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot), 'state' => 'review',
    ]);
    expect($dependencies->capture($case->tenant, $area)['signature'])->not->toBe($frame['signature'])
        ->and($reports->read($case->tenant, $case->owner, $area, $filters)['status'])->toBe('preparing');
});

test('a merge waiting behind purchase earning retains the receipt and ledger on the canonical survivor', function () {
    $tenant = $this->tenant->id;
    $survivor = MarketingProfile::create(['tenant_id' => $tenant, 'first_name' => 'Reviewed survivor']);
    $operation = app(CustomerMergeService::class)->createOperation($tenant, [$this->profile->id, $survivor->id], $survivor->id, 'core', (string) Str::uuid());
    PosProcessRace::run(
        fn () => app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order')['status'],
        fn () => app(CustomerMergeService::class)->apply($operation)->status,
        fn ($query) => str_contains($query->sql, 'customer_account_fences') && str_contains($query->sql, 'for update')
    );
    app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order');
    expect(MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->sole()->marketing_profile_id)->toBe($survivor->id)
        ->and(CandleCashTransaction::where('marketing_profile_id', $survivor->id)->where('source', 'square_purchase')->count())->toBe(1)
        ->and(app(CandleCashService::class)->currentBalance($survivor))->toBe(1.8);
});

test('purchase processing waiting behind a merge resolves current links and earns only on the survivor', function () {
    $tenant = $this->tenant->id;
    $survivor = MarketingProfile::create(['tenant_id' => $tenant, 'first_name' => 'Reviewed survivor']);
    $operation = app(CustomerMergeService::class)->createOperation($tenant, [$this->profile->id, $survivor->id], $survivor->id, 'core', (string) Str::uuid());
    PosProcessRace::run(
        fn () => app(CustomerMergeService::class)->apply($operation)->status,
        fn () => app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order')['status'],
        fn ($query) => str_contains($query->sql, 'customer_account_fences') && str_contains($query->sql, 'for update')
    );
    app(SquarePurchaseEffects::class)->reconcile($tenant, 'original-order');
    expect(MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->sole()->marketing_profile_id)->toBe($survivor->id)
        ->and(CandleCashTransaction::where('marketing_profile_id', $survivor->id)->where('source', 'square_purchase')->count())->toBe(1)
        ->and(app(CandleCashService::class)->currentBalance($survivor))->toBe(1.8);
});

test('merge approval reads current wallet state after waiting for an earning writer', function () {
    $tenant = $this->tenant->id;
    $other = MarketingProfile::create(['tenant_id' => $tenant, 'first_name' => 'Reviewed duplicate']);
    $operation = app(CustomerMergeService::class)->createOperation($tenant, [$this->profile->id, $other->id], $this->profile->id, 'core', (string) Str::uuid(), [], ['source' => 'core_customers']);
    $profile = $this->profile;
    $results = PosProcessRace::run(
        fn () => app(\App\Services\Marketing\CustomerAccountFence::class)->forTenant($tenant,
            fn () => app(CandleCashService::class)->addRewardAmountIdempotent($profile, 5, 'gift', 'concurrent-preview-gift')['balance']),
        function () use ($tenant, $operation) {
            try {
                app(\App\Services\Marketing\CustomerAccountFence::class)->forTenant($tenant,
                    fn () => app(CustomerMergeService::class)->assertCurrentPreview($operation));

                return 'accepted';
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                if ($error->getStatusCode() !== 409) {
                    throw $error;
                }

                return 'review';
            }
        },
        fn ($query) => str_contains($query->sql, 'customer_account_fences') && str_contains($query->sql, 'for update')
    );
    expect($results[1])->toBe('review')->and(app(CandleCashService::class)->currentBalance($profile))->toBe(5.0);
});
