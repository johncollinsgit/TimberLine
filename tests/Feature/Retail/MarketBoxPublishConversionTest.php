<?php

use App\Livewire\Retail\Plan as RetailPlanComponent;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('publishing a markets box plan expands box qty into individual pour units', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);

    $scent = Scent::query()->create([
        'name' => 'Test Scent',
        'display_name' => 'Test Scent',
        'is_active' => true,
    ]);
    $event = Event::query()->create([
        'name' => 'Spring Market',
        'display_name' => 'Spring Market 2026',
        'starts_at' => now()->addWeek()->toDateString(),
        'ends_at' => now()->addWeek()->toDateString(),
        'city' => 'Nashville',
        'state' => 'TN',
        'source' => 'asana_calendar',
        'source_ref' => 'market-box-test-1',
        'status' => 'needs_mapping',
    ]);

    $size16 = Size::query()->firstOrCreate(
        ['code' => '16oz-cotton'],
        ['label' => '16 oz Cotton', 'is_active' => true]
    );

    $size8 = Size::query()->firstOrCreate(
        ['code' => '8oz-cotton'],
        ['label' => '8 oz Cotton', 'is_active' => true]
    );

    $waxMelt = Size::query()->firstOrCreate(
        ['code' => 'wax-melts'],
        ['label' => 'Wax Melts', 'is_active' => true]
    );

    $component = Livewire::test(RetailPlanComponent::class, ['queue' => 'markets'])
        ->call('addMarketFullBox', $scent->id, $event->id)
        ->call('publishPlan');

    $marketOrder = Order::query()
        ->where('order_number', 'like', 'MKT-BOX-%')
        ->latest('id')
        ->first();

    expect($marketOrder)->not->toBeNull();
    expect($marketOrder->status)->toBe('submitted_to_pouring');

    $lines = OrderLine::query()
        ->where('order_id', $marketOrder->id)
        ->get()
        ->keyBy('size_id');

    expect($lines)->toHaveCount(3);
    expect((int) $lines[$size16->id]->ordered_qty)->toBe(4);
    expect((int) $lines[$size8->id]->ordered_qty)->toBe(8);
    expect((int) $lines[$waxMelt->id]->ordered_qty)->toBe(8);
    expect((int) $lines[$waxMelt->id]->quantity)->toBe(8);

    $component->assertDispatched('toast');
});

test('publishing markets plan twice does not duplicate market box orders', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user);

    $scent = Scent::query()->create([
        'name' => 'Repeat Guard',
        'display_name' => 'Repeat Guard',
        'is_active' => true,
    ]);
    $event = Event::query()->create([
        'name' => 'Summer Market',
        'display_name' => 'Summer Market 2026',
        'starts_at' => now()->addWeeks(2)->toDateString(),
        'ends_at' => now()->addWeeks(2)->toDateString(),
        'city' => 'Nashville',
        'state' => 'TN',
        'source' => 'asana_calendar',
        'source_ref' => 'market-box-test-2',
        'status' => 'needs_mapping',
    ]);

    Size::query()->firstOrCreate(
        ['code' => '16oz-cotton'],
        ['label' => '16 oz Cotton', 'is_active' => true]
    );
    Size::query()->firstOrCreate(
        ['code' => '8oz-cotton'],
        ['label' => '8 oz Cotton', 'is_active' => true]
    );
    Size::query()->firstOrCreate(
        ['code' => 'wax-melts'],
        ['label' => 'Wax Melts', 'is_active' => true]
    );

    Livewire::test(RetailPlanComponent::class, ['queue' => 'markets'])
        ->call('addMarketHalfBox', $scent->id, $event->id)
        ->call('publishPlan')
        ->call('publishPlan'); // second call publishes the new empty draft; should not duplicate the prior market box order

    expect(Order::query()->where('order_number', 'like', 'MKT-BOX-%')->count())->toBe(1);
    expect(OrderLine::query()->count())->toBe(3);
});
