<?php

use App\Mail\TenantUsageReceiptMail;
use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantDirectInvoice;
use App\Services\Billing\TenantBillingReceiptLedger;
use App\Services\Billing\TenantUsageReceiptService;
use Illuminate\Support\Facades\Mail;

function usageReceiptFixture(): array
{
    $tenant = Tenant::create(['name' => 'Paid Example', 'slug' => 'paid-example']);
    $invoice = TenantDirectInvoice::create(['tenant_id' => $tenant->id, 'status' => 'paid',
        'customer_name' => 'Example Owner', 'customer_email' => 'owner@example.invalid', 'billing_address' => [],
        'authorization_reference' => 'Fixture only', 'line_items' => [], 'authorized_subtotal_cents' => 79800,
        'provider_invoice_id' => 'in_usage_paid_fixture', 'paid_at' => now()]);
    StripeWebhookEvent::create(['event_id' => 'evt_usage_paid_fixture', 'event_type' => 'invoice.paid', 'status' => 'processed',
        'livemode' => true, 'tenant_id' => $tenant->id, 'processed_at' => now(), 'payload' => []]);
    $input = ['provider_receipt_id' => $invoice->provider_invoice_id, 'status' => 'paid', 'paid_at' => now(),
        'subtotal_amount_cents' => 79800, 'tax_amount_cents' => 0, 'source_event_id' => 'evt_usage_paid_fixture'];
    $receipt = app(TenantBillingReceiptLedger::class)->recordVerifiedProviderReceipt($tenant, 'stripe', $input, directInvoice: $invoice);

    return [$receipt, $tenant, $input, $invoice];
}

beforeEach(function () {
    config()->set('commercial.billing_readiness.usage_receipts_enabled', true);
    config()->set('mail.default', 'smtp');
    Mail::fake();
});

test('a verified paid receipt delivers one informational statement and preserves delivery state across provider retries', function () {
    [$receipt, $tenant, $input, $invoice] = usageReceiptFixture();
    $service = app(TenantUsageReceiptService::class);
    expect($service->dispatchPending())->toBe(1);
    app(TenantBillingReceiptLedger::class)->recordVerifiedProviderReceipt($tenant, 'stripe', [...$input, 'metadata' => ['usage_notice' => ['status' => 'pending']]], directInvoice: $invoice);
    expect($service->dispatchPending())->toBe(0)->and(data_get($receipt->fresh()->metadata, 'usage_notice.status'))->toBe('sent');
    Mail::assertSent(TenantUsageReceiptMail::class, fn ($mail) => $mail->hasTo('owner@example.invalid') && str_contains($mail->render(), 'not charged again'));
    Mail::assertSentCount(1);
});

test('disabled rollout and sandbox or unprocessed payment events cannot dispatch usage mail', function () {
    usageReceiptFixture();
    config()->set('commercial.billing_readiness.usage_receipts_enabled', false);
    expect(app(TenantUsageReceiptService::class)->dispatchPending())->toBe(0);
    config()->set('commercial.billing_readiness.usage_receipts_enabled', true);
    StripeWebhookEvent::query()->update(['livemode' => false]);
    expect(app(TenantUsageReceiptService::class)->dispatchPending())->toBe(0);
    StripeWebhookEvent::query()->update(['livemode' => true, 'status' => 'processing', 'processed_at' => null]);
    expect(app(TenantUsageReceiptService::class)->dispatchPending())->toBe(0);
    Mail::assertNothingSent();
});

test('an ambiguous provider mail response is held for reconciliation without a duplicate retry', function () {
    [$receipt] = usageReceiptFixture();
    Mail::shouldReceive('to')->once()->andReturnSelf();
    Mail::shouldReceive('send')->once()->andThrow(new RuntimeException('Ambiguous provider timeout'));
    $service = app(TenantUsageReceiptService::class);
    expect($service->dispatchPending())->toBe(0)->and($service->dispatchPending())->toBe(0)
        ->and(data_get($receipt->fresh()->metadata, 'usage_notice.status'))->toBe('needs_reconciliation');
});
