<?php

use App\Models\Tenant;
use App\Services\Billing\TenantBillingReceiptLedger;
use App\Services\Billing\TenantUsageStatementService;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;

test('usage statements use tenant-scoped settlements and distinguish purchases from deductions', function () {
    $tenant = Tenant::create(['name' => 'Usage Co', 'slug' => 'usage-co']);
    $other = Tenant::create(['name' => 'Other Co', 'slug' => 'other-usage-co']);
    $usage = app(TenantMessagingUsageService::class);
    $usage->fund($tenant->id, 25000000, 'paid-fixture');
    $usage->reserve($tenant->id, 'email', 10, 'email-fixture', 'sendgrid');
    $usage->settle($tenant->id, 'email-fixture');
    $statement = app(TenantUsageStatementService::class)->snapshot($tenant);
    expect($statement['credit_purchases_micros'])->toBe(25000000)
        ->and($statement['wallet_usage_micros'])->toBe(50000)
        ->and($statement['current_balance_micros'])->toBe(24950000)
        ->and($statement['wallet_usage'][0]['quantity'])->toBe(10)
        ->and($statement['informational_only'])->toBeTrue()
        ->and(app(TenantUsageStatementService::class)->snapshot($other)['wallet_usage'])->toBeEmpty();
});

test('verified paid receipts freeze their informational usage statement across provider retries', function () {
    $tenant = Tenant::create(['name' => 'Paid Co', 'slug' => 'paid-usage-co']);
    $usage = app(TenantMessagingUsageService::class);
    $usage->fund($tenant->id, 25000000, 'paid-fixture');
    $receiptInput = ['provider_receipt_id' => 'in_usage_fixture', 'status' => 'paid',
        'subtotal_amount_cents' => 79800, 'tax_amount_cents' => 0, 'total_amount_cents' => 79800,
        'source_event_id' => 'evt_usage_fixture', 'paid_at' => now()];
    $ledger = app(TenantBillingReceiptLedger::class);
    $receipt = $ledger->recordVerifiedProviderReceipt($tenant, 'stripe', $receiptInput);
    $usage->reserve($tenant->id, 'sms', 1, 'sms-fixture', 'twilio');
    $usage->settle($tenant->id, 'sms-fixture');
    $again = $ledger->recordVerifiedProviderReceipt($tenant, 'stripe', $receiptInput);
    expect($again->id)->toBe($receipt->id)
        ->and(data_get($again->metadata, 'usage_statement.wallet_usage_micros'))->toBe(0)
        ->and($again->total_amount_cents)->toBe(79800)
        ->and(app(TenantUsageStatementService::class)->snapshot($tenant)['wallet_usage_micros'])->toBe(50000);
});

test('advance service periods do not prevent recording a verified payment or imply future actual usage', function () {
    $tenant = Tenant::create(['name' => 'Advance', 'slug' => 'advance-usage']);
    $receipt = app(TenantBillingReceiptLedger::class)->recordVerifiedProviderReceipt($tenant, 'stripe', [
        'provider_receipt_id' => 'in_advance_fixture', 'status' => 'paid', 'subtotal_amount_cents' => 79800, 'paid_at' => now(),
        'billing_period_starts_at' => now()->addMonth()->startOfMonth(), 'billing_period_ends_at' => now()->addMonths(2)->startOfMonth(),
    ]);
    expect(data_get($receipt->metadata, 'usage_statement.period_end'))->toBe(now()->toIso8601String())
        ->and(data_get($receipt->metadata, 'usage_statement.wallet_usage_micros'))->toBe(0);
});

test('only active owners and admins can see their tenant usage page', function () {
    $this->withoutVite();
    $tenant = Tenant::create(['name' => 'Private Usage', 'slug' => 'private-usage']);
    $other = Tenant::create(['name' => 'Foreign Usage', 'slug' => 'foreign-usage']);
    $user = \App\Models\User::factory()->create(['role' => 'manager', 'is_active' => true, 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->actingAs($user)->get(route('billing.usage', ['tenant' => $tenant->slug]))->assertOk()->assertSeeText('Usage & balance');
    $this->get(route('billing.usage', ['tenant' => $other->slug]))->assertForbidden();
    $user->tenants()->updateExistingPivot($tenant, ['role' => 'manager']);
    $this->get(route('billing.usage', ['tenant' => $tenant->slug]))->assertForbidden();
    $user->tenants()->updateExistingPivot($tenant, ['role' => 'owner', 'membership_active' => false]);
    $this->get(route('billing.usage', ['tenant' => $tenant->slug]))->assertForbidden();
});
