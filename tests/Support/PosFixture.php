<?php

namespace Tests\Support;

use App\Models\PosRegister;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteFulfillmentLocation;
use App\Models\WebsiteOrder;
use App\Models\WebsiteProduct;
use App\Models\WebsiteProductVariant;
use Illuminate\Support\Str;

/** Fictional, database-only POS fixtures. No payment account or credentials. */
class PosFixture
{
    public Tenant $tenant;

    public User $manager;

    public User $cashier;

    public TenantSite $site;

    public WebsiteFulfillmentLocation $location;

    public WebsiteProduct $product;

    public WebsiteProductVariant $variant;

    public PosRegister $register;

    public static function merchant(string $suffix = 'one', int $stock = 5): self
    {
        $fixture = new self;
        $fixture->tenant = Tenant::query()->create(['name' => 'Fictional POS Merchant '.$suffix, 'slug' => 'pos-sandbox-'.$suffix]);
        $fixture->manager = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
        $fixture->cashier = User::factory()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
        $fixture->manager->tenants()->attach($fixture->tenant, ['role' => 'admin', 'membership_active' => true]);
        $fixture->cashier->tenants()->attach($fixture->tenant, ['role' => 'member', 'membership_active' => true]);
        TenantModuleEntitlement::query()->create(['tenant_id' => $fixture->tenant->id, 'module_key' => 'managed_website',
            'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped',
            'entitlement_source' => 'test', 'price_source' => 'catalog']);
        $fixture->site = TenantSite::query()->create(['tenant_id' => $fixture->tenant->id, 'subdomain' => $fixture->tenant->slug, 'status' => 'draft', 'settings' => []]);
        $fixture->location = WebsiteFulfillmentLocation::query()->create(['tenant_id' => $fixture->tenant->id, 'tenant_site_id' => $fixture->site->id,
            'name' => 'Fictional counter', 'active' => true, 'is_default' => true,
            'address' => ['name' => 'Fictional counter', 'street1' => '1 Fictional Street', 'city' => 'Austin', 'state' => 'TX', 'zip' => '78701', 'country' => 'US']]);
        $fixture->product = WebsiteProduct::query()->create(['tenant_id' => $fixture->tenant->id, 'tenant_site_id' => $fixture->site->id,
            'handle' => 'fictional-retail-kit', 'title' => 'Fictional retail kit', 'product_type' => 'physical', 'status' => 'active', 'track_inventory' => true]);
        $fixture->variant = WebsiteProductVariant::query()->create(['tenant_id' => $fixture->tenant->id, 'website_product_id' => $fixture->product->id,
            'title' => 'Counter kit', 'sku' => 'SIM-KIT', 'price_cents' => 2500, 'inventory_quantity' => $stock, 'is_available' => true]);
        $fixture->register = PosRegister::query()->create(['tenant_id' => $fixture->tenant->id, 'tenant_site_id' => $fixture->site->id,
            'website_fulfillment_location_id' => $fixture->location->id, 'name' => 'Fictional register',
            'reader_id' => 'sim_reader_'.$suffix, 'merchant_id' => 'sim_merchant_'.$suffix, 'currency' => 'usd', 'active' => true,
            'cashier_user_ids' => [$fixture->cashier->id], 'tax_rate_bps' => 825,
            'tax_basis' => 'Fictional 8.25% simulation policy; not a merchant tax decision.', 'tax_confirmed_at' => now()]);
        config()->set(['pos.enabled' => true, 'pos.tenant_ids' => array_values(array_unique([...(array) config('pos.tenant_ids', []), $fixture->tenant->id]))]);

        return $fixture;
    }

    public function input(int $quantity = 1, ?string $requestId = null): array
    {
        return ['request_id' => $requestId ?? (string) Str::uuid(), 'lines' => [['variant_id' => $this->variant->id, 'quantity' => $quantity]]];
    }

    public function websiteOrder(): WebsiteOrder
    {
        return WebsiteOrder::query()->create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $this->site->id,
            'number' => 'WEB-'.Str::upper(Str::random(12)), 'lookup_token' => Str::random(56), 'source' => 'native', 'currency' => 'usd',
            'payment_status' => 'pending', 'fulfillment_status' => 'unfulfilled', 'subtotal_cents' => 2500, 'total_cents' => 2500]);
    }
}
