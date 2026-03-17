<?php

use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Size;
use App\Models\User;
use App\Services\Marketing\CandleCashGiftReportService;
use Carbon\CarbonImmutable;

test('candle cash gift report aggregates intents, origins, notifications, actors, and conversions', function () {
    $now = CarbonImmutable::parse('2026-03-10 12:00:00');
    $user = User::factory()->create(['name' => 'Report Admin']);

    $profileA = MarketingProfile::query()->create([
        'email' => 'alice@example.com',
        'normalized_email' => 'alice@example.com',
    ]);
    $profileB = MarketingProfile::query()->create([
        'email' => 'bob@example.com',
        'normalized_email' => 'bob@example.com',
    ]);

    $giftA = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileA->id,
        'type' => 'gift',
        'points' => 20,
        'source' => 'shopify_embedded_admin',
        'source_id' => (string) $user->id,
        'description' => 'Retention gift',
        'gift_intent' => 'retention',
        'gift_origin' => 'support',
        'notification_status' => 'skipped',
        'notified_via' => 'none',
    ]);

    $giftA->forceFill([
        'created_at' => $now->subDays(5),
        'updated_at' => $now->subDays(5),
    ])->saveQuietly();

    $giftB = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileB->id,
        'type' => 'gift',
        'points' => 30,
        'source' => 'shopify_embedded_admin',
        'source_id' => 'embedded',
        'description' => 'VIP thank you',
        'gift_intent' => 'vip',
        'gift_origin' => 'marketing',
        'campaign_key' => 'spring-royalty',
        'notification_status' => 'sent',
        'notified_via' => 'sms',
    ]);

    $giftB->forceFill([
        'created_at' => $now->subDays(4),
        'updated_at' => $now->subDays(4),
    ])->saveQuietly();

    $sizeA = Size::query()->create([
        'code' => 'small',
        'label' => 'Small',
        'retail_price' => 25.00,
        'wholesale_price' => 20.00,
        'is_active' => true,
    ]);

    $sizeB = Size::query()->create([
        'code' => 'medium',
        'label' => 'Medium',
        'retail_price' => 15.00,
        'wholesale_price' => 12.00,
        'is_active' => true,
    ]);

    $orderA = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'GC-001',
        'container_name' => 'Manual Gift',
        'customer_name' => 'Alice Example',
        'status' => 'complete',
        'internal_notes' => 'Gifted customer order',
        'ordered_at' => $now->subDays(3),
        'due_date' => $now->subDays(2),
        'customer_email' => $profileA->email,
        'created_at' => $now->subDays(3),
        'updated_at' => $now->subDays(3),
    ]);

    OrderLine::query()->create([
        'order_id' => $orderA->id,
        'size_id' => $sizeA->id,
        'ordered_qty' => 1,
        'quantity' => 1,
        'scent_name' => 'Test Scent',
        'size_code' => 'small',
        'raw_title' => 'Test',
        'raw_variant' => 'Variant',
        'pour_status' => 'queued',
        'created_at' => $now->subDays(3),
        'updated_at' => $now->subDays(3),
    ]);

    $orderB = Order::query()->create([
        'source' => 'manual',
        'order_number' => 'GC-002',
        'container_name' => 'Manual Gift',
        'customer_name' => 'Bob Sample',
        'status' => 'complete',
        'internal_notes' => 'VIP gift order',
        'ordered_at' => $now->subDays(2),
        'due_date' => $now->subDays(1),
        'customer_email' => $profileB->email,
        'created_at' => $now->subDays(2),
        'updated_at' => $now->subDays(2),
    ]);

    OrderLine::query()->create([
        'order_id' => $orderB->id,
        'size_id' => $sizeB->id,
        'ordered_qty' => 2,
        'quantity' => 2,
        'scent_name' => 'Sample Scent',
        'size_code' => 'medium',
        'raw_title' => 'Sample',
        'raw_variant' => 'Variant',
        'pour_status' => 'queued',
        'created_at' => $now->subDays(2),
        'updated_at' => $now->subDays(2),
    ]);

    $report = app(CandleCashGiftReportService::class)->generate();

    expect(data_get($report, 'totals.gift_transactions'))->toBe(2)
        ->and(data_get($report, 'totals.gift_points'))->toBe(50)
        ->and(data_get($report, 'totals.gift_amount'))->toBe($report['totals']['gift_amount'])
        ->and(data_get($report, 'breakdowns.intent.retention.count'))->toBe(1)
        ->and(data_get($report, 'breakdowns.intent.vip.points'))->toBe(30)
        ->and(data_get($report, 'breakdowns.origin.support.count'))->toBe(1)
        ->and(data_get($report, 'breakdowns.origin.marketing.count'))->toBe(1)
        ->and(data_get($report, 'breakdowns.notification.skipped.count'))->toBe(1)
        ->and(data_get($report, 'breakdowns.notification.sent.count'))->toBe(1)
        ->and(data_get($report, "breakdowns.actor.{$user->id}.count"))->toBe(1)
        ->and(data_get($report, 'breakdowns.actor.embedded.count'))->toBe(1)
        ->and(data_get($report, 'conversion.gifted_customers_with_orders'))->toBe(2)
        ->and(data_get($report, 'conversion.revenue_after_gifts'))->toBe(55.0)
        ->and(count(data_get($report, 'transactions', [])))->toBe(2);
});
