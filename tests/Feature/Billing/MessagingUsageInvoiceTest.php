<?php

use App\Models\Agreement;
use App\Models\AgreementAcceptance;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantBillingOrder;
use App\Models\TenantDirectInvoice;
use App\Models\TenantMessagingUsagePeriod;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Billing\MessagingUsageInvoiceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

test('closed contract usage creates one auditable invoice and cannot be billed twice', function (): void {
    config()->set('services.stripe.secret', 'sk_test_messaging');
    config()->set('services.stripe.api_base', 'https://stripe.test');
    $tenant = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-invoice']);
    $agreement = app(AgreementManagementService::class)->prepareCollinsElectric($tenant, null);
    $agreement->forceFill(['status' => 'active', 'accepted_at' => now()->subMonth(), 'effective_at' => now()->subMonth()])->save();
    $acceptance = AgreementAcceptance::query()->create([
        'agreement_id' => $agreement->id,
        'agreement_version_id' => $agreement->current_version_id,
        'tenant_id' => $tenant->id,
        'signer_legal_name' => 'Collins Electric Owner',
        'signer_title' => 'Owner',
        'signer_email' => 'collinselectric91@gmail.com',
        'electronic_signature_value' => 'Collins Electric Owner',
        'authorized_to_bind' => true,
        'accepted_scope' => true,
        'accepted_pricing' => true,
        'accepted_subscription' => true,
        'accepted_hourly_rate' => true,
        'accepted_termination' => true,
        'electronic_consent' => true,
        'accepted_at' => now()->subMonth(),
        'evidence_hash' => hash('sha256', 'messaging-invoice-acceptance'),
    ]);
    TenantAccessAddon::query()->create([
        'tenant_id' => $tenant->id,
        'addon_key' => 'messaging_usage',
        'enabled' => true,
        'source' => 'agreement_test',
        'metadata' => [
            'billing_mode' => 'postpaid_invoice',
            'included_units' => ['sms' => 250, 'email' => 1000],
            'overage_rates_micros' => ['sms' => 50000, 'email' => 5000],
            'pricing_version' => 'collins-test-v2',
            'agreement_template_key' => Agreement::TEMPLATE_COLLINS_ELECTRIC_CLIENT_SERVICES,
        ],
    ]);
    TenantBillingOrder::query()->create([
        'tenant_id' => $tenant->id,
        'agreement_id' => $agreement->id,
        'agreement_version_id' => $agreement->current_version_id,
        'agreement_acceptance_id' => $acceptance->id,
        'order_type' => 'initial',
        'status' => 'paid',
        'provider' => 'stripe',
        'currency' => 'USD',
        'line_items' => [],
        'authorized_subtotal_cents' => 0,
        'provider_customer_id' => 'cus_contract',
        'authorized_at' => now()->subMonth(),
        'paid_at' => now()->subMonth(),
    ]);
    $period = TenantMessagingUsagePeriod::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'channel' => 'sms',
        'period_start' => now()->subMonthNoOverflow()->startOfMonth(),
        'period_end' => now()->subMonthNoOverflow()->endOfMonth(),
        'included_units' => 250,
        'used_units' => 254,
        'reserved_units' => 0,
        'provider_cost_micros' => 3378200,
        'buyer_charge_micros' => 200000,
    ]);
    DB::table('tenant_messaging_ledger_entries')->insert([
        'tenant_id' => $tenant->id,
        'tenant_messaging_usage_period_id' => $period->id,
        'entry_type' => 'usage_settlement',
        'status' => 'settled',
        'channel' => 'sms',
        'unit_type' => 'segment',
        'units' => 254,
        'amount_micros' => 200000,
        'provider_cost_micros' => 3378200,
        'pricing_version' => 'collins-test-v2',
        'idempotency_key' => 'settle:closed-period',
        'metadata' => json_encode(['charged_units' => 4], JSON_THROW_ON_ERROR),
        'occurred_at' => now()->subMonth(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Http::fake([
        'https://stripe.test/v1/customers/cus_contract' => Http::response([
            'id' => 'cus_contract',
            'name' => 'Collins Electric',
            'email' => 'collinselectric91@gmail.com',
            'address' => ['line1' => '91 Main Street', 'city' => 'Charlotte', 'state' => 'NC', 'postal_code' => '28202', 'country' => 'US'],
        ]),
    ]);

    $first = app(MessagingUsageInvoiceService::class)->invoiceClosedPeriods($tenant->slug, false);
    $second = app(MessagingUsageInvoiceService::class)->invoiceClosedPeriods($tenant->slug, false);
    $invoice = TenantDirectInvoice::query()->sole();

    expect($first['drafts_created'])->toBe(1)
        ->and($first['failures'])->toBe(0)
        ->and($second['groups'])->toBe(0)
        ->and($invoice->authorized_subtotal_cents)->toBe(20)
        ->and($invoice->line_items[0]['description'])->toContain('4 SMS segments')
        ->and($period->fresh()->tenant_direct_invoice_id)->toBe($invoice->id)
        ->and($period->fresh()->invoiced_at)->not->toBeNull();
});
