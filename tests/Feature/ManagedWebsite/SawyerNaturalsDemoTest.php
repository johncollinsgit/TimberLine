<?php

use App\Models\Order;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderLine;
use App\Models\WebsiteProduct;
use App\Models\WebsiteShipment;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\PirateShipSpreadsheetBridge;
use App\Services\ManagedWebsite\WebsiteCommerceService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('managed_website.editor_enabled', true);
    config()->set('managed_website.publishing_enabled', true);
    config()->set('managed_website.public_render_enabled', true);
    config()->set('managed_website.commerce_enabled', true);
});

function sawyerTestSite(string $slug = 'sawyer-test'): array
{
    $tenant = Tenant::query()->create(['name' => 'Sawyer Test', 'slug' => $slug]);
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'trial', 'entitlement_source' => 'test']);
    config()->set('managed_website.editor_tenant_ids', array_unique([...config('managed_website.editor_tenant_ids', []), $tenant->id]));
    $websites = app(ManagedWebsiteService::class);
    $site = $websites->createSite($tenant, $actor);
    $site = $websites->applyTheme($site, 'sawyer-naturals', $actor);
    $websites->publish($site, $actor);

    return [$tenant, $actor, $site->fresh()];
}

test('Sawyer preparation is idempotent, private, and gives the requested admin a sourced retail catalog', function (): void {
    $this->artisan('everbranch:prepare-sawyer-naturals')->assertSuccessful();
    $tenant = Tenant::query()->where('slug', 'sawyer-naturals')->firstOrFail();
    $site = $tenant->managedSite;
    $admin = User::query()->where('email', 'johncollinemail@gmail.com')->firstOrFail();
    $expectedCount = count(json_decode((string) file_get_contents(resource_path('data/sawyer-naturals-catalog.json')), true)['products']);

    expect($tenant->users()->whereKey($admin->id)->first()?->pivot->role)->toBe('admin')
        ->and($site->public_enabled)->toBeFalse()
        ->and(data_get($site->settings, 'theme_key'))->toBe('sawyer-naturals')
        ->and(WebsiteProduct::query()->forTenant($tenant)->count())->toBe($expectedCount)
        ->and(WebsiteProduct::query()->forTenant($tenant)->where('handle', 'sawyer-naturals-egift-card')->firstOrFail()->variants->first()->is_available)->toBeFalse();

    $this->artisan('everbranch:prepare-sawyer-naturals')->assertSuccessful();
    expect(WebsiteProduct::query()->forTenant($tenant)->count())->toBe($expectedCount);
});

test('Sawyer catalog and cart preview can open while checkout remains closed for every tenant', function (): void {
    [$tenant] = sawyerTestSite();
    [$other] = sawyerTestSite('other-preview');
    config()->set('managed_website.commerce_enabled', false);
    config()->set('managed_website.commerce_preview_tenant_ids', [$tenant->id]);
    $commerce = app(WebsiteCommerceService::class);

    expect($commerce->enabledFor($tenant))->toBeTrue()
        ->and($commerce->enabledFor($other))->toBeFalse()
        ->and($commerce->checkoutReadiness($tenant)['ready'])->toBeFalse();
    $this->get('https://sawyer-test.theeverbranch.com/cart')->assertOk();
    $this->get('https://other-preview.theeverbranch.com/cart')->assertStatus(423);
});

test('email link grants only the shopper’s own Website order history', function (): void {
    [$tenant, , $site] = sawyerTestSite();
    $customer = WebsiteCustomer::query()->create(['tenant_id' => $tenant->id, 'email' => 'buyer@example.com', 'first_name' => 'Buyer', 'status' => 'active']);
    $other = WebsiteCustomer::query()->create(['tenant_id' => $tenant->id, 'email' => 'other@example.com', 'status' => 'active']);
    foreach ([$customer, $other] as $index => $shopper) {
        WebsiteOrder::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'website_customer_id' => $shopper->id, 'number' => 'WEB-ABC1234'.$index, 'lookup_token' => Str::random(56), 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'fulfillment_method' => 'ship', 'currency' => 'usd', 'subtotal_cents' => 2500, 'total_cents' => 2500, 'customer_snapshot' => ['email' => $shopper->email]]);
    }
    $mailBody = '';
    Mail::shouldReceive('raw')->once()->andReturnUsing(function (string $body) use (&$mailBody): void {
        $mailBody = $body;
    });
    $host = 'https://sawyer-test.theeverbranch.com';
    $this->post($host.'/account/link', ['email' => 'buyer@example.com'])->assertRedirect();
    preg_match('#https://[^\s]+/account/verify/([A-Za-z0-9]+)#', $mailBody, $matches);
    expect($matches)->toHaveCount(2);
    $token = $matches[1];
    sawyerTestSite('another-sawyer');
    $this->post('https://another-sawyer.theeverbranch.com/account/verify/'.$token)->assertNotFound();
    $this->get($host.'/account/verify/'.$token)->assertOk();
    $this->post($host.'/account/verify/'.$token)->assertRedirect();
    $this->get($host.'/account')->assertOk()->assertSee('WEB-ABC12340')->assertDontSee('WEB-ABC12341');
    $this->post($host.'/account/profile', ['first_name' => 'Updated', 'last_name' => 'Buyer', 'phone' => '864-555-0100'])->assertRedirect();
    expect($customer->fresh()->first_name)->toBe('Updated')->and($customer->fresh()->phone)->toBe('864-555-0100');
    $this->get($host.'/account/orders/WEB-ABC12341')->assertNotFound();
    $this->post($host.'/account/verify/'.$token)->assertNotFound();
});

test('Pirate Ship spreadsheet bridge exports scoped paid orders and imports tracking exactly once', function (): void {
    [$tenant, $actor, $site] = sawyerTestSite('sawyer-shipping');
    config()->set('managed_website.pirate_ship_bridge_enabled', true);
    config()->set('managed_website.pirate_ship_bridge_tenant_ids', [$tenant->id]);
    $customer = WebsiteCustomer::query()->create(['tenant_id' => $tenant->id, 'email' => 'ship@example.com', 'status' => 'active']);
    $order = WebsiteOrder::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'website_customer_id' => $customer->id, 'number' => 'WEB-SHIP1234', 'lookup_token' => Str::random(56), 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'fulfillment_method' => 'ship', 'currency' => 'usd', 'subtotal_cents' => 3499, 'total_cents' => 3499, 'customer_snapshot' => ['email' => $customer->email], 'shipping_address' => ['name' => 'Buyer Name', 'street1' => '1 Main St', 'city' => 'Marietta', 'state' => 'SC', 'zip' => '29661', 'country' => 'US']]);
    WebsiteOrderLine::query()->create(['tenant_id' => $tenant->id, 'website_order_id' => $order->id, 'title' => 'Body Balm', 'product_type' => 'physical', 'quantity' => 1, 'unit_price_cents' => 3499, 'line_total_cents' => 3499, 'snapshot' => []]);
    $legacyBefore = Order::query()->count();
    $bridge = app(PirateShipSpreadsheetBridge::class);
    ob_start();
    ($bridge->export($tenant, $site)->getCallback())();
    $csv = ob_get_clean();
    expect($csv)->toContain('WEB-SHIP1234')->toContain('1 Main St');

    $file = UploadedFile::fake()->createWithContent('shipments.csv', "Order ID,Tracking Number,Carrier,Service\nWEB-SHIP1234,9400111899223856923456,USPS,Ground Advantage\n");
    expect($bridge->importTracking($tenant, $site, $actor, $file))->toBe(['imported' => 1, 'already_present' => 0])
        ->and($bridge->importTracking($tenant, $site, $actor, $file))->toBe(['imported' => 0, 'already_present' => 1])
        ->and(WebsiteShipment::query()->forTenant($tenant)->count())->toBe(1)
        ->and($order->fresh()->fulfillment_status)->toBe('fulfilled')
        ->and(Order::query()->count())->toBe($legacyBefore);

    [$foreignTenant, , $foreignSite] = sawyerTestSite('foreign-shipping');
    WebsiteOrder::query()->create(['tenant_id' => $foreignTenant->id, 'tenant_site_id' => $foreignSite->id, 'number' => 'WEB-FOREIGN1', 'lookup_token' => Str::random(56), 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'fulfillment_method' => 'ship', 'currency' => 'usd', 'subtotal_cents' => 1000, 'total_cents' => 1000]);
    $localPending = WebsiteOrder::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'number' => 'WEB-LOCAL123', 'lookup_token' => Str::random(56), 'payment_status' => 'paid', 'fulfillment_status' => 'unfulfilled', 'fulfillment_method' => 'ship', 'currency' => 'usd', 'subtotal_cents' => 1000, 'total_cents' => 1000]);
    $mixed = UploadedFile::fake()->createWithContent('mixed.csv', "Order ID,Tracking Number,Carrier\nWEB-LOCAL123,9400111899223856923000,USPS\nWEB-FOREIGN1,9400111899223856923111,USPS\n");
    expect(fn () => $bridge->importTracking($tenant, $site, $actor, $mixed))->toThrow(ValidationException::class)
        ->and($localPending->fresh()->fulfillment_status)->toBe('unfulfilled')
        ->and(WebsiteShipment::query()->forTenant($tenant)->count())->toBe(1)
        ->and(WebsiteShipment::query()->forTenant($foreignTenant)->count())->toBe(0);
});
