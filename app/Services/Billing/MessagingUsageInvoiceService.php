<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantBillingOrder;
use App\Models\TenantDirectInvoice;
use App\Models\TenantMessagingLedgerEntry;
use App\Models\TenantMessagingUsagePeriod;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class MessagingUsageInvoiceService
{
    public function __construct(
        protected TenantMessagingUsageService $usage,
        protected DirectInvoiceManagementService $invoices,
        protected DirectStripeInvoiceService $stripe,
    ) {}

    /** @return array{groups:int,drafts_created:int,invoices_sent:int,blocked:int,failures:int,invoice_ids:array<int,int>,errors:array<int,string>} */
    public function invoiceClosedPeriods(?string $tenantSlug = null, bool $send = true): array
    {
        $tenantId = null;
        if (filled($tenantSlug)) {
            $tenantId = Tenant::query()->where('slug', strtolower(trim((string) $tenantSlug)))->value('id');
            if (! $tenantId) {
                throw new RuntimeException('The requested tenant was not found.');
            }
        }
        $query = TenantMessagingUsagePeriod::query()->forAllTenants()
            ->whereDate('period_end', '<', now()->toDateString())
            ->whereNull('tenant_direct_invoice_id')
            ->where('buyer_charge_micros', '>', 0)
            ->orderBy('tenant_id')->orderBy('period_start')->orderBy('channel');
        if ($tenantId) {
            $query->where('tenant_id', (int) $tenantId);
        }

        $groups = $query->get()->groupBy(fn (TenantMessagingUsagePeriod $period): string => implode(':', [
            $period->tenant_id,
            $period->period_start->toDateString(),
            $period->period_end->toDateString(),
        ]));
        $summary = ['groups' => $groups->count(), 'drafts_created' => 0, 'invoices_sent' => 0, 'blocked' => 0, 'failures' => 0, 'invoice_ids' => [], 'errors' => []];

        if ($send) {
            $pending = TenantDirectInvoice::query()->forAllTenants()
                ->whereIn('status', ['draft', 'send_failed'])
                ->when($tenantId, fn ($query) => $query->where('tenant_id', (int) $tenantId))
                ->get()
                ->filter(fn (TenantDirectInvoice $invoice): bool => data_get($invoice->metadata, 'generated_by') === 'messaging_usage_period_close');
            foreach ($pending as $invoice) {
                $result = $this->stripe->send($invoice, null);
                if ((bool) ($result['ok'] ?? false)) {
                    $summary['invoices_sent']++;
                } else {
                    $summary['blocked']++;
                }
            }
        }

        foreach ($groups as $periods) {
            try {
                $invoice = $this->createInvoiceForPeriods($periods);
                if (! $invoice) {
                    $summary['blocked']++;

                    continue;
                }
                $summary['drafts_created']++;
                $summary['invoice_ids'][] = (int) $invoice->id;
                if ($send) {
                    $result = $this->stripe->send($invoice, null);
                    if ((bool) ($result['ok'] ?? false)) {
                        $summary['invoices_sent']++;
                    } else {
                        $summary['blocked']++;
                    }
                }
            } catch (\Throwable $exception) {
                $summary['failures']++;
                $summary['errors'][] = mb_substr($exception->getMessage(), 0, 500);
            }
        }

        return $summary;
    }

    /** @param Collection<int,TenantMessagingUsagePeriod> $candidatePeriods */
    protected function createInvoiceForPeriods(Collection $candidatePeriods): ?TenantDirectInvoice
    {
        $first = $candidatePeriods->first();
        if (! $first instanceof TenantMessagingUsagePeriod) {
            return null;
        }
        $tenant = Tenant::query()->findOrFail((int) $first->tenant_id);
        $agreement = $this->usage->postpaidAgreement((int) $tenant->id);
        $order = TenantBillingOrder::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('agreement_id', $agreement?->id)
            ->where('status', 'paid')
            ->whereNotNull('provider_customer_id')
            ->latest('id')->first();
        if (! $agreement || ! $order) {
            return null;
        }
        $customer = $this->stripeCustomer((string) $order->provider_customer_id, (string) $agreement->acceptance?->signer_legal_name, (string) $agreement->acceptance?->signer_email);
        $periodIds = $candidatePeriods->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return DB::transaction(function () use ($periodIds, $tenant, $agreement, $order, $customer): ?TenantDirectInvoice {
            $periods = TenantMessagingUsagePeriod::query()->forAllTenants()
                ->whereIn('id', $periodIds)
                ->whereNull('tenant_direct_invoice_id')
                ->where('buyer_charge_micros', '>', 0)
                ->lockForUpdate()->get();
            if ($periods->isEmpty()) {
                return null;
            }
            $lines = $periods->map(function (TenantMessagingUsagePeriod $period): array {
                $chargedUnits = TenantMessagingLedgerEntry::query()->forAllTenants()
                    ->where('tenant_messaging_usage_period_id', $period->id)
                    ->where('entry_type', 'usage_settlement')
                    ->get()->sum(fn (TenantMessagingLedgerEntry $entry): int => (int) data_get($entry->metadata, 'charged_units', 0));
                $amountCents = (int) ceil($period->buyer_charge_micros / 10000);
                $unitLabel = $period->channel === 'email' ? 'emails' : ($period->channel === 'mms' ? 'MMS messages' : 'SMS segments');

                return [
                    'category' => 'everbranch_service',
                    'description' => sprintf('%s messaging overage — %s %s (%s through %s)', ucfirst((string) $period->channel), number_format($chargedUnits), $unitLabel, $period->period_start->format('M j, Y'), $period->period_end->format('M j, Y')),
                    'quantity' => 1,
                    'unit_amount' => number_format($amountCents / 100, 2, '.', ''),
                ];
            })->all();
            $periodStart = $periods->sortBy('period_start')->firstOrFail()->period_start->toDateString();
            $periodEnd = $periods->sortByDesc('period_end')->firstOrFail()->period_end->toDateString();
            $invoice = $this->invoices->createDraft($tenant, [
                'customer_name' => $customer['name'],
                'customer_email' => $customer['email'],
                'billing_address' => $customer['billing_address'],
                'days_until_due' => 15,
                'authorization_reference' => 'Accepted '.$tenant->name.' agreement #'.$agreement->id.' version '.$agreement->currentVersion?->version_number,
                'memo' => 'Everbranch messaging usage above the included monthly allowance for '.$periodStart.' through '.$periodEnd.'.',
                'footer' => 'Usage is calculated from Everbranch immutable delivery settlements. Contact Everbranch with billing questions.',
                'lines' => $lines,
            ], null);
            $invoice->forceFill([
                'provider_customer_id' => (string) $order->provider_customer_id,
                'metadata' => [
                    ...(array) $invoice->metadata,
                    'generated_by' => 'messaging_usage_period_close',
                    'usage_period_ids' => $periods->pluck('id')->map(fn ($id): int => (int) $id)->all(),
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'buyer_charge_micros' => (int) $periods->sum('buyer_charge_micros'),
                    'rounding' => 'each_channel_rounded_up_to_nearest_cent',
                ],
            ])->save();
            TenantMessagingUsagePeriod::query()->forAllTenants()->whereIn('id', $periods->pluck('id'))->update([
                'tenant_direct_invoice_id' => (int) $invoice->id,
                'invoiced_at' => now(),
                'updated_at' => now(),
            ]);

            return $invoice;
        }, 3);
    }

    /** @return array{name:string,email:string,billing_address:array<string,?string>} */
    protected function stripeCustomer(string $customerId, string $fallbackName, string $fallbackEmail): array
    {
        if (! str_starts_with($customerId, 'cus_')) {
            throw new RuntimeException('A verified Stripe customer is required for messaging overage invoicing.');
        }
        $response = Http::acceptJson()->timeout(max(5, (int) config('services.stripe.timeout', 20)))
            ->withBasicAuth((string) config('services.stripe.secret'), '')
            ->get(rtrim((string) config('services.stripe.api_base', 'https://api.stripe.com'), '/').'/v1/customers/'.urlencode($customerId));
        if ($response->failed()) {
            throw new RuntimeException('Stripe customer billing details could not be verified.');
        }
        $name = trim((string) $response->json('name')) ?: trim($fallbackName);
        $email = strtolower(trim((string) $response->json('email'))) ?: strtolower(trim($fallbackEmail));
        $address = (array) $response->json('address');
        foreach (['line1', 'city', 'state', 'postal_code', 'country'] as $required) {
            if (blank($address[$required] ?? null)) {
                throw new RuntimeException('Stripe customer billing details are incomplete.');
            }
        }
        if ($name === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Stripe customer name and email are required for messaging overage invoicing.');
        }

        return [
            'name' => $name,
            'email' => $email,
            'billing_address' => [
                'line1' => trim((string) $address['line1']),
                'line2' => trim((string) ($address['line2'] ?? '')) ?: null,
                'city' => trim((string) $address['city']),
                'state' => strtoupper(trim((string) $address['state'])),
                'postal_code' => trim((string) $address['postal_code']),
                'country' => strtoupper(trim((string) $address['country'])),
            ],
        ];
    }
}
