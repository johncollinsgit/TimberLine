<?php

use App\Models\Event;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use App\Services\Pouring\PouringQueueService;
use Illuminate\Support\Str;

it('aggregates all-candles rows by scent across sizes in combined mode', function (): void {
    $scent = Scent::query()->create([
        'name' => 'Coffeehouse '.Str::uuid(),
        'display_name' => 'Coffeehouse',
        'is_active' => true,
    ]);

    $size16 = Size::query()->create([
        'code' => '16oz-'.Str::uuid(),
        'label' => '16oz',
        'is_active' => true,
    ]);

    $size8 = Size::query()->create([
        'code' => '8oz-'.Str::uuid(),
        'label' => '8oz',
        'is_active' => true,
    ]);

    $order = Order::factory()->create([
        'order_type' => 'event',
        'status' => 'submitted_to_pouring',
        'published_at' => now(),
        'due_at' => now()->addDays(4),
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size16->id,
        'scent_name' => 'Coffeehouse',
        'size_code' => '16oz',
        'quantity' => 4,
        'ordered_qty' => 4,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size8->id,
        'scent_name' => 'Coffeehouse',
        'size_code' => '8oz',
        'quantity' => 8,
        'ordered_qty' => 8,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $rows = app(PouringQueueService::class)->allCandles([
        'channel' => 'all',
        'due_window' => 'all',
        'batch_mode' => 'all_markets_combined',
    ]);

    expect($rows)->toHaveCount(1);

    $row = $rows->first();

    expect((int) ($row['units'] ?? 0))->toBe(12)
        ->and(collect($row['size_rows'] ?? [])->count())->toBe(2)
        ->and((string) ($row['size_summary'] ?? ''))->toContain('×16')
        ->and((string) ($row['size_summary'] ?? ''))->toContain('×8');
});

it('splits event scents by market in by-market mode and merges in combined mode', function (): void {
    $scent = Scent::query()->create([
        'name' => 'Nightfall '.Str::uuid(),
        'display_name' => 'Nightfall',
        'is_active' => true,
    ]);

    $size16 = Size::query()->create([
        'code' => '16oz-'.Str::uuid(),
        'label' => '16oz',
        'is_active' => true,
    ]);

    $eventA = Event::query()->create([
        'name' => 'Market A',
        'display_name' => 'Market A 2026',
        'starts_at' => now()->addDays(7)->toDateString(),
        'ends_at' => now()->addDays(7)->toDateString(),
        'status' => 'needs_mapping',
    ]);

    $eventB = Event::query()->create([
        'name' => 'Market B',
        'display_name' => 'Market B 2026',
        'starts_at' => now()->addDays(9)->toDateString(),
        'ends_at' => now()->addDays(9)->toDateString(),
        'status' => 'needs_mapping',
    ]);

    $orderA = Order::factory()->create([
        'order_type' => 'event',
        'event_id' => $eventA->id,
        'status' => 'submitted_to_pouring',
        'published_at' => now(),
        'due_at' => now()->addDays(5),
    ]);

    $orderB = Order::factory()->create([
        'order_type' => 'event',
        'event_id' => $eventB->id,
        'status' => 'submitted_to_pouring',
        'published_at' => now(),
        'due_at' => now()->addDays(6),
    ]);

    OrderLine::query()->create([
        'order_id' => $orderA->id,
        'scent_id' => $scent->id,
        'size_id' => $size16->id,
        'scent_name' => 'Nightfall',
        'size_code' => '16oz',
        'quantity' => 2,
        'ordered_qty' => 2,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    OrderLine::query()->create([
        'order_id' => $orderB->id,
        'scent_id' => $scent->id,
        'size_id' => $size16->id,
        'scent_name' => 'Nightfall',
        'size_code' => '16oz',
        'quantity' => 3,
        'ordered_qty' => 3,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    $byMarket = app(PouringQueueService::class)->allCandles([
        'channel' => 'event',
        'due_window' => 'all',
        'batch_mode' => 'by_market',
    ]);

    $combined = app(PouringQueueService::class)->allCandles([
        'channel' => 'event',
        'due_window' => 'all',
        'batch_mode' => 'all_markets_combined',
    ]);

    expect($byMarket)->toHaveCount(2)
        ->and($combined)->toHaveCount(1)
        ->and((int) ($combined->first()['units'] ?? 0))->toBe(5);
});
