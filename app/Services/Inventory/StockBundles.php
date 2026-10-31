<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Services\PaymentsCore\Contract;

/** Reviewed mix-and-match composition. Prices remain the parent catalog variant's prices. */
final class StockBundles
{
    public function __construct(private StockLedger $stock) {}

    public function review(int $tenant, string $request, int $actor, string $parentId, int $required, array $eligible, string $revision): array
    {
        if ($required < 1 || $required > 100 || ! $eligible || count($eligible) > 500 || count(array_unique($eligible)) !== count($eligible)) {
            throw new StockException('stock_bundle_invalid', 422);
        }
        sort($eligible);

        return $this->stock->operation($tenant, $request, 'bundle_review', $actor, [$parentId, $required, $eligible, $revision], function () use ($tenant, $actor, $parentId, $required, $eligible, $revision): array {
            $parent = $this->stock->item($tenant, $parentId);
            if ($parent->revision !== $revision) {
                throw new StockException('stock_bundle_price_changed');
            }
            if ($this->stock->rows('balances', $tenant)->where('item_id', $parentId)->where(fn ($q) => $q->where('on_hand', '>', 0)->orWhere('reserved', '>', 0))->exists()) {
                throw new StockException('stock_bundle_parent_count_review_required');
            }
            foreach ($eligible as $id) {
                $item = $this->stock->item($tenant, $id);
                if ($id === $parentId || ! $item->tracked || (json_decode($item->metadata ?? '{}', true)['requires_components'] ?? false)) {
                    throw new StockException('stock_bundle_components_invalid');
                }
            }
            $definition = Contract::hash([$parentId, $required, $eligible]);
            $old = $this->stock->rows('bundles', $tenant)->where('item_id', $parentId)->first();
            $this->stock->rows('bundles', $tenant)->updateOrInsert(['tenant_id' => $tenant, 'item_id' => $parentId],
                ['required_quantity' => $required, 'eligible_items' => json_encode($eligible, JSON_THROW_ON_ERROR), 'revision' => $definition,
                    'active' => true, 'reviewed_by' => $actor, 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);
            $metadata = json_decode($parent->metadata ?? '{}', true);
            $metadata['requires_components'] = true;
            $this->stock->rows('items', $tenant)->where('id', $parentId)->update(['tracked' => false, 'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR)]);

            return ['item_id' => $parentId, 'required_quantity' => $required, 'revision' => $definition];
        });
    }

    public function decorate(int $tenant, array $catalog): array
    {
        $byId = array_column($catalog, null, 'item_id');
        $definitions = $this->stock->rows('bundles', $tenant)->where('active', true)->get()->keyBy('item_id');
        foreach ($catalog as &$item) {
            $definition = $definitions[$item['item_id']] ?? null;
            if (! $definition) {
                continue;
            }
            $eligible = json_decode($definition->eligible_items, true, 64, JSON_THROW_ON_ERROR);
            $components = [];
            $available = 0;
            $valid = true;
            foreach ($eligible as $id) {
                if (! isset($byId[$id]) || $byId[$id]['kind'] !== 'merchandise' || $byId[$id]['available_quantity'] === null) {
                    $valid = false;
                    break;
                }
                $components[] = $byId[$id];
                $available += $byId[$id]['available_quantity'];
            }
            $item['kind'] = 'bundle';
            $item['revision'] = Contract::hash([$item['revision'], $definition->revision]);
            $item['bundle_quantity'] = (int) $definition->required_quantity;
            $item['bundle_items'] = $components;
            $item['available_quantity'] = $valid ? intdiv($available, (int) $definition->required_quantity) : 0;
            $item['availability'] = ! $valid ? 'review_required' : ($item['available_quantity'] > 0 ? 'available' : 'components_count_required');
        }

        return $catalog;
    }

    public function expand(array $parent, array $selection): array
    {
        $required = $parent['bundle_quantity'] ?? null;
        $eligible = array_column($parent['bundle_items'] ?? [], null, 'item_id');
        if (! is_int($required) || empty($selection['components'])) {
            throw new StockException('stock_bundle_choices_required');
        }
        $stock = [];
        $display = [];
        foreach ($selection['components'] as $part) {
            $item = $eligible[$part['item_id']] ?? null;
            if (! $item || isset($stock[$part['item_id']]) || $item['revision'] !== $part['revision'] || $part['quantity'] < 1
                || $item['available_quantity'] < $part['quantity']) {
                throw new StockException('stock_bundle_choices_changed');
            }
            $stock[$part['item_id']] = $part['quantity'];
            $display[] = ['item_id' => $item['item_id'], 'title' => trim($item['title'].' · '.$item['variant_label']), 'quantity' => $part['quantity']];
        }
        if (array_sum($stock) !== $required * $selection['quantity']) {
            throw new StockException('stock_bundle_quantity_required');
        }

        return ['stock' => $stock, 'display' => $display];
    }
}
