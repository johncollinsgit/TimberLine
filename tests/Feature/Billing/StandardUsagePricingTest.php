<?php

use App\Services\Billing\StandardUsagePricing;
use Illuminate\Validation\ValidationException;

test('future Standard uses one prepaid balance without legacy SMS setup or monthly sending subscriptions', function () {
    $quote = app(StandardUsagePricing::class)->quote(['sms', 'email', 'ai'], 5000);
    expect($quote['subtotal_cents'])->toBe(79800)->and($quote['recurring_cents'])->toBe(29900)
        ->and($quote['sms_setup_cents'])->toBe(0)->and($quote['usage']['sms_monthly_cents'])->toBe(0)
        ->and($quote['usage']['ai_provider_cost_multiplier'])->toBe(2)
        ->and($quote['usage']['messaging_rates_micros']['sms'])->toBe(50000)
        ->and($quote['usage']['messaging_rates_micros']['email'])->toBe(1490)
        ->and(config('marketing.messaging.platform.buyer_rates_micros.email'))->toBe(5000)
        ->and(config('module_catalog.addons.sms.pricing.setup_price_cents'))->toBe(9900);
    expect(app(StandardUsagePricing::class)->quote(['ai', 'email', 'sms'], 5000)['hash'])->toBe($quote['hash']);
});

test('future service quotes keep phone connection fees unresolved and distinguish AI audio estimates from actual usage', function () {
    $quote = app(StandardUsagePricing::class)->quote(['phone_voice', 'customer_ai'], 0);
    expect($quote['subtotal_cents'])->toBe(79800)->and($quote['quote_required'])->not->toBeEmpty()
        ->and($quote['usage']['ai_examples'][0]['customer_cost_micros'])->toBe(30000)
        ->and($quote['usage']['ai_examples'][1]['customer_audio_micros_per_balanced_minute'])->toBe(96000)
        ->and($quote['usage']['ai_examples'][2]['customer_audio_micros_per_balanced_minute'])->toBe(30000)
        ->and($quote['usage']['auto_reload_default'])->toBeFalse();
});

test('the server rejects arbitrary service names and prepaid amounts', function () {
    expect(fn () => app(StandardUsagePricing::class)->quote(['all_unlimited'], 5000))->toThrow(ValidationException::class);
    expect(fn () => app(StandardUsagePricing::class)->quote(['sms'], 1))->toThrow(ValidationException::class);
});

test('the five-person planning example includes future memory without adding estimates to the amount due', function () {
    $pricing = app(StandardUsagePricing::class);
    $example = $pricing->teamUsageExample();
    expect($example['usage_total_cents'])->toBe(4895)
        ->and($example['regular_month_cents'])->toBe(34795)
        ->and($example['first_month_cents'])->toBe(79800)
        ->and($example['initial_invoice_cents'])->toBe(79800)
        ->and($pricing->presentation()['memory_example']['customer_cost_micros_per_reply'])->toBe(8000)
        ->and($pricing->invoiceUsageFooter())->toContain('$7.45', '$4.00', '$347.95', 'FIRST PAYMENT: $798.00', 'INFORMATIONAL ONLY');
});
