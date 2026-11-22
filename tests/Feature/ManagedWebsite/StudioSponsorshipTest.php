<?php

use App\Models\StudioOffer;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\ManagedWebsite\StudioSponsorshipService;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

it('reserves a single approved booking and exports paid deliverables only while funds remain confirmed', function () {
    $tenant = Tenant::create(['name' => 'Studio fixture', 'slug' => 'studio-sponsor-test']);
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'approved_at' => now(), 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'managed_website', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'test', 'price_source' => 'catalog']);
    config(['managed_website.editor_enabled' => true, 'managed_website.editor_tenant_ids' => [$tenant->id],
        'managed_website.invoice_tenant_ids' => [$tenant->id], 'managed_website.invoices_enabled' => true, 'managed_website.commerce_enabled' => true]);
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $actor);
    $studio = app(StudioSponsorshipService::class);
    $offer = StudioOffer::create(['episode_id' => (string) Str::uuid(), 'tenant_site_id' => $site->id, 'title' => 'Host read', 'kind' => 'host_read',
        'price_cents' => 2500, 'clip_cents' => 7500, 'closes_at' => now()->addDays(5)]);
    $data = ['request_id' => (string) Str::uuid(), 'sponsor' => 'Fixture maker', 'email' => 'maker@example.test', 'brief' => 'Visit our spring market', 'social_clip' => true];
    $first = $studio->submit($site, $offer->id, $data);
    expect($studio->submit($site, $offer->id, $data)->id)->toBe($first->id);
    $second = $studio->submit($site, $offer->id, [...$data, 'request_id' => (string) Str::uuid()]);
    $tax = ['tax_cents' => 0, 'tax_basis' => 'Reviewed fixture'];
    $approved = $studio->approve($site, $offer->id, $first->id, $tax, $actor);
    expect($studio->approve($site, $offer->id, $first->id, $tax, $actor)->website_order_id)->toBe($approved->website_order_id);
    expect(fn () => $studio->approve($site, $offer->id, $second->id, $tax, $actor))->toThrow(HttpException::class);
    $order = $approved->order;
    expect($order->total_cents)->toBe(10000)->and($order->fulfillment_method)->toBe('service');
    expect($studio->rundown($site, $offer->episode_id)['deliverables'])->toBe([]);
    $order->payments()->create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'amount_cents' => 10000, 'currency' => 'usd', 'status' => 'paid',
        'metadata' => ['checkout_version' => 2, 'paid_confirmed' => true]]);
    $order->update(['payment_status' => 'paid']);
    expect(array_column($studio->rundown($site, $offer->episode_id)['deliverables'], 'kind'))->toBe(['host_read', 'social_clip']);
    $order->update(['refunded_cents' => 1]);
    expect($studio->rundown($site, $offer->episode_id)['deliverables'])->toBe([]);
});
