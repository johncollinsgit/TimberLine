<?php

use App\Actions\ScentGovernance\CreateScentAction;
use App\Livewire\Analytics\AnalyticsWidgets;
use App\Models\BaseOil;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Size;
use App\Models\User;
use Livewire\Livewire;

it('opens unmapped exceptions drilldown with action links and trend section', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    \DB::table('mapping_exceptions')->insert([
        'store_key' => 'retail-main',
        'raw_title' => 'Drilldown Mystery',
        'raw_scent_name' => 'Drilldown Mystery',
        'resolved_at' => null,
        'excluded_at' => null,
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $this->actingAs($user);

    Livewire::test(AnalyticsWidgets::class)
        ->call('openDrilldown', 'unmapped_exceptions', 'current')
        ->assertSet('showDrilldown', true)
        ->assertSet('drilldownWidget', 'unmapped_exceptions')
        ->assertSee('Unmapped Exceptions Detail')
        ->assertSee('Open Scent Intake')
        ->assertSee('Unmapped Exceptions Trend')
        ->assertSee('Raw Name');
});

it('shows oil reorder risk drilldown with contributors and inventory action', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $oil = BaseOil::query()->create([
        'name' => 'Drilldown Risk Oil',
        'grams_on_hand' => 80,
        'reorder_threshold' => 200,
    ]);
    $size = Size::query()->create([
        'code' => '16oz Cotton Wick',
        'label' => '16oz Cotton Wick',
        'is_active' => true,
    ]);
    $scent = app(CreateScentAction::class)->execute([
        'name' => 'drilldown current scent',
        'display_name' => 'Drilldown Current Scent',
        'lifecycle_status' => 'active',
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
    ]);

    $order = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'DR-100',
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
        'quantity' => 6,
        'ordered_qty' => 6,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $this->actingAs($user);

    Livewire::test(AnalyticsWidgets::class)
        ->call('openDrilldown', 'oil_reorder_risk', 'current')
        ->assertSee('Current Oil Reorder Risk Detail')
        ->assertSee('Open Inventory')
        ->assertSee('Current Oil Demand Trend');
});

it('preserves state and timeframe labels in top scents drilldown', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $oil = BaseOil::query()->create(['name' => 'Drilldown Label Oil']);
    $size = Size::query()->create([
        'code' => '16oz Cotton Wick',
        'label' => '16oz Cotton Wick',
        'is_active' => true,
    ]);
    $scent = app(CreateScentAction::class)->execute([
        'name' => 'drilldown label scent',
        'display_name' => 'Drilldown Label Scent',
        'lifecycle_status' => 'active',
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
    ]);

    $order = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'DL-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'published_at' => '2026-03-04 10:00:00',
        'due_at' => '2026-03-04 10:00:00',
    ]);
    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => $scent->name,
        'size_code' => $size->code,
        'quantity' => 5,
        'ordered_qty' => 5,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $this->actingAs($user);

    Livewire::test(AnalyticsWidgets::class)
        ->set('preset', 'custom')
        ->set('customStartDate', '2026-03-01')
        ->set('customEndDate', '2026-03-07')
        ->set('comparisonMode', 'previous_period')
        ->call('applyFilters')
        ->call('openDrilldown', 'top_scents_current', 'current')
        ->assertSee('Top Scents (Current)')
        ->assertSee('State: current')
        ->assertSee('Primary:')
        ->assertSee('Compare:')
        ->assertSee('Scent Demand Trend')
        ->assertSee('Drilldown Label Scent');
});

it('shows top oils forecast drilldown with contributor expansion and actions', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $oil = BaseOil::query()->create(['name' => 'Forecast Drilldown Oil']);
    $size = Size::query()->create([
        'code' => '8oz Cotton Wick',
        'label' => '8oz Cotton Wick',
        'is_active' => true,
    ]);
    $scent = app(CreateScentAction::class)->execute([
        'name' => 'forecast drilldown scent',
        'display_name' => 'Forecast Drilldown Scent',
        'lifecycle_status' => 'active',
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
    ]);

    $order = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'FD-100',
        'order_type' => 'retail',
        'status' => 'reviewed',
        'published_at' => null,
        'due_at' => now()->addDay(),
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

    $this->actingAs($user);

    Livewire::test(AnalyticsWidgets::class)
        ->call('openDrilldown', 'top_oils_forecast', 'forecast')
        ->assertSee('Top Oils by Forecast Demand Detail')
        ->assertSee('Oil Contributors')
        ->assertSee('Open Wholesale Custom')
        ->assertSee('Forecast Oil Trend');
});
