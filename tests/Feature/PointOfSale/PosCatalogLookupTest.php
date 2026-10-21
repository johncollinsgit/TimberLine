<?php

use App\Models\PosPaymentAttempt;
use App\Models\PosSimulatorObject;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Facades\Http;
use Tests\Support\PosFixture;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->fixture = PosFixture::merchant('sku-lookup');
    $this->lookup = fn (string $code) => $this->actingAs($this->fixture->cashier)->getJson(route('pos.catalog.lookup', [
        'register' => $this->fixture->register->id, 'tenant' => $this->fixture->tenant->slug, 'code' => $code,
    ]));
});

it('returns exact current native SKU pricing with no checkout stock or provider effects', function (): void {
    ($this->lookup)('SIM-KIT')->assertOk()->assertJsonPath('item.variant_id', $this->fixture->variant->id)
        ->assertJsonPath('item.sku', 'SIM-KIT')->assertJsonPath('item.price_cents', 2500)->assertJsonPath('item.available_quantity', 5)
        ->assertJsonMissingPath('item.tenant_id')->assertJsonMissingPath('item.snapshot')
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
    expect(PosPaymentAttempt::count())->toBe(0)->and(PosSimulatorObject::count())->toBe(0)->and(WebsiteOrder::count())->toBe(0)
        ->and(WebsiteInventoryReservation::count())->toBe(0)->and(WebsiteInventoryMovement::count())->toBe(0)
        ->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5);
    Http::assertNothingSent();
});

it('rejects unknown prefix and different-case SKU input', function (string $code): void {
    ($this->lookup)($code)->assertNotFound()->assertJsonMissingPath('item');
    Http::assertNothingSent();
})->with(['SIM', 'sim-kit', 'MISSING-SKU']);

it('rejects duplicate eligible SKU instead of choosing an item', function (): void {
    $product = WebsiteProduct::create(['tenant_id' => $this->fixture->tenant->id, 'tenant_site_id' => $this->fixture->site->id,
        'handle' => 'ambiguous-sku-product', 'title' => 'Fictional ambiguous product', 'product_type' => 'physical', 'status' => 'active', 'track_inventory' => false]);
    WebsiteProductVariant::create(['tenant_id' => $this->fixture->tenant->id, 'website_product_id' => $product->id,
        'title' => 'Ambiguous variant', 'sku' => 'SIM-KIT', 'price_cents' => 100, 'is_available' => true]);
    ($this->lookup)('SIM-KIT')->assertConflict()->assertJsonMissingPath('item')->assertSeeText('More than one');
    expect(WebsiteOrder::count())->toBe(0)->and(PosSimulatorObject::count())->toBe(0);
    Http::assertNothingSent();
});

it('rejects another tenant or site and a foreign register without leaking their items', function (): void {
    $other = PosFixture::merchant('foreign-sku');
    $other->variant->update(['sku' => 'FOREIGN-TENANT-SKU']);
    ($this->lookup)('FOREIGN-TENANT-SKU')->assertNotFound()->assertJsonMissingPath('item');
    // Even a mismatched retained product/site reference must not widen lookup.
    $product = WebsiteProduct::create(['tenant_id' => $this->fixture->tenant->id, 'tenant_site_id' => $other->site->id,
        'handle' => 'other-site-product', 'title' => 'Other site product', 'product_type' => 'physical', 'status' => 'active', 'track_inventory' => false]);
    WebsiteProductVariant::create(['tenant_id' => $this->fixture->tenant->id, 'website_product_id' => $product->id,
        'title' => 'Other site variant', 'sku' => 'FOREIGN-SITE-SKU', 'price_cents' => 100, 'is_available' => true]);
    ($this->lookup)('FOREIGN-SITE-SKU')->assertNotFound()->assertJsonMissingPath('item');
    $this->getJson(route('pos.catalog.lookup', ['register' => $other->register->id, 'tenant' => $this->fixture->tenant->slug, 'code' => 'FOREIGN-TENANT-SKU']))->assertNotFound();
    Http::assertNothingSent();
});

it('looks up an exact item beyond the 100-row browse limit', function (): void {
    for ($index = 0; $index < 101; $index++) {
        $variant = $this->fixture->variant->replicate();
        $variant->sku = $index === 100 ? 'BEYOND-BROWSE' : 'FILL-'.$index;
        $variant->save();
    }
    ($this->lookup)('BEYOND-BROWSE')->assertOk()->assertJsonPath('item.variant_id', $variant->id)->assertJsonPath('item.sku', 'BEYOND-BROWSE');
    Http::assertNothingSent();
});

it('rejects unavailable service draft and unstocked items', function (string $change, int $status): void {
    match ($change) {
        'unavailable' => $this->fixture->variant->update(['is_available' => false]),
        'service' => $this->fixture->product->update(['product_type' => 'service']),
        'draft' => $this->fixture->product->update(['status' => 'draft']),
        'zero stock' => $this->fixture->variant->update(['inventory_quantity' => 0]),
        'unknown stock' => $this->fixture->variant->update(['inventory_quantity' => null]),
    };
    ($this->lookup)('SIM-KIT')->assertStatus($status)->assertJsonMissingPath('item');
    Http::assertNothingSent();
})->with([['unavailable', 404], ['service', 404], ['draft', 404], ['zero stock', 409], ['unknown stock', 409]]);

it('respects existing native inventory holds and production denial', function (): void {
    $order = $this->fixture->websiteOrder();
    WebsiteInventoryReservation::create(['tenant_id' => $this->fixture->tenant->id, 'website_order_id' => $order->id,
        'website_product_variant_id' => $this->fixture->variant->id, 'quantity' => 5, 'status' => 'active', 'expires_at' => now()->addHour()]);
    ($this->lookup)('SIM-KIT')->assertConflict();
    expect(WebsiteInventoryReservation::sole()->quantity)->toBe(5)->and($this->fixture->variant->fresh()->inventory_quantity)->toBe(5);
    app()->instance('env', 'production');
    ($this->lookup)('SIM-KIT')->assertNotFound();
    Http::assertNothingSent();
});
