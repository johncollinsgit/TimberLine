<?php

use App\Livewire\Intake\ProgressiveMapper;
use App\Models\MappingException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\Size;
use App\Models\User;
use App\Models\WholesaleCustomScent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function makeWholesaleException(string $rawTitle, string $rawVariant, string $rawScentName, string $accountName, ?int $scentId = null): MappingException
{
    $order = Order::query()->create([
        'order_type' => 'wholesale',
        'source' => 'shopify',
        'order_number' => 'WH-1001',
        'status' => 'new',
    ]);

    $size = Size::query()->firstOrCreate(
        ['code' => '8oz-cotton'],
        ['label' => '8oz Cotton Wick', 'is_active' => true]
    );

    $line = OrderLine::query()->create([
        'order_id' => $order->id,
        'raw_title' => $rawTitle,
        'raw_variant' => $rawVariant,
        'scent_id' => $scentId,
        'size_id' => $size->id,
        'ordered_qty' => 6,
        'extra_qty' => 0,
    ]);

    return MappingException::query()->create([
        'store_key' => 'wholesale',
        'order_id' => $order->id,
        'order_line_id' => $line->id,
        'account_name' => $accountName,
        'raw_title' => $rawTitle,
        'raw_variant' => $rawVariant,
        'raw_scent_name' => $rawScentName,
        'reason' => null,
        'payload_json' => [],
    ]);
}

test('search results include wholesale custom mapping candidates', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $wholesaleBlend = Scent::query()->create([
        'name' => 'fresh coffee reserve',
        'display_name' => 'Fresh Coffee Reserve',
        'is_wholesale_custom' => true,
        'is_blend' => true,
        'is_active' => true,
    ]);

    WholesaleCustomScent::query()->create([
        'account_name' => 'Summit Goods',
        'custom_scent_name' => 'Morning Roast Club Blend',
        'canonical_scent_id' => $wholesaleBlend->id,
        'active' => true,
    ]);

    $exception = makeWholesaleException('Morning Roast Club Blend', '8oz Cotton Wick', 'Morning Roast Club Blend', 'Summit Goods');

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$exception->id]])
        ->set('existingScentSearch', 'Morning Roast Club Blend')
        ->assertSee('Fresh Coffee Reserve')
        ->assertSee('Wholesale Custom Blend');
});

test('search can find scents by partial phrase inside longer names', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    Scent::query()->create([
        'name' => 'march 2026 candle club — vintage amber',
        'display_name' => 'March 2026 Candle Club — Vintage Amber',
        'is_candle_club' => true,
        'is_active' => true,
    ]);

    $exception = makeWholesaleException('Custom Scent', '8oz Cotton Wick', 'Custom Scent', 'ERIN NUTZ');

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$exception->id]])
        ->set('existingScentSearch', 'vintage amber')
        ->assertSee('Vintage Amber')
        ->assertSee('Subscription Drop');
});

test('save maps selected scent and applies to same-name unresolved wholesale items when enabled', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $scent = Scent::query()->create([
        'name' => 'cedar smoke',
        'display_name' => 'Cedar Smoke',
        'is_wholesale_custom' => true,
        'is_active' => true,
    ]);

    $first = makeWholesaleException('Scent of the Month', '8oz Cotton Wick', 'Scent of the Month', 'Trail House');
    $second = makeWholesaleException('Scent of the Month', '8oz Cotton Wick', 'Scent of the Month', 'Trail House');

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$first->id]])
        ->set('selectedScentId', $scent->id)
        ->set('applySameName', true)
        ->call('save')
        ->assertDispatched('intake-done');

    expect(MappingException::query()->find($first->id)?->resolved_at)->not->toBeNull();
    expect(MappingException::query()->find($second->id)?->resolved_at)->not->toBeNull();

    expect(WholesaleCustomScent::query()
        ->where('account_name', 'Trail House')
        ->where('custom_scent_name', 'Scent of the Month')
        ->where('canonical_scent_id', $scent->id)
        ->exists())->toBeTrue();
});

test('save only maps current exception when same-name apply toggle is off', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $scent = Scent::query()->create([
        'name' => 'river birch',
        'display_name' => 'River Birch',
        'is_active' => true,
    ]);

    $first = makeWholesaleException('Scent of the Month', '8oz Cotton Wick', 'Scent of the Month', 'Pine House');
    $second = makeWholesaleException('Scent of the Month', '8oz Cotton Wick', 'Scent of the Month', 'Pine House');

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$first->id]])
        ->set('selectedScentId', $scent->id)
        ->set('applySameName', false)
        ->call('save')
        ->assertDispatched('intake-done');

    expect(MappingException::query()->find($first->id)?->resolved_at)->not->toBeNull();
    expect(MappingException::query()->find($second->id)?->resolved_at)->toBeNull();
});

test('press enter selects scent when search narrows to one match', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $target = Scent::query()->create([
        'name' => 'vintage amber reserve',
        'display_name' => 'Vintage Amber Reserve',
        'is_active' => true,
    ]);

    Scent::query()->create([
        'name' => 'forest trail',
        'display_name' => 'Forest Trail',
        'is_active' => true,
    ]);

    $exception = makeWholesaleException('Custom Scent', '8oz Cotton Wick', 'Custom Scent', 'ERIN NUTZ');

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$exception->id]])
        ->set('existingScentSearch', 'Vintage Amber Reserve')
        ->call('selectOnlyMatch')
        ->assertSet('selectedScentId', $target->id);
});

test('save handles database failures gracefully and dispatches error toast', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $target = Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_active' => true,
    ]);

    $exception = makeWholesaleException('Custom Scent', '8oz Cotton Wick', 'Custom Scent', 'ERIN NUTZ');

    DB::shouldReceive('transaction')
        ->once()
        ->andThrow(new RuntimeException('forced failure'));

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$exception->id]])
        ->set('selectedScentId', $target->id)
        ->call('save')
        ->assertDispatched('toast')
        ->assertNotDispatched('intake-done');
});

test('search finds vintage amber even when many alphabetically earlier scents exist', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $now = now();
    $bulkRows = [];
    for ($index = 1; $index <= 1305; $index++) {
        $label = str_pad((string) $index, 4, '0', STR_PAD_LEFT);
        $bulkRows[] = [
            'name' => 'alpha filler '.$label,
            'display_name' => 'Alpha Filler '.$label,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    foreach (array_chunk($bulkRows, 300) as $chunk) {
        Scent::query()->insert($chunk);
    }

    Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_wholesale_custom' => true,
        'is_active' => true,
    ]);

    $exception = makeWholesaleException('Custom Scent', '8oz Cotton Wick', 'Custom Scent', 'ERIN NUTZ');

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$exception->id]])
        ->set('existingScentSearch', 'vintage amber')
        ->assertSee('Vintage Amber');
});

test('save merges duplicate order lines instead of failing unique scent-size constraint', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $target = Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_active' => true,
    ]);

    $size = Size::query()->firstOrCreate(
        ['code' => '8oz-cotton'],
        ['label' => '8oz Cotton Wick', 'is_active' => true]
    );

    $order = Order::query()->create([
        'order_type' => 'wholesale',
        'source' => 'shopify',
        'order_number' => 'WH-2001',
        'status' => 'new',
    ]);

    $existingMapped = OrderLine::query()->create([
        'order_id' => $order->id,
        'raw_title' => 'Vintage Amber',
        'raw_variant' => '8oz Cotton Wick',
        'scent_id' => $target->id,
        'size_id' => $size->id,
        'ordered_qty' => 4,
        'quantity' => 4,
        'extra_qty' => 0,
    ]);

    $unmapped = OrderLine::query()->create([
        'order_id' => $order->id,
        'raw_title' => 'Custom Scent',
        'raw_variant' => '8oz Cotton Wick',
        'scent_id' => null,
        'size_id' => $size->id,
        'ordered_qty' => 6,
        'quantity' => 6,
        'extra_qty' => 0,
    ]);

    $exception = MappingException::query()->create([
        'store_key' => 'wholesale',
        'order_id' => $order->id,
        'order_line_id' => $unmapped->id,
        'account_name' => 'ERIN NUTZ',
        'raw_title' => 'Custom Scent',
        'raw_variant' => '8oz Cotton Wick',
        'raw_scent_name' => 'Custom Scent',
        'reason' => null,
        'payload_json' => [],
    ]);

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$exception->id]])
        ->set('selectedScentId', $target->id)
        ->set('applySameName', false)
        ->call('save')
        ->assertDispatched('intake-done');

    expect(OrderLine::query()->whereKey($unmapped->id)->exists())->toBeFalse();

    $merged = OrderLine::query()->findOrFail($existingMapped->id);
    expect((int) $merged->ordered_qty)->toBe(10);
    expect((int) ($merged->quantity ?? 0))->toBe(10);

    $resolved = MappingException::query()->findOrFail($exception->id);
    expect((int) ($resolved->order_line_id ?? 0))->toBe((int) $existingMapped->id);
    expect($resolved->resolved_at)->not->toBeNull();
});

test('sale candles uses variant scent label for same-name batching and wholesale custom mapping', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $target = Scent::query()->create([
        'name' => 'sippin sunshine',
        'display_name' => "Sippin' Sunshine",
        'is_active' => true,
    ]);

    $size = Size::query()->firstOrCreate(
        ['code' => '8oz-cotton'],
        ['label' => '8oz Cotton Wick', 'is_active' => true]
    );

    $order = Order::query()->create([
        'order_type' => 'wholesale',
        'source' => 'shopify',
        'order_number' => 'WH-3001',
        'status' => 'new',
    ]);

    $buildException = function (string $variant) use ($order, $size): MappingException {
        $line = OrderLine::query()->create([
            'order_id' => $order->id,
            'raw_title' => 'Sale Candles',
            'raw_variant' => $variant,
            'scent_id' => null,
            'size_id' => $size->id,
            'ordered_qty' => 1,
            'quantity' => 1,
            'extra_qty' => 0,
        ]);

        return MappingException::query()->create([
            'store_key' => 'wholesale',
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'account_name' => 'ERIN NUTZ',
            'raw_title' => 'Sale Candles',
            'raw_variant' => $variant,
            'raw_scent_name' => 'Sale Candles',
            'reason' => null,
            'payload_json' => [],
        ]);
    };

    $first = $buildException("Sippin' Sunshine 8oz");
    $sameScent = $buildException("Sippin' Sunshine 16oz");
    $differentScent = $buildException('Vintage Amber 8oz');

    $component = Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$first->id]]);
    $sameNameIds = $component->get('sameNameExceptionIds');
    expect($sameNameIds)->toEqualCanonicalizing([$sameScent->id]);

    $component
        ->set('selectedScentId', $target->id)
        ->set('applySameName', true)
        ->call('save')
        ->assertDispatched('intake-done');

    expect(MappingException::query()->find($first->id)?->resolved_at)->not->toBeNull();
    expect(MappingException::query()->find($sameScent->id)?->resolved_at)->not->toBeNull();
    expect(MappingException::query()->find($differentScent->id)?->resolved_at)->toBeNull();

    expect(WholesaleCustomScent::query()
        ->where('account_name', 'ERIN NUTZ')
        ->where('custom_scent_name', "Sippin' Sunshine")
        ->where('canonical_scent_id', $target->id)
        ->exists())->toBeTrue();
});

test('custom scent uses variant scent label for same-name batching and wholesale custom mapping', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $target = Scent::query()->create([
        'name' => 'vintage amber',
        'display_name' => 'Vintage Amber',
        'is_active' => true,
    ]);

    $size = Size::query()->firstOrCreate(
        ['code' => '8oz-cotton'],
        ['label' => '8oz Cotton Wick', 'is_active' => true]
    );

    $order = Order::query()->create([
        'order_type' => 'wholesale',
        'source' => 'shopify',
        'order_number' => 'WH-3002',
        'status' => 'new',
    ]);

    $buildException = function (string $variant) use ($order, $size): MappingException {
        $line = OrderLine::query()->create([
            'order_id' => $order->id,
            'raw_title' => 'Custom Scent',
            'raw_variant' => $variant,
            'scent_id' => null,
            'size_id' => $size->id,
            'ordered_qty' => 1,
            'quantity' => 1,
            'extra_qty' => 0,
        ]);

        return MappingException::query()->create([
            'store_key' => 'wholesale',
            'order_id' => $order->id,
            'order_line_id' => $line->id,
            'account_name' => 'ERIN NUTZ',
            'raw_title' => 'Custom Scent',
            'raw_variant' => $variant,
            'raw_scent_name' => 'Custom Scent',
            'reason' => null,
            'payload_json' => [],
        ]);
    };

    $first = $buildException('Vintage Amber 8oz');
    $sameScent = $buildException('Vintage Amber 16oz');
    $differentScent = $buildException("Sippin' Sunshine 8oz");

    $component = Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$first->id]]);
    $sameNameIds = $component->get('sameNameExceptionIds');
    expect($sameNameIds)->toEqualCanonicalizing([$sameScent->id]);

    $component
        ->set('selectedScentId', $target->id)
        ->set('applySameName', true)
        ->call('save')
        ->assertDispatched('intake-done');

    expect(MappingException::query()->find($first->id)?->resolved_at)->not->toBeNull();
    expect(MappingException::query()->find($sameScent->id)?->resolved_at)->not->toBeNull();
    expect(MappingException::query()->find($differentScent->id)?->resolved_at)->toBeNull();

    expect(WholesaleCustomScent::query()
        ->where('account_name', 'ERIN NUTZ')
        ->where('custom_scent_name', 'Vintage Amber')
        ->where('canonical_scent_id', $target->id)
        ->exists())->toBeTrue();
});
