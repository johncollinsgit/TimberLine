<?php

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantDirectInvoice;
use App\Models\TenantMessagingCreditAccount;
use App\Models\TenantMessagingLedgerEntry;
use App\Models\TenantMessagingUsagePeriod;
use App\Services\Billing\MessagingUsageInvoiceService;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use Illuminate\Support\Facades\Http;

it('retains excluded email units and provider cost with no credit charge or invoice', function (string $provider, int $cost): void {
    Http::preventStrayRequests();
    $tenant = Tenant::create(['name' => 'Fictional excluded workspace', 'slug' => 'excluded-email']);
    config()->set('marketing.messaging.platform.email_billing_exempt_tenant_ids', [$tenant->id]);
    TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'messaging_usage', 'enabled' => true,
        'metadata' => ['included_units' => ['email' => 1], 'overage_rates_micros' => ['email' => 7000], 'pricing_version' => 'frozen-contract']]);
    $usage = app(TenantMessagingUsageService::class);
    $reservation = $usage->reserve($tenant->id, 'email', 3, 'excluded-attempt', $provider);
    // Reconciliation uses the frozen reservation even if rollout config changes.
    config()->set('marketing.messaging.platform.email_billing_exempt_tenant_ids', []);
    expect($usage->reserve($tenant->id, 'email', 3, 'excluded-attempt', $provider))->toBe($reservation);
    $first = $usage->settle($tenant->id, 'excluded-attempt');
    $second = $usage->settle($tenant->id, 'excluded-attempt');
    $period = TenantMessagingUsagePeriod::forAllTenants()->sole();
    expect($reservation['amount_micros'])->toBe(0)
        ->and($first->id)->toBe($second->id)
        ->and($first->metadata['email_billing_exempt'])->toBeTrue()
        ->and($first->metadata['unbilled_overage_units'])->toBe(2)
        ->and($first->metadata['charged_units'])->toBe(0)
        ->and($first->pricing_version)->toBe('frozen-contract')
        ->and($period->included_units)->toBe(1)
        ->and($period->used_units)->toBe(3)
        ->and($period->reserved_units)->toBe(0)
        ->and($period->provider_cost_micros)->toBe(3 * $cost)
        ->and($period->buyer_charge_micros)->toBe(0)
        ->and(TenantMessagingCreditAccount::forAllTenants()->sole()->balance_micros)->toBe(0)
        ->and(TenantMessagingLedgerEntry::forAllTenants()->where('entry_type', 'usage_settlement')->count())->toBe(1);
    $period->update(['period_start' => now()->subMonthNoOverflow()->startOfMonth(), 'period_end' => now()->subMonthNoOverflow()->endOfMonth()]);
    $result = app(MessagingUsageInvoiceService::class)->invoiceClosedPeriods($tenant->slug, true);
    expect($result['groups'])->toBe(0)->and($result['invoices_sent'])->toBe(0)
        ->and(TenantDirectInvoice::count())->toBe(0);
    Http::assertNothingSent();
})->with(['SES' => ['ses_tenant', 175], 'SendGrid' => ['sendgrid_subuser', 1100]]);

it('preserves nonexcluded allowances contract rates and SMS charges', function (): void {
    $excluded = Tenant::create(['name' => 'Excluded fixture', 'slug' => 'excluded-fixture']);
    $billable = Tenant::create(['name' => 'Billable fixture', 'slug' => 'billable-fixture']);
    config()->set('marketing.messaging.platform.email_billing_exempt_tenant_ids', [$excluded->id]);
    TenantAccessAddon::create(['tenant_id' => $billable->id, 'addon_key' => 'messaging_usage', 'enabled' => true,
        'metadata' => ['included_units' => ['email' => 2], 'overage_rates_micros' => ['email' => 7000], 'pricing_version' => 'original-contract']]);
    $usage = app(TenantMessagingUsageService::class);
    $usage->fund($billable->id, 100000, 'fund-billable');
    $usage->fund($excluded->id, 100000, 'fund-sms');
    $reservation = $usage->reserve($billable->id, 'email', 3, 'billable-attempt', 'ses_tenant');
    // A later exclusion cannot rewrite an already authorized customer charge.
    config()->set('marketing.messaging.platform.email_billing_exempt_tenant_ids', [$excluded->id, $billable->id]);
    $settlement = $usage->settle($billable->id, 'billable-attempt');
    $usage->settle($billable->id, 'billable-attempt');
    expect($reservation['amount_micros'])->toBe(7000)
        ->and($settlement->amount_micros)->toBe(7000)
        ->and($settlement->pricing_version)->toBe('original-contract')
        ->and($settlement->metadata['email_billing_exempt'])->toBeFalse()
        ->and(TenantMessagingCreditAccount::forAllTenants()->where('tenant_id', $billable->id)->sole()->balance_micros)->toBe(93000)
        ->and($usage->reserve($excluded->id, 'sms', 1, 'sms-attempt', 'twilio_subaccount')['amount_micros'])->toBe(50000)
        ->and($usage->summary($excluded->id, 'email')['email_billing_exempt'])->toBeTrue()
        ->and($usage->summary($excluded->id, 'sms')['email_billing_exempt'])->toBeFalse()
        ->and($usage->emailBillingExempt(0))->toBeFalse();
});
