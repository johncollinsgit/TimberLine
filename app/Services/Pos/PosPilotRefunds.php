<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\WebsiteProductVariant;
use App\Services\PaymentsCore\Contract;

final class PosPilotRefunds
{
    public function __construct(private PosStore $store) {}

    public function eligible(array $snapshot): array
    {
        $terms = $snapshot['terms'];
        if ($terms['source'] === 'website_invoice') {
            return array_values(array_map(fn ($l) => ['item_id' => 'website:'.$l['variant_id'], 'quantity' => (int) $l['quantity']], array_filter($terms['invoice_terms']['lines'], fn ($l) => $l['variant_id'] && ($l['snapshot']['track_inventory'] ?? false))));
        }

        return array_map(fn ($l) => ['item_id' => $l['item_id'], 'quantity' => (int) $l['quantity']], $terms['source'] === 'shopify' ? $terms['selections'] : $terms['stock']);
    }

    public function reserve(array $scope, object $sale, array $input): void
    {
        if (! is_bool($input['restock'] ?? null) || ! is_array($input['restock_lines'] ?? null)
            || ($input['restock'] && ! $input['restock_lines']) || (! $input['restock'] && $input['restock_lines'])) {
            throw new PosException('explicit_restock_decision_required', 422);
        }
        $decision = ['reason' => $input['reason'], 'restock' => $input['restock'], 'lines' => $input['restock_lines']];
        $this->store->db->transaction(function () use ($scope, $sale, $input, $decision): void {
            $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            $prior = $this->store->rows('refund_details', $scope['tenant_id'])->where('request_id', $input['request_id'])->first();
            if ($prior) {
                if ($prior->state === 'not_dispatched') {
                    throw new PosException('refund_request_retired');
                }
                if ($prior->sale_id !== $sale->id || (int) $prior->amount_minor !== $input['amount_minor'] || ! hash_equals(Contract::hash($decision), Contract::hash($this->store->decrypt($prior->decision)))) {
                    throw new PosException('refund_request_conflict');
                }

                return;
            }
            if ($this->store->rows('refund_details', $scope['tenant_id'])->where('sale_id', $sale->id)->whereNull('confirmed_at')->whereNotIn('state', ['failed', 'cancelled', 'not_dispatched'])->exists()) {
                throw new PosException('original_refund_recovery_required');
            }
            $spent = (int) $this->store->db->table('payment_core_operations')->where('tenant_id', $scope['tenant_id'])->where('original_id', $sale->attempt_id)->where('kind', 'refund')->whereNotIn('state', ['failed', 'cancelled'])->sum('amount_minor');
            if ($input['amount_minor'] > (int) $sale->amount_minor - $spent) {
                throw new PosException('refund_budget_exceeded');
            }
            $available = array_column($this->eligible(app(PosService::class)->snapshot($sale)), 'quantity', 'item_id');
            foreach ($this->store->rows('refund_details', $scope['tenant_id'])->where('sale_id', $sale->id)->whereNotIn('state', ['failed', 'cancelled', 'not_dispatched'])->get() as $old) {
                foreach ($this->store->decrypt($old->decision)['lines'] as $line) {
                    $available[$line['item_id']] = ($available[$line['item_id']] ?? 0) - $line['quantity'];
                }
            }
            foreach ($decision['lines'] as $line) {
                if (! is_int($line['quantity']) || $line['quantity'] < 1 || ($available[$line['item_id']] ?? 0) < $line['quantity']) {
                    throw new PosException('restock_quantity_exceeded');
                }
                $available[$line['item_id']] -= $line['quantity'];
            }
            $this->store->rows('refund_details', $scope['tenant_id'])->insert($this->store->values($scope['tenant_id'], ['id' => bin2hex(random_bytes(16)), 'sale_id' => $sale->id,
                'request_id' => $input['request_id'], 'operator_id' => $scope['operator_id'], 'amount_minor' => $input['amount_minor'], 'decision' => $this->store->encrypt($decision), 'created_at' => now()]));
        });
    }

    public function record(object $sale, array $operation): void
    {
        if ($operation['kind'] !== 'refund' || $operation['sale_id'] !== $sale->id) {
            throw new PosException('refund_sale_mismatch');
        }
        $q = $this->store->rows('refund_details', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('request_id', $operation['request_id']);
        $q->update(['operation_id' => $operation['id'], 'state' => $operation['state']]);
        if ($operation['state'] === 'succeeded') {
            $q->whereNull('confirmed_at')->update(['confirmed_at' => now()]);
        }
    }

    public function decision(object $sale, array $operation): array
    {
        $row = $this->store->rows('refund_details', (int) $sale->tenant_id)->where('sale_id', $sale->id)->where('request_id', $operation['request_id'])->first();

        return $row ? $this->store->decrypt($row->decision) : ['restock' => false, 'lines' => []];
    }

    public function completeStock(object $sale, array $snapshot, array $operation): void
    {
        $this->record($sale, $operation);
        $this->store->db->transaction(function () use ($sale, $snapshot, $operation): void {
            $row = $this->store->rows('refund_details', (int) $sale->tenant_id)->where('request_id', $operation['request_id'])->lockForUpdate()->first();
            if (! $row || $row->restocked_at || ! $row->confirmed_at || $operation['state'] !== 'succeeded') {
                return;
            }
            $decision = $this->store->decrypt($row->decision);
            if ($snapshot['terms']['source'] !== 'shopify') {
                foreach ($decision['lines'] as $line) {
                    if ($snapshot['terms']['source'] === 'website_invoice') {
                        $variant = WebsiteProductVariant::forTenantId($sale->tenant_id)->with('product')->whereKey(substr($line['item_id'], 8))->lockForUpdate()->firstOrFail();
                        if ($variant->product?->tenant_site_id !== $snapshot['terms']['site_id'] || $variant->inventory_quantity === null) {
                            throw new PosException('restock_item_mismatch');
                        }
                        $variant->increment('inventory_quantity', $line['quantity']);
                    } else {
                        $item = $this->store->rows('items', (int) $sale->tenant_id)->where('id', $line['item_id'])->where('location_id', $sale->location_id)->where('tracked', true)->lockForUpdate()->firstOrFail();
                        $this->store->rows('items', (int) $sale->tenant_id)->where('id', $item->id)->increment('quantity', $line['quantity']);
                    }
                }
            }
            $this->store->rows('refund_details', (int) $sale->tenant_id)->where('id', $row->id)->update(['restocked_at' => now()]);
        });
    }
}
