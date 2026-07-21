<?php

use App\Models\Tenant;
use App\Models\TenantBillingReceipt;
use App\Models\TenantBillingRefund;
use App\Models\TenantDirectInvoice;
use App\Models\User;
use App\Services\Billing\LandlordTransactionRefundService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Http;

test('landlord can review itemized workspace payment activity from the transactions submenu', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
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
    ]);
    $operator = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);

    $this->actingAs($operator)
        ->get('http://app.theeverbranch.com/landlord/transactions')
        ->assertOk()
        ->assertSeeText('Itemized activity')
        ->assertSeeText('Collins Electric')
        ->assertSeeText('EB-NATE-001')
        ->assertSeeText('$125.00')
        ->assertSeeText('Transactions');
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
