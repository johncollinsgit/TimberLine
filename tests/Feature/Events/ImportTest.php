<?php

use App\Livewire\Events\Import;
use App\Models\Event;
use App\Models\EventShipment;
use App\Models\MarketPlan;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('events import backfills market plans from csv rows', function () {
    Scent::query()->firstOrCreate(['name' => 'Blue Ridge'], [
        'name' => 'Blue Ridge',
        'display_name' => 'Blue Ridge',
        'is_active' => true,
    ]);

    Size::query()->firstOrCreate(['code' => '16oz-cotton'], [
        'code' => '16oz-cotton',
        'label' => '16 oz Cotton',
        'is_active' => true,
    ]);

    $component = new Import();
    $method = new ReflectionMethod($component, 'importRows');
    $method->setAccessible(true);
    $report = $method->invoke($component, [[
        'name' => 'TR Winter Pop Up',
        'venue' => 'The Forestry',
        'city' => 'Nashville',
        'state' => 'TN',
        'starts_at' => '2025-02-08',
        'ends_at' => '2025-02-08',
        'status' => 'published',
        'scent' => 'Blue Ridge',
        'size' => '16oz-cotton',
        'planned_qty' => 2,
        'sent_qty' => 3,
    ]]);

    expect($report['events_created'])->toBe(1);
    expect($report['shipments_created'])->toBe(1);
    expect($report['market_plans_created'])->toBe(1);
    expect($report['market_plans_updated'])->toBe(0);

    $event = Event::query()->first();
    expect($event)->not->toBeNull();

    $shipment = EventShipment::query()->first();
    expect($shipment)->not->toBeNull();
    expect((int) $shipment->planned_qty)->toBe(2);
    expect((int) $shipment->sent_qty)->toBe(3);

    $marketPlan = MarketPlan::query()->first();
    expect($marketPlan)->not->toBeNull();
    expect((string) $marketPlan->event_title)->toBe('TR Winter Pop Up');
    expect($marketPlan->event_date?->toDateString())->toBe('2025-02-08');
    expect((string) $marketPlan->normalized_title)->toBe('tr winter pop up');
    expect((string) $marketPlan->scent)->toBe('Blue Ridge');
    expect((int) $marketPlan->box_count)->toBe(3);
    expect((string) $marketPlan->box_type)->toBe('full');
    expect((string) $marketPlan->status)->toBe('published');
});

test('events import updates existing market plan rows instead of duplicating them', function () {
    Scent::query()->firstOrCreate(['name' => 'Blue Ridge'], [
        'name' => 'Blue Ridge',
        'display_name' => 'Blue Ridge',
        'is_active' => true,
    ]);

    Size::query()->firstOrCreate(['code' => '16oz-cotton'], [
        'code' => '16oz-cotton',
        'label' => '16 oz Cotton',
        'is_active' => true,
    ]);

    $component = new Import();
    $method = new ReflectionMethod($component, 'importRows');
    $method->setAccessible(true);

    $method->invoke($component, [[
        'name' => 'TR Winter Pop Up',
        'starts_at' => '2025-02-08',
        'status' => 'published',
        'scent' => 'Blue Ridge',
        'size' => '16oz-cotton',
        'planned_qty' => 2,
        'sent_qty' => 3,
    ]]);

    $report = $method->invoke($component, [[
        'name' => 'TR Winter Pop Up',
        'starts_at' => '2025-02-08',
        'status' => 'published',
        'scent' => 'Blue Ridge',
        'size' => '16oz-cotton',
        'planned_qty' => 2,
        'sent_qty' => 5,
    ]]);

    expect($report['market_plans_created'])->toBe(0);
    expect($report['market_plans_updated'])->toBe(1);

    expect(MarketPlan::query()->count())->toBe(1);
    expect((int) MarketPlan::query()->first()->box_count)->toBe(5);
});
