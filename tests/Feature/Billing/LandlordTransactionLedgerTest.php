<?php

use App\Models\StripeWebhookEvent;
use App\Models\Tenant;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingRefund;
use App\Models\TenantDirectInvoice;
use App\Models\User;
use App\Services\Billing\LandlordTransactionRefundService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

beforeEach(function (): void {
    config()->set('services.stripe.secret', 'sk_test_transaction_ledger');
    config()->set('services.stripe.api_base', 'https://stripe.test');
    Cache::flush();
});

test('landlord can review itemized workspace payment activity from the transactions submenu', function (): void {
    Http::fake(['https://stripe.test/v1/payment_intents*' => Http::response([], 503)]);
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    StripeWebhookEvent::query()->create([
        'event_id' => 'evt_nate_paid', 'event_type' => 'invoice.paid', 'status' => 'processed',
        'tenant_id' => $tenant->id, 'processed_at' => now(),
    ]);
    TenantBillingReceipt::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'stripe',
        'provider_receipt_id' => 'in_nate_paid',
        'invoice_number' => 'EB-NATE-001',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_amount_cents' => 12500,
        'tax_amount_cents' => 0,
        'total_amount_cents' => 12500,
        'paid_at' => now(),
        'source_event_id' => 'evt_nate_paid',
    ]);
    $operator = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);

    $this->actingAs($operator)
        ->get('http://app.theeverbranch.com/landlord/transactions')
        ->assertOk()
        ->assertSeeText('Transaction ledger')
        ->assertSeeText('Collins Electric')
        ->assertSeeText('EB-NATE-001')
        ->assertSeeText('$125.00')
        ->assertSeeText('Transactions');
});

test('incoming activity excludes receipts without signed Stripe paid evidence', function (): void {
    Http::fake(['https://stripe.test/v1/payment_intents*' => Http::response([], 503)]);
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    StripeWebhookEvent::query()->create([
        'event_id' => 'evt_confirmed', 'event_type' => 'invoice.paid', 'status' => 'processed',
        'tenant_id' => $tenant->id, 'processed_at' => now(),
    ]);
    StripeWebhookEvent::query()->create([
        'event_id' => 'evt_open', 'event_type' => 'invoice.finalized', 'status' => 'processed',
        'tenant_id' => $tenant->id, 'processed_at' => now(),
    ]);
    $confirmed = [
        'tenant_id' => $tenant->id,
        'provider' => 'stripe',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_amount_cents' => 12500,
        'tax_amount_cents' => 0,
        'total_amount_cents' => 12500,
        'paid_at' => now(),
        'source_event_id' => 'evt_confirmed',
    ];
    TenantBillingReceipt::query()->create([...$confirmed, 'provider_receipt_id' => 'in_confirmed', 'invoice_number' => 'CONFIRMED-001']);
    TenantBillingReceipt::query()->create([...$confirmed, 'provider_receipt_id' => 'in_open', 'invoice_number' => 'OPEN-001', 'status' => 'open', 'subtotal_amount_cents' => 10500, 'total_amount_cents' => 10500, 'paid_at' => null, 'source_event_id' => 'evt_open']);
    TenantBillingReceipt::query()->create([...$confirmed, 'provider_receipt_id' => 'in_mislabeled', 'invoice_number' => 'MISLABELED-001', 'subtotal_amount_cents' => 10500, 'total_amount_cents' => 10500, 'source_event_id' => 'evt_open']);
    TenantBillingReceipt::query()->create([...$confirmed, 'provider_receipt_id' => 'in_unverified', 'invoice_number' => 'UNVERIFIED-001', 'source_event_id' => null]);
    TenantBillingReceipt::query()->create([...$confirmed, 'provider' => 'shopify', 'provider_receipt_id' => 'shopify_paid', 'invoice_number' => 'SHOPIFY-001']);
    TenantBillingReceipt::query()->create([...$confirmed, 'provider_receipt_id' => 'in_zero', 'invoice_number' => 'ZERO-001', 'subtotal_amount_cents' => 0, 'total_amount_cents' => 0]);
    $operator = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);

    $response = $this->actingAs($operator)
        ->get('http://app.theeverbranch.com/landlord/transactions')
        ->assertOk()
        ->assertSeeText('CONFIRMED-001')
        ->assertSeeText('OPEN-001')
        ->assertSeeText('MISLABELED-001')
        ->assertSeeText('Incomplete')
        ->assertSeeText('$125.00')
        ->assertDontSeeText('UNVERIFIED-001')
        ->assertDontSeeText('SHOPIFY-001')
        ->assertDontSeeText('ZERO-001');

    $response->assertViewHas('summary', fn (array $summary): bool => $summary['incoming_cents'] === 12500
        && $summary['stripe_activity_count'] === 3);
});

test('Stripe account activity is the primary ledger source and matches an incomplete provider payment', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Front Yard Foods', 'slug' => 'front-yard-foods']);
    StripeWebhookEvent::query()->create([
        'event_id' => 'evt_stale_local', 'event_type' => 'invoice.paid', 'status' => 'processed',
        'tenant_id' => $tenant->id, 'processed_at' => now(),
    ]);
    TenantBillingReceipt::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'stripe',
        'provider_receipt_id' => 'in_stale_local',
        'invoice_number' => 'STALE-358',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_amount_cents' => 35800,
        'tax_amount_cents' => 0,
        'total_amount_cents' => 35800,
        'paid_at' => now(),
        'source_event_id' => 'evt_stale_local',
    ]);
    Http::fake(['https://stripe.test/v1/payment_intents*' => Http::response([
        'object' => 'list',
        'has_more' => false,
        'data' => [[
            'id' => 'pi_front_yard_incomplete',
            'object' => 'payment_intent',
            'amount' => 10500,
            'amount_received' => 0,
            'currency' => 'usd',
            'status' => 'requires_payment_method',
            'description' => 'Payment for Invoice',
            'receipt_email' => 'laura@frontyardfoods.com',
            'customer' => null,
            'latest_charge' => null,
            'created' => now()->timestamp,
            'livemode' => false,
            'metadata' => [],
        ]],
    ])]);
    $operator = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);

    $response = $this->actingAs($operator)
        ->get('http://app.theeverbranch.com/landlord/transactions')
        ->assertOk();
    $response
        ->assertSeeText('pi_front_yard_incomplete')
        ->assertSeeText('Payment for Invoice')
        ->assertSeeText('laura@frontyardfoods.com')
        ->assertSeeText('Incomplete')
        ->assertSeeText('$105.00')
        ->assertDontSeeText('STALE-358')
        ->assertDontSeeText('$358.00');

    $response->assertViewHas('summary', fn (array $summary): bool => $summary['incoming_cents'] === 0
        && $summary['stripe_activity_count'] === 1);
});

test('paid direct invoice itemization uses the stored extended amounts exactly once', function (): void {
    Http::fake(['https://stripe.test/v1/payment_intents*' => Http::response([], 503)]);
    $tenant = Tenant::query()->create(['name' => 'Acme', 'slug' => 'acme']);
    $operator = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    StripeWebhookEvent::query()->create([
        'event_id' => 'evt_acme_paid', 'event_type' => 'invoice.payment_succeeded', 'status' => 'processed',
        'tenant_id' => $tenant->id, 'processed_at' => now(),
    ]);
    $invoice = TenantDirectInvoice::query()->create([
        'tenant_id' => $tenant->id,
        'created_by' => $operator->id,
        'status' => 'paid',
        'currency' => 'USD',
        'customer_name' => 'Acme',
        'customer_email' => 'billing@acme.test',
        'billing_address' => [],
        'authorization_reference' => 'ACME-001',
        'line_items' => [
            ['description' => 'Hours of work', 'quantity' => 3, 'unit_amount_cents' => 5000, 'amount_cents' => 15000],
            ['description' => 'Courtesy rate discount', 'quantity' => 1, 'unit_amount_cents' => -4500, 'amount_cents' => -4500],
        ],
        'authorized_subtotal_cents' => 10500,
        'provider_total_cents' => 10500,
        'provider_payment_intent_id' => 'pi_acme_paid',
        'paid_at' => now(),
    ]);
    TenantBillingReceipt::query()->create([
        'tenant_id' => $tenant->id,
        'tenant_direct_invoice_id' => $invoice->id,
        'provider' => 'stripe',
        'provider_receipt_id' => 'in_acme_paid',
        'invoice_number' => 'ACME-PAID-001',
        'status' => 'paid',
        'currency' => 'USD',
        'subtotal_amount_cents' => 10500,
        'tax_amount_cents' => 0,
        'total_amount_cents' => 10500,
        'paid_at' => now(),
        'source_event_id' => 'evt_acme_paid',
    ]);

    $this->actingAs($operator)
        ->get('http://app.theeverbranch.com/landlord/transactions')
        ->assertOk()
        ->assertSeeText('Hours of work')
        ->assertSeeText('$150.00')
        ->assertSeeText('$-45.00')
        ->assertSeeText('$105.00')
        ->assertDontSeeText('$450.00');
});

test('a Stripe payment refund is idempotent and appears as an outgoing ledger record', function (): void {
    config()->set('services.stripe.secret', 'sk_test_transactions');
    config()->set('services.stripe.api_base', 'https://stripe.test');
    Http::fake(['https://stripe.test/v1/refunds' => Http::response([
        'id' => 're_nate_refund', 'status' => 'succeeded', 'charge' => 'ch_nate_paid',
    ])]);

    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    $operator = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $invoice = TenantDirectInvoice::query()->create([
        'tenant_id' => $tenant->id, 'created_by' => $operator->id, 'status' => 'paid', 'currency' => 'USD',
        'customer_name' => 'Nate', 'customer_email' => 'nate@example.com', 'billing_address' => [], 'authorization_reference' => 'EB-NATE',
        'line_items' => [['label' => 'Everbranch launch', 'amount_cents' => 12500]], 'authorized_subtotal_cents' => 12500,
        'provider_total_cents' => 12500, 'provider_payment_intent_id' => 'pi_nate_paid', 'paid_at' => now(),
    ]);
    $receipt = TenantBillingReceipt::query()->create([
        'tenant_id' => $tenant->id, 'tenant_direct_invoice_id' => $invoice->id, 'provider' => 'stripe',
        'provider_receipt_id' => 'in_nate_refundable', 'status' => 'paid', 'currency' => 'USD',
        'subtotal_amount_cents' => 12500, 'tax_amount_cents' => 0, 'total_amount_cents' => 12500, 'paid_at' => now(),
    ]);

    $refund = app(LandlordTransactionRefundService::class)->refund($receipt, 2500, 'requested_by_customer', 'Nate requested a partial refund.', '3bb456cb-09cc-44ce-a1b9-5b407e594f4d', $operator);

    expect($refund->status)->toBe('succeeded')
        ->and($refund->provider_refund_id)->toBe('re_nate_refund')
        ->and(TenantBillingRefund::query()->whereKey($refund->id)->value('amount_cents'))->toBe(2500);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://stripe.test/v1/refunds'
        && $request['payment_intent'] === 'pi_nate_paid' && $request['amount'] === 2500);

    $same = app(LandlordTransactionRefundService::class)->refund($receipt, 2500, 'requested_by_customer', 'Nate requested a partial refund.', '3bb456cb-09cc-44ce-a1b9-5b407e594f4d', $operator);
    expect($same->id)->toBe($refund->id);
    Http::assertSentCount(1);
});

test('refund schema migrations tolerate a pre-existing refunds table and retain idempotency indexes', function (): void {
    expect(Schema::hasIndex('tenant_billing_refunds', ['provider_refund_id'], 'unique'))->toBeTrue()
        ->and(Schema::hasIndex('tenant_billing_refunds', ['idempotency_key'], 'unique'))->toBeTrue();

    Schema::drop('tenant_billing_refunds');
    Schema::create('tenant_billing_refunds', function ($table): void {
        $table->id();
    });

    $baseMigration = require database_path('migrations/2026_07_20_230000_create_tenant_billing_refunds_table.php');
    $baseMigration->up();

    expect(Schema::hasTable('tenant_billing_refunds'))->toBeTrue()
        ->and(Schema::hasColumn('tenant_billing_refunds', 'id'))->toBeTrue();
});
