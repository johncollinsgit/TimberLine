<?php

use App\Http\Middleware\VerifyMarketingStorefrontRequest;
use App\Mail\ModernForestryScentQuizWeeklyReportMail;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingProfileScentQuizResult;
use App\Models\MarketingStorefrontEvent;
use App\Models\Order;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use Illuminate\Support\Facades\Mail;

test('customer dashboard renders the scent quiz for a linked Shopify customer and saves the result to the account', function (): void {
    $this->withoutMiddleware(VerifyMarketingStorefrontRequest::class);

    $tenant = modernForestryScentQuizTenant();
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'first_name' => 'Scent',
        'last_name' => 'Customer',
        'email' => 'scent.customer@example.com',
        'normalized_email' => 'scent.customer@example.com',
        'phone' => '5554441111',
        'normalized_phone' => '+15554441111',
    ]);

    MarketingProfileLink::query()->create([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:777',
        'match_method' => 'direct_source_id',
    ]);

    $query = [
        'store_key' => 'retail',
        'logged_in_customer_id' => '777',
        'scent_quiz' => 1,
    ];

    $this->get(route('marketing.shopify.account', $query))
        ->assertOk()
        ->assertSeeText('Find your scent personality')
        ->assertSeeText('Save scent profile');

    $quiz = app(\App\Services\Mobile\ModernForestryMobileScentQuizService::class)->definition($profile);
    $answers = collect($quiz['questions'])
        ->map(function (array $question): array {
            return [
                'question_id' => (string) $question['id'],
                'option_id' => (string) data_get($question, 'options.0.id'),
            ];
        })
        ->values()
        ->all();

    $this->post(route('marketing.shopify.scent-quiz.submit', $query), [
        'answers' => $answers,
    ])->assertOk()
        ->assertSeeText('Your scent profile is saved and now follows your account.');

    $saved = MarketingProfileScentQuizResult::query()
        ->where('marketing_profile_id', $profile->id)
        ->first();

    expect($saved)->not()->toBeNull()
        ->and((string) $saved?->quiz_version)->toBe('scent-v1');
});

test('modern forestry scent quiz analytics report counts quiz takers, wishlist adds, and attributed purchases', function (): void {
    $tenant = modernForestryScentQuizTenant('modern-forestry-scent-analytics');

    $recentProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'recent.quiz@example.com',
        'normalized_email' => 'recent.quiz@example.com',
    ]);

    $olderProfile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'older.quiz@example.com',
        'normalized_email' => 'older.quiz@example.com',
    ]);

    MarketingProfileScentQuizResult::query()->create([
        'marketing_profile_id' => $recentProfile->id,
        'tenant_id' => $tenant->id,
        'quiz_version' => 'scent-v1',
        'axis_scores' => ['woodsy' => 88],
        'dominant_traits' => ['woodsy', 'earthy'],
        'headline' => 'Woodsy + Earthy',
        'personality_title' => 'The Grounded Explorer',
        'personality_body' => 'Rooted and calm.',
        'answers' => [],
        'completed_at' => now()->subDays(2),
    ]);

    MarketingProfileScentQuizResult::query()->create([
        'marketing_profile_id' => $olderProfile->id,
        'tenant_id' => $tenant->id,
        'quiz_version' => 'scent-v1',
        'axis_scores' => ['clean' => 74],
        'dominant_traits' => ['clean', 'citrus'],
        'headline' => 'Clean + Citrus',
        'personality_title' => 'The Bright Minimalist',
        'personality_body' => 'Fresh and polished.',
        'answers' => [],
        'completed_at' => now()->subDays(12),
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'wishlist_added',
        'status' => 'ok',
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'wishlist:1',
        'meta' => [
            'mf_source_label' => 'scent_quiz',
        ],
        'occurred_at' => now()->subDays(1),
        'resolution_status' => 'resolved',
    ]);

    MarketingStorefrontEvent::query()->create([
        'tenant_id' => $tenant->id,
        'event_type' => 'wishlist_added',
        'status' => 'ok',
        'source_type' => 'shopify_storefront_funnel',
        'source_id' => 'wishlist:2',
        'meta' => [
            'mf_source_label' => 'something_else',
        ],
        'occurred_at' => now()->subDays(1),
        'resolution_status' => 'resolved',
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'order_number' => '#4001',
        'status' => 'paid',
        'total_price' => 64.00,
        'ordered_at' => now()->subDays(3),
        'attribution_meta' => [
            'email_source_label' => 'scent_quiz',
        ],
    ]);

    Order::query()->create([
        'tenant_id' => $tenant->id,
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'order_number' => '#4002',
        'status' => 'paid',
        'total_price' => 18.00,
        'ordered_at' => now()->subDays(3),
        'attribution_meta' => [
            'email_source_label' => 'email_campaign',
        ],
    ]);

    $report = app(\App\Services\Marketing\ModernForestryScentQuizAnalyticsService::class)
        ->reportSnapshot($tenant->id, now(), 7);

    expect(data_get($report, 'quiz.recent_takers'))->toBe(1)
        ->and(data_get($report, 'quiz.total_takers'))->toBe(2)
        ->and(data_get($report, 'wishlist.recent_additions'))->toBe(1)
        ->and(data_get($report, 'orders.recent_purchases'))->toBe(1)
        ->and((float) data_get($report, 'orders.recent_revenue'))->toBe(64.0);
});

test('modern forestry scent quiz weekly report command sends the summary email', function (): void {
    Mail::fake();

    $tenant = modernForestryScentQuizTenant('modern-forestry-scent-mail');
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $tenant->id,
        'email' => 'mailer.quiz@example.com',
        'normalized_email' => 'mailer.quiz@example.com',
    ]);

    MarketingProfileScentQuizResult::query()->create([
        'marketing_profile_id' => $profile->id,
        'tenant_id' => $tenant->id,
        'quiz_version' => 'scent-v1',
        'axis_scores' => ['sweet' => 81],
        'dominant_traits' => ['sweet', 'floral'],
        'headline' => 'Sweet + Floral',
        'personality_title' => 'The Velvet Host',
        'personality_body' => 'Warm and inviting.',
        'answers' => [],
        'completed_at' => now()->subDay(),
    ]);

    $this->artisan('marketing:send-modern-forestry-scent-quiz-report', [
        '--email' => 'info@theforestrystudio.com',
        '--days' => 7,
    ])->assertSuccessful();

    Mail::assertSent(ModernForestryScentQuizWeeklyReportMail::class, function (ModernForestryScentQuizWeeklyReportMail $mail): bool {
        return (int) data_get($mail->report, 'quiz.recent_takers', 0) === 1
            && (int) data_get($mail->report, 'quiz.total_takers', 0) === 1;
    });
});

function modernForestryScentQuizTenant(
    string $slug = 'modern-forestry-scent-storefront',
    string $shopDomain = 'modernforestry.myshopify.com'
): Tenant {
    $tenant = Tenant::query()->create([
        'name' => str_replace('-', ' ', ucfirst($slug)),
        'slug' => $slug,
    ]);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => $shopDomain,
            'access_token' => 'modern-forestry-scent-storefront-token',
            'tenant_id' => $tenant->id,
            'installed_at' => now(),
        ]
    );

    return $tenant;
}
