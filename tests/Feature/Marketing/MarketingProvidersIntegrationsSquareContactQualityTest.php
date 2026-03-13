<?php

use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\User;

beforeEach(function () {
    config()->set('marketing.square.enabled', true);
    config()->set('marketing.square.sync_customers_enabled', true);
    config()->set('marketing.square.sync_orders_enabled', true);
    config()->set('marketing.square.sync_payments_enabled', true);
});

test('providers integrations page renders square contact quality audit and filters square-only missing contact profiles', function () {
    $user = User::factory()->create([
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);

    $squareOnlyMissingContact = MarketingProfile::query()->create([
        'first_name' => 'No',
        'last_name' => 'Contact',
        'source_channels' => ['square'],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $squareOnlyMissingContact->id,
        'source_type' => 'square_customer',
        'source_id' => 'SQ-CUST-1',
        'match_method' => 'exact_square_customer',
        'confidence' => 1,
    ]);

    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-1',
        'given_name' => 'No',
        'family_name' => 'Contact',
        'synced_at' => now(),
    ]);

    SquarePayment::query()->create([
        'square_payment_id' => 'SQ-PAY-1',
        'square_customer_id' => 'SQ-CUST-1',
        'amount_money' => 12500,
        'currency' => 'USD',
        'status' => 'COMPLETED',
        'created_at_source' => now(),
        'raw_payload' => [
            'card_details' => [
                'card' => [
                    'cardholder_name' => 'Manual Buyer',
                ],
            ],
        ],
        'synced_at' => now(),
    ]);

    $squareAndShopify = MarketingProfile::query()->create([
        'first_name' => 'Linked',
        'last_name' => 'Buyer',
        'email' => 'linked@example.com',
        'normalized_email' => 'linked@example.com',
        'source_channels' => ['square', 'shopify'],
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $squareAndShopify->id,
        'source_type' => 'square_customer',
        'source_id' => 'SQ-CUST-2',
        'match_method' => 'exact_square_customer',
        'confidence' => 1,
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $squareAndShopify->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'SHOP-CUST-2',
        'match_method' => 'exact_email',
        'confidence' => 1,
    ]);

    SquareCustomer::query()->create([
        'square_customer_id' => 'SQ-CUST-2',
        'given_name' => 'Linked',
        'family_name' => 'Buyer',
        'email' => 'linked@example.com',
        'synced_at' => now(),
    ]);

    SquareOrder::query()->create([
        'square_order_id' => 'SQ-ORDER-UNLINKED',
        'location_id' => 'LOC-1',
        'state' => 'COMPLETED',
        'total_money_amount' => 12000,
        'total_money_currency' => 'USD',
        'closed_at' => now(),
        'source_name' => 'Spring Market',
        'raw_payload' => [],
        'synced_at' => now(),
    ]);

    SquarePayment::query()->create([
        'square_payment_id' => 'SQ-PAY-UNLINKED',
        'square_order_id' => 'SQ-ORDER-UNLINKED',
        'amount_money' => 12000,
        'currency' => 'USD',
        'status' => 'COMPLETED',
        'created_at_source' => now(),
        'raw_payload' => [
            'card_details' => [
                'card' => [
                    'cardholder_name' => 'Manual Buyer',
                ],
            ],
        ],
        'synced_at' => now(),
    ]);

    MarketingIdentityReview::query()->create([
        'source_type' => 'square_customer',
        'source_id' => 'SQ-REVIEW-1',
        'status' => 'pending',
        'raw_email' => 'review@example.com',
        'conflict_reasons' => ['email_conflict'],
        'payload' => ['source_label' => 'square_customer_sync'],
    ]);

    $this->actingAs($user)
        ->get(route('marketing.providers-integrations', [
            'square_filter' => 'square_only_missing_contact',
            'square_min_spend' => '100',
            'overlap_filter' => 'square_only_missing_contact',
        ]))
        ->assertOk()
        ->assertSeeText('Square Contact Quality')
        ->assertSeeText('SQ-CUST-1')
        ->assertSeeText('SQ-ORDER-UNLINKED')
        ->assertSeeText('Manual Buyer')
        ->assertSeeText('Recoverable payment cardholder')
        ->assertDontSeeText('linked@example.com');
});
