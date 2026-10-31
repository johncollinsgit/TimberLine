<?php

namespace App\Services\Marketing;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TenantMarketingSetting;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use App\Support\Marketing\CandleCashMeasurement;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

/** A read-only view over original records, never a second customer or money ledger. */
class CustomerCommerceSnapshotService
{
    public function __construct(private CanonicalMarketingProfileResolver $identities) {}

    /** Authenticated Website shoppers need an explicit, unambiguous association. */
    public function forWebsiteCustomer(WebsiteCustomer $customer, int $tenantId): ?array
    {
        if ($tenantId <= 0 || (int) $customer->tenant_id !== $tenantId) {
            return null;
        }
        $links = MarketingProfileLink::query()->forTenantId($tenantId)
            ->where('source_type', 'website_customer')->where('source_id', (string) $customer->id)
            ->with('marketingProfile')->get();
        if ($links->contains(fn ($link) => $link->marketingProfile === null)) {
            return null;
        }
        $profile = $this->identities->oneCanonical($links->pluck('marketingProfile'), $tenantId);

        return $profile ? $this->forProfile($profile, $tenantId) : null;
    }

    public function forProfile(MarketingProfile $profile, int $tenantId, int $limit = 50): array
    {
        $canonical = $this->identities->canonical($profile, $tenantId);
        if (! $canonical || $tenantId <= 0) {
            throw (new ModelNotFoundException)->setModel(MarketingProfile::class, [$profile->id]);
        }

        $limit = max(1, min(100, $limit));
        $ids = $this->aliasIds($canonical, $tenantId);
        $links = MarketingProfileLink::query()->forTenantId($tenantId)
            ->whereIn('marketing_profile_id', $ids)->get();
        $issues = [];
        $purchases = collect();

        if (Schema::hasTable('orders')) {
            $orderIds = $this->sourceIds($links, 'order', $tenantId, $canonical, $issues);
            foreach (Order::query()->forTenantId($tenantId)->whereIn('id', $orderIds)
                ->latest('ordered_at')->latest('id')->limit($limit + 1)->get() as $order) {
                $evidence = (array) data_get($order->attribution_meta, 'customer_commerce', []);
                if ($evidence['test'] ?? false) {
                    continue;
                }
                $state = strtolower((string) ($evidence['financial_status'] ?? 'unverified'));
                $confirmed = in_array($state, ['paid', 'partially_refunded', 'refunded'], true)
                    && ! ($evidence['conflicted'] ?? false)
                    && filled($order->shopify_order_id) && filled($order->shopify_store_key)
                    && (int) ($evidence['tenant_id'] ?? 0) === $tenantId
                    && (string) ($evidence['shopify_order_id'] ?? '') === (string) $order->shopify_order_id
                    && (string) ($evidence['store_key'] ?? '') === (string) $order->shopify_store_key;
                $purchases->push($this->row('order', (string) $order->id,
                    (string) ($order->order_number ?: $order->shopify_name ?: 'Order '.$order->id),
                    $this->channel((string) ($evidence['source_name'] ?? '')),
                    $confirmed ? $state : 'unverified', $confirmed,
                    $this->minor($confirmed ? ($evidence['total_price'] ?? null) : $order->total_price),
                    $this->minor($confirmed ? ($evidence['refund_total'] ?? null) : $order->refund_total),
                    $confirmed ? ($evidence['currency'] ?? null) : $order->currency_code,
                    $order->ordered_at?->toIso8601String()));
            }
        }

        $squareOrders = collect();
        if (Schema::hasTable('square_orders')) {
            $sourceIds = $this->sourceIds($links, 'square_order', $tenantId, $canonical, $issues);
            $squareOrders = SquareOrder::query()->forTenantId($tenantId)->whereIn('square_order_id', $sourceIds)
                ->latest('closed_at')->latest('id')->limit($limit + 1)->get();
            $payments = SquarePayment::query()->forTenantId($tenantId)
                ->whereIn('square_order_id', $squareOrders->pluck('square_order_id'))
                ->where('status', 'COMPLETED')->get()->groupBy('square_order_id');
            $conflictingPayments = $this->conflictingSourceIds('square_payment', $payments->flatten(1)->pluck('square_payment_id'), $tenantId, $canonical, $issues);
            foreach ($squareOrders as $order) {
                $confirmed = strtoupper((string) $order->state) === 'COMPLETED' && $order->closed_at !== null;
                $tenders = $payments->get($order->square_order_id, collect());
                if ($tenders->contains(fn ($payment) => in_array($payment->square_payment_id, $conflictingPayments, true))) {
                    continue;
                }
                $refunds = $tenders->map(fn ($payment) => $this->integer(data_get($payment->raw_payload, 'refunded_money.amount')));
                $refunded = $refunds->isNotEmpty() && ! $refunds->containsStrict(null) ? $refunds->sum() : null;
                $inPerson = $tenders->isNotEmpty() && $tenders->every(fn ($payment) => in_array(
                    strtoupper((string) data_get($payment->raw_payload, 'card_details.entry_method', '')),
                    ['CONTACTLESS', 'CHIP', 'SWIPED', 'MAGSTRIPE'], true));
                $purchases->push($this->row('square_order', $order->square_order_id, 'Square order '.$order->square_order_id,
                    $inPerson ? 'in_person' : 'unknown', $confirmed ? 'paid' : 'unverified', $confirmed,
                    $this->integer($order->total_money_amount), $refunded, $order->total_money_currency,
                    $order->closed_at?->toIso8601String()));
            }
        }

        if (Schema::hasTable('square_payments')) {
            $sourceIds = $this->sourceIds($links, 'square_payment', $tenantId, $canonical, $issues);
            $payments = SquarePayment::query()->forTenantId($tenantId)->whereIn('square_payment_id', $sourceIds)
                ->latest('created_at_source')->latest('id')->limit($limit + 1)->get();
            $conflictingOrders = $this->conflictingSourceIds('square_order', $payments->pluck('square_order_id')->filter(), $tenantId, $canonical, $issues);
            foreach ($payments as $payment) {
                if (in_array((string) $payment->square_order_id, $conflictingOrders, true)) {
                    continue;
                }
                // A provider payment is another representation of its original order.
                if ($payment->square_order_id && $squareOrders->contains('square_order_id', $payment->square_order_id)) {
                    continue;
                }
                $confirmed = strtoupper((string) $payment->status) === 'COMPLETED';
                $entry = strtoupper((string) data_get($payment->raw_payload, 'card_details.entry_method', ''));
                $channel = in_array($entry, ['CONTACTLESS', 'CHIP', 'SWIPED', 'MAGSTRIPE'], true) ? 'in_person' : 'unknown';
                $purchases->push($this->row('square_payment', $payment->square_payment_id, 'Square payment '.$payment->square_payment_id,
                    $channel, $confirmed ? 'paid' : 'unverified', $confirmed,
                    $this->integer($payment->amount_money), $this->integer(data_get($payment->raw_payload, 'refunded_money.amount')),
                    $payment->currency, $payment->created_at_source?->toIso8601String()));
            }
        }

        if (Schema::hasTable('website_orders')) {
            $customerIds = $this->sourceIds($links, 'website_customer', $tenantId, $canonical, $issues);
            $orderIds = $this->sourceIds($links, 'website_order', $tenantId, $canonical, $issues);
            // Only an explicit source association admits Website records. No contact matching.
            $websiteOrders = WebsiteOrder::query()->withoutPosSimulation()->forTenantId($tenantId)
                ->where(fn ($q) => $q->whereIn('website_customer_id', $customerIds)->orWhereIn('id', $orderIds))
                ->latest('paid_at')->latest('id')->limit($limit + 1)->get();
            $conflictingOrders = $this->conflictingSourceIds('website_order', $websiteOrders->pluck('id')->map('strval'), $tenantId, $canonical, $issues);
            $conflictingCustomers = $this->conflictingSourceIds('website_customer', $websiteOrders->pluck('website_customer_id')->filter()->map('strval'), $tenantId, $canonical, $issues);
            foreach ($websiteOrders as $order) {
                if (in_array((string) $order->id, $conflictingOrders, true) || in_array((string) $order->website_customer_id, $conflictingCustomers, true)) {
                    continue;
                }
                $state = strtolower((string) $order->payment_status);
                $confirmed = $order->paid_at !== null && in_array($state, ['paid', 'partially_refunded', 'refunded'], true);
                $purchases->push($this->row('website_order', (string) $order->id, (string) ($order->number ?: 'Order '.$order->id),
                    'online', $confirmed ? $state : 'unverified', $confirmed,
                    $this->integer($order->total_cents), $this->integer($order->refunded_cents), $order->currency,
                    ($order->paid_at ?? $order->created_at)?->toIso8601String()));
            }
        }

        $purchases = $purchases->unique('id')->sortByDesc('occurred_at')->values();
        $shown = $purchases->take($limit)->values();

        return [
            'contract_version' => 1,
            'included_for_every_tenant' => true,
            'customer_id' => (int) $canonical->id,
            'tenant_id' => $tenantId,
            'purchases' => $shown->all(),
            'confirmed_purchase_count' => $shown->where('confirmed', true)->count(),
            'unverified_record_count' => $shown->where('confirmed', false)->count(),
            'coverage' => 'explicitly_linked_records_only',
            'has_more' => $purchases->count() > $limit,
            'issues' => array_values(array_unique($issues)),
            'rewards' => $this->rewards($canonical, $tenantId),
        ];
    }

    private function aliasIds(MarketingProfile $canonical, int $tenantId): array
    {
        $ids = [(int) $canonical->id];
        $frontier = $ids;
        for ($depth = 0; $frontier !== [] && $depth < 32; $depth++) {
            $frontier = MarketingProfile::query()->forTenantId($tenantId)
                ->whereIn('merged_into_profile_id', $frontier)->whereNotIn('id', $ids)
                ->limit(1001)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $ids = [...$ids, ...$frontier];
            if (count($ids) > 1000) {
                throw (new ModelNotFoundException)->setModel(MarketingProfile::class);
            }
        }
        if ($frontier !== []) {
            throw (new ModelNotFoundException)->setModel(MarketingProfile::class);
        }

        return $ids;
    }

    private function sourceIds(Collection $links, string $type, int $tenantId, MarketingProfile $canonical, array &$issues): array
    {
        $ids = $links->where('source_type', $type)->pluck('source_id')->filter()->map(fn ($id) => (string) $id)->unique()->values();
        $conflicts = $this->conflictingSourceIds($type, $ids, $tenantId, $canonical, $issues);

        return $ids->reject(fn ($id) => in_array($id, $conflicts, true))->all();
    }

    private function conflictingSourceIds(string $type, Collection $ids, int $tenantId, MarketingProfile $canonical, array &$issues): array
    {
        $conflicts = MarketingProfileLink::query()->forTenantId($tenantId)->where('source_type', $type)
            ->whereIn('source_id', $ids)->with('marketingProfile')->get()
            ->filter(fn ($link) => $this->identities->canonical($link->marketingProfile, $tenantId)?->id !== $canonical->id)
            ->pluck('source_id')->map(fn ($id) => (string) $id)->all();
        if ($conflicts !== []) {
            $issues[] = 'customer_identity_review_required';
        }

        return $conflicts;
    }

    private function row(string $source, string $sourceId, string $number, string $channel, string $status,
        bool $confirmed, ?int $amount, ?int $refunded, ?string $currency, ?string $date): array
    {
        $currency = strtoupper(trim((string) $currency));
        // These source adapters currently use two-decimal money. Fail closed for
        // other currency scales instead of silently showing an incorrect value.
        $supportedCurrency = in_array($currency, ['USD', 'CAD', 'EUR', 'GBP', 'AUD', 'NZD', 'CHF', 'SEK', 'NOK', 'DKK', 'MXN', 'BRL', 'ZAR', 'SGD', 'HKD'], true);
        if (! $supportedCurrency || $amount === null || ($refunded !== null && $refunded > $amount)) {
            $confirmed = false;
            $status = 'unverified';
        }
        if (! $supportedCurrency) {
            $amount = $refunded = null;
        }
        if ($confirmed && $refunded > 0) {
            $status = $refunded === $amount ? 'refunded' : 'partially_refunded';
        }

        return ['id' => $source.':'.$sourceId, 'source' => $source, 'source_id' => $sourceId,
            'number' => $number, 'channel' => $channel, 'status' => $status, 'confirmed' => $confirmed,
            'amount_minor' => $amount, 'refunded_minor' => $refunded,
            'currency' => $currency ?: null, 'currency_scale' => $supportedCurrency ? 2 : null, 'occurred_at' => $date];
    }

    private function channel(string $source): string
    {
        return match (strtolower(trim($source))) {
            'pos', 'everbranch_pos' => 'in_person',
            'web', 'online_store' => 'online',
            default => 'unknown',
        };
    }

    private function rewards(MarketingProfile $profile, int $tenantId): array
    {
        $balance = Schema::hasTable('candle_cash_balances')
            ? CandleCashBalance::query()->where('marketing_profile_id', $profile->id)->first() : null;
        $settings = Schema::hasTable('tenant_marketing_settings')
            ? TenantMarketingSetting::query()->forTenantId($tenantId)
                ->where('key', 'candle_cash_program_config')->first() : null;
        $transactions = Schema::hasTable('candle_cash_transactions')
            ? CandleCashTransaction::query()->where('marketing_profile_id', $profile->id)
                ->latest('id')->limit(21)->get() : collect();
        $history = $transactions->take(20)->map(function (CandleCashTransaction $transaction): array {
            $raw = $transaction->getRawOriginal();
            $delta = $raw['candle_cash_delta'] ?? (CandleCashMeasurement::isLegacyPointsOriginTransaction($raw)
                ? CandleCashMeasurement::legacyPointsToStartingCandleCash($raw['points'] ?? 0)
                : CandleCashMeasurement::normalizeStoredAmount($raw['points'] ?? 0));

            // Never expose private staff descriptions, provider payloads or notes.
            return ['id' => (int) $transaction->id, 'amount' => number_format((float) $delta, 2, '.', ''),
                'label' => (float) $delta > 0 ? 'Rewards earned' : ((float) $delta < 0 ? 'Rewards used or adjusted' : 'Rewards adjustment'),
                'occurred_at' => $transaction->created_at?->toIso8601String()];
        })->all();

        return ['available' => true, 'ledger' => 'candle_cash', 'unit' => 'reward_credit',
            'label' => app(\App\Services\Tenancy\TenantDisplayLabelResolver::class)->label($tenantId, 'rewards_label', 'Rewards'),
            'balance' => $balance ? number_format($balance->balance, 2, '.', '') : null,
            'program_status' => $settings ? 'configured' : 'not_configured',
            'history' => $history, 'has_more' => $transactions->count() > 20,
            'purchase_earning_verified' => false];
    }

    private function minor(mixed $value): ?int
    {
        if (! is_numeric($value) || ! preg_match('/^([0-9]{1,11})(?:\.([0-9]{1,2}))?$/D', (string) $value, $match)) {
            return null;
        }

        return ((int) $match[1]) * 100 + (int) str_pad($match[2] ?? '', 2, '0');
    }

    private function integer(mixed $value): ?int
    {
        return (is_int($value) && $value >= 0) || (is_string($value) && ctype_digit($value) && strlen($value) <= 13)
            ? (int) $value : null;
    }
}
