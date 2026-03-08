<?php

use App\Actions\ScentGovernance\CreateScentAction;
use App\Models\BaseOil;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Size;
use App\Models\User;
use App\Services\ScentGovernance\ScentRecipeService;

it('renders analytics widgets page with block-9 reporting widgets and explicit state labels', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertSee('Unmapped Exceptions Summary')
        ->assertSee('Top Scents by Forecast Demand')
        ->assertSee('Top Scents by Current/Open Demand')
        ->assertSee('Top Scents by Actual Usage')
        ->assertSee('Top Oils by Forecast Demand')
        ->assertSee('Current Oil Reorder Risk')
        ->assertSee('Wax Reorder Risk')
        ->assertSee('Inventory Snapshot')
        ->assertSee('forecast')
        ->assertSee('current')
        ->assertSee('actual');
});

it('shows reporting data signals from service-backed widgets', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $oil = BaseOil::query()->create(['name' => 'Widget Oil']);
    $size = Size::query()->create([
        'code' => '8oz Cotton Wick',
        'label' => '8oz Cotton Wick',
        'is_active' => true,
    ]);

    $scent = app(CreateScentAction::class)->execute([
        'name' => 'widget signal scent',
        'display_name' => 'Widget Signal Scent',
        'lifecycle_status' => 'active',
    ]);

    app(ScentRecipeService::class)->syncActiveRecipeForScent($scent, [
        'recipe_components' => [
            ['component_type' => 'oil', 'base_oil_id' => $oil->id, 'parts' => 1],
        ],
        'source_context' => 'analytics-widget-test',
        'lifecycle_status' => 'active',
    ], true);

    $order = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'W-100',
        'order_type' => 'retail',
        'status' => 'submitted_to_pouring',
        'published_at' => now(),
        'due_at' => now()->addDays(2),
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

    \DB::table('mapping_exceptions')->insert([
        'store_key' => 'retail-main',
        'raw_title' => 'Widget Mystery',
        'raw_scent_name' => 'Widget Mystery',
        'resolved_at' => null,
        'excluded_at' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(route('analytics.index'))
        ->assertOk()
        ->assertSee('Widget Signal Scent')
        ->assertSee('Widget Oil')
        ->assertSee('Widget Mystery')
        ->assertSee('Open Mapping Resolution');
});
