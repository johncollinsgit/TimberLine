<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\TenantBillingOrder;
use App\Models\TenantBudSetting;
use App\Models\TenantDirectInvoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class TenantAiUsageInvoiceService
{
    public function __construct(
        protected DirectInvoiceManagementService $invoices,
        protected DirectStripeInvoiceService $stripe,
    ) {}

    /** @return array{groups:int,drafts_created:int,invoices_sent:int,blocked:int,failures:int,invoice_ids:array<int,int>,errors:array<int,string>} */
    public function invoiceClosedMonths(?string $tenantSlug = null, bool $send = true): array
    {
        $tenantId = filled($tenantSlug) ? Tenant::query()->where('slug', strtolower(trim((string) $tenantSlug)))->value('id') : null;
        if (filled($tenantSlug) && ! $tenantId) {
            throw new RuntimeException('The requested tenant was not found.');
        }

        $events = TenantAiUsageEvent::query()->withoutGlobalScopes()->where('status', 'settled')
            ->whereNull('tenant_direct_invoice_id')->where('buyer_charge_micros', '>', 0)
            ->where('occurred_at', '<', now()->startOfMonth())
            ->when($tenantId, fn ($query) => $query->where('tenant_id', (int) $tenantId))
            ->orderBy('tenant_id')->orderBy('occurred_at')->get();
        $groups = $events->groupBy(fn (TenantAiUsageEvent $event): string => $event->tenant_id.':'.$event->occurred_at->format('Y-m'));
        $summary = ['groups' => $groups->count(), 'drafts_created' => 0, 'invoices_sent' => 0, 'blocked' => 0, 'failures' => 0, 'invoice_ids' => [], 'errors' => []];

        foreach ($groups as $group) {
            try {
                $invoice = $this->createInvoice($group);
                if (! $invoice) {
                    $summary['blocked']++;

                    continue;
                }
                $summary['drafts_created']++;
                $summary['invoice_ids'][] = (int) $invoice->id;
                if ($send) {
                    $result = $this->stripe->send($invoice, null);
                    $summary[(bool) ($result['ok'] ?? false) ? 'invoices_sent' : 'blocked']++;
                }
            } catch (\Throwable $exception) {
                $summary['failures']++;
                $summary['errors'][] = mb_substr($exception->getMessage(), 0, 500);
            }
        }

        return $summary;
    }

    /** @param Collection<int,TenantAiUsageEvent> $candidateEvents */
    private function createInvoice(Collection $candidateEvents): ?TenantDirectInvoice
    {
        $first = $candidateEvents->first();
        if (! $first instanceof TenantAiUsageEvent) {
            return null;
        }
        $tenant = Tenant::query()->findOrFail((int) $first->tenant_id);
        $setting = TenantBudSetting::query()->where('tenant_id', (int) $tenant->id)->first();
        $order = TenantBillingOrder::withoutGlobalScopes()->with('acceptance')->where('tenant_id', (int) $tenant->id)
            ->where('status', 'paid')->whereNotNull('provider_customer_id')->latest('id')->first();
        if (! $setting || ! $setting->ai_reviewed_at || ! $order) {
            return null;
        }
        $customer = $this->stripeCustomer((string) $order->provider_customer_id, (string) $order->acceptance?->signer_legal_name, (string) $order->acceptance?->signer_email);
        $ids = $candidateEvents->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return DB::transaction(function () use ($ids, $tenant, $setting, $order, $customer): ?TenantDirectInvoice {
            $events = TenantAiUsageEvent::query()->withoutGlobalScopes()->whereIn('id', $ids)->where('status', 'settled')
                ->whereNull('tenant_direct_invoice_id')->lockForUpdate()->get();
            if ($events->isEmpty()) {
                return null;
            }
            $chargeMicros = (int) $events->sum('buyer_charge_micros');
            $seconds = (int) $events->sum('duration_seconds');
            $month = $events->firstOrFail()->occurred_at;
            $invoice = $this->invoices->createDraft($tenant, [
                'customer_name' => $customer['name'], 'customer_email' => $customer['email'], 'billing_address' => $customer['billing_address'],
                'days_until_due' => 15,
                'authorization_reference' => 'Tenant paid AI approval #'.$setting->id.' reviewed '.$setting->ai_reviewed_at->toDateString(),
                'memo' => 'Everbranch paid AI usage for '.$month->format('F Y').'.',
                'footer' => 'Usage is measured from immutable, successfully settled Everbranch AI requests.',
                'lines' => [[
                    'category' => 'everbranch_service',
                    'description' => sprintf('Everbranch AI usage — %d voice drafts, %s audio minutes', $events->count(), number_format($seconds / 60, 1)),
                    'quantity' => 1, 'unit_amount' => number_format((int) ceil($chargeMicros / 10000) / 100, 2, '.', ''),
                ]],
            ], null);
            $invoice->forceFill([
                'provider_customer_id' => (string) $order->provider_customer_id,
                'metadata' => [...(array) $invoice->metadata, 'generated_by' => 'tenant_ai_usage_month_close',
                    'usage_event_ids' => $events->pluck('id')->all(), 'usage_month' => $month->format('Y-m'),
                    'buyer_charge_micros' => $chargeMicros, 'rounding' => 'monthly_total_rounded_up_to_nearest_cent'],
            ])->save();
            TenantAiUsageEvent::query()->withoutGlobalScopes()->whereIn('id', $events->pluck('id'))->update([
                'tenant_direct_invoice_id' => (int) $invoice->id, 'updated_at' => now(),
            ]);

            return $invoice;
        }, 3);
    }

    /** @return array{name:string,email:string,billing_address:array<string,?string>} */
    private function stripeCustomer(string $customerId, string $fallbackName, string $fallbackEmail): array
    {
        if (! str_starts_with($customerId, 'cus_')) {
            throw new RuntimeException('A verified Stripe customer is required for AI usage invoicing.');
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
            throw new RuntimeException('Stripe customer name and email are required for AI usage invoicing.');
        }

        return ['name' => $name, 'email' => $email, 'billing_address' => [
            'line1' => trim((string) $address['line1']), 'line2' => trim((string) ($address['line2'] ?? '')) ?: null,
            'city' => trim((string) $address['city']), 'state' => strtoupper(trim((string) $address['state'])),
            'postal_code' => trim((string) $address['postal_code']), 'country' => strtoupper(trim((string) $address['country'])),
        ]];
    }
}
