<?php

use App\Models\MappingException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ShopifyImportException;
use App\Models\ShopifyImportRun;
use App\Models\Size;
use App\Services\Dashboard\DashboardMetrics;
use Carbon\CarbonImmutable;

it('builds due, unpublished, shipping, and import metrics from current data', function () {
    $today = CarbonImmutable::now()->startOfDay();

    $retailDueToday = Order::factory()->create([
        'order_type' => 'retail',
        'status' => 'new',
        'published_at' => null,
        'ship_by_at' => $today->copy()->hour(10),
        'created_at' => $today->subDay(),
    ]);

    $wholesaleReady = Order::factory()->create([
        'order_type' => 'wholesale',
        'status' => 'reviewed',
        'published_at' => null,
        'ship_by_at' => $today->addDay()->hour(10),
        'created_at' => $today->subDays(2),
        'requires_shipping_review' => false,
    ]);

    Order::factory()->create([
        'order_type' => 'retail',
        'status' => 'hold',
        'published_at' => null,
        'ship_by_at' => $today->addDay()->hour(12),
        'created_at' => $today->subDays(3),
        'requires_shipping_review' => true,
    ]);

    Order::factory()->create([
        'order_type' => 'retail',
        'status' => 'complete',
        'published_at' => $today,
        'ship_by_at' => $today,
        'created_at' => $today->subDays(5),
    ]);

    MappingException::query()->create([
        'store_key' => 'retail',
        'reason' => 'unmapped',
    ]);

    ShopifyImportRun::query()->create([
        'store_key' => 'retail',
        'imported_count' => 5,
        'updated_count' => 2,
        'started_at' => now()->subHours(3),
        'finished_at' => now()->subHours(2),
    ]);

    ShopifyImportException::query()->create([
        'shop' => 'retail',
        'reason' => 'bad payload',
        'title' => 'Failed order',
        'payload' => ['x' => 1],
        'created_at' => now()->subHour(),
        'updated_at' => now()->subHour(),
    ]);

    $snapshot = app(DashboardMetrics::class)->snapshot(7, 'all');

    expect($snapshot['todayAtGlance']['dueToday'])->toBe(1)
        ->and($snapshot['todayAtGlance']['dueNext3Days'])->toBeGreaterThanOrEqual(2)
        ->and($snapshot['todayAtGlance']['unpublishedOrders'])->toBe(3)
        ->and($snapshot['shippingQueue']['ready'])->toBe(1)
        ->and($snapshot['shippingQueue']['blocked'])->toBe(1)
        ->and($snapshot['importHealth']['ordersImportedLast24h'])->toBe(7)
        ->and($snapshot['importHealth']['importExceptionsLast24h'])->toBe(1)
        ->and($snapshot['importHealth']['mappingExceptionsOpen'])->toBe(1);

    $dueCustomerIds = collect($snapshot['dueWindow']['upcoming'])->pluck('id')->all();
    expect($dueCustomerIds)->toContain($retailDueToday->id)
        ->and($dueCustomerIds)->toContain($wholesaleReady->id);
});

it('calculates top scents and revenue estimates from order lines and size prices', function () {
    $today = CarbonImmutable::now();

    $size = Size::query()->firstOrCreate(
        ['code' => '8oz-cotton'],
        ['label' => '8oz Cotton Wick', 'is_active' => true, 'sort_order' => 1]
    );
    $size->update([
        'wholesale_price' => 10,
        'retail_price' => 20,
        'is_active' => true,
        'sort_order' => 1,
    ]);

    $scent = Scent::query()->firstOrCreate(
        ['name' => 'Autumn Leaves'],
        ['is_active' => true, 'sort_order' => 1]
    );

    $retailOrder = Order::factory()->create([
        'order_type' => 'retail',
        'status' => 'new',
        'created_at' => $today->subDays(2),
    ]);

    $wholesaleOrder = Order::factory()->create([
        'order_type' => 'wholesale',
        'status' => 'new',
        'created_at' => $today->subDays(2),
    ]);

    OrderLine::factory()->create([
        'order_id' => $retailOrder->id,
        'size_id' => $size->id,
        'scent_id' => $scent->id,
        'ordered_qty' => 3,
        'extra_qty' => 0,
        'quantity' => 3,
    ]);

    OrderLine::factory()->create([
        'order_id' => $wholesaleOrder->id,
        'size_id' => $size->id,
        'scent_id' => $scent->id,
        'ordered_qty' => 6,
        'extra_qty' => 0,
        'quantity' => 6,
    ]);

    $snapshot = app(DashboardMetrics::class)->snapshot(30, 'all');

    expect($snapshot['topScents']['byChannel']['retail'][0]['scent'])->toBe('Autumn Leaves')
        ->and($snapshot['topScents']['byChannel']['retail'][0]['qty'])->toBe(3)
        ->and($snapshot['topScents']['byChannel']['wholesale'][0]['qty'])->toBe(6)
        ->and($snapshot['revenue']['byChannel']['retail']['gross_30'])->toBe(60.0)
        ->and($snapshot['revenue']['byChannel']['wholesale']['gross_30'])->toBe(60.0);
});
