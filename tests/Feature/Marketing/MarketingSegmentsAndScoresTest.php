<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileScore;
use App\Models\MarketingSegment;
use App\Models\User;
use App\Services\Marketing\MarketingProfileScoreService;
use App\Services\Marketing\MarketingSegmentEvaluator;
use App\Services\Marketing\MarketingSegmentPreviewService;

test('segment evaluator handles simple and rules', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'And',
        'accepts_sms_marketing' => true,
        'source_channels' => ['shopify', 'online'],
    ]);

    $segment = MarketingSegment::query()->create([
        'name' => 'AND test segment',
        'status' => 'active',
        'channel_scope' => 'any',
        'rules_json' => [
            'logic' => 'and',
            'conditions' => [
                ['field' => 'has_sms_consent', 'operator' => 'eq', 'value' => true],
                ['field' => 'source_channel', 'operator' => 'contains', 'value' => 'shopify'],
            ],
            'groups' => [],
        ],
    ]);

    $result = app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $profile);

    expect($result['matched'])->toBeTrue()
        ->and($result['reasons'])->toContain('has_sms_consent eq true');
});

test('segment evaluator handles or rules', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Or',
        'accepts_sms_marketing' => true,
        'accepts_email_marketing' => false,
    ]);

    $segment = MarketingSegment::query()->create([
        'name' => 'OR test segment',
        'status' => 'active',
        'rules_json' => [
            'logic' => 'or',
            'conditions' => [
                ['field' => 'has_email_consent', 'operator' => 'eq', 'value' => true],
                ['field' => 'has_sms_consent', 'operator' => 'eq', 'value' => true],
            ],
            'groups' => [],
        ],
    ]);

    $result = app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $profile);

    expect($result['matched'])->toBeTrue();
});

test('system segments are seeded and segment preview returns matches', function () {
    MarketingProfile::query()->create([
        'first_name' => 'Preview',
        'last_name' => 'One',
        'email' => 'preview.one@example.com',
        'normalized_email' => 'preview.one@example.com',
        'accepts_sms_marketing' => true,
    ]);
    MarketingProfile::query()->create([
        'first_name' => 'Preview',
        'last_name' => 'Two',
        'email' => 'preview.two@example.com',
        'normalized_email' => 'preview.two@example.com',
        'accepts_sms_marketing' => false,
    ]);

    $segment = MarketingSegment::query()->create([
        'name' => 'Preview Segment',
        'status' => 'active',
        'rules_json' => [
            'logic' => 'and',
            'conditions' => [
                ['field' => 'has_sms_consent', 'operator' => 'eq', 'value' => true],
            ],
            'groups' => [],
        ],
    ]);

    $preview = app(MarketingSegmentPreviewService::class)->preview($segment, 10);

    expect(MarketingSegment::query()->where('is_system', true)->count())->toBeGreaterThanOrEqual(8)
        ->and($preview['count'])->toBe(1)
        ->and($preview['profiles']->first()?->email)->toBe('preview.one@example.com');
});

test('score engine calculates and stores score idempotently', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Scored',
        'email' => 'scored@example.com',
        'normalized_email' => 'scored@example.com',
        'phone' => '5551234567',
        'normalized_phone' => '+15551234567',
        'accepts_email_marketing' => true,
        'accepts_sms_marketing' => true,
        'source_channels' => ['shopify', 'square', 'event'],
    ]);

    $service = app(MarketingProfileScoreService::class);
    $first = $service->refreshForProfile($profile);
    $second = $service->refreshForProfile($profile);

    $profile->refresh();

    expect($first['score'])->toBeGreaterThan(0)
        ->and($first['score'])->toBe($second['score'])
        ->and($profile->marketing_score)->not->toBeNull()
        ->and(MarketingProfileScore::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('score_type', 'likelihood')
            ->count())->toBe(1);
});

test('customer detail page shows score breakdown', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Score',
        'last_name' => 'Detail',
        'email' => 'score.detail@example.com',
        'normalized_email' => 'score.detail@example.com',
        'accepts_sms_marketing' => true,
        'phone' => '5557771111',
        'normalized_phone' => '+15557771111',
    ]);

    app(MarketingProfileScoreService::class)->refreshForProfile($profile);

    $admin = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Score Breakdown')
        ->assertSeeText('Marketing Likelihood');
});
