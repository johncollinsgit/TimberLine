<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\StockLedger;
use App\Services\PaymentsCore\Contract;

/** A physical count belongs to the reviewed pool/catalog the operator actually saw. */
final class PosInventoryCounts
{
    public function __construct(private PosStore $store, private PosAccess $access, private StockLedger $ledger, private StockPosCommerce $stock) {}

    public function context(array $scope, ?array $catalog = null): array
    {
        $settings = $this->stock->settings($scope);
        $catalog ??= $this->stock->catalog($scope);
        $binding = $this->ledger->rows('bindings', $scope['tenant_id'])->where('channel', 'pos')
            ->where('channel_key', $this->store->runtime().':'.$scope['register']->id)->where('active', true)->firstOrFail();
        $identities = collect($catalog)->map(fn ($row) => array_intersect_key($row, array_flip(['item_id', 'revision', 'kind', 'title', 'variant_label', 'sku'])))->sortBy('item_id')->values()->all();

        return ['inventory_pool_id' => $settings['inventory_pool_id'],
            'inventory_context_version' => Contract::hash([$scope['tenant_id'], $this->store->runtime(), $scope['register']->id,
                $settings['inventory_pool_id'], $scope['register']->catalog_source, json_decode($binding->settings, true, 64, JSON_THROW_ON_ERROR), $identities])];
    }

    public function save(string $registerId, array $input, bool $single = false): void
    {
        if (array_key_exists('expected_inventory_pool_id', $input) !== array_key_exists('expected_inventory_context_version', $input)) {
            throw new PosException('count_context_required', 422);
        }
        $scope = $this->access->register($registerId, 'quote');
        if (! in_array($scope['role'], ['owner', 'tenant_owner', 'admin'], true) || $input['physically_confirmed'] !== true) {
            throw new PosException('physical_count_confirmation_required', 403);
        }
        $this->store->db->transaction(function () use ($registerId, $input, $scope, $single): void {
            // Match collection's register→stock lock order. Binding writers share the stock mutex.
            $register = $this->store->rows('registers', $scope['tenant_id'])->where('id', $registerId)->lockForUpdate()->firstOrFail();
            $current = $this->access->register($registerId, 'quote');
            if (! $register->active || ! in_array($current['role'], ['owner', 'tenant_owner', 'admin'], true)) {
                throw new PosException('inventory_owner_required', 403);
            }
            $current['register'] = $register;
            $this->ledger->operation($scope['tenant_id'], 'mobile-count-context:'.$input['request_id'], 'reviewed_count_batch', $scope['actor_id'], [$this->store->runtime(), $registerId, $input, $single],
                function () use ($current, $input, $single): array {
                    $catalog = $this->stock->catalog($current);
                    $context = $this->context($current, $catalog);
                    if (isset($input['expected_inventory_pool_id']) && ($input['expected_inventory_pool_id'] !== $context['inventory_pool_id']
                        || ! hash_equals($context['inventory_context_version'], $input['expected_inventory_context_version'] ?? ''))) {
                        throw new PosException('count_context_changed', 409);
                    }
                    $catalog = collect($catalog)->keyBy('item_id');
                    $quantities = [];
                    $versions = [];
                    foreach ($input['items'] as $item) {
                        if (! $catalog->has($item['item_id']) || $catalog[$item['item_id']]['kind'] === 'bundle') {
                            throw new PosException('count_product_unavailable', 409);
                        }
                        $quantities[$item['item_id']] = $item['quantity'];
                        $versions[$item['item_id']] = $item['expected_version'];
                    }

                    return $this->ledger->count($current['tenant_id'], ($single ? 'mobile:' : 'mobile-batch:').$input['request_id'], $current['actor_id'],
                        $context['inventory_pool_id'], $quantities, 'physical_count', $versions);
                });
        }, 3);
        $this->stock->queuePublications($scope['tenant_id']);
    }
}
