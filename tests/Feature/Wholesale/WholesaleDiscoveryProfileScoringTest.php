<?php

use App\Models\Tenant;
use App\Models\TenantDiscoveryProfile;
use App\Services\Wholesale\WholesaleProspectFitScorer;

test('prospect scoring uses tenant merchandising signals and stays neutral without a profile', function (): void {
    $outdoor = Tenant::query()->create(['name' => 'Trail Supply', 'slug' => 'trail-supply']);
    $neutral = Tenant::query()->create(['name' => 'Neutral Shop', 'slug' => 'neutral-shop']);
    TenantDiscoveryProfile::query()->create([
        'tenant_id' => $outdoor->id,
        'primary_brand_name' => 'Trail Supply',
        'wholesale_brand_label' => 'Trail Supply Wholesale',
        'brand_keywords' => ['outdoor gear'],
        'merchant_signals' => [
            'product_categories' => ['hiking'],
            'best_fit_descriptors' => ['outdoor retailer'],
        ],
        'is_active' => true,
    ]);

    $prospect = [
        'business_name' => 'Mountain Hiking Outfitters',
        'primary_category' => 'outdoor retailer',
        'types' => ['store'],
        'website' => 'https://example.test',
        'phone' => '555-111-2222',
        'operational_status' => 'OPERATIONAL',
    ];
    $scorer = app(WholesaleProspectFitScorer::class);
    $configured = $scorer->score((int) $outdoor->id, $prospect);
    $fallback = $scorer->score((int) $neutral->id, $prospect);

    expect(implode(' ', $configured['positive_signals']))->toContain('tenant merchandising signals')
        ->and($configured['score'])->toBeGreaterThan($fallback['score'])
        ->and(implode(' ', $fallback['missing_information']))->toContain('neutral retail-fit scoring')
        ->and(strtolower(json_encode($fallback)))->not->toContain('candle')
        ->and(strtolower(json_encode($fallback)))->not->toContain('appalachian');
});
