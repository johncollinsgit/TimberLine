<?php

use App\Models\MarketingCampaign;
use App\Models\MarketingSegment;
use App\Models\Tenant;
use App\Services\Marketing\ModernForestryScentAudienceActivationService;

beforeEach(function (): void {
    Tenant::query()->firstOrCreate([
        'id' => 1,
    ], [
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);
});

test('scent audience activation creates a reusable saved segment', function (): void {
    $service = app(ModernForestryScentAudienceActivationService::class);

    $segment = $service->createSegment(tenantId: 1, trait: 'woodsy', userId: null);

    expect($segment)->toBeInstanceOf(MarketingSegment::class)
        ->and($segment->name)->toBe('Woodsy Scent Quiz Audience')
        ->and(data_get($segment->rules_json, 'conditions.0.field'))->toBe('scent_dominant_traits')
        ->and(data_get($segment->rules_json, 'conditions.0.value'))->toBe('woodsy');

    $again = $service->createSegment(tenantId: 1, trait: 'woodsy', userId: null);

    expect($again->id)->toBe($segment->id)
        ->and(MarketingSegment::query()->where('tenant_id', 1)->count())->toBe(1);
});

test('scent audience activation creates a prefilled discount campaign draft', function (): void {
    $service = app(ModernForestryScentAudienceActivationService::class);

    $campaign = $service->createCampaignDraft(
        tenantId: 1,
        storeKey: 'retail',
        trait: 'citrus',
        userId: null
    );

    expect($campaign)->toBeInstanceOf(MarketingCampaign::class)
        ->and($campaign->channel)->toBe('email')
        ->and($campaign->status)->toBe('draft')
        ->and($campaign->coupon_code)->toBe('CITRUS10')
        ->and($campaign->objective)->toBe('wishlist_triggered_offer')
        ->and(data_get($campaign->target_snapshot, 'trait'))->toBe('citrus')
        ->and($campaign->segment)->not->toBeNull()
        ->and($campaign->segment?->name)->toBe('Citrus Scent Quiz Audience');
});
