<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfileLink;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\PaymentsCore\PaymentJournal;
use Illuminate\Support\Facades\Schema;

/** Reads original confirmed operations; never creates an order or accounting entry. */
final class PosCustomerPurchases
{
    public function __construct(private PosStore $store, private PaymentJournal $journal,
        private CanonicalMarketingProfileResolver $identities) {}

    public function forProfiles(int $tenant, array $profiles, int $limit): array
    {
        $result = ['purchases' => [], 'square_payments' => [], 'square_orders' => [], 'shopify_orders' => [], 'issues' => []];
        if (! Schema::hasTable('pos_live_sales') || ! filled(config('pos_live.runtime_id'))) {
            return $result;
        }
        $links = MarketingProfileLink::query()->forTenantId($tenant)->whereIn('marketing_profile_id', $profiles)
            ->where('source_type', 'pos_sale')->latest('id')->limit($limit + 1)->get();
        foreach ($links as $link) {
            $prefix = $this->store->runtime().':';
            if (! str_starts_with($link->source_id, $prefix)) {
                continue;
            }
            $id = substr($link->source_id, strlen($prefix));
            if (! preg_match('/^[a-f0-9]{32}$/D', $id)) {
                continue;
            }
            try {
                $sale = $this->store->rows('sales', $tenant)->where('id', $id)->where('state', 'succeeded')->first();
                if (! $sale || ! $sale->attempt_id) {
                    continue;
                }
                $snapshot = app(PosService::class)->snapshot($sale);
                $customer = $this->identities->byId($tenant, (int) ($snapshot['customer']['id'] ?? 0));
                $conflict = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', 'pos_sale')
                    ->where('source_id', $link->source_id)->get()->contains(fn ($other) => $this->identities->byId($tenant, (int) $other->marketing_profile_id)?->id !== $customer?->id);
                if (! $customer || ! in_array((int) $customer->id, $profiles, true) || $conflict) {
                    $result['issues'][] = 'customer_identity_review_required';

                    continue;
                }
                $payment = $this->journal->get($tenant, $sale->attempt_id);
                if ($payment['state'] !== 'succeeded' || $payment['kind'] !== 'payment'
                    || ($payment['binding']['environment'] ?? null) !== 'live'
                    || $payment['provider_id'] === null || $payment['sale_id'] !== $sale->id
                    || (int) $payment['amount_minor'] !== (int) $sale->amount_minor
                    || $payment['request_id'] !== $sale->payment_request_id
                    || \App\Services\PaymentsCore\Contract::hash($payment['binding']) !== \App\Services\PaymentsCore\Contract::hash($snapshot['binding'])) {
                    continue;
                }
                if ($this->mirrorLinksConflict($tenant, $sale, $snapshot, $payment['provider_id'], $customer->id)) {
                    $result['issues'][] = 'customer_identity_review_required';

                    continue;
                }
                $refunds = $this->confirmedRefunds($sale);
                $result['purchases'][] = ['id' => 'pos_sale:'.$id, 'source' => 'pos_sale', 'source_id' => $id,
                    'number' => 'Everbranch Pay · EB-'.strtoupper(substr($id, 0, 12)), 'channel' => 'in_person',
                    'status' => $refunds >= $sale->amount_minor ? 'refunded' : ($refunds > 0 ? 'partially_refunded' : 'paid'),
                    'confirmed' => true, 'amount_minor' => (int) $sale->amount_minor, 'refunded_minor' => $refunds,
                    'currency' => 'USD', 'currency_scale' => 2, 'occurred_at' => \Carbon\CarbonImmutable::parse($sale->created_at)->toIso8601String()];
                $result['square_payments'][] = $payment['provider_id'];
                if ($sale->provider_order_id) {
                    $result['square_orders'][] = $sale->provider_order_id;
                }
                $reference = $sale->source_reference ? $this->store->decrypt($sale->source_reference) : [];
                if (isset($reference['order_id'], $snapshot['customer']['shopify_store_key'])) {
                    $result['shopify_orders'][] = $snapshot['customer']['shopify_store_key'].':'.basename($reference['order_id']);
                }
            } catch (\Throwable) {
                $result['issues'][] = 'pos_purchase_verification_incomplete';
            }
        }

        return $result;
    }

    public function sourceConflicts(int $tenant, string $type, string $source, int $profile): bool
    {
        if (! Schema::hasTable('pos_live_sales') || ! filled(config('pos_live.runtime_id'))) {
            return false;
        }
        $query = $this->store->rows('sales', $tenant)->where('state', 'succeeded');
        if ($type === 'square_order') {
            $query->where('provider_order_id', $source);
        } elseif ($type === 'square_payment') {
            $query->whereIn('attempt_id', $this->store->db->table('payment_core_operations')->select('id')
                ->where('tenant_id', $tenant)->where('kind', 'payment')->where('state', 'succeeded')->where('provider_id', $source));
        } elseif ($type === 'order') {
            $order = \App\Models\Order::forTenantId($tenant)->find($source);
            if (! $order || ! $order->shopify_store_key || ! $order->shopify_order_id) {
                return false;
            }
            $query->where('commerce_order_id', (string) $order->shopify_order_id)->whereIn('commerce_store_id',
                \App\Models\ShopifyStore::forTenantId($tenant)->where('store_key', $order->shopify_store_key)->select('id'));
        } else {
            return false;
        }
        foreach ($query->limit(2)->get() as $sale) {
            try {
                $snapshot = app(PosService::class)->snapshot($sale);
                if (! empty($snapshot['customer']['id']) && $this->mirrorLinksConflict($tenant, $sale, $snapshot, $this->journal->get($tenant, $sale->attempt_id)['provider_id'], (int) $snapshot['customer']['id'])) {
                    return true;
                }
                if (! empty($snapshot['customer']['id'])
                    && $this->identities->byId($tenant, (int) $snapshot['customer']['id'])?->id !== $profile) {
                    return true;
                }
            } catch (\Throwable) {
                return true;
            }
        }

        return false;
    }

    private function mirrorLinksConflict(int $tenant, object $sale, array $snapshot, string $payment, int $profile): bool
    {
        $sources = [['square_payment', $payment]];
        if ($sale->provider_order_id) {
            $sources[] = ['square_order', $sale->provider_order_id];
        }
        $reference = $sale->source_reference ? $this->store->decrypt($sale->source_reference) : [];
        if (isset($reference['order_id'], $snapshot['customer']['shopify_store_key'])) {
            foreach (\App\Models\Order::forTenantId($tenant)->where('shopify_store_key', $snapshot['customer']['shopify_store_key'])
                ->where('shopify_order_id', basename($reference['order_id']))->pluck('id') as $orderId) {
                $sources[] = ['order', (string) $orderId];
            }
        }
        $canonical = $this->identities->byId($tenant, $profile)?->id;
        foreach ($sources as [$type, $id]) {
            foreach (MarketingProfileLink::forTenantId($tenant)->where('source_type', $type)->where('source_id', $id)->get() as $link) {
                if ($this->identities->byId($tenant, (int) $link->marketing_profile_id)?->id !== $canonical) {
                    return true;
                }
            }
        }

        return false;
    }

    public function confirmedRefunds(object $sale): int
    {
        $operations = [];
        foreach ($this->store->db->table('payment_core_operations')->where('tenant_id', $sale->tenant_id)->where('original_id', $sale->attempt_id)->where('kind', 'refund')->where('state', 'succeeded')->pluck('id') as $id) {
            $refund = $this->journal->get((int) $sale->tenant_id, $id);
            if ($refund['kind'] === 'refund' && $refund['state'] === 'succeeded' && $refund['original_id'] === $sale->attempt_id) {
                $operations[$refund['provider_id'] ?? ('operation:'.$id)] = (int) $refund['amount_minor'];
            }
        }
        foreach ($this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)
            ->where('code', 'customer_refund_confirmed')->get() as $event) {
            $details = $this->store->decrypt($event->details);
            $refund = $this->journal->get((int) $sale->tenant_id, $details['operation_id']);
            if ($refund['state'] === 'succeeded' && $refund['kind'] === 'refund' && $refund['original_id'] === $sale->attempt_id) {
                $operations[$refund['provider_id'] ?? ('operation:'.$refund['id'])] = (int) $refund['amount_minor'];
            }
        }

        foreach ($this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('code', 'square_refund_observed')->get() as $event) {
            $details = $this->store->decrypt($event->details);
            $payment = $this->journal->get((int) $sale->tenant_id, $sale->attempt_id);
            if ($payment['provider_id'] === $details['payment_id']) {
                $operations[$details['refund_id']] = (int) $details['amount_minor'];
            }
        }
        if (array_sum($operations) > (int) $sale->amount_minor) {
            throw new PosException('square_refund_coverage_requires_review');
        }

        return array_sum($operations);
    }
}
