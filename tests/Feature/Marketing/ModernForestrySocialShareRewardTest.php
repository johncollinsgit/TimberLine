<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileScentQuizResult;
use App\Models\MarketingSocialShareClaim;
use App\Models\MarketingStorefrontEvent;
use App\Models\Tenant;
use App\Services\Mobile\ModernForestryMobileScentQuizService;
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
        ->assertSee('/share/scent-personality/'.$result->public_share_token.'/image.png', false)
        ->assertDontSee('Ada')
        ->assertDontSee('ada@example.com');
});

test('public scent personality share image renders a branded preview card', function (): void {
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
            ['key' => 'clean', 'label' => 'Clean', 'score' => 88],
            ['key' => 'citrus', 'label' => 'Citrus', 'score' => 73],
            ['key' => 'earthy', 'label' => 'Earthy', 'score' => 42],
        ],
        'dominant_traits' => ['clean', 'citrus', 'earthy'],
        'headline' => 'Clean + Citrus',
        'personality_title' => 'The Sunlit Organizer',
        'personality_body' => 'Fresh, bright, and a little polished.',
        'public_share_token' => 'previewcardsharetoken1234567890',
        'answers' => [],
        'completed_at' => now(),
    ]);

    $response = $this->get('/share/scent-personality/'.$result->public_share_token.'/image.png');

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('image/png')
        ->and(strlen($response->getContent()))->toBeGreaterThan(5000);
});

test('public scent personality share page can run an anonymous quiz funnel and recommend products', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);

    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
    ]);

    $result = MarketingProfileScentQuizResult::query()->create([
        'tenant_id' => 1,
        'marketing_profile_id' => $profile->id,
        'quiz_version' => 'scent-v1',
        'axis_scores' => ['clean' => 88, 'citrus' => 73, 'earthy' => 42],
        'dominant_traits' => ['clean', 'citrus', 'earthy'],
        'headline' => 'Clean + Citrus',
        'personality_title' => 'The Sunlit Organizer',
        'personality_body' => 'Fresh, bright, and a little polished.',
        'public_share_token' => 'funnelsharetoken1234567890',
        'answers' => [],
        'completed_at' => now(),
    ]);

    $quiz = app(ModernForestryMobileScentQuizService::class)->publicDefinition();
    $answers = collect($quiz['questions'])
        ->map(fn (array $question): array => [
            'question_id' => $question['id'],
            'option_id' => $question['options'][0]['id'],
        ])
        ->values()
        ->all();

    $response = $this->post(route('marketing.public.scent-personality-share.submit', [
        'token' => $result->public_share_token,
        'source' => 'facebook_post',
    ]), [
        'source' => 'facebook_post',
        'answers' => $answers,
    ]);

    $response->assertOk()
        ->assertSeeText('Top 4 scent matches')
        ->assertSeeText('Show my scent map')
        ->assertSeeText('Save My Results')
        ->assertSeeText('Candles to start with');

    expect(MarketingStorefrontEvent::query()->where('event_type', 'public_scent_quiz_completed')->count())->toBe(1);
});

test('public scent personality product redirects preserve scent quiz attribution', function (): void {
    config()->set('mobile_catalog.fake_enabled', true);

    $profile = MarketingProfile::factory()->create([
        'tenant_id' => 1,
    ]);

    $result = MarketingProfileScentQuizResult::query()->create([
        'tenant_id' => 1,
        'marketing_profile_id' => $profile->id,
        'quiz_version' => 'scent-v1',
        'axis_scores' => ['woodsy' => 92, 'earthy' => 70],
        'dominant_traits' => ['woodsy', 'earthy'],
        'headline' => 'Woodsy + Earthy',
        'personality_title' => 'The Grounded Explorer',
        'personality_body' => 'Rooted and calm.',
        'public_share_token' => 'productredirecttoken1234567890',
        'answers' => [],
        'completed_at' => now(),
    ]);

    $response = $this->get(route('marketing.public.scent-personality-share.product', [
        'token' => $result->public_share_token,
        'handle' => 'fraser-fir',
        'source' => 'facebook_post',
    ]));

    $location = (string) $response->headers->get('Location');

    $response->assertRedirect();
    expect($location)
        ->toContain('https://theforestrystudio.com/products/fraser-fir')
        ->toContain('mf_source_label=scent_quiz')
        ->toContain('mf_template_key=modern_forestry_scent_quiz')
        ->toContain('mf_share_source=facebook_post');
});
