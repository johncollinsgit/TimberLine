<?php

use App\Models\AgreementAcceptance;
use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBillingOrder;
use App\Models\TenantBudSetting;
use App\Models\TenantDirectInvoice;
use App\Models\User;
use App\Services\Agreements\AgreementManagementService;
use App\Services\Billing\TenantAiUsageInvoiceService;
use Illuminate\Support\Facades\Http;

test('settled tenant AI usage rolls into one idempotent monthly Everbranch invoice', function (): void {
    config()->set('services.stripe.secret', 'sk_test_ai');
    config()->set('services.stripe.api_base', 'https://stripe.test');
    $tenant = Tenant::query()->create(['name' => 'AI Customer', 'slug' => 'ai-customer']);
    $user = User::factory()->create();
    $agreement = app(AgreementManagementService::class)->prepareCollinsElectric($tenant, null);
    $agreement->forceFill(['status' => 'active', 'accepted_at' => now()->subMonths(2), 'effective_at' => now()->subMonths(2)])->save();
    $acceptance = AgreementAcceptance::query()->create([
        'agreement_id' => $agreement->id, 'agreement_version_id' => $agreement->current_version_id, 'tenant_id' => $tenant->id,
        'signer_legal_name' => 'AI Customer Owner', 'signer_title' => 'Owner', 'signer_email' => 'owner@example.com',
        'electronic_signature_value' => 'AI Customer Owner', 'authorized_to_bind' => true, 'accepted_scope' => true,
        'accepted_pricing' => true, 'accepted_subscription' => true, 'accepted_hourly_rate' => true,
        'accepted_termination' => true, 'electronic_consent' => true, 'accepted_at' => now()->subMonths(2),
        'evidence_hash' => hash('sha256', 'tenant-ai-invoice-acceptance'),
    ]);
    TenantBillingOrder::query()->create([
        'tenant_id' => $tenant->id, 'agreement_id' => $agreement->id, 'agreement_version_id' => $agreement->current_version_id,
        'agreement_acceptance_id' => $acceptance->id, 'order_type' => 'initial', 'status' => 'paid', 'provider' => 'stripe',
        'currency' => 'USD', 'line_items' => [], 'authorized_subtotal_cents' => 0, 'provider_customer_id' => 'cus_ai_customer',
        'authorized_at' => now()->subMonths(2), 'paid_at' => now()->subMonths(2),
    ]);
    TenantBudSetting::query()->create([
        'tenant_id' => $tenant->id, 'status' => 'approved', 'ai_status' => 'approved', 'ai_monthly_budget_cents' => 2500,
        'ai_used_cents' => 1, 'ai_period_started_at' => now()->subMonth()->startOfMonth(),
        'ai_reviewed_by_user_id' => $user->id, 'ai_reviewed_at' => now()->subMonths(2),
    ]);
    $event = TenantAiUsageEvent::query()->create([
        'tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => fake()->uuid(),
        'feature' => 'field_voice_transcription', 'context' => 'job_note', 'model' => 'gpt-transcribe', 'status' => 'settled',
        'duration_seconds' => 90, 'provider_cost_micros' => 6750, 'buyer_charge_micros' => 6750,
        'occurred_at' => now()->subMonth()->startOfMonth()->addDay(), 'metadata' => ['billing_scope' => 'tenant_monthly'],
    ]);
    Http::fake(['https://stripe.test/v1/customers/cus_ai_customer' => Http::response([
        'id' => 'cus_ai_customer', 'name' => 'AI Customer', 'email' => 'owner@example.com',
        'address' => ['line1' => '1 Main Street', 'city' => 'Greenville', 'state' => 'SC', 'postal_code' => '29601', 'country' => 'US'],
    ])]);

    $first = app(TenantAiUsageInvoiceService::class)->invoiceClosedMonths($tenant->slug, false);
    $second = app(TenantAiUsageInvoiceService::class)->invoiceClosedMonths($tenant->slug, false);
    $invoice = TenantDirectInvoice::query()->sole();

    expect($first['drafts_created'])->toBe(1)
        ->and($second['groups'])->toBe(0)
        ->and($invoice->authorized_subtotal_cents)->toBe(1)
        ->and($invoice->line_items[0]['description'])->toContain('1 voice drafts')
        ->and($event->fresh()->tenant_direct_invoice_id)->toBe($invoice->id)
        ->and(data_get($invoice->metadata, 'generated_by'))->toBe('tenant_ai_usage_month_close');
});
