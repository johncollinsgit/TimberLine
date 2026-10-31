<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Services\PaymentsCore\Contract;

/** Event plans express demand. Only an explicit physical confirmation adds sellable units. */
final class StockEventProduction
{
    public function __construct(private StockLedger $stock) {}

    /** Existing global plans need an explicit ownership review, never assignment by event name. */
    public function adopt(int $tenant, string $request, int $actor, int $orderId): array
    {
        return $this->stock->operation($tenant, $request, 'production_source', $actor, [$orderId], function () use ($tenant, $actor, $orderId): array {
            $order = $this->stock->db->table('orders')->where('id', $orderId)->where('source', 'internal')
                ->where('order_type', 'event')->whereNotNull('event_id')->lockForUpdate()->first();
            if (! $order || ($order->tenant_id !== null && (int) $order->tenant_id !== $tenant)) {
                throw new StockException('production_source_unavailable', 404);
            }
            $old = $this->stock->db->table('commerce_stock_production_sources')->where('order_id', $orderId)->first();
            if ($old && (int) $old->tenant_id !== $tenant) {
                throw new StockException('production_source_unavailable', 404);
            }
            if ($order->tenant_id === null && ! $this->stock->db->table('retail_plan_items as i')->join('retail_plans as p', 'p.id', '=', 'i.retail_plan_id')
                ->join('tenant_user as m', 'm.user_id', '=', 'p.created_by')->where('m.tenant_id', $tenant)->where('m.membership_active', true)
                ->where('i.order_id', $orderId)->exists()) {
                throw new StockException('production_source_ownership_review_required', 403);
            }
            if (! $old) {
                $this->stock->rows('production_sources', $tenant)->insert(['tenant_id' => $tenant, 'order_id' => $orderId,
                    'reviewed_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            }

            return ['order_id' => $orderId, 'event_id' => (int) $order->event_id];
        });
    }

    private function owned(\Illuminate\Database\Query\Builder $query, int $tenant): \Illuminate\Database\Query\Builder
    {
        return $query->where(function ($q) use ($tenant): void {
            $q->where('o.tenant_id', $tenant)->orWhere(function ($q) use ($tenant): void {
                $q->whereNull('o.tenant_id')->whereExists(function ($q) use ($tenant): void {
                    $q->selectRaw('1')->from('commerce_stock_production_sources as cs')->whereColumn('cs.order_id', 'o.id')->where('cs.tenant_id', $tenant);
                });
            });
        });
    }

    public function demand(int $tenant, ?int $event = null): array
    {
        $query = $this->stock->db->table('order_lines as l')->join('orders as o', 'o.id', '=', 'l.order_id')
            ->leftJoin('events as e', 'e.id', '=', 'o.event_id')
            ->where('o.source', 'internal')->where('o.order_type', 'event')->whereNotNull('o.event_id')
            ->whereNotIn('o.status', ['cancelled', 'canceled', 'deleted']);
        $this->owned($query, $tenant);
        if ($event !== null) {
            $query->where('o.event_id', $event);
        }

        return $query->orderByDesc('o.id')->orderBy('l.id')->limit(500)
            ->get(['l.id', 'l.sku', 'l.scent_name', 'l.size_code', 'o.order_number', 'e.name as event_name'])
            ->map(function ($line) use ($tenant): array {
                $source = $this->source($tenant, (int) $line->id);
                $mapping = $this->stock->rows('production_links', $tenant)->where('order_line_id', $line->id)->first();

                return [...$source, 'sku' => $line->sku, 'title' => trim($line->scent_name.' '.$line->size_code),
                    'order_number' => $line->order_number, 'event_name' => $line->event_name,
                    'item_id' => $mapping?->item_id, 'received_quantity' => (int) ($mapping?->received_quantity ?? 0),
                    'mapping_current' => $mapping && hash_equals($mapping->source_signature, $source['signature'])];
            })->all();
    }

    public function source(int $tenant, int $lineId): array
    {
        $query = $this->stock->db->table('order_lines as l')->join('orders as o', 'o.id', '=', 'l.order_id')
            ->where('o.source', 'internal')->where('o.order_type', 'event')
            ->whereNotNull('o.event_id')->where('l.id', $lineId)
            ->select(['l.id', 'l.order_id', 'l.scent_id', 'l.size_id', 'l.wick_type', 'l.ordered_qty', 'l.quantity', 'l.extra_qty', 'o.event_id', 'o.status']);
        $this->owned($query, $tenant);
        if ($this->stock->db->transactionLevel() > 0) {
            $query->lockForUpdate();
        }
        $line = $query->first();
        if (! $line || (int) $line->event_id < 1 || in_array($line->status, ['cancelled', 'canceled', 'deleted'], true)) {
            throw new StockException('production_source_unavailable', 404);
        }
        $quantity = (int) ($line->ordered_qty ?? $line->quantity ?? 0) + (int) ($line->extra_qty ?? 0);
        if ($quantity < 1 || $quantity > 10000000) {
            throw new StockException('production_quantity_invalid');
        }

        return ['line_id' => (int) $line->id, 'order_id' => (int) $line->order_id, 'event_id' => (int) $line->event_id,
            'planned_quantity' => $quantity, 'signature' => Contract::hash([$tenant, (int) $line->id, (int) $line->event_id,
                $line->scent_id, $line->size_id, $line->wick_type]),
            'revision' => Contract::hash([(array) $line, $quantity])];
    }

    /** The owner chooses an exact canonical variant; names/SKUs are never identity proof. */
    public function map(int $tenant, string $request, int $actor, int $lineId, string $itemId, string $revision): array
    {
        return $this->stock->operation($tenant, $request, 'production_mapping', $actor, [$lineId, $itemId, $revision], function () use ($tenant, $actor, $lineId, $itemId, $revision): array {
            $source = $this->source($tenant, $lineId);
            if (! hash_equals($source['revision'], $revision)) {
                throw new StockException('production_plan_changed');
            }
            if (! $this->stock->item($tenant, $itemId)->tracked) {
                throw new StockException('production_tracked_item_required');
            }
            $old = $this->stock->rows('production_links', $tenant)->where('order_line_id', $lineId)->first();
            if ($old && (int) $old->received_quantity > 0 && ($old->item_id !== $itemId || $old->source_signature !== $source['signature'])) {
                throw new StockException('production_mapping_has_receipts');
            }
            $this->stock->rows('production_links', $tenant)->updateOrInsert(['tenant_id' => $tenant, 'order_line_id' => $lineId],
                ['item_id' => $itemId, 'source_signature' => $source['signature'], 'reviewed_by' => $actor,
                    'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);

            return ['order_line_id' => $lineId, 'item_id' => $itemId];
        });
    }

    /** Actual sellable units confirmed now, excluding goods included in an opening count/receipt. */
    public function confirm(int $tenant, string $request, int $actor, int $lineId, string $pool, int $quantity, string $revision, bool $notPreviouslyCounted): array
    {
        if (! $notPreviouslyCounted || $quantity < 1 || $quantity > 10000000) {
            throw new StockException('production_physical_confirmation_required', 422);
        }

        return $this->stock->operation($tenant, $request, 'production_ready', $actor, [$lineId, $pool, $quantity, $revision, true], function ($key) use ($tenant, $actor, $lineId, $pool, $quantity, $revision): array {
            $source = $this->source($tenant, $lineId);
            if (! hash_equals($source['revision'], $revision)) {
                throw new StockException('production_plan_changed');
            }
            $target = $this->stock->pool($tenant, $pool);
            if ($target->kind !== 'show' || $target->event_key !== 'event:'.$source['event_id']) {
                throw new StockException('production_event_pool_mismatch');
            }
            $mapping = $this->stock->rows('production_links', $tenant)->where('order_line_id', $lineId)->lockForUpdate()->first();
            if (! $mapping || $mapping->source_signature !== $source['signature']) {
                throw new StockException('production_mapping_required');
            }
            if ($mapping->received_quantity + $quantity > $source['planned_quantity']) {
                throw new StockException('production_exceeds_plan_review_required');
            }
            $this->stock->receiveProduction($tenant, $pool, $mapping->item_id, $quantity, $key, $actor, 'order-line:'.$lineId);
            $this->stock->rows('production_links', $tenant)->where('id', $mapping->id)->update(['received_quantity' => $mapping->received_quantity + $quantity, 'updated_at' => now()]);

            return ['order_line_id' => $lineId, 'pool_id' => $pool, 'received_now' => $quantity,
                'received_total' => $mapping->received_quantity + $quantity, 'planned_quantity' => $source['planned_quantity']];
        });
    }
}
