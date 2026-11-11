<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/** Cash is an explicit internal tender, never a fabricated processor payment. */
final class PosCashSale
{
    public function __construct(private PosStore $store, private PosAccess $access) {}

    public function available(array $scope): bool
    {
        return config('pos_live.cash_enabled') === true && config('pos_live.owned_inventory_enabled') === true
            && config('pos_live.native_client_enabled') === true
            && in_array((int) $scope['register']->tenant_id, config('pos_live.tenant_ids', []), true) && ! config('enterprise.runtime')
            && app(StockPosCommerce::class)->enabled($scope['register'])
            && ($this->store->decrypt($scope['register']->configuration)['cash_enabled'] ?? false) === true;
    }

    public function collectionReady(array $scope, object $sale, array $snapshot): bool
    {
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (! $this->available($scope) || ! $scope['register']->active || $sale->state !== 'quoted'
            || strtotime($sale->expires_at) <= time() || ($settings['catalog_reviewed'] ?? false) !== true
            || ($settings['tax_reviewed'] ?? false) !== true || strtotime($settings['tax_valid_until'] ?? '') <= time()) {
            return false;
        }
        try {
            app(PosTaxLocations::class)->assertCurrentReview($scope, true);

            return app(StockPosCommerce::class)->collectionReady($sale, $snapshot);
        } catch (\DomainException) {
            return false;
        }
    }

    public function collect(string $register, string $id, int $received): array
    {
        $scope = $this->access->register($register, 'quote');
        $key = 'pos-start-'.hash('sha256', $this->store->runtime().':'.$scope['tenant_id'].':'.$register);

        return Cache::lock($key, 180)->block(5, function () use ($register, $id, $received): array {
            $scope = $this->access->register($register, 'quote');
            $service = app(PosService::class);
            $sale = $service->sale($scope, $id);
            $snapshot = $service->snapshot($sale);
            if (($snapshot['tender'] ?? 'card') !== 'cash') {
                throw new PosException('cash_quote_required', 409);
            }
            if ($sale->state === 'succeeded') {
                $evidence = $this->evidence($sale, $snapshot);
                if (! $evidence || $evidence['received_minor'] !== $received) {
                    throw new PosException('cash_confirmation_conflict', 409);
                }

                return $service->inspect($register, $id);
            }
            if (! $this->collectionReady($scope, $sale, $snapshot)) {
                throw new PosException('cash_checkout_unavailable', 503);
            }
            if ($received < (int) $sale->amount_minor || $received > Contract::MAX_MINOR) {
                throw new PosException('cash_received_insufficient', 422);
            }
            app(StockPosCommerce::class)->prepare($scope, $sale, $snapshot);
            app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $sale, $snapshot, $received, $service): void {
                $register = $this->store->rows('registers', $scope['tenant_id'])->where('id', $scope['register']->id)->lockForUpdate()->firstOrFail();
                $scope = [...$scope, 'register' => $register];
                $current = $service->sale($scope, $sale->id);
                $current = $this->store->rows('sales', $scope['tenant_id'])->where('id', $current->id)->lockForUpdate()->firstOrFail();
                if (! $this->available($scope) || ! $register->active
                    || $current->state !== 'quoted' || $current->attempt_id || $current->descriptor_issued_at || $current->source_reference
                    || strtotime($current->expires_at) <= time() || ! hash_equals($current->snapshot_hash, Contract::hash($snapshot))
                    || $this->store->db->table('pos_payment_start_claims')->where('runtime_id', $this->store->runtime())->where('tenant_id', $scope['tenant_id'])->where('sale_id', $sale->id)->exists()
                    || $this->store->db->table('payment_core_operations')->where('tenant_id', $scope['tenant_id'])->where('request_id', $sale->payment_request_id)->exists()) {
                    throw new PosException('cash_sale_changed', 409);
                }
                $settings = $this->store->decrypt($register->configuration);
                if (($settings['catalog_reviewed'] ?? false) !== true || ($settings['tax_reviewed'] ?? false) !== true) {
                    throw new PosException('cash_checkout_unavailable', 503);
                }
                if (strtotime($settings['tax_valid_until'] ?? '') <= time()) {
                    throw new PosException('tax_review_expired', 503);
                }
                if (! hash_equals(Contract::hash($snapshot['binding']), Contract::hash($this->access->binding($scope)->snapshot()))) {
                    throw new PosException('merchant_binding_changed', 409);
                }
                if (isset($snapshot['customer']['id'])) {
                    app(PosCustomerDirectory::class)->freeze($scope, (int) $snapshot['customer']['id']);
                }
                if (($snapshot['customer_rewards']['enabled'] ?? false)
                    && ($snapshot['customer_rewards']['version'] ?? null) !== app(PosCustomerRewards::class)->policy($scope['tenant_id'])['version']) {
                    throw new PosException('customer_rewards_policy_changed', 409);
                }
                app(StockPosCommerce::class)->lockCollection($scope, $current, $snapshot);
                app(PosRewardPaymentAdmission::class)->assert($this->store->db, MerchantBinding::restore($snapshot['binding']), $current, $snapshot);
                app(StockLedger::class)->settle($scope['tenant_id'], 'pos:'.$this->store->runtime().':'.$sale->id, true, 'cash:'.$sale->id);
                $this->store->db->table('pos_candle_cash_reservations')->where('runtime_id', $this->store->runtime())
                    ->where('tenant_id', $scope['tenant_id'])->where('sale_id', $sale->id)->where('state', 'held')->update(['state' => 'spent', 'updated_at' => now()]);
                $this->store->db->table('pos_payment_start_claims')->insert(['id' => (string) Str::uuid(), 'runtime_id' => $this->store->runtime(),
                    'tenant_id' => $scope['tenant_id'], 'sale_id' => $sale->id, 'payment_request_id' => $sale->payment_request_id,
                    'state' => 'cash_completed', 'created_at' => now(), 'updated_at' => now()]);
                $this->store->event($scope['tenant_id'], $sale->id, 'cash_sale_completed', ['snapshot_hash' => $sale->snapshot_hash,
                    'received_minor' => $received, 'change_minor' => $received - (int) $sale->amount_minor, 'actor_id' => $scope['actor_id']]);
                $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['state' => 'succeeded', 'stock_state' => 'committed']);
                $service->enqueue($scope['tenant_id'], $sale->id, 'customer_purchase', ['operation_id' => 'cash:'.$sale->id]);
            });
            app(StockPosCommerce::class)->queuePublications($scope['tenant_id']);
            app(PosOutbox::class)->drainSale($scope, $sale->id);

            return $service->inspect($register, $id);
        });
    }

    public function evidence(object $sale, array $snapshot): ?array
    {
        if (($snapshot['tender'] ?? 'card') !== 'cash' || $sale->state !== 'succeeded' || $sale->attempt_id || $sale->descriptor_issued_at
            || ! hash_equals($sale->snapshot_hash, Contract::hash($snapshot))) {
            return null;
        }
        $event = $this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('code', 'cash_sale_completed')->first();
        $details = $event ? $this->store->decrypt($event->details) : [];
        if (($details['snapshot_hash'] ?? null) !== $sale->snapshot_hash || ! is_int($details['received_minor'] ?? null)
            || $details['received_minor'] < (int) $sale->amount_minor || ($details['change_minor'] ?? null) !== $details['received_minor'] - (int) $sale->amount_minor) {
            return null;
        }

        return ['id' => 'cash:'.$sale->id, 'tenant_id' => (int) $sale->tenant_id, 'kind' => 'payment', 'state' => 'succeeded',
            'source' => 'everbranch_cash', 'sale_id' => $sale->id, 'request_id' => $sale->payment_request_id,
            'amount_minor' => (int) $sale->amount_minor, 'currency' => strtolower($sale->currency), 'binding' => $snapshot['binding'],
            'provider_id' => 'EB-CASH-'.strtoupper(substr($sale->id, 0, 12)),
            'received_minor' => $details['received_minor'], 'change_minor' => $details['change_minor']];
    }

    /** Full cash returns require explicit manager recording; the product is not automatically restocked. */
    public function refund(string $register, string $id, array $input): array
    {
        $scope = $this->access->register($register, 'refund');
        $service = app(PosService::class);
        $sale = $service->sale($scope, $id);
        $snapshot = $service->snapshot($sale);
        if (! $this->evidence($sale, $snapshot) || $input['amount_minor'] !== (int) $sale->amount_minor) {
            throw new PosException('cash_full_refund_required', 422);
        }
        app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $sale, $snapshot, $input, $service): void {
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            $previous = $this->store->rows('events', $scope['tenant_id'])->where('sale_id', $sale->id)->where('code', 'cash_refund_completed')->first();
            if ($previous) {
                $details = $this->store->decrypt($previous->details);
                if ($details['request_id'] !== $input['request_id'] || $details['amount_minor'] !== $input['amount_minor']) {
                    throw new PosException('cash_already_refunded', 409);
                }

                return;
            }
            $this->store->event($scope['tenant_id'], $sale->id, 'cash_refund_completed', ['snapshot_hash' => $sale->snapshot_hash,
                'amount_minor' => $input['amount_minor'], 'request_id' => $input['request_id'], 'actor_id' => $scope['actor_id']]);
            if (isset($snapshot['terms']['pos_candle_cash'])) {
                app(PosCandleCashLifecycle::class)->restoreFullCashReturn($scope['tenant_id'], $sale->id);
            }
            $service->enqueue($scope['tenant_id'], $sale->id, 'customer_refund_cash', ['operation_id' => 'cash:'.$sale->id]);
        });
        app(PosOutbox::class)->drainSale($scope, $sale->id);

        return ['refund_id' => 'cash-refund:'.$sale->id, 'state' => 'succeeded', 'sale' => $service->inspect($register, $sale->id)];
    }

    public function refunded(object $sale): int
    {
        $event = $this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('code', 'cash_refund_completed')->first();
        $details = $event ? $this->store->decrypt($event->details) : [];

        return ($details['snapshot_hash'] ?? null) === $sale->snapshot_hash && ($details['amount_minor'] ?? null) === (int) $sale->amount_minor
            ? $details['amount_minor'] : 0;
    }
}
