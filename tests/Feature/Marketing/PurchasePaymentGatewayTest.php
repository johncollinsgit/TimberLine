<?php

use App\Models\Order;
use App\Support\Payments\PurchasePaymentGateways;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Http;

test('purchase gateway display preserves split payments and never infers Shop Pay or Braintree from another provider', function () {
    $summary = new PurchasePaymentGateways;
    $metadata = $summary->capture(['original' => 'preserved'], ['payment_gateway_names' => ['shopify_payments', 'gift_card'], 'updated_at' => '2026-10-30T12:00:00Z']);
    $badges = $summary->forOrder(new Order(['attribution_meta' => $metadata]));
    expect(array_column($badges, 'label'))->toBe(['Shopify Payments', 'Gift card'])->and($metadata['original'])->toBe('preserved');
    expect(array_column($summary->badges(['paypal_express']), 'label'))->toBe(['PayPal']);
    expect(array_column($summary->badges(['shop_pay', 'everbranch_stripe', 'braintree']), 'label'))->toBe(['Shop Pay', 'Stripe', 'Braintree']);
});

test('successful transaction gateways exclude failed attempts and refunds from the displayed payment method', function () {
    $summary = new PurchasePaymentGateways;
    $metadata = $summary->capture([], ['payment_gateway_names' => ['stripe', 'paypal'], 'transactions' => [
        ['gateway' => 'stripe', 'kind' => 'sale', 'status' => 'failure'],
        ['gateway' => 'paypal', 'kind' => 'sale', 'status' => 'success'],
        ['gateway' => 'manual', 'kind' => 'refund', 'status' => 'success'],
    ]]);
    expect($metadata['payment_gateways']['names'])->toBe(['paypal'])
        ->and($metadata['payment_gateways']['evidence'])->toBe('successful_transactions');
});

test('partial and older Shopify payloads retain known gateway evidence and do not retain payment secrets', function () {
    $summary = new PurchasePaymentGateways;
    $metadata = $summary->capture([], ['payment_gateway_names' => ['braintree'], 'updated_at' => '2026-10-30T12:00:00Z',
        'transactions' => [['gateway' => 'braintree', 'kind' => 'sale', 'status' => 'success', 'authorization' => 'private_token', 'receipt' => ['secret' => 'not_for_display']]]]);
    expect($summary->capture($metadata, []))->toBe($metadata)
        ->and($summary->capture($metadata, ['payment_gateway_names' => ['stripe'], 'updated_at' => '2026-10-29T12:00:00Z']))->toBe($metadata)
        ->and(json_encode($metadata))->not->toContain('private_token', 'not_for_display');
});

test('gateway badges render escaped readable labels and only fixed local icons without making provider reads', function () {
    Http::preventStrayRequests();
    $summary = new PurchasePaymentGateways;
    $html = Blade::render('<x-payment-gateway-badges :gateways="$gateways" />', ['gateways' => $summary->badges(['stripe', '<script>alert(1)</script>'])]);
    expect($html)->toContain('Payment gateway', 'Stripe', '/brand/payments/stripe.svg', '&lt;script&gt;')
        ->not->toContain('<script>alert(1)</script>');
    expect(Blade::render('<x-payment-gateway-badges />'))->toContain('Not recorded');
    Http::assertNothingSent();
});
