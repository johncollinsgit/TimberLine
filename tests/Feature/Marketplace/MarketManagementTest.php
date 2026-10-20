<?php

use App\Models\Marketplace\Checkout;
use App\Models\Marketplace\Identity;
use App\Models\Marketplace\Listing;
use App\Models\Marketplace\Maker;
use App\Models\Marketplace\Market;
use App\Models\Marketplace\Membership;
use App\Models\Marketplace\Order;
use App\Models\Marketplace\OrderLine;
use App\Models\Marketplace\PickupLocation;
use App\Models\Marketplace\RateAcceptance;
use App\Models\Marketplace\Store;
use App\Models\Marketplace\StoreLink;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketplace\MarketplaceCatalog;
use App\Services\Marketplace\MarketplaceMarkets;
use App\Services\Marketplace\MarketplaceRates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config(['marketplace.enabled' => true, 'marketplace.checkout_provider' => 'everbranch', 'marketplace.checkout_enabled' => false, 'marketplace.host' => 'marketsandmakers.test', 'app.url' => 'https://app.theeverbranch.test']);
    Route::middleware('web')->group(base_path('routes/marketplace.php'));
    $routes = new Illuminate\Routing\RouteCollection;
    foreach (Route::getRoutes() as $route) {
        if (str_starts_with((string) $route->getName(), 'marketplace.public.')) {
            $routes->add($route);
        }
    }
    foreach (Route::getRoutes() as $route) {
        if (! str_starts_with((string) $route->getName(), 'marketplace.public.')) {
            $routes->add($route);
        }
    }Route::setRoutes($routes);
    $this->withoutVite();
    Http::preventStrayRequests();
});
function marketAdminFixture(): array
{
    $tenant = Tenant::create(['name' => 'Markets and Makers', 'slug' => 'markets-and-makers']);
    Store::create(['tenant_id' => $tenant->id, 'name' => $tenant->name, 'host' => 'marketsandmakers.test', 'verified_at' => now()]);
    $owner = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    $identity = Identity::create(['email' => 'maker@market.example.test', 'active' => true]);
    $maker = Maker::create(['name' => 'Fieldwork test maker', 'slug' => 'fieldwork-test-maker', 'city' => 'Easley', 'status' => 'approved', 'story' => 'Local handmade goods', 'private_details' => ['phone' => '555-0100', 'legal_business_name' => 'Private Supplier LLC']]);
    Membership::create(['maker_id' => $maker->id, 'identity_id' => $identity->id, 'role' => 'owner', 'active' => true]);
    $rate = app(MarketplaceRates::class)->global();
    RateAcceptance::create(['maker_id' => $maker->id, 'identity_id' => $identity->id, 'rate_id' => $rate->id]);
    $listing = Listing::create(['maker_id' => $maker->id, 'slug' => 'test-market-mug', 'title' => 'Test market mug', 'category' => 'Ceramics', 'description' => 'A useful mug', 'images' => ['https://example.test/photo.jpg'], 'price_cents' => 5000, 'shipping' => true, 'status' => 'approved']);

    return compact('tenant', 'owner', 'identity', 'maker', 'listing', 'rate');
}
function marketTestSale(array $f, bool $testMode = false): Order
{
    $checkout = Checkout::create(['uuid' => (string) Str::uuid(), 'session_hash' => hash('sha256', Str::random()), 'provider' => 'stripe', 'group_key' => 'shipping', 'status' => 'paid', 'snapshot' => ['lines' => [['listing_id' => $f['listing']->id, 'maker_id' => $f['maker']->id]]], 'expires_at' => now()->addHour()]);
    $order = Order::create(['checkout_id' => $checkout->id, 'provider' => 'stripe', 'provider_order_gid' => 'cs_'.Str::random(20), 'number' => 'MM-REPORT', 'customer' => ['email' => 'buyer@market.example.test', 'shipping_address' => ['name' => 'Example buyer']], 'email_hash' => hash('sha256', 'buyer@market.example.test'), 'group_key' => 'shipping', 'merchandise_cents' => 10000, 'discount_cents' => 1000, 'shipping_cents' => 600, 'tax_cents' => 0, 'total_cents' => 9600, 'test_mode' => $testMode]);
    OrderLine::create(['order_id' => $order->id, 'maker_id' => $f['maker']->id, 'listing_id' => $f['listing']->id, 'provider_line_gid' => 'li_'.Str::random(12), 'title' => $f['listing']->title, 'quantity' => 2, 'unit_price_cents' => 5000, 'maker_unit_cents' => 4250, 'rate_id' => $f['rate']->id, 'discount_cents' => 1000]);

    return $order;
}
test('merchant management offers sales customers discounts maker drill downs markets pickups and connections', function (): void {
    $f = marketAdminFixture();
    marketTestSale($f);
    marketTestSale($f, true);
    $this->actingAs($f['owner']);
    foreach (['overview', 'customers', 'discounts', 'markets', 'pickups', 'connections'] as $tab) {
        $this->get('https://app.theeverbranch.test/marketplace/operations?tab='.$tab)->assertOk();
    }
    $this->get('https://app.theeverbranch.test/marketplace/operations?tab=customers')->assertSee('buyer@market.example.test')->assertSee('$96.00');
    $this->get('https://app.theeverbranch.test/marketplace/operations/makers/'.$f['maker']->id)->assertOk()->assertSee('Private Supplier LLC')->assertSee('555-0100')->assertSee('$100.00')->assertSee('buyer@market.example.test');
    $this->get('https://marketsandmakers.test/makers/'.$f['maker']->slug)->assertOk()->assertDontSee('Private Supplier LLC')->assertDontSee('555-0100');
    $outsider = User::factory()->create(['is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($outsider)->get('https://app.theeverbranch.test/marketplace/operations/makers/'.$f['maker']->id)->assertForbidden();
});
test('market participation controls maker pickup eligibility and publication exposes only approved makers', function (): void {
    $f = marketAdminFixture();
    $this->actingAs($f['owner'])->post('https://app.theeverbranch.test/marketplace/operations/markets', ['name' => 'Saturday Gathering', 'kind' => 'market', 'status' => 'published', 'description' => 'Local goods and makers', 'address' => 'Example market address', 'timezone' => 'America/New_York', 'starts_at' => now()->addWeek()->format('Y-m-d').'T09:00', 'ends_at' => now()->addWeek()->format('Y-m-d').'T13:00', 'offer_pickup' => '1', 'tax_address' => ['line1' => '100 Example Street', 'city' => 'Easley', 'state' => 'SC', 'postal_code' => '29640', 'country' => 'US'], 'pickup_instructions' => 'Find the collection tent'])->assertRedirect();
    $market = Market::firstOrFail();
    $pickup = PickupLocation::findOrFail($market->pickup_location_id);
    expect(app(MarketplaceMarkets::class)->pickupAvailable($f['maker'], $pickup->id))->toBeFalse();
    $this->withSession(['marketplace_identity' => $f['identity']->id])->post('https://marketsandmakers.test/maker/'.$f['maker']->id.'/markets', ['market_id' => $market->id, 'action' => 'apply'])->assertRedirect();
    expect(fn () => app(MarketplaceCatalog::class)->save($f['maker'], ['title' => 'Pickup mug', 'shipping' => false, 'pickup_location_ids' => [$pickup->id]], $f['listing']))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);
    $this->post('https://app.theeverbranch.test/marketplace/operations/markets/'.$market->id.'/makers', ['maker_id' => $f['maker']->id, 'status' => 'approved', 'booth' => 'A4'])->assertRedirect();
    expect(app(MarketplaceMarkets::class)->pickupAvailable($f['maker'], $pickup->id))->toBeTrue();
    $this->get('https://marketsandmakers.test/markets')->assertOk()->assertSee('Saturday Gathering')->assertSee($f['maker']->name);
    $market->update(['pickup_cutoff_at' => now()->subMinute()]);
    expect(app(MarketplaceMarkets::class)->pickupAvailable($f['maker'], $pickup->id))->toBeFalse();
});
test('makers can share storefronts and private business information without granting ecommerce or other maker access', function (): void {
    $f = marketAdminFixture();
    $this->withSession(['marketplace_identity' => $f['identity']->id])->post('https://marketsandmakers.test/maker/'.$f['maker']->id.'/store-links', ['platform' => 'wix', 'url' => 'https://example.test/my-store', 'label' => 'My existing store'])->assertRedirect();
    expect(StoreLink::first()->status)->toBe('shared');
    $other = Maker::create(['name' => 'Other maker', 'slug' => 'other-maker', 'city' => 'Greer', 'status' => 'approved', 'story' => 'Other maker test goods']);
    $this->post('https://marketsandmakers.test/maker/'.$other->id.'/details', ['phone' => 'stolen'])->assertForbidden();
    $this->post('https://marketsandmakers.test/maker/'.$f['maker']->id.'/details', ['contact_name' => 'Private Contact', 'phone' => '555-0102'])->assertRedirect();
    expect($f['maker']->fresh()->private_details['contact_name'])->toBe('Private Contact')->and(DB::table('marketplace_makers')->where('id', $f['maker']->id)->value('private_details'))->not->toContain('Private Contact');
    $this->actingAs($f['owner'])->get('https://app.theeverbranch.test/marketplace/operations?tab=connections')->assertOk()->assertSee('My existing store')->assertSee('No catalog sync');
});

test('merchant daily reports use South Carolina dates across UTC midnight', function (): void {
    $f = marketAdminFixture();
    $yesterday = marketTestSale($f);
    $today = marketTestSale($f);
    $yesterday->forceFill(['created_at' => '2026-10-20 02:00:00'])->save();
    $today->forceFill(['created_at' => '2026-10-21 02:00:00'])->save();
    $report = app(\App\Services\Marketplace\MarketplaceReporting::class)->overview(\Illuminate\Http\Request::create('/?from=2026-10-20&to=2026-10-20'));
    expect($report['salesOrders']->modelKeys())->toBe([$today->id])->and($report['metrics']['orders'])->toBe(1)->and($report['from']->toDateString())->toBe('2026-10-20')->and($report['to']->toDateString())->toBe('2026-10-20');
});

test('maker withdrawal immediately holds uncollected pickup orders for owner review', function (): void {
    $f = marketAdminFixture();
    $pickup = PickupLocation::create(['name' => 'Departure market', 'address' => 'Example market address', 'instructions' => 'Collection tent', 'shopify_location_gid' => 'eb:departure', 'active' => true]);
    $market = Market::create(['name' => 'Departure market', 'slug' => 'departure-market', 'kind' => 'market', 'status' => 'published', 'address' => 'Example market address', 'timezone' => 'America/New_York', 'starts_at' => now()->addWeek(), 'ends_at' => now()->addWeek()->addHours(4), 'pickup_location_id' => $pickup->id, 'created_by' => $f['owner']->id]);
    DB::table('marketplace_market_makers')->insert(['market_id' => $market->id, 'maker_id' => $f['maker']->id, 'status' => 'approved']);
    $order = marketTestSale($f);
    $order->update(['group_key' => 'pickup:'.$pickup->id]);
    $order->checkout->update(['group_key' => $order->group_key]);
    $this->withSession(['marketplace_identity' => $f['identity']->id])->post('https://marketsandmakers.test/maker/'.$f['maker']->id.'/markets', ['market_id' => $market->id, 'action' => 'withdraw'])->assertRedirect();
    expect($order->fresh()->review_required)->toBeTrue()->and(app(MarketplaceMarkets::class)->pickupAvailable($f['maker'], $pickup->id))->toBeFalse();
    $this->actingAs($f['owner'])->get('https://app.theeverbranch.test/marketplace/operations?tab=pickups')->assertOk()->assertSee('A maker withdrew');
});

test('all operator destinations preserve filters and the maker console remains separate from staff membership', function (): void {
    $f = marketAdminFixture();
    marketTestSale($f);
    foreach (array_keys(array_merge(...array_values(\App\Services\Marketplace\MarketplaceManagementPresentation::groups()))) as $tab) {
        $this->actingAs($f['owner'])->get('https://app.theeverbranch.test/marketplace/operations?tab='.$tab.'&from=2026-10-01&to=2026-10-31')->assertOk()->assertSee('mm-sidebar', false)->assertSee('from=2026-10-01', false);
    }
    $this->withSession(['marketplace_identity' => $f['identity']->id])->get('https://marketsandmakers.test/maker/'.$f['maker']->id)->assertOk()->assertSeeText('No Everbranch subscription required')->assertSeeText('Your products')->assertDontSeeText('Account & security');
    $this->assertDatabaseCount('tenant_user', 1);
    Http::assertNothingSent();
});

test('export marketplace management browser fixtures', function (): void {
    if (! getenv('MARKETPLACE_CONSOLE_FIXTURES')) {
        $this->markTestSkipped('Synthetic rendered fixtures are opt-in for isolated browser checks.');
    }
    $this->withVite();
    $directory = storage_path('framework/testing/marketplace-console');
    if (! is_dir($directory)) {
        mkdir($directory, 0755, true);
    }
    $f = marketAdminFixture();
    $f['listing']->update(['images' => ['/images/marketplace/studio.jpg']]);
    $order = marketTestSale($f);
    $order->update(['review_required' => true, 'review_reason' => 'Fictional operator review hold']);
    \App\Models\Marketplace\Statement::create(['uuid' => (string) Str::uuid(), 'maker_id' => $f['maker']->id, 'status' => 'review', 'amount_cents' => 8500]);
    $this->actingAs($f['owner']);
    foreach (array_keys(array_merge(...array_values(\App\Services\Marketplace\MarketplaceManagementPresentation::groups()))) as $tab) {
        $response = $this->get('https://app.theeverbranch.test/marketplace/operations?tab='.$tab)->assertOk();
        file_put_contents($directory.'/operator-'.$tab.'.html', $response->getContent());
    }
    $response = $this->get('https://app.theeverbranch.test/marketplace/operations/makers/'.$f['maker']->id)->assertOk();
    file_put_contents($directory.'/operator-maker.html', $response->getContent());
    $response = $this->withSession(['marketplace_identity' => $f['identity']->id])->get('https://marketsandmakers.test/maker/'.$f['maker']->id)->assertOk();
    file_put_contents($directory.'/maker.html', $response->getContent());
    $response = $this->get('https://marketsandmakers.test/')->assertOk();
    file_put_contents($directory.'/shopper.html', $response->getContent());
    config(['marketplace.account_preview_user_ids' => [$f['owner']->id], 'marketplace.enabled' => false, 'marketplace.checkout_enabled' => false, 'marketplace.host' => '']);
    foreach (array_keys(array_merge(...array_values(\App\Services\Marketplace\MarketplaceManagementPresentation::groups()))) as $tab) {
        $response = $this->get('https://app.theeverbranch.test/marketplace/preview?tab='.$tab)->assertOk();
        file_put_contents($directory.'/preview-'.$tab.'.html', $response->getContent());
    }
    Http::assertNothingSent();
});

test('management reads confirmed business context and existing branding without initializing profiles', function (): void {
    $f = marketAdminFixture();
    \App\Models\TenantAccessProfile::create(['tenant_id' => $f['tenant']->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'metadata' => ['tenant_blueprint' => ['company_context' => ['category' => 'retail', 'specialty' => 'Fictional maker marketplace', 'model' => 'products', 'confirmed_at' => now()->toIso8601String()]]]]);
    $this->actingAs($f['owner'])->get('https://app.theeverbranch.test/marketplace/operations')->assertOk()->assertSeeText('Fictional maker marketplace');
    $this->assertDatabaseCount('tenant_brand_profiles', 0);
    $brand = app(\App\Services\Tenancy\TenantBrandProfileService::class)->ensureForTenant($f['tenant'], $f['owner']);
    $brand->update(['display_name' => 'Fictional Collective']);
    $this->get('https://app.theeverbranch.test/marketplace/operations')->assertOk()->assertSeeText('Fictional Collective');
    $this->assertDatabaseCount('tenant_brand_profiles', 1);
    $response = $this->get('https://app.theeverbranch.test/marketplace/operations/makers/'.$f['maker']->id.'?q=Fieldwork&status=approved&detail=maker-'.$f['maker']->id.'&from=2026-10-01&to=2026-10-31')->assertOk();
    $response->assertSee('q=Fieldwork', false)->assertSee('status=approved', false)->assertSee('detail=maker-'.$f['maker']->id, false);
    Http::assertNothingSent();
});
