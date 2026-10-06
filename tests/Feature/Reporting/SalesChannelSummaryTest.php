<?php

use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WebsiteOrder;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\Reporting\SalesChannelSummaryService;
use Illuminate\Support\Carbon;

function salesChannelTenant(string $slug = 'sales-channel-pilot'): Tenant
{
    return Tenant::query()->create(['name' => 'Sales channel pilot', 'slug' => $slug]);
}

test('sales channel summaries normalize confirmed sources without copying Website orders into legacy orders', function (): void {
    $tenant = salesChannelTenant();
    $otherTenant = salesChannelTenant('other-sales-channel-pilot');
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $site = app(ManagedWebsiteService::class)->createSite($tenant, $actor);
    $now = Carbon::parse('2026-07-27 10:00:00');

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'order_number' => '#SHOP-100',
        'ordered_at' => $now,
        'status' => 'complete',
        'total_price' => 91.50,
    ]);
    $legacyOrderCount = Order::query()->count();

    WebsiteOrder::query()->create([
        'tenant_id' => $tenant->id,
        'tenant_site_id' => $site->id,
        'number' => 'WEB-100',
        'lookup_token' => 'website-order-lookup-token-100',
        'payment_status' => 'paid',
        'subtotal_cents' => 12000,
        'total_cents' => 12000,
        'paid_at' => $now,
    ]);
    WebsiteOrder::query()->create([
        'tenant_id' => $tenant->id,
        'tenant_site_id' => $site->id,
        'number' => 'WEB-PENDING',
        'lookup_token' => 'website-order-lookup-token-pending',
        'payment_status' => 'pending',
        'subtotal_cents' => 7000,
        'total_cents' => 7000,
    ]);

    $summary = app(SalesChannelSummaryService::class)->forTenant($tenant, $now->copy()->startOfDay(), $now->copy()->endOfDay());

    expect($summary['order_count'])->toBe(2)
        ->and($summary['revenue_cents'])->toBe(21150)
        ->and($summary['channel_count'])->toBe(2)
        ->and($summary['has_website_channel'])->toBeTrue()
        ->and(collect($summary['channels'])->pluck('label')->all())->toContain('Everbranch Website', 'Shopify')
        ->and(Order::query()->count())->toBe($legacyOrderCount)
        ->and(WebsiteOrder::query()->forTenant($otherTenant)->count())->toBe(0);
});

test('sales channels page is tenant-scoped and read-only', function (): void {
    $tenant = salesChannelTenant();
    $otherTenant = salesChannelTenant('sales-channel-other');
    $actor = User::factory()->tenantAdmin()->create(['is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    $actor->tenants()->attach($tenant->id, ['role' => 'admin']);
    $otherSite = app(ManagedWebsiteService::class)->createSite($otherTenant, $actor);
    WebsiteOrder::query()->create([
        'tenant_id' => $otherTenant->id,
        'tenant_site_id' => $otherSite->id,
        'number' => 'OTHER-WEB-100',
        'lookup_token' => 'other-website-order-lookup-token',
        'payment_status' => 'paid',
        'subtotal_cents' => 9000,
        'total_cents' => 9000,
        'paid_at' => now(),
    ]);

    $this->actingAs($actor)
        ->get(route('sales-channels.index'))
        ->assertOk()
        ->assertSeeText('Sales channels')
        ->assertSeeText('No confirmed sales in this range')
        ->assertDontSeeText('Everbranch Website');
});
