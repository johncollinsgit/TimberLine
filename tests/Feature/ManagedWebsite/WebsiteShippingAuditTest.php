<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Models\WebsiteShipment;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('runtime shipping audit requires an explicit scope and complete valid dates', function (): void {
    $this->artisan('website:audit-shipping')->assertFailed();
    $this->artisan('website:audit-shipping', ['--all' => true, '--tenant' => 'any'])->assertFailed();
    $this->artisan('website:audit-shipping', ['--all' => true, '--since' => '2026-11-07', '--until' => '2026-08-10'])->assertFailed();
    $this->artisan('website:audit-shipping', ['--all' => true, '--since' => 'bad-date'])->assertFailed();
});

test('runtime audit reports the purchased label cohort without secrets or unrelated order revenue', function (): void {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-08 12:00:00'));
    Http::preventStrayRequests();
    $tenant = Tenant::query()->create(['name' => 'Audit store', 'slug' => 'audit-store']);
    $other = Tenant::query()->create(['name' => 'Other store', 'slug' => 'private-other-store']);
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config()->set(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$tenant->id], 'managed_website.commerce_enabled' => false]);
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $actor);
    IntegrationConnection::query()->create(['tenant_id' => $tenant->id, 'provider' => 'easypost', 'status' => 'connected',
        'access_token' => 'private_audit_api_key', 'external_account_secret' => 'private_audit_webhook_secret']);
    $order = WebsiteOrder::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id, 'number' => 'WEB-COHORT',
        'lookup_token' => Str::random(56), 'source' => 'native', 'currency' => 'usd', 'shipping_cents' => 500, 'total_cents' => 2500,
        'shipping_address' => ['street1' => 'Private test street']]);
    $fulfillment = $order->fulfillments()->create(['tenant_id' => $tenant->id, 'method' => 'ship', 'status' => 'pending']);
    WebsiteShipment::query()->create(['tenant_id' => $tenant->id, 'website_order_id' => $order->id, 'website_fulfillment_id' => $fulfillment->id, 'provider' => 'easypost',
        'provider_shipment_id' => 'shp_audit', 'carrier' => 'UPS', 'service' => 'Ground', 'currency' => 'usd', 'label_cost_cents' => 1500,
        'purchased_at' => '2026-08-10 00:00:00', 'parcel' => ['_native' => ['version' => 1, 'mode' => 'production', 'buy_state' => 'purchased', 'refund_state' => 'refunded']]]);
    foreach (['test', null] as $mode) {
        WebsiteShipment::query()->create(['tenant_id' => $tenant->id, 'website_order_id' => $order->id, 'website_fulfillment_id' => $fulfillment->id, 'provider' => 'easypost',
            'provider_shipment_id' => 'shp_excluded_'.($mode ?? 'unknown'), 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'currency' => 'usd', 'label_cost_cents' => 99000,
            'purchased_at' => '2026-08-10 00:00:00', 'parcel' => ['_native' => ['version' => 1, 'mode' => $mode, 'buy_state' => 'purchased']]]);
    }
    $unrelated = $order->replicate(['number', 'lookup_token']);
    $unrelated->fill(['number' => 'WEB-UNRELATED', 'lookup_token' => Str::random(56), 'shipping_cents' => 99000])->save();
    $exit = Artisan::call('website:audit-shipping', ['--tenant' => 'audit-store', '--since' => '2026-08-10', '--until' => '2026-11-07']);
    $json = Artisan::output();
    $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
    $cost = $data['tenants'][0]['native_parcel'];
    expect($exit)->toBe(0)->and($data['tenants'])->toHaveCount(1)
        ->and($cost['purchased_labels'])->toBe(1)->and($cost['purchased_label_cents'])->toBe(1500)
        ->and($cost['test_or_unknown_mode_labels_excluded'])->toBe(2)
        ->and($cost['associated_original_customer_shipping_cents'])->toBe(500)
        ->and($cost['gross_label_cost_minus_original_shipping_charge_cents'])->toBe(1000)
        ->and($cost['provider_confirmed_refund_label_cents'])->toBe(1500)
        ->and($json)->not->toContain('private_audit_api_key')->not->toContain('private_audit_webhook_secret')
        ->not->toContain('Private test street')->not->toContain('private-other-store');
    Http::assertNothingSent();
});
