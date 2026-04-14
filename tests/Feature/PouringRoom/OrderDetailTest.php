<?php

use App\Livewire\PouringRoom\OrderDetail;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Support\Str;
use Livewire\Livewire;

it('groups order detail rows by scent for compact summary', function (): void {
    $order = Order::factory()->create([
        'status' => 'submitted_to_pouring',
        'order_type' => 'event',
        'container_name' => 'Market: Spring Event',
    ]);

    $coffeehouse = Scent::query()->create([
        'name' => 'Coffeehouse '.Str::uuid(),
        'display_name' => 'Coffeehouse',
        'is_active' => true,
    ]);

    $nightfall = Scent::query()->create([
        'name' => 'Nightfall '.Str::uuid(),
        'display_name' => 'Nightfall',
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

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $coffeehouse->id,
        'size_id' => $size16->id,
        'scent_name' => 'Coffeehouse',
        'size_code' => '16oz',
        'quantity' => 2,
        'ordered_qty' => 2,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $coffeehouse->id,
        'size_id' => $size8->id,
        'scent_name' => 'Coffeehouse',
        'size_code' => '8oz',
        'quantity' => 3,
        'ordered_qty' => 3,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $nightfall->id,
        'size_id' => $size16->id,
        'scent_name' => 'Nightfall',
        'size_code' => '16oz',
        'quantity' => 1,
        'ordered_qty' => 1,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    Livewire::test(OrderDetail::class, ['order' => $order])
        ->assertViewHas('scentRows', function ($rows) use ($coffeehouse, $nightfall): bool {
            if (! $rows instanceof \Illuminate\Support\Collection) {
                return false;
            }

            if ($rows->count() !== 2) {
                return false;
            }

            $coffeeRow = $rows->firstWhere('scent_id', $coffeehouse->id);
            $nightfallRow = $rows->firstWhere('scent_id', $nightfall->id);

            if (! is_array($coffeeRow) || ! is_array($nightfallRow)) {
                return false;
            }

            return count($coffeeRow['details'] ?? []) === 2
                && count($nightfallRow['details'] ?? []) === 1;
        });
});

it('does not allow completing order until every scent is brought down', function (): void {
    $order = Order::factory()->create([
        'status' => 'submitted_to_pouring',
        'order_type' => 'event',
    ]);

    $scent = Scent::query()->create([
        'name' => 'River Birch '.Str::uuid(),
        'display_name' => 'River Birch',
        'is_active' => true,
    ]);

    $size = Size::query()->create([
        'code' => '16oz-'.Str::uuid(),
        'label' => '16oz',
        'is_active' => true,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => 'River Birch',
        'size_code' => '16oz',
        'quantity' => 2,
        'ordered_qty' => 2,
        'extra_qty' => 0,
        'pour_status' => 'first_pour',
    ]);

    Livewire::test(OrderDetail::class, ['order' => $order])
        ->call('complete');

    expect($order->fresh()->status)->toBe('submitted_to_pouring');

    Livewire::test(OrderDetail::class, ['order' => $order])
        ->set("scentStatuses.scent_{$scent->id}", 'brought_down')
        ->call('saveScentStatuses')
        ->call('complete');

    expect($order->fresh()->status)->toBe('brought_down');
});

it('moves order into pouring when any scent status enters active flow', function (): void {
    $order = Order::factory()->create([
        'status' => 'submitted_to_pouring',
        'order_type' => 'event',
    ]);

    $scent = Scent::query()->create([
        'name' => 'Lavender '.Str::uuid(),
        'display_name' => 'Lavender',
        'is_active' => true,
    ]);

    $size = Size::query()->create([
        'code' => '8oz-'.Str::uuid(),
        'label' => '8oz',
        'is_active' => true,
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'scent_id' => $scent->id,
        'size_id' => $size->id,
        'scent_name' => 'Lavender',
        'size_code' => '8oz',
        'quantity' => 4,
        'ordered_qty' => 4,
        'extra_qty' => 0,
        'pour_status' => 'queued',
    ]);

    Livewire::test(OrderDetail::class, ['order' => $order])
        ->set("scentStatuses.scent_{$scent->id}", 'laid_out')
        ->call('saveScentStatuses');

    expect($order->fresh()->status)->toBe('pouring');
    expect(
        OrderLine::query()->where('order_id', $order->id)->first()?->pour_status
    )->toBe('laid_out');
});

it('accepts configured legacy landlord absolute return_to host during dual-domain transition', function (): void {
    config()->set('app.url', 'https://app.grovebud.com');
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');
    config()->set('tenancy.landlord.hosts', ['app.grovebud.com', 'app.forestrybackstage.com']);
    config()->set('tenancy.auth.flagship_hosts', ['app.grovebud.com', 'app.forestrybackstage.com']);

    $order = Order::factory()->create([
        'status' => 'submitted_to_pouring',
        'order_type' => 'event',
    ]);

    Livewire::withQueryParams([
        'return_to' => 'https://app.forestrybackstage.com/retail/plan?queue=retail',
    ])->test(OrderDetail::class, ['order' => $order])
        ->assertSet('returnTo', 'https://app.forestrybackstage.com/retail/plan?queue=retail');
});

it('rejects unknown absolute return_to host', function (): void {
    config()->set('app.url', 'https://app.grovebud.com');
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');
    config()->set('tenancy.landlord.hosts', ['app.grovebud.com', 'app.forestrybackstage.com']);
    config()->set('tenancy.auth.flagship_hosts', ['app.grovebud.com', 'app.forestrybackstage.com']);

    $order = Order::factory()->create([
        'status' => 'submitted_to_pouring',
        'order_type' => 'event',
    ]);

    Livewire::withQueryParams([
        'return_to' => 'https://unknown.example/retail/plan?queue=retail',
    ])->test(OrderDetail::class, ['order' => $order])
        ->assertSet('returnTo', null);
});
