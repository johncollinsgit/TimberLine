<?php

use App\Models\SquareOrder;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\Marketing\SquareMarketingSyncService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\Support\PosProcessRace;

uses(Tests\TestCase::class);

beforeEach(function () {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Explicit disposable MySQL source-marker acceptance only.');
    }
    if (! app()->environment('testing') || getenv('TEAM_WORK_DISPOSABLE_MYSQL') !== '1' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1'
        || ! str_starts_with($db->getDatabaseName(), 'codex_teams_') || ! in_array($db->getConfig('host'), ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('Requires explicitly authorized local codex_teams_ MySQL.');
    }
    if (! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Independent PHP processes required.');
    }
    DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    Http::preventStrayRequests();
});

test('MySQL scheduled source upsert preserves a historical marker committed by the competing manual writer', function (bool $projectedCommandModel) {
    $tenant = Tenant::create(['name' => 'Fictional historical source company', 'slug' => 'square-history-race-'.Str::lower(Str::random(12))]);
    $sourceId = 'FICTORDER'.strtoupper(bin2hex(random_bytes(8)));
    $binding = ['merchant_id' => 'FICTMERCHANT'.strtoupper(bin2hex(random_bytes(8))), 'environment' => 'sandbox',
        'connection_id' => 'square-source:'.Str::uuid(), 'connection_generation' => 1, 'location_ids' => []];
    TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'square_config', 'value' => [
        'merchant_id' => $binding['merchant_id'], 'environment' => 'sandbox', 'connection_id' => $binding['connection_id'], 'generation' => 1,
        'location_ids' => [], 'access_token' => 'fictional-no-provider-source', 'expires_at' => now()->addDay()->toIso8601String(), 'status' => 'active',
    ]]);
    $original = SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => $sourceId, 'state' => 'COMPLETED',
        'total_money_amount' => 1234, 'total_money_currency' => 'USD', 'raw_payload' => ['id' => $sourceId, '_source_binding' => $binding]]);
    $stale = $projectedCommandModel
        ? SquareOrder::whereKey($original->id)->firstOrFail(['id', 'square_order_id', 'square_customer_id', 'source_name', 'raw_tax_names', 'raw_payload'])
        : $original;
    $marker = ['historical_only' => true, 'reward_earning_authority' => false, 'reward_redemption_authority' => false];
    $results = PosProcessRace::run(
        fn () => app(CustomerAccountFence::class)->forTenant($tenant->id, function () use ($original, $marker): string {
            $current = SquareOrder::whereKey($original->id)->lockForUpdate()->firstOrFail();
            $current->update(['raw_payload' => [...$current->raw_payload, '_everbranch_source_import' => $marker]]);

            return 'historical_marked';
        }),
        function () use ($tenant, $sourceId, $binding): string {
            // Exercise the actual scheduled source write, without any attribution,
            // provider or reward follow-up. A plain stale read would lose the marker.
            $method = new ReflectionMethod(SquareMarketingSyncService::class, 'upsertSquareOrder');
            $result = $method->invoke(app(SquareMarketingSyncService::class), ['id' => $sourceId, 'state' => 'COMPLETED',
                'total_money' => ['amount' => 1234, 'currency' => 'USD'], '_source_binding' => $binding], false, $tenant->id);

            return $result['action'];
        },
        fn ($query) => preg_match('/update [`"]square_orders[`"]/i', $query->sql)
            && collect($query->bindings)->contains(fn ($value) => is_string($value) && str_contains($value, 'historical_only')),
    );
    expect($results)->toBe(['historical_marked', 'updated'])
        ->and(data_get($original->fresh()->raw_payload, '_everbranch_source_import.historical_only'))->toBeTrue()
        ->and($original->fresh()->total_money_amount)->toBe(1234);
    $summary = app(CandleCashRedemptionReconciliationService::class)->reconcileSquareOrder($stale, ['tenant_id' => $tenant->id, 'codes' => ['CANDLE-FICTIONAL-HISTORY']]);
    expect($summary['historical_source_skipped'] ?? 0)->toBe(1)->and($summary['reconciled'])->toBe(0);
    Http::assertNothingSent();
})->with(['full original model' => false, 'projected CLI model without tenant_id' => true]);
