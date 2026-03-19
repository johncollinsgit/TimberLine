<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingReviewHistory;
use App\Models\MarketingReviewSummary;

test('growave snapshot merge remaps donor rows to target profiles and stays idempotent', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Target',
        'last_name' => 'Customer',
        'email' => 'retail.customer@example.com',
        'normalized_email' => 'retail.customer@example.com',
        'phone' => '+1 (555) 600-1111',
        'normalized_phone' => '5556001111',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '5001',
        'external_customer_gid' => 'gid://shopify/Customer/5001',
        'first_name' => 'Target',
        'last_name' => 'Customer',
        'email' => 'retail.customer@example.com',
        'normalized_email' => 'retail.customer@example.com',
        'phone' => '+1 (555) 600-1111',
        'normalized_phone' => '5556001111',
        'source_channels' => ['shopify'],
        'raw_metafields' => [],
        'synced_at' => '2026-03-12 00:00:00',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'adjust',
        'points' => 5,
        'source' => 'admin',
        'source_id' => 'seed-admin-adjustment',
        'description' => 'Seed admin adjustment',
    ]);

    CandleCashBalance::query()->create([
        'marketing_profile_id' => $profile->id,
        'balance' => 5,
    ]);

    $snapshotPath = makeGrowaveSnapshotDatabase();

    $this->artisan('marketing:merge-growave-snapshot', [
        'snapshot' => $snapshotPath,
    ])->assertExitCode(0);

    $this->artisan('marketing:merge-growave-snapshot', [
        'snapshot' => $snapshotPath,
    ])->assertExitCode(0);

    $external = CustomerExternalProfile::query()
        ->where('integration', 'growave')
        ->where('store_key', 'retail')
        ->where('external_customer_id', '5001')
        ->first();

    expect($external)->not->toBeNull();

    $external = $external->refresh();

    $summary = MarketingReviewSummary::query()
        ->where('integration', 'growave')
        ->where('store_key', 'retail')
        ->where('external_customer_id', '5001')
        ->first();

    $history = MarketingReviewHistory::query()
        ->where('integration', 'growave')
        ->where('store_key', 'retail')
        ->where('external_review_id', '8001')
        ->first();

    $transaction = CandleCashTransaction::query()
        ->where('source', 'growave_activity')
        ->where('source_id', 'retail:5001:7001')
        ->first();

    expect((int) $external->marketing_profile_id)->toBe($profile->id)
        ->and((int) $external->points_balance)->toBe(20)
        ->and((string) $external->referral_link)->toBe('https://growave.example.test/ref/5001')
        ->and(CustomerExternalProfile::query()->where('integration', 'growave')->count())->toBe(1)
        ->and($summary)->not->toBeNull()
        ->and((int) $summary->marketing_profile_id)->toBe($profile->id)
        ->and((int) $summary->review_count)->toBe(1)
        ->and((float) $summary->average_rating)->toBe(5.0)
        ->and(MarketingReviewSummary::query()->where('integration', 'growave')->count())->toBe(1)
        ->and($history)->not->toBeNull()
        ->and((int) $history->marketing_profile_id)->toBe($profile->id)
        ->and((int) $history->marketing_review_summary_id)->toBe((int) $summary->id)
        ->and(MarketingReviewHistory::query()->where('integration', 'growave')->count())->toBe(1)
        ->and($transaction)->not->toBeNull()
        ->and((int) $transaction->marketing_profile_id)->toBe($profile->id)
        ->and((float) $transaction->candle_cash_delta)->toBe(0.06)
        ->and((bool) $transaction->legacy_points_origin)->toBeTrue()
        ->and((int) $transaction->legacy_points_value)->toBe(20)
        ->and((string) optional($transaction->created_at)->toDateTimeString())->toBe('2026-03-01 10:00:00')
        ->and(CandleCashTransaction::query()->where('source', 'growave_activity')->count())->toBe(1)
        ->and((float) CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->value('balance'))->toBe(5.06);

    @unlink($snapshotPath);
});

function makeGrowaveSnapshotDatabase(): string
{
    $path = storage_path('framework/testing/growave-snapshot-' . uniqid('', true) . '.sqlite');
    if (is_file($path)) {
        @unlink($path);
    }

    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec('CREATE TABLE customer_external_profiles (
        id INTEGER PRIMARY KEY,
        marketing_profile_id INTEGER NULL,
        provider TEXT NULL,
        integration TEXT NULL,
        store_key TEXT NULL,
        external_customer_id TEXT NULL,
        external_customer_gid TEXT NULL,
        first_name TEXT NULL,
        last_name TEXT NULL,
        full_name TEXT NULL,
        email TEXT NULL,
        normalized_email TEXT NULL,
        phone TEXT NULL,
        normalized_phone TEXT NULL,
        accepts_marketing INTEGER NULL,
        order_count INTEGER NULL,
        last_order_at TEXT NULL,
        last_activity_at TEXT NULL,
        source_channels TEXT NULL,
        raw_metafields TEXT NULL,
        points_balance INTEGER NULL,
        vip_tier TEXT NULL,
        referral_link TEXT NULL,
        synced_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE marketing_review_summaries (
        id INTEGER PRIMARY KEY,
        marketing_profile_id INTEGER NULL,
        provider TEXT NULL,
        integration TEXT NULL,
        store_key TEXT NULL,
        external_customer_id TEXT NULL,
        external_customer_email TEXT NULL,
        review_count INTEGER NULL,
        published_review_count INTEGER NULL,
        average_rating REAL NULL,
        last_reviewed_at TEXT NULL,
        source_synced_at TEXT NULL,
        raw_payload TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE marketing_review_histories (
        id INTEGER PRIMARY KEY,
        marketing_profile_id INTEGER NULL,
        marketing_review_summary_id INTEGER NULL,
        provider TEXT NULL,
        integration TEXT NULL,
        store_key TEXT NULL,
        external_customer_id TEXT NULL,
        external_review_id TEXT NULL,
        rating INTEGER NULL,
        title TEXT NULL,
        body TEXT NULL,
        is_published INTEGER NULL,
        is_pinned INTEGER NULL,
        is_verified_buyer INTEGER NULL,
        votes INTEGER NULL,
        has_media INTEGER NULL,
        media_count INTEGER NULL,
        product_id TEXT NULL,
        product_title TEXT NULL,
        reviewed_at TEXT NULL,
        source_synced_at TEXT NULL,
        raw_payload TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE candle_cash_transactions (
        id INTEGER PRIMARY KEY,
        marketing_profile_id INTEGER NULL,
        type TEXT NULL,
        points INTEGER NULL,
        source TEXT NULL,
        source_id TEXT NULL,
        description TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec("INSERT INTO customer_external_profiles (
        id, marketing_profile_id, provider, integration, store_key, external_customer_id, external_customer_gid,
        first_name, last_name, full_name, email, normalized_email, phone, normalized_phone, accepts_marketing,
        order_count, last_order_at, last_activity_at, source_channels, raw_metafields, points_balance, vip_tier,
        referral_link, synced_at, created_at, updated_at
    ) VALUES (
        1, 185, 'shopify', 'growave', 'retail', '5001', 'growave:5001',
        'Retail', 'Customer', 'Retail Customer', 'retail.customer@example.com', 'retail.customer@example.com',
        '+1 (555) 600-1111', '5556001111', 1, NULL, NULL, '2026-03-12 12:00:00',
        '[\"shopify\",\"growave\"]',
        '[{\"namespace\":\"growave\",\"key\":\"review_count\",\"value\":\"1\",\"type\":\"number_integer\"}]',
        20, 'Gold', 'https://growave.example.test/ref/5001', '2026-03-12 12:00:00', '2026-03-12 12:00:00', '2026-03-12 12:00:00'
    )");

    $pdo->exec("INSERT INTO marketing_review_summaries (
        id, marketing_profile_id, provider, integration, store_key, external_customer_id, external_customer_email,
        review_count, published_review_count, average_rating, last_reviewed_at, source_synced_at, raw_payload, created_at, updated_at
    ) VALUES (
        1, 185, 'growave', 'growave', 'retail', '5001', 'retail.customer@example.com',
        1, 1, 5.0, '2026-03-05 10:00:00', '2026-03-12 12:00:00',
        '{\"api_total\":1,\"pages\":1}', '2026-03-12 12:00:00', '2026-03-12 12:00:00'
    )");

    $pdo->exec("INSERT INTO marketing_review_histories (
        id, marketing_profile_id, marketing_review_summary_id, provider, integration, store_key,
        external_customer_id, external_review_id, rating, title, body, is_published, is_pinned,
        is_verified_buyer, votes, has_media, media_count, product_id, product_title, reviewed_at,
        source_synced_at, raw_payload, created_at, updated_at
    ) VALUES (
        1, 185, 1, 'growave', 'growave', 'retail',
        '5001', '8001', 5, 'Great scent', 'Loved it', 1, 0,
        1, 3, 0, 0, '901', 'Nightfall Candle', '2026-03-05 10:00:00',
        '2026-03-12 12:00:00', '{\"id\":8001}', '2026-03-12 12:00:00', '2026-03-12 12:00:00'
    )");

    $pdo->exec("INSERT INTO candle_cash_transactions (
        id, marketing_profile_id, type, points, source, source_id, description, created_at, updated_at
    ) VALUES (
        1, 185, 'earn', 20, 'growave_activity', 'retail:5001:7001', 'Imported Growave activity #7001 (reward)', '2026-03-01 10:00:00', '2026-03-01 10:00:00'
    )");

    return $path;
}
