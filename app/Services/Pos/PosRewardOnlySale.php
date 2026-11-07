<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CustomerAccountFence;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use Illuminate\Support\Str;

/** A fully discounted purchase has no processor charge. Its authority is the reserved wallet and reviewed original sale. */
final class PosRewardOnlySale
{
    public function __construct(private PosStore $store, private StockLedger $stock) {}

    public function complete(array $scope, object $sale, array $snapshot, MerchantBinding $binding): object
    {
        if (($snapshot['reward_only'] ?? false) !== true || (int) $sale->amount_minor !== 0 || ($snapshot['terms']['total_minor'] ?? null) !== 0
            || $sale->commerce_source !== StockPosCommerce::VERSION || ($snapshot['terms']['pos_candle_cash']['amount_minor'] ?? 0) < 1) {
            throw new PosException('reward_only_sale_invalid', 409);
        }
        app(StockPosCommerce::class)->prepare($scope, $sale, $snapshot);
        app(StockPosCommerce::class)->lockCollection($scope, $sale, $snapshot);

        return app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $sale, $snapshot, $binding) {
            $current = $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if ($this->evidence($current, $snapshot)) {
                return $current;
            }
            if ($current->state !== 'quoted' || $current->attempt_id || $current->descriptor_issued_at || $current->source_reference
                || strtotime($current->expires_at) <= time() || ! hash_equals($current->snapshot_hash, Contract::hash($snapshot))
                || $this->store->db->table('pos_payment_start_claims')->where('runtime_id', $this->store->runtime())->where('tenant_id', $scope['tenant_id'])->where('sale_id', $current->id)->exists()
                || $this->store->db->table('payment_core_operations')->where('tenant_id', $scope['tenant_id'])->where('request_id', $current->payment_request_id)->exists()) {
                throw new PosException('reward_only_sale_unavailable', 409);
            }
            app(PosRewardPaymentAdmission::class)->assert($this->store->db, $binding, $current, $snapshot);
            $hold = $this->hold($current);
            if (! $hold || $hold->state !== 'held') {
                throw new PosException('reward_reservation_mismatch', 409);
            }
            $this->stock->settle($scope['tenant_id'], 'pos:'.$this->store->runtime().':'.$current->id, true, 'rewards:'.$current->id);
            $this->store->db->table('pos_candle_cash_reservations')->where('id', $hold->id)->update(['state' => 'spent', 'updated_at' => now()]);
            $this->store->db->table('pos_payment_start_claims')->insert(['id' => (string) Str::uuid(), 'runtime_id' => $this->store->runtime(),
                'tenant_id' => $scope['tenant_id'], 'sale_id' => $current->id, 'payment_request_id' => $current->payment_request_id,
                'state' => 'rewards_completed', 'created_at' => now(), 'updated_at' => now()]);
            $this->store->event($scope['tenant_id'], $current->id, 'reward_only_sale_completed', ['snapshot_hash' => $current->snapshot_hash,
                'reservation_id' => $hold->id, 'actor_id' => $scope['actor_id'], 'external_payment_minor' => 0]);
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $current->id)->update(['state' => 'succeeded', 'stock_state' => 'committed']);

            return $this->store->rows('sales', $scope['tenant_id'])->where('id', $current->id)->firstOrFail();
        });
    }

    /** Independent internal evidence. Never represent a zero-tender sale as a Square payment. */
    public function evidence(object $sale, array $snapshot): ?array
    {
        if (($snapshot['reward_only'] ?? false) !== true || $sale->state !== 'succeeded' || (int) $sale->amount_minor !== 0
            || $sale->attempt_id || $sale->descriptor_issued_at || ! hash_equals($sale->snapshot_hash, Contract::hash($snapshot))) {
            return null;
        }
        $hold = $this->hold($sale);
        $event = $this->store->rows('events', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('code', 'reward_only_sale_completed')->first();
        $details = $event ? $this->store->decrypt($event->details) : [];
        if (! $hold || ! in_array($hold->state, ['spent', 'refunded'], true) || ($details['snapshot_hash'] ?? null) !== $sale->snapshot_hash
            || ($details['reservation_id'] ?? null) !== $hold->id || (int) $hold->amount_minor !== ($snapshot['terms']['pos_candle_cash']['amount_minor'] ?? null)
            || (int) $hold->marketing_profile_id !== ($snapshot['customer']['id'] ?? null)) {
            return null;
        }

        return ['id' => 'rewards:'.$sale->id, 'tenant_id' => (int) $sale->tenant_id, 'kind' => 'payment', 'state' => 'succeeded',
            'source' => 'everbranch_rewards_only', 'sale_id' => $sale->id, 'request_id' => $sale->payment_request_id, 'amount_minor' => 0,
            'currency' => strtolower($sale->currency), 'binding' => $snapshot['binding'], 'provider_id' => 'EB-REWARDS-'.strtoupper(substr($sale->id, 0, 12)),
            'rewards_returned_minor' => $hold->state === 'refunded' ? (int) $hold->amount_minor : 0];
    }

    public function restore(array $scope, object $sale, array $snapshot): void
    {
        app(CustomerAccountFence::class)->forTenant($scope['tenant_id'], function () use ($scope, $sale, $snapshot) {
            $current = $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if (! $this->evidence($current, $snapshot)) {
                throw new PosException('reward_only_sale_unavailable', 409);
            }
            $hold = $this->hold($current);
            if ($hold->state === 'refunded') {
                return;
            }
            $balance = CandleCashBalance::whereKey($hold->marketing_profile_id)->lockForUpdate()->firstOrFail();
            $balance->balance += $hold->amount_minor / 100;
            $balance->save();
            CandleCashTransaction::create(['marketing_profile_id' => $hold->marketing_profile_id, 'type' => 'adjustment',
                'points' => (int) round($hold->amount_minor / 100), 'candle_cash_delta' => $hold->amount_minor / 100,
                'source' => 'pos_reservation_refund', 'source_id' => $hold->id, 'description' => 'Returned rewards-only purchase']);
            $this->store->db->table('pos_candle_cash_reservations')->where('id', $hold->id)->update(['state' => 'refunded', 'updated_at' => now()]);
            $this->store->event($scope['tenant_id'], $current->id, 'reward_only_sale_returned', ['reservation_id' => $hold->id, 'actor_id' => $scope['actor_id']]);
        });
    }

    private function hold(object $sale): ?object
    {
        return $this->store->db->table('pos_candle_cash_reservations')->where('runtime_id', $this->store->runtime())->where('tenant_id', $sale->tenant_id)
            ->where('sale_id', $sale->id)->lockForUpdate()->first();
    }
}
