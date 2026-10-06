<?php

use App\Models\MarketingProfile;
use App\Models\ModernForestryMobileBagSnapshot;
use App\Models\Tenant;
use App\Services\Mobile\ModernForestryMobileBagReminderService;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use App\Services\Shopify\ShopifyOrderIngestor;

test('a confirmed Shopify purchase completes a matching mobile bag before it can be reminded', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'Bakery25@Example.com',
        'normalized_email' => 'bakery25@example.com',
    ]);
    $snapshot = ModernForestryMobileBagSnapshot::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'email' => 'BAKERY25@example.com',
        'item_count' => 3,
        'is_active' => true,
        'cart_started_at' => now()->subMinute(),
        'next_reminder_at' => now()->addDay(),
    ]);

    app(ShopifyOrderIngestor::class)->ingest([
        'key' => 'retail',
        'source' => 'shopify_retail',
        'tenant_id' => $tenant->id,
    ], [
        'id' => 32884,
        'name' => '#32884',
        'created_at' => now()->toIso8601String(),
        'processed_at' => now()->toIso8601String(),
        'financial_status' => 'paid',
        'email' => 'bakery25@example.com',
        'line_items' => [],
    ]);

    $snapshot->refresh();

    expect($snapshot->is_active)->toBeFalse()
        ->and($snapshot->next_reminder_at)->toBeNull()
        ->and($snapshot->completed_at)->not->toBeNull()
        ->and(data_get($snapshot->meta, 'completion.source'))->toBe('shopify_confirmed_order')
        ->and(data_get($snapshot->meta, 'completion.shopify_order_id'))->toBe(32884);
});

test('a stale post-checkout bag sync stays completed until the customer changes the bag', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'bag@example.com',
        'normalized_email' => 'bag@example.com',
    ]);
    $session = new ModernForestryMobileCustomerSession($profile, 'test-token');
    $service = app(ModernForestryMobileBagReminderService::class);
    $items = [[
        'productHandle' => 'three-candle-bundle',
        'productTitle' => 'Three Candle Bundle',
        'variantId' => '123',
        'variantTitle' => '8oz',
        'quantity' => 1,
        'price' => '54.00',
    ]];

    $service->sync($session, $items, '54.00', 'USD');
    $service->completeBagsForConfirmedPurchase((int) $tenant->id, ['bag@example.com'], now(), 32885);

    $service->sync($session, $items, '54.00', 'USD');
    $staleSnapshot = ModernForestryMobileBagSnapshot::query()->sole();

    expect($staleSnapshot->is_active)->toBeFalse()
        ->and($staleSnapshot->completed_at)->not->toBeNull();

    $service->sync($session, [[...$items[0], 'quantity' => 2]], '108.00', 'USD');
    $newBagSnapshot = ModernForestryMobileBagSnapshot::query()->sole();

    expect($newBagSnapshot->is_active)->toBeTrue()
        ->and($newBagSnapshot->completed_at)->toBeNull()
        ->and($newBagSnapshot->reminder_count)->toBe(0);
});
