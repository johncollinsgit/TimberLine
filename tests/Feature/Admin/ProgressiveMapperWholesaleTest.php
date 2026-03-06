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
