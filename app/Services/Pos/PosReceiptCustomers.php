<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\PaymentsCore\PaymentJournal;
use Illuminate\Support\Facades\Schema;

/** An audited association is separate from the immutable financial snapshot. */
final class PosReceiptCustomers
{
    public function __construct(private PosStore $store, private StockLedger $stock, private PosCustomerDirectory $directory,
        private CanonicalMarketingProfileResolver $identities) {}

    public function association(object $sale): ?object
    {
        if (! Schema::hasTable('commerce_stock_receipt_associations')) {
            return null;
        }

        return $this->stock->rows('receipt_associations', (int) $sale->tenant_id)->where('runtime_id', $this->store->runtime())->where('sale_id', $sale->id)->first();
    }

    public function decorate(object $sale, array $snapshot): array
    {
        $link = $this->association($sale);
        if ($link?->customer_profile_id && empty($snapshot['customer']['id'])) {
            $profile = $this->identities->byId((int) $sale->tenant_id, (int) $link->customer_profile_id);
            if (! $profile) {
                throw new PosException('customer_identity_review_required');
            }
            $snapshot['customer'] = ['id' => (int) $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name), 'shopify_customer_id' => null];
            if ($link->reward_context) {
                $snapshot['customer_rewards'] = $this->store->decrypt($link->reward_context);
            }
        }

        return $snapshot;
    }

    public function attach(array $scope, object $sale, array $snapshot, array $input): array
    {
        $operation = $sale->attempt_id ? app(PaymentJournal::class)->get($scope['tenant_id'], $sale->attempt_id) : null;
        if ($sale->state !== 'succeeded' || ! $operation || $operation['state'] !== 'succeeded' || ! $operation['provider_id']
            || $operation['sale_id'] !== $sale->id || $operation['request_id'] !== $sale->payment_request_id
            || $operation['kind'] !== 'payment' || (int) $operation['amount_minor'] !== (int) $sale->amount_minor
            || \App\Services\PaymentsCore\Contract::hash($operation['binding']) !== \App\Services\PaymentsCore\Contract::hash($snapshot['binding'])) {
            throw new PosException('paid_receipt_required');
        }
        $customer = isset($input['customer_profile_id']) ? $this->directory->freeze($scope, $input['customer_profile_id']) : null;
        $contact = array_filter(['name' => trim($input['name'] ?? ''), 'phone' => trim($input['phone'] ?? ''), 'email' => trim($input['email'] ?? '')], fn ($x) => $x !== '');
        if (! $customer && ! $contact) {
            throw new PosException('receipt_contact_or_customer_required', 422);
        }
        $review = $input['reward_evidence'] ?? null;
        if ($review && ! in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true)) {
            throw new PosException('reward_owner_review_required', 403);
        }
        $result = $this->stock->operation($scope['tenant_id'], 'receipt:'.$this->store->runtime().':'.$input['request_id'], 'receipt_customer', $scope['actor_id'],
            [$sale->id, $input], function ($key) use ($scope, $sale, $snapshot, $customer, $contact, $review, $input): array {
                $current = $this->association($sale);
                $contact = [...($current ? $this->store->decrypt($current->contact) : []), ...$contact];
                $selected = $current?->customer_profile_id ?? ($snapshot['customer']['id'] ?? null);
                if ($selected && $customer && (int) $selected !== $customer['id']) {
                    throw new PosException('receipt_customer_already_associated');
                }
                $profileId = $customer['id'] ?? $selected;
                $reward = $current?->reward_context ? $this->store->decrypt($current->reward_context) : ($snapshot['customer_rewards'] ?? ['enabled' => false]);
                if ($profileId && ($reward['anonymous'] ?? false)) {
                    $reward = $this->claimTerms($scope['tenant_id'], (int) $profileId, $sale, $reward, $review, $scope['actor_id']);
                }
                $values = ['tenant_id' => $scope['tenant_id'], 'runtime_id' => $this->store->runtime(), 'sale_id' => $sale->id,
                    'customer_profile_id' => $profileId, 'actor_id' => $scope['actor_id'], 'request_hash' => $key,
                    'input_hash' => \App\Services\PaymentsCore\Contract::hash($input), 'contact' => $this->store->encrypt($contact),
                    'reward_context' => $this->store->encrypt($reward), 'created_at' => $current?->created_at ?? now(), 'updated_at' => now()];
                $this->stock->rows('receipt_associations', $scope['tenant_id'])->updateOrInsert(
                    ['tenant_id' => $scope['tenant_id'], 'runtime_id' => $this->store->runtime(), 'sale_id' => $sale->id], $values);
                if ($profileId && ! $selected) {
                    $this->directory->linkSale($scope, $sale->id, (int) $profileId);
                }
                $this->store->event($scope['tenant_id'], $sale->id, 'receipt_customer_associated', ['actor_id' => $scope['actor_id'],
                    'profile_id' => $profileId, 'request_hash' => $key, 'reward_review' => $review !== null]);

                return ['associated' => $profileId !== null, 'profile_id' => $profileId];
            });
        app(PosService::class)->enqueue($scope['tenant_id'], $sale->id, 'customer_association_'.substr(hash('sha256', $input['request_id']), 0, 32), ['operation_id' => $operation['id']]);
        if ($review) {
            // Retry historical eligibility effects only; spent-credit and identity reviews remain independent.
            $this->store->rows('outbox', $scope['tenant_id'])->where('sale_id', $sale->id)->where('state', 'review')
                ->where('error_code', 'customer_rewards_historical_review')->update(['state' => 'retry', 'available_at' => now()]);
        }
        app(PosOutbox::class)->drainSale($scope, $sale->id);

        return $result;
    }

    private function claimTerms(int $tenant, int $profileId, object $sale, array $terms, ?array $review, int $actor): array
    {
        if (($terms['enabled'] ?? false) !== true) {
            return $terms;
        }
        if ($review) {
            return [...$terms, 'anonymous' => false, 'historical_review_required' => false,
                'member' => $review['member_at_purchase'], 'order_number' => $review['prior_confirmed_orders'] + 1,
                'eligibility_review' => ['actor_id' => $actor, 'reason' => $review['reason'], 'at' => now()->toIso8601String()]];
        }
        $profile = MarketingProfile::query()->forTenantId($tenant)->findOrFail($profileId);
        $history = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant, 100);
        $purchases = collect($history['purchases']);
        $ambiguous = count($history['issues'] ?? []) > 0 || $purchases->count() >= 100
            || ($terms['rules']['candle_club_multiplier_enabled'] ?? false)
            || $purchases->contains(fn ($p) => $p['confirmed'] && empty($p['occurred_at']));
        if ($ambiguous) {
            return [...$terms, 'historical_review_required' => true];
        }
        $prior = $purchases->filter(fn ($p) => $p['confirmed'] && $p['amount_minor'] !== null
            && ($p['refunded_minor'] ?? 0) < $p['amount_minor'] && strtotime($p['occurred_at']) < strtotime($sale->created_at))->count();

        return [...$terms, 'anonymous' => false, 'historical_review_required' => false, 'member' => false, 'order_number' => $prior + 1];
    }
}
