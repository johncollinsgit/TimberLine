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

function makeWholesaleException(string $rawTitle, string $rawVariant, string $rawScentName, string $accountName, ?int $scentId = null): MappingException {
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

test('guided guesses prioritize wholesale custom candidates in wholesale context', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $wholesaleScent = Scent::query()->create([
        'name' => 'nightfall reserve',
        'display_name' => 'Nightfall Reserve',
        'is_wholesale_custom' => true,
        'is_active' => true,
    ]);

    Scent::query()->create([
        'name' => 'nightfall',
        'display_name' => 'Nightfall',
        'is_active' => true,
    ]);

    WholesaleCustomScent::query()->create([
        'account_name' => 'Acme Candle Co',
        'custom_scent_name' => 'Scent of the Month',
        'canonical_scent_id' => $wholesaleScent->id,
        'active' => true,
    ]);

    $exception = makeWholesaleException('Scent of the Month', '8oz Cotton Wick', 'Scent of the Month', 'Acme Candle Co');

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$exception->id]])
        ->call('classify', 'wholesale-custom-existing')
        ->assertSet('step', 2)
        ->assertSet('guesses.0.id', $wholesaleScent->id)
        ->assertSet('guesses.0.mapping_type', 'Wholesale Custom Scent');
});

test('advanced search returns wholesale custom records by custom scent name', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $customBlend = Scent::query()->create([
        'name' => 'fresh coffee reserve',
        'display_name' => 'Fresh Coffee Reserve',
        'is_wholesale_custom' => true,
        'is_blend' => true,
        'is_active' => true,
    ]);

    WholesaleCustomScent::query()->create([
        'account_name' => 'Summit Goods',
        'custom_scent_name' => 'Morning Roast Club Blend',
        'canonical_scent_id' => $customBlend->id,
        'active' => true,
    ]);

    $exception = makeWholesaleException('Morning Roast Club Blend', '8oz Cotton Wick', 'Morning Roast Club Blend', 'Summit Goods');

    Livewire::test(ProgressiveMapper::class, ['exceptionIds' => [$exception->id]])
        ->call('manualSearch')
        ->set('existingScentSearch', 'Morning Roast Club Blend')
        ->assertSee('Fresh Coffee Reserve')
        ->assertSee('Wholesale Custom Blend');
});

test('save can apply mapping to remaining repeated wholesale labels and persist account scoped rule', function () {
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
        ->call('classify', 'wholesale-custom-existing')
        ->set('selectedScentId', $scent->id)
        ->set('batchApplyRemaining', true)
        ->set('batchScope', 'this_account')
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
