<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileScentQuizResult;
use App\Models\MarketingSocialShareClaim;
use App\Models\MarketingStorefrontEvent;
use App\Models\Tenant;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\ModernForestrySocialShareRewardService;

beforeEach(function (): void {
    Tenant::query()->create([
        'id' => 1,
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    config()->set('marketing.candle_cash.storefront_base_url', 'https://theforestrystudio.com');
});

test('social share start logs a storefront event', function (): void {
    $profile = MarketingProfile::factory()->create(['tenant_id' => 1]);
    $service = app(ModernForestrySocialShareRewardService::class);

    $payload = $service->started($profile, 'facebook', [
        'type' => 'product',
        'id' => 'product:coffeehouse',
        'handle' => 'coffeehouse',
        'title' => 'Coffeehouse',
    ]);

    expect($payload['state'])->toBe('started')
        ->and(MarketingSocialShareClaim::query()->count())->toBe(1)
        ->and(MarketingStorefrontEvent::query()->where('event_type', 'social_share_started')->count())->toBe(1);
});

test('first social share claim awards one candle cash and duplicate does not award again', function (): void {
    $profile = MarketingProfile::factory()->create(['tenant_id' => 1]);
    $service = app(ModernForestrySocialShareRewardService::class);
    $target = [
        'type' => 'product',
        'id' => 'product:coffeehouse',
        'handle' => 'coffeehouse',
        'title' => 'Coffeehouse',
    ];

    $first = $service->claim($profile, 'facebook', $target);
    $second = $service->claim($profile, 'facebook', $target);

    expect($first['alreadyAwarded'])->toBeFalse()
        ->and($second['alreadyAwarded'])->toBeTrue()
        ->and(CandleCashTransaction::query()->where('source', 'social_share_reward')->count())->toBe(1)
        ->and(app(CandleCashService::class)->currentBalance($profile))->toBe(1.0);
});

test('same share target can earn once per platform', function (): void {
    $profile = MarketingProfile::factory()->create(['tenant_id' => 1]);
    $service = app(ModernForestrySocialShareRewardService::class);
    $target = [
        'type' => 'purchased_product',
        'id' => 'order-line:42',
        'handle' => 'coffeehouse',
        'title' => 'Coffeehouse',
    ];

    $service->claim($profile, 'facebook', $target);
    $service->claim($profile, 'instagram', $target);

    expect(CandleCashTransaction::query()->where('source', 'social_share_reward')->count())->toBe(2)
        ->and(app(CandleCashService::class)->currentBalance($profile))->toBe(2.0);
});

test('mobile social share endpoints require a mobile profile token', function (): void {
    $this->getJson('/api/mobile/v1/modern-forestry/social-share/config')
        ->assertUnauthorized();

    $this->postJson('/api/mobile/v1/modern-forestry/social-share/claim', [
        'platform' => 'facebook',
        'target' => [
            'type' => 'product',
            'id' => 'product:coffeehouse',
            'handle' => 'coffeehouse',
        ],
    ])->assertUnauthorized();
});

test('public scent personality share page hides private customer data', function (): void {
    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
        'first_name' => 'Ada',
        'last_name' => 'Woods',
        'email' => 'ada@example.com',
        'normalized_email' => 'ada@example.com',
    ]);

    $result = MarketingProfileScentQuizResult::query()->create([
        'tenant_id' => 1,
        'marketing_profile_id' => $profile->id,
        'quiz_version' => 'scent-v1',
        'axis_scores' => [
            ['key' => 'woodsy', 'label' => 'Woodsy', 'score' => 92],
            ['key' => 'smoky', 'label' => 'Smoky', 'score' => 81],
        ],
        'dominant_traits' => ['woodsy', 'smoky'],
        'headline' => 'Woodsy + Smoky',
        'personality_title' => 'The Campfire Archivist',
        'personality_body' => 'Grounded, warm, and drawn to quiet spaces.',
        'public_share_token' => 'publicscenttoken1234567890',
        'answers' => [],
        'completed_at' => now(),
    ]);
    $result->refresh();

    expect($result->public_share_token)->toBe('publicscenttoken1234567890');

    $this->get('/share/scent-personality/'.$result->public_share_token)
        ->assertOk()
        ->assertSee('Woodsy + Smoky')
        ->assertSee('The Campfire Archivist')
        ->assertSee('forestry-backstage-intro-tree.png', false)
        ->assertDontSee('Ada')
        ->assertDontSee('ada@example.com');
});
