<?php

use App\Livewire\Admin\Imports\ImportExceptions;
use App\Models\MappingException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeFormException(string $rawTitle, string $rawVariant): array
{
    $order = Order::query()->create([
        'order_type' => 'retail',
        'source' => 'shopify',
        'order_number' => 'RT-9001',
        'status' => 'new',
    ]);

    $line = OrderLine::query()->create([
        'order_id' => $order->id,
        'raw_title' => $rawTitle,
        'raw_variant' => $rawVariant,
        'scent_id' => null,
        'size_id' => null,
        'size_code' => null,
        'wick_type' => 'cotton',
        'ordered_qty' => 1,
        'quantity' => 1,
        'extra_qty' => 0,
    ]);

    $exception = MappingException::query()->create([
        'store_key' => 'retail',
        'order_id' => $order->id,
        'order_line_id' => $line->id,
        'raw_title' => $rawTitle,
        'raw_variant' => $rawVariant,
        'raw_scent_name' => 'Lavender',
        'reason' => null,
        'payload_json' => [],
    ]);

    return [$line, $exception];
}

test('legacy mapping saveGroup maps room spray context through size and clears wick', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $target = Scent::query()->create([
        'name' => 'lavender',
        'display_name' => 'Lavender',
        'is_active' => true,
    ]);

    $roomSpray = Size::query()->firstOrCreate(
        ['code' => 'room-sprays'],
        [
            'label' => 'Room Sprays',
            'is_active' => true,
            'sort_order' => 10,
        ]
    );

    [$line, $exception] = makeFormException('Room Sprays', 'Lavender');

    Livewire::test(ImportExceptions::class)
        ->call('openModalForLine', 'line-'.$exception->id, $exception->id)
        ->set('matchScentId', $target->id)
        ->call('saveGroup');

    $line->refresh();
    $exception->refresh();

    expect((int) $line->scent_id)->toBe((int) $target->id);
    expect((int) $line->size_id)->toBe((int) $roomSpray->id);
    expect((string) $line->size_code)->toBe('room-sprays');
    expect($line->wick_type)->toBeNull();
    expect((int) $exception->canonical_scent_id)->toBe((int) $target->id);
    expect($exception->resolved_at)->not->toBeNull();
});

test('legacy mapping saveGroup maps wax melt context through size and clears wick', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $target = Scent::query()->create([
        'name' => 'lavender',
        'display_name' => 'Lavender',
        'is_active' => true,
    ]);

    $waxMelt = Size::query()->firstOrCreate(
        ['code' => 'wax-melts'],
        [
            'label' => 'Wax Melts',
            'is_active' => true,
            'sort_order' => 11,
        ]
    );

    [$line, $exception] = makeFormException('Wax Melts', 'Lavender');

    Livewire::test(ImportExceptions::class)
        ->call('openModalForLine', 'line-'.$exception->id, $exception->id)
        ->set('matchScentId', $target->id)
        ->call('saveGroup');

    $line->refresh();
    $exception->refresh();

    expect((int) $line->scent_id)->toBe((int) $target->id);
    expect((int) $line->size_id)->toBe((int) $waxMelt->id);
    expect((string) $line->size_code)->toBe('wax-melts');
    expect($line->wick_type)->toBeNull();
    expect((int) $exception->canonical_scent_id)->toBe((int) $target->id);
    expect($exception->resolved_at)->not->toBeNull();
});
