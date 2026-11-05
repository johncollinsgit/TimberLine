<?php

namespace App\Services\Wholesale;

use App\Models\Tenant;
use App\Models\TenantWholesaleSetting;
use App\Models\WholesaleFollowUp;
use App\Models\WholesaleProspect;
use App\Models\WholesaleSuggestion;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Str;

class WholesaleOutreachDigest
{
    public function __construct(private WholesaleOperationsService $operations, private TenantModuleAccessResolver $modules) {}

    /** @return array<string,mixed> */
    public function snapshot(Tenant $tenant): array
    {
        $tenantId = (int) $tenant->id;
        $reporting = $this->operations->reportingStatus($tenantId);
        if (! $reporting['complete'] || $reporting['stale']) {
            throw new \DomainException('Current complete wholesale history is required before recommending outreach.');
        }
        $setting = TenantWholesaleSetting::query()->where('tenant_id', $tenantId)
            ->whereNotNull('confirmed_at')->with('shopifyStore')->first();
        if (! $setting?->shopifyStore || (int) $setting->shopifyStore->tenant_id !== $tenantId || ! $this->modules->canAccess($tenantId, 'wholesale_operations')) {
            throw new \DomainException('A confirmed wholesale store owned by this workspace is required.');
        }

        $suggestions = WholesaleSuggestion::query()->forAllTenants()->where('tenant_id', $tenantId)
            ->where('target_type', 'customer')->orderByDesc('id')->get()->groupBy('target_key');
        $followUps = WholesaleFollowUp::query()->forAllTenants()->where('tenant_id', $tenantId)
            ->where('target_type', 'customer')->get()->groupBy('target_key');
        $blockedProspects = WholesaleProspect::query()->forAllTenants()->where('tenant_id', $tenantId)->where('do_not_contact', true)->get(['existing_customer_match', 'public_business_email']);
        $candidates = $this->operations->customers($tenantId)->filter(function (array $customer) use ($suggestions, $followUps, $blockedProspects): bool {
            if (! in_array($customer['timing_state'], ['due', 'at_risk', 'lapsed'], true)
                || ! filter_var($customer['email'], FILTER_VALIDATE_EMAIL)) {
                return false;
            }
            if ($blockedProspects->contains(fn ($prospect): bool => $prospect->existing_customer_match === $customer['public_key'] || (filled($prospect->public_business_email) && Str::lower(trim($prospect->public_business_email)) === Str::lower(trim($customer['email']))))) {
                return false;
            }
            $last = $customer['orders']->first();
            if (! $last?->ordered_at || $last->ordered_at->isFuture()
                || in_array(strtolower((string) $last->status), ['cancelled', 'canceled', 'voided', 'refunded'], true)
                || (float) $last->total_price <= (float) $last->refund_total) {
                return false;
            }
            // A decision applies only to the same order evidence. New orders can
            // become due again without reviving a dismissed historical suggestion.
            foreach ($suggestions->get($customer['public_key'], collect()) as $suggestion) {
                if ($suggestion->status === 'superseded') {
                    continue;
                }
                if (! WholesaleSuggestionEvidence::matchesLatestOrder((array) $suggestion->supporting_evidence, $last)) {
                    continue;
                }
                if ($suggestion->status === 'snoozed' && $suggestion->snoozed_until?->isPast()) {
                    continue;
                }
                if (! in_array($suggestion->status, ['pending', 'accepted'], true)) {
                    return false;
                }
            }
            foreach ($followUps->get($customer['public_key'], collect()) as $followUp) {
                if (in_array($followUp->status, ['open', 'in_progress'], true) && $followUp->due_at?->isFuture()) {
                    return false;
                }
                if ($followUp->completed_at?->gte(now()->subDays(14))) {
                    return false;
                }
            }

            return true;
        })->sort(function (array $a, array $b): int {
            $priority = ['at_risk' => 3, 'due' => 2, 'lapsed' => 1];

            return ($priority[$b['timing_state']] <=> $priority[$a['timing_state']])
                ?: ($b['trailing_12_revenue'] <=> $a['trailing_12_revenue'])
                ?: ($b['days_since_last_order'] <=> $a['days_since_last_order'])
                ?: strcmp($a['public_key'], $b['public_key']);
        });

        // Different source customer IDs can share one buying inbox. Show it once.
        $selected = $candidates->unique(fn (array $customer): string => Str::lower(trim($customer['email'])))->take(10)->values();
        $rows = $selected->map(function (array $customer) use ($tenant): array {
            $last = $customer['orders']->first();
            $last->loadMissing('lines');
            $items = $last->lines->sortBy('id')->take(5)->map(function ($line): string {
                $title = $this->plain($line->raw_title ?: $line->scent_name ?: $line->sku ?: 'Item', 70);
                $variant = $this->plain($line->raw_variant ?: $line->size_code, 40);

                return max(0, (int) ($line->quantity ?: $line->ordered_qty)).' × '.$title.($variant !== '' ? ' ('.$variant.')' : '');
            })->all();
            $company = $this->plain($customer['company'], 100);
            $buyer = $this->plain($customer['primary_buyer'], 80);
            $orderNumber = $this->plain($last->shopify_name ?: $last->order_number ?: 'your last order', 40);
            $subject = 'Checking in on '.$company.' · '.$this->plain($tenant->name, 60);
            $body = 'Hi '.($buyer ?: 'there').",\n\nI wanted to check in with you at ".$company.'. Your most recent order, '.$orderNumber.', was placed on '.$last->ordered_at->copy()->timezone('America/New_York')->format('F j, Y').'.';
            if ($items !== []) {
                $body .= " It included:\n\n".implode("\n", $items).($last->lines->count() > 5 ? "\n(and other items)" : '');
            }
            $body .= "\n\nHow have those been selling? Would you like help putting together your next order, or trying a different mix this time?\n\nHappy to help when you’re ready.\n\n".$this->plain($tenant->name, 80);
            $draftUrl = 'https://mail.google.com/mail/?'.http_build_query([
                'view' => 'cm', 'fs' => '1', 'to' => $customer['email'], 'su' => $subject, 'body' => $body,
            ], '', '&', PHP_QUERY_RFC3986);

            return [
                'account_key' => $customer['public_key'],
                'company' => $company, 'buyer' => $buyer, 'email' => $customer['email'],
                'reason' => $customer['median_reorder_days'] === null ? 'Estimated reorder check-in (limited history)' : match ($customer['timing_state']) {
                    'at_risk' => 'Past its usual reorder window',
                    'due' => 'Approaching its usual reorder window',
                    default => 'Lapsed account worth reconnecting with',
                },
                'days_since_order' => $customer['days_since_last_order'],
                'expected_days' => $customer['median_reorder_days'],
                'order_number' => $orderNumber, 'order_date' => $last->ordered_at->copy()->timezone('America/New_York')->format('M j, Y'),
                'order_total' => number_format(max(0, (float) $last->total_price - (float) $last->refund_total), 2),
                'currency' => $this->plain($last->currency_code ?: 'Unknown currency', 16),
                'items' => $items, 'draft_url' => $this->reviewUrl($tenant, $customer['public_key']),
                'gmail_url' => $draftUrl,
                'draft_subject' => $subject, 'draft_body' => $body,
                'product_evidence' => $last->lines->map(fn ($line) => [
                    'product_id' => $line->shopify_product_id,
                    'title' => $this->plain($line->raw_title ?: $line->sku ?: 'Item', 100),
                    'variant' => $this->plain($line->raw_variant ?: $line->size_code, 80),
                    'quantity' => max(0, (int) ($line->quantity ?: $line->ordered_qty)),
                ])->all(),
            ];
        })->all();

        return [
            'tenant' => ['id' => $tenantId, 'name' => $tenant->name],
            'week' => now('America/New_York')->startOfWeek()->toDateString(),
            'generated_at' => now('America/New_York')->format('M j, Y g:i a T'),
            'rows' => $rows,
            'import_at' => \Carbon\CarbonImmutable::parse($reporting['refreshed_at'])->timezone('America/New_York')->format('M j, Y g:i a T'),
            'import_stale' => false,
            'source_order_count' => $reporting['source_order_count'],
        ];
    }

    private function reviewUrl(Tenant $tenant, string $accountKey): string
    {
        $setting = TenantWholesaleSetting::query()->where('tenant_id', $tenant->id)->with('shopifyStore')->firstOrFail();
        $store = ShopifyStores::find($setting->shopifyStore->store_key, true);
        if (! $store || (int) ($store['tenant_id'] ?? 0) !== (int) $tenant->id || ($store['store_role'] ?? '') !== 'wholesale') {
            throw new \DomainException('The wholesale app does not belong to this workspace.');
        }
        $handle = trim((string) config('services.shopify.stores.'.$store['key'].'.embedded_app_handle'));
        $shop = strtolower((string) $store['shop']);
        if (! preg_match('/^[a-z0-9][a-z0-9-]*\.myshopify\.com$/D', $shop) || ! preg_match('/^[a-z0-9][a-z0-9-]*$/D', $handle)) {
            throw new \DomainException('The wholesale app review destination is not configured.');
        }

        return 'https://admin.shopify.com/store/'.str_replace('.myshopify.com', '', $shop).'/apps/'.$handle
            .route('shopify.app.wholesale.customers.outreach', ['accountKey' => $accountKey], false);
    }

    private function plain(?string $text, int $length): string
    {
        return Str::limit(trim(preg_replace('/\s+/u', ' ', strip_tags((string) $text)) ?? ''), $length, '…');
    }
}
