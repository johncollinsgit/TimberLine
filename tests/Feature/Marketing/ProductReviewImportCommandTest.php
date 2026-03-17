<?php

use App\Mail\ProductReviewSubmittedMail;
use App\Models\CustomerExternalProfile;
use App\Models\MappingException;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingReviewHistory;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Facades\Mail;

test('replacement review import command creates missing reviews, links matches, and stays idempotent', function () {
    Mail::fake();

    $linda = MarketingProfile::query()->create([
        'first_name' => 'Linda',
        'last_name' => 'Pittman',
        'email' => 'lindapittman@hotmail.com',
        'normalized_email' => 'lindapittman@hotmail.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $linda->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '5970660688131',
        'first_name' => 'Linda',
        'last_name' => 'Pittman',
        'full_name' => 'Linda Pittman',
        'email' => 'lindapittman@hotmail.com',
        'normalized_email' => 'lindapittman@hotmail.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $linda->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '5970660688131',
        'first_name' => 'Linda',
        'last_name' => 'Pittman',
        'full_name' => 'Linda Pittman',
        'email' => 'lindapittman@hotmail.com',
        'normalized_email' => 'lindapittman@hotmail.com',
    ]);

    $erin = MarketingProfile::query()->create([
        'first_name' => 'Erin',
        'last_name' => 'Viera',
        'email' => 'erinviera@ymail.com',
        'normalized_email' => 'erinviera@ymail.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $erin->id,
        'provider' => 'shopify',
        'integration' => 'shopify_customer',
        'store_key' => 'retail',
        'external_customer_id' => '8897156940035',
        'first_name' => 'Erin',
        'last_name' => 'Viera',
        'full_name' => 'Erin Viera',
        'email' => 'erinviera@ymail.com',
        'normalized_email' => 'erinviera@ymail.com',
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $erin->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '8897156940035',
        'first_name' => 'Erin',
        'last_name' => 'Viera',
        'full_name' => 'Erin Viera',
        'email' => 'erinviera@ymail.com',
        'normalized_email' => 'erinviera@ymail.com',
    ]);

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 6942291099907,
        'shopify_customer_id' => '8897156940035',
        'customer_name' => 'Erin Viera',
        'first_name' => 'Erin',
        'last_name' => 'Viera',
        'email' => 'erinviera@ymail.com',
        'customer_email' => 'erinviera@ymail.com',
        'ordered_at' => '2026-03-07 12:54:40',
        'status' => 'fulfilled',
    ]);

    OrderLine::query()->create([
        'order_id' => $order->id,
        'quantity' => 1,
        'ordered_qty' => 1,
        'extra_qty' => 0,
        'raw_title' => 'Candle Club 6 month prepaid Gift option (Shipping included)',
    ]);

    MappingException::query()->create([
        'store_key' => 'retail',
        'shopify_order_id' => 6942291099907,
        'shopify_line_item_id' => 16208154034435,
        'raw_title' => 'Candle Club 6 month prepaid Gift option (Shipping included)',
        'reason' => 'candle_club',
        'payload_json' => [
            'product_id' => 1472438501411,
            'title' => 'Candle Club 6 month prepaid Gift option (Shipping included)',
            'name' => 'Candle Club 6 month prepaid Gift option (Shipping included)',
        ],
    ]);

    MarketingReviewHistory::query()->create([
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '3395945201738',
        'external_review_id' => '480',
        'rating' => 5,
        'title' => 'Sale!',
        'body' => 'Thanks for offering your great candles at a discount. Always love to stock up this time of year.',
        'reviewer_name' => 'Patty Example',
        'reviewer_email' => 'patty@example.com',
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'growave_import',
        'submitted_at' => '2025-12-27 05:13:43',
        'approved_at' => '2025-12-27 05:13:43',
        'reviewed_at' => '2025-12-27 05:13:43',
        'raw_payload' => [
            'product' => [
                'id' => 8923251802371,
                'handle' => 'sale-candles',
            ],
        ],
    ]);

    $existingErin = MarketingReviewHistory::query()->create([
        'provider' => 'growave',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '8897156940035',
        'external_review_id' => '494',
        'rating' => 5,
        'title' => 'Candle Club subscription!',
        'body' => 'I am super excited to be joining this club! There are so many candles I\'m anxious to try out and this is a great way to do that!',
        'reviewer_name' => 'Erin Viera',
        'reviewer_email' => 'erinviera@ymail.com',
        'is_published' => true,
        'status' => 'approved',
        'submission_source' => 'growave_import',
        'submitted_at' => '2026-03-12 12:56:32',
        'approved_at' => '2026-03-12 12:56:32',
        'reviewed_at' => '2026-03-12 12:56:32',
        'raw_payload' => [
            'product' => [
                'id' => 1472438501411,
                'handle' => 'mug-club-gift-option',
            ],
        ],
    ]);

    $this->artisan('marketing:import-replacement-reviews')
        ->expectsOutputToContain('processed=2')
        ->expectsOutputToContain('created=1')
        ->expectsOutputToContain('existing=1')
        ->expectsOutputToContain('matched=2')
        ->assertExitCode(0);

    $lindaReview = MarketingReviewHistory::query()
        ->where('reviewer_name', 'Linda Pittman')
        ->where('title', 'Amazing candles')
        ->first();

    expect($lindaReview)->not->toBeNull()
        ->and($lindaReview->provider)->toBe('backstage')
        ->and($lindaReview->integration)->toBe('native')
        ->and($lindaReview->marketing_profile_id)->toBe($linda->id)
        ->and((string) $lindaReview->product_id)->toBe('8923251802371')
        ->and($lindaReview->product_handle)->toBe('sale-candles')
        ->and($lindaReview->product_title)->toBe('Sale Candles')
        ->and($lindaReview->status)->toBe('approved')
        ->and((string) $lindaReview->submission_source)->toBe('growave_import')
        ->and($lindaReview->submitted_at?->toDateString())->toBe('2026-03-15');

    $erinReview = $existingErin->fresh();

    expect($erinReview)->not->toBeNull()
        ->and($erinReview->marketing_profile_id)->toBe($erin->id)
        ->and((string) $erinReview->product_id)->toBe('1472438501411')
        ->and($erinReview->product_handle)->toBe('mug-club-gift-option')
        ->and($erinReview->product_title)->toBe('Candle Club 6 month prepaid Gift option (Shipping included)');

    expect(MarketingProfileLink::query()->where('source_type', 'product_review_import')->count())->toBe(2);

    Mail::assertSent(ProductReviewSubmittedMail::class, function (ProductReviewSubmittedMail $mail) use ($lindaReview): bool {
        return $mail->review->is($lindaReview);
    });

    Mail::assertSent(ProductReviewSubmittedMail::class, 1);

    $this->artisan('marketing:import-replacement-reviews')
        ->expectsOutputToContain('processed=2')
        ->expectsOutputToContain('created=0')
        ->expectsOutputToContain('existing=2')
        ->expectsOutputToContain('matched=2')
        ->assertExitCode(0);

    expect(MarketingReviewHistory::query()
        ->where('reviewer_name', 'Linda Pittman')
        ->where('title', 'Amazing candles')
        ->count())->toBe(1);

    Mail::assertSent(ProductReviewSubmittedMail::class, 1);
});
