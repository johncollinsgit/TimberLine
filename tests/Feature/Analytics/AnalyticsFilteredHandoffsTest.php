<?php

use App\Actions\ScentGovernance\CreateScentAction;
use App\Livewire\Admin\Catalog\ScentsCrud;
use App\Livewire\Admin\MappingExceptions;
use App\Livewire\Inventory\Index as InventoryIndex;
use App\Livewire\PouringRoom\AllCandles;
use App\Livewire\PouringRoom\StackOrders;
use App\Models\BaseOil;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Size;
use App\Models\User;
use App\Services\Reporting\AnalyticsDrilldownService;
use App\Services\Reporting\AnalyticsTimeframeService;
use App\Services\Reporting\DemandReportingService;
use App\Services\Reporting\InventoryReportingService;
use App\Services\Reporting\ScentAnalyticsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('builds filtered drilldown handoff urls with preserved analytics context', function () {
    $oil = BaseOil::query()->create([
        'name' => 'Handoff Oil',
        'grams_on_hand' => 120,
        'reorder_threshold' => 250,
    ]);

    $size = Size::query()->create([
        'code' => '8oz Cotton Wick',
        'label' => '8oz Cotton Wick',
        'is_active' => true,
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'handoff scent',
        'display_name' => 'Handoff Scent',
        'lifecycle_status' => 'active',
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
    ]);

    $order = Order::query()->create([
        'source' => 'retail',
        'order_number' => 'HO-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'published_at' => now()->subHour(),
        'due_at' => now()->subHour(),
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 4,
        'ordered_qty' => 4,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $forecastOrder = Order::query()->create([
        'source' => 'retail',
        'order_number' => 'HO-101',
        'order_type' => 'retail',
        'status' => 'reviewed',
        'published_at' => null,
        'due_at' => now()->addDay(),
    ]);

    OrderLine::query()->create([
        'order_id' => $forecastOrder->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 3,
        'ordered_qty' => 3,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    \DB::table('mapping_exceptions')->insert([
        'store_key' => 'wholesale-main',
        'account_name' => 'Erin Nutz',
        'raw_title' => 'Vintage Amber 8oz',
        'raw_scent_name' => 'Vintage Amber',
        'resolved_at' => null,
        'excluded_at' => null,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $timeframe = app(AnalyticsTimeframeService::class)->resolve([
        'time_mode' => 'fixed',
        'preset' => 'custom',
        'custom_start_date' => now()->subDay()->toDateString(),
        'custom_end_date' => now()->addDays(2)->toDateString(),
        'comparison_mode' => 'previous_period',
    ]);

    $service = app(AnalyticsDrilldownService::class);

    $unmapped = $service->build('unmapped_exceptions', $timeframe, 'wholesale');
    $unmappedUrl = (string) data_get($unmapped, 'details.rows.0.handoff_url', '');
    expect($unmappedUrl)->not->toBe('');
    parse_str((string) parse_url($unmappedUrl, PHP_URL_QUERY), $unmappedQuery);
    expect($unmappedQuery['filter'] ?? null)->toBe('wholesale');
    expect($unmappedQuery['raw'] ?? null)->toBe('Vintage Amber');
    expect($unmappedQuery['source_widget'] ?? null)->toBe('unmapped_exceptions');
    expect($unmappedQuery['analytics_preset'] ?? null)->toBe('custom');

    $topScents = $service->build('top_scents_current', $timeframe, 'retail');
    $topScentsUrl = (string) data_get($topScents, 'bundle.primary.rows.0.handoff_url', '');
    expect($topScentsUrl)->not->toBe('');
    parse_str((string) parse_url($topScentsUrl, PHP_URL_QUERY), $topScentsQuery);
    expect($topScentsQuery['scent'] ?? null)->toBe('Handoff Scent');
    expect($topScentsQuery['source_widget'] ?? null)->toBe('top_scents');
    expect($topScentsQuery['analytics_state'] ?? null)->toBe('current');

    $overview = $service->build('demand_state_overview', $timeframe, 'wholesale');
    expect((string) ($overview['state_handoffs']['forecast'] ?? ''))->toContain('queue=wholesale');
    expect((string) ($overview['state_handoffs']['current'] ?? ''))->toContain('/pouring/stack/wholesale');
    expect((string) ($overview['state_handoffs']['actual'] ?? ''))->toContain('state=actual');
});

it('generates oil row handoff urls for top oils drilldown rows', function () {
    $timeframe = app(AnalyticsTimeframeService::class)->resolve([
        'time_mode' => 'rolling',
        'preset' => 'last_30_days',
        'comparison_mode' => 'none',
    ]);

    $demand = \Mockery::mock(DemandReportingService::class);
    $demand->shouldReceive('explodedOilDemandWithComparison')->once()->andReturn([
        'state' => 'forecast',
        'channel' => 'retail',
        'timeframe' => $timeframe,
        'primary' => [
            'rows' => [
                [
                    'base_oil_id' => 77,
                    'base_oil_name' => 'Vintage Amber Base',
                    'grams' => 321.5,
                    'percent_of_total' => 100,
                ],
            ],
        ],
        'comparison' => null,
        'delta' => ['metrics' => []],
    ]);
    $demand->shouldReceive('oilContributorsWithComparison')->once()->andReturn(['primary' => ['rows' => []]]);
    $demand->shouldReceive('trendSeries')->once()->andReturn([]);

    app()->instance(DemandReportingService::class, $demand);
    app()->instance(InventoryReportingService::class, \Mockery::mock(InventoryReportingService::class));
    app()->instance(ScentAnalyticsService::class, \Mockery::mock(ScentAnalyticsService::class));

    $service = app(AnalyticsDrilldownService::class);
    $detail = $service->build('top_oils_forecast', $timeframe, 'retail');
    $url = (string) data_get($detail, 'bundle.primary.rows.0.handoff_url', '');
    expect($url)->not->toBe('');

    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    expect((int) ($query['oil'] ?? 0))->toBe(77);
    expect($query['materialSearch'] ?? null)->toBe('Vintage Amber Base');
    expect($query['source_widget'] ?? null)->toBe('top_oils_forecast');
});

it('hydrates destination filters from analytics handoff query params', function () {
    $oil = BaseOil::query()->create([
        'name' => 'Hydrate Oil',
        'grams_on_hand' => 75,
        'reorder_threshold' => 200,
    ]);

    Livewire::withQueryParams([
        'scent' => 'Vintage Amber',
    ])->test(ScentsCrud::class)
        ->assertSet('scent', 'Vintage Amber')
        ->assertSet('search', 'Vintage Amber');

    Livewire::withQueryParams([
        'oil' => (string) $oil->id,
    ])->test(InventoryIndex::class)
        ->assertSet('focusOilId', $oil->id)
        ->assertSet('materialSearch', 'Hydrate Oil');

    $mapping = Livewire::withQueryParams([
        'channel' => 'wholesale',
        'raw' => 'Custom Scent',
        'account' => 'Erin Nutz',
        'store' => 'wholesale-main',
    ])->test(MappingExceptions::class)
        ->assertSet('filter', 'wholesale')
        ->assertSet('raw', 'Custom Scent')
        ->assertSet('account', 'Erin Nutz')
        ->assertSet('store', 'wholesale-main');

    expect((string) $mapping->get('search'))->toContain('Custom Scent');

    Livewire::withQueryParams([
        'state' => 'actual',
    ])->test(StackOrders::class, ['channel' => 'retail'])
        ->assertSet('state', 'actual')
        ->assertSet('channel', 'retail');

    Livewire::withQueryParams([
        'channel' => 'wholesale',
        'state' => 'current',
    ])->test(AllCandles::class)
        ->assertSet('channel', 'wholesale')
        ->assertSet('state', 'current');
});

it('preserves scent intake redirect query params for analytics handoffs', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('/admin/scent-intake?raw=Custom%20Scent&filter=wholesale&analytics_preset=last_30_days&source_widget=unmapped_exceptions')
        ->assertRedirect(route('admin.index', [
            'tab' => 'scent-intake',
            'raw' => 'Custom Scent',
            'filter' => 'wholesale',
            'analytics_preset' => 'last_30_days',
            'source_widget' => 'unmapped_exceptions',
        ]));
});
