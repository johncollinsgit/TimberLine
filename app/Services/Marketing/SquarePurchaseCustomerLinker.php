<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use Illuminate\Support\Facades\DB;

/** Original provider customer relationships only. Never infer a customer from contact/card similarity. */
class SquarePurchaseCustomerLinker
{
    public function inspect(int $tenant, string $orderId): array
    {
        $order = SquareOrder::forTenantId($tenant)->where('square_order_id', $orderId)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
        $payments = SquarePayment::forTenantId($tenant)->where('square_order_id', $orderId)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
        if (! $order || ! app(SquarePurchaseEvidence::class)->confirmedOrder($order, $payments)) {
            return ['status' => 'review', 'reason' => 'confirmed_payment_required'];
        }
        $binding = $order->raw_payload['_source_binding'] ?? [];
        if (empty($binding['merchant_id']) || empty($binding['environment'])) {
            return ['status' => 'review', 'reason' => 'original_merchant_required'];
        }
        app(SquareConnectionContext::class)->assertCurrent($tenant, $binding);
        foreach ($payments as $payment) {
            foreach (['merchant_id', 'environment'] as $key) {
                if (data_get($payment->raw_payload, '_source_binding.'.$key) !== $binding[$key]) {
                    return ['status' => 'review', 'reason' => 'payment_merchant_conflict'];
                }
            }
        }
        $customerIds = $payments->pluck('square_customer_id')->push($order->square_customer_id)->filter()->unique();
        $keys = [['type' => 'square_order', 'id' => $orderId]];
        foreach ($payments as $payment) {
            $keys[] = ['type' => 'square_payment', 'id' => $payment->square_payment_id];
        }
        $resolver = app(CanonicalMarketingProfileResolver::class);
        $roots = [];
        foreach ($customerIds as $customerId) {
            $customer = SquareCustomer::forTenantId($tenant)->where('square_customer_id', $customerId)->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
            foreach (['merchant_id', 'environment'] as $key) {
                if (data_get($customer?->raw_payload, '_source_binding.'.$key) !== $binding[$key]) {
                    return ['status' => 'review', 'reason' => 'customer_merchant_review_required'];
                }
            }
            $keys[] = ['type' => 'square_customer', 'id' => $customerId];
        }
        foreach ($keys as $key) {
            $claims = MarketingProfileLink::withoutGlobalScopes()->where('source_type', $key['type'])->where('source_id', $key['id'])
                ->where(fn ($q) => $q->where('tenant_id', $tenant)->orWhereNull('tenant_id'))->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
            foreach ($claims as $claim) {
                $root = DB::transactionLevel() > 0 ? $resolver->lockedById($tenant, (int) $claim->marketing_profile_id) : $resolver->byId($tenant, (int) $claim->marketing_profile_id);
                if (! $root || $root->archived_at) {
                    return ['status' => 'review', 'reason' => 'customer_identity_review_required'];
                }
                foreach (['merchant_id', 'environment'] as $field) {
                    if (! empty($claim->source_meta[$field]) && $claim->source_meta[$field] !== $binding[$field]) {
                        return ['status' => 'review', 'reason' => 'source_claim_merchant_conflict'];
                    }
                }
                $roots[$root->id] = $root;
            }
        }
        if (count($roots) !== 1) {
            return ['status' => 'review', 'reason' => $roots ? 'conflicting_customer_claims' : 'customer_not_linked'];
        }

        return ['status' => 'ready', 'profile' => array_values($roots)[0], 'order' => $order, 'payments' => $payments,
            'binding' => $binding, 'sources' => $keys];
    }

    public function reconcile(int $tenant, string $orderId, bool $dryRun = false): array
    {
        $work = function () use ($tenant, $orderId, $dryRun): array {
            $result = $this->inspect($tenant, $orderId);
            if ($result['status'] !== 'ready' || $dryRun) {
                return $result;
            }
            foreach ($result['sources'] as $source) {
                if ($source['type'] === 'square_customer') {
                    continue;
                }
                $link = MarketingProfileLink::forTenantId($tenant)->where('source_type', $source['type'])->where('source_id', $source['id'])->lockForUpdate()->first();
                if (! $link) {
                    MarketingProfileLink::create(['tenant_id' => $tenant, 'marketing_profile_id' => $result['profile']->id,
                        'source_type' => $source['type'], 'source_id' => $source['id'], 'match_method' => 'verified_square_payment_customer',
                        'source_meta' => $result['binding'] + ['square_order_id' => $orderId, 'payment_ids' => $result['payments']->pluck('square_payment_id')->all(),
                            'identity_basis' => 'original_provider_customer_ids', 'location_id' => $result['order']->location_id,
                            'location_is_event' => false, 'linked_at' => now()->toIso8601String()]]);
                }
            }

            foreach ($result['sources'] as $source) {
                $reviews = \App\Models\MarketingIdentityReview::where('source_type', $source['type'])->where('source_id', $source['id'])->where('status', 'pending')->get();
                foreach ($reviews as $review) {
                    $scope = data_get($review->payload, 'source_context.tenant_id');
                    $proposed = $review->proposed_marketing_profile_id ? app(CanonicalMarketingProfileResolver::class)->byId($tenant, (int) $review->proposed_marketing_profile_id) : null;
                    if (($scope !== null && (int) $scope !== $tenant) || ($scope === null && $proposed?->id !== $result['profile']->id)
                        || ! array_intersect($review->conflict_reasons ?? [], ['source_link_conflict', 'square_source_link_conflict'])) {
                        continue;
                    }
                    $payload = (array) $review->payload;
                    $payload['purchase_source_resolution'] = ['tenant_id' => $tenant, 'profile_id' => $result['profile']->id,
                        'order_id' => $orderId, 'payment_ids' => $result['payments']->pluck('square_payment_id')->all(), 'resolved_at' => now()->toIso8601String()];
                    $review->update(['status' => 'resolved', 'proposed_marketing_profile_id' => $result['profile']->id, 'payload' => $payload,
                        'reviewed_at' => now(), 'resolution_notes' => 'Original provider purchase relationships resolve to one existing canonical customer. No contact permission was granted.']);
                }
            }

            return $result;
        };

        return $dryRun ? $work() : app(CustomerAccountFence::class)->forTenant($tenant, $work);
    }
}
