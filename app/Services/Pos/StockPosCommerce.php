<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\Inventory\StockException;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockShopifyApi;

/** New in-person lane. Only calculation touches Shopify; orders never do. */
final class StockPosCommerce
{
    public const VERSION = 'everbranch_inventory_v1';

    public function __construct(private PosStore $store, private StockLedger $stock, private StockShopifyApi $shopify) {}

    public function enabled(object $register): bool
    {
        return ($this->store->decrypt($register->configuration)['commerce_adapter'] ?? null) === self::VERSION;
    }

    public function settings(array $scope): array
    {
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (($settings['commerce_adapter'] ?? null) !== self::VERSION || ! is_string($settings['inventory_pool_id'] ?? null)) {
            throw new PosException('inventory_setup_required', 503);
        }
        $key = $this->store->runtime().':'.$scope['register']->id;
        $binding = $this->stock->rows('bindings', $scope['tenant_id'])->where('channel', 'pos')->where('channel_key', $key)->where('active', true)->first();
        if (! $binding || $binding->pool_id !== $settings['inventory_pool_id']) {
            throw new PosException('inventory_binding_unreviewed', 503);
        }
        $this->stock->pool($scope['tenant_id'], $binding->pool_id);

        return $settings;
    }

    public function catalog(array $scope): array
    {
        $settings = $this->settings($scope);
        $items = $this->stock->listing($scope['tenant_id'], $settings['inventory_pool_id']);
        if (isset($settings['catalog_item_ids'])) {
            if (! is_array($settings['catalog_item_ids']) || count($settings['catalog_item_ids']) > 1000
                || count(array_filter($settings['catalog_item_ids'], fn ($id) => is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id))) !== count($settings['catalog_item_ids'])) {
                throw new PosException('register_catalog_selection_invalid', 503);
            }
            $items = array_values(array_filter($items, fn ($item) => in_array($item['item_id'], $settings['catalog_item_ids'], true)));
        }
        if ($scope['register']->catalog_source === 'shopify') {
            $mapping = $this->stock->rows('provider_items', $scope['tenant_id'])->where('store_id', $settings['shopify']['store_id'])
                ->where('active', true)->get()->keyBy('item_id');
            $items = array_values(array_filter($items, fn ($i) => isset($mapping[$i['item_id']])));
            foreach ($items as &$item) {
                $item['unit_amount_minor'] = (int) $mapping[$item['item_id']]->unit_amount_minor;
                $item['revision'] = $mapping[$item['item_id']]->revision;
            }
        }

        return app(\App\Services\Inventory\StockBundles::class)->decorate($scope['tenant_id'], $items);
    }

    public function quote(array $scope, array $input): array
    {
        $settings = $this->settings($scope);
        $catalog = array_column($this->catalog($scope), null, 'item_id');
        $quantities = [];
        $lines = [];
        $display = [];
        $expected = [];
        $taxableSubtotal = 0;
        foreach ($input['items'] ?? [] as $selection) {
            $item = $catalog[$selection['item_id']] ?? null;
            if (! $item || $selection['revision'] !== $item['revision']) {
                throw new PosException('catalog_changed');
            }
            if ($item['availability'] === 'review_required') {
                throw new StockException('stock_bundle_components_review_required');
            }
            if ($item['available_quantity'] !== null && $item['available_quantity'] < $selection['quantity']) {
                throw new StockException('stock_unavailable');
            }
            $components = [];
            if ($item['kind'] === 'bundle') {
                $bundle = app(\App\Services\Inventory\StockBundles::class)->expand($item, $selection);
                foreach ($bundle['stock'] as $id => $quantity) {
                    $quantities[$id] = ($quantities[$id] ?? 0) + $quantity;
                }
                $components = $bundle['display'];
            } else {
                if (! empty($selection['components'])) {
                    throw new StockException('stock_bundle_not_configured');
                }
                $quantities[$item['item_id']] = ($quantities[$item['item_id']] ?? 0) + $selection['quantity'];
            }
            $metadata = json_decode($this->stock->item($scope['tenant_id'], $item['item_id'])->metadata ?? '{}', true);
            if (($metadata['taxable'] ?? true) === true) {
                $taxableSubtotal += $item['unit_amount_minor'] * $selection['quantity'];
            }
            $display[] = ['item_id' => $item['item_id'], 'title' => $item['title'].($item['variant_label'] && $item['variant_label'] !== 'Default Title' ? ' · '.$item['variant_label'] : ''),
                'sku' => $item['sku'], 'quantity' => $selection['quantity'], 'subtotal_minor' => $item['unit_amount_minor'] * $selection['quantity'], 'components' => $components];
            if ($scope['register']->catalog_source === 'shopify') {
                $m = $this->stock->rows('provider_items', $scope['tenant_id'])->where('store_id', $settings['shopify']['store_id'])
                    ->where('item_id', $item['item_id'])->where('active', true)->firstOrFail();
                $lines[] = ['variantId' => $m->variant_id, 'quantity' => $selection['quantity']];
                $expected[$m->variant_id] = ['quantity' => $selection['quantity'], 'price' => $item['unit_amount_minor']];
            }
        }
        if (isset($input['custom_charge'])) {
            $custom = $input['custom_charge'];
            if (($settings['custom_amount_enabled'] ?? false) !== true || ! is_int($custom['amount_minor']) || $custom['amount_minor'] < 1
                || trim($custom['description']) === '' || ($settings['custom_taxable_reviewed'] ?? false) !== true) {
                throw new PosException('custom_charge_not_configured', 422);
            }
            $lines[] = ['title' => $custom['description'], 'quantity' => 1, 'originalUnitPrice' => number_format($custom['amount_minor'] / 100, 2, '.', ''),
                'taxable' => $settings['custom_taxable'] ?? true, 'requiresShipping' => false];
            $display[] = ['title' => $custom['description'], 'quantity' => 1, 'subtotal_minor' => $custom['amount_minor'], 'custom_charge' => true];
            if (($settings['custom_taxable'] ?? true) === true) {
                $taxableSubtotal += $custom['amount_minor'];
            }
        }
        foreach ($quantities as $id => $quantity) {
            if (($catalog[$id]['available_quantity'] ?? PHP_INT_MAX) < $quantity) {
                throw new StockException('stock_unavailable');
            }
        }
        if (! $display) {
            throw new PosException('basket_required', 422);
        }
        $subtotal = array_sum(array_column($display, 'subtotal_minor'));
        $tax = 0;
        $total = $subtotal;
        if ($scope['register']->catalog_source === 'shopify') {
            $draft = ['lineItems' => $lines, 'shippingAddress' => $settings['shopify']['tax_address'], 'acceptAutomaticDiscounts' => false];
            $result = $this->shopify->request($scope['tenant_id'], $settings['shopify']['store_id'], StockShopifyApi::CALCULATE, ['input' => $draft]);
            $payload = $result['draftOrderCalculate'] ?? [];
            if (! empty($payload['userErrors']) || empty($payload['calculatedDraftOrder'])) {
                throw new PosException('total_unavailable', 503);
            }
            $calculated = $payload['calculatedDraftOrder'];
            if (($calculated['taxesIncluded'] ?? true) !== false || ($calculated['totalPriceSet']['shopMoney']['currencyCode'] ?? '') !== 'USD') {
                throw new PosException('tax_unavailable', 503);
            }
            $actual = [];
            foreach ($calculated['lineItems'] as $line) {
                if (isset($line['variant']['id'])) {
                    $actual[$line['variant']['id']] = ['quantity' => $line['quantity'], 'price' => ShopifyPosCommerce::minor($line['originalUnitPriceSet']['shopMoney']['amount'])];
                }
            }
            ksort($expected);
            ksort($actual);
            if ($expected !== $actual) {
                throw new PosException('catalog_changed');
            }
            $total = ShopifyPosCommerce::minor($calculated['totalPriceSet']['shopMoney']['amount']);
            $tax = ShopifyPosCommerce::minor($calculated['totalTaxSet']['shopMoney']['amount']);
            if ($total - $tax !== $subtotal) {
                throw new PosException('total_changed');
            }
        } else {
            if (($settings['native_tax_reviewed'] ?? false) !== true || ! is_int($settings['native_tax_bps'] ?? null)) {
                throw new PosException('tax_unavailable', 503);
            }
            if ($settings['native_tax_bps'] < 0 || $settings['native_tax_bps'] > 10000) {
                throw new PosException('tax_unavailable', 503);
            }
            $tax = intdiv($taxableSubtotal * $settings['native_tax_bps'] + 5000, 10000);
            $total += $tax;
        }

        return ['source' => self::VERSION, 'adapter_version' => self::VERSION, 'stock_pool_id' => $settings['inventory_pool_id'],
            'stock' => $quantities, 'selections' => $input['items'] ?? [], 'total_minor' => $total, 'tax_minor' => $tax,
            'custom_charge' => $input['custom_charge'] ?? null, 'display' => $display,
            'rewardable_subtotal_minor' => $subtotal - ($input['custom_charge']['amount_minor'] ?? 0),
            'lines' => [['line_id' => 'counter_basket', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => $subtotal, 'tax_amount_minor' => $tax]]];
    }

    public function prepare(array $scope, object $sale, array $snapshot): void
    {
        $settings = $this->settings($scope);
        if ($snapshot['terms']['stock_pool_id'] !== $settings['inventory_pool_id']) {
            throw new PosException('inventory_binding_changed');
        }
        foreach (['shopify.store_id', 'shopify.location_id', 'shopify.tax_address', 'native_tax_bps', 'native_tax_reviewed', 'custom_amount_enabled', 'custom_taxable', 'custom_taxable_reviewed'] as $field) {
            if (\App\Services\PaymentsCore\Contract::hash([data_get($settings, $field)]) !== \App\Services\PaymentsCore\Contract::hash([data_get($snapshot['configuration'], $field)])) {
                throw new PosException('checkout_settings_changed');
            }
        }
        $catalog = array_column($this->catalog($scope), null, 'item_id');
        foreach ($snapshot['terms']['selections'] as $selection) {
            if (($catalog[$selection['item_id']]['revision'] ?? null) !== $selection['revision']) {
                throw new PosException('catalog_changed');
            }
            foreach ($selection['components'] ?? [] as $component) {
                if (($catalog[$component['item_id']]['revision'] ?? null) !== $component['revision']) {
                    throw new PosException('catalog_changed');
                }
            }
        }
        $this->stock->operation($scope['tenant_id'], 'prepare:'.$this->hold($sale), 'prepare_quote', (int) $sale->actor_id,
            [$sale->id, $snapshot['terms']['stock_pool_id'], $snapshot['terms']['stock']], function () use ($scope, $sale, $snapshot): array {
                $current = $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
                if ($current->state !== 'quoted' || $current->attempt_id !== null) {
                    throw new PosException('quote_changed');
                }
                if ($snapshot['terms']['stock']) {
                    $this->stock->reserve($scope['tenant_id'], $this->hold($sale), (int) $sale->actor_id,
                        $snapshot['terms']['stock_pool_id'], $snapshot['terms']['stock'], new \DateTimeImmutable($sale->expires_at));
                }
                $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->update(['stock_state' => 'held']);

                return ['held' => true];
            });
    }

    /** Shared-stock projections are confirmed during basket review, never in the Square handoff. */
    public function collectionReady(object $sale, array $snapshot): bool
    {
        if ($sale->state !== 'quoted' || $sale->stock_state !== 'held') {
            return false;
        }
        $tenant = (int) $sale->tenant_id;
        $pool = $snapshot['terms']['stock_pool_id'];
        foreach ($this->stock->rows('bindings', $tenant)->where('pool_id', $pool)->where('active', true)->whereIn('channel', ['shopify', 'website'])->get() as $binding) {
            if ($binding->channel === 'website') {
                return false;
            } // The Website adapter must prove its own reservation contract before activation.
            foreach ($this->stock->rows('reservations', $tenant)->where('hold_key', $this->hold($sale))->get() as $hold) {
                $mapping = $this->stock->rows('provider_items', $tenant)->where('store_id', (int) $binding->channel_key)->where('item_id', $hold->item_id)->where('active', true)->first();
                if (! $mapping) {
                    continue;
                }
                if (! $binding->publish_enabled || $mapping->inventory_policy !== 'DENY' || $hold->state !== 'held'
                    || (int) $mapping->acknowledged_version < (int) $hold->balance_version
                    || $this->stock->rows('online_inbox', $tenant)->where('store_id', (int) $binding->channel_key)->where('state', '!=', 'completed')->exists()) {
                    return false;
                }
            }
        }

        return true;
    }

    public function queuePublications(int $tenant): void
    {
        \App\Jobs\StockSyncTenant::dispatch($tenant)->afterCommit();
    }

    public function lockCollection(array $scope, object $sale, array $snapshot): void
    {
        $this->stock->operation($scope['tenant_id'], 'collect-lock:'.$this->hold($sale), 'collection_lock', (int) $sale->actor_id, [$sale->id], function () use ($scope, $sale, $snapshot): array {
            $current = $this->store->rows('sales', $scope['tenant_id'])->where('id', $sale->id)->lockForUpdate()->firstOrFail();
            if (! $this->collectionReady($current, $snapshot)) {
                throw new PosException('stock_availability_update_pending');
            }
            $this->stock->rows('reservations', $scope['tenant_id'])->where('hold_key', $this->hold($sale))->where('state', 'held')->update(['collection_locked_at' => now()]);

            return ['locked' => true];
        });
    }

    /** An accepted online order may displace a quote, never an issued/locked payment. Caller holds stock mutex. */
    public function preemptUnstarted(int $tenant, string $pool, string $item): void
    {
        if ($this->stock->db->transactionLevel() < 1) {
            throw new StockException('stock_transaction_required');
        }
        $holds = $this->stock->rows('reservations', $tenant)->where('pool_id', $pool)->where('item_id', $item)
            ->where('state', 'held')->whereNull('collection_locked_at')->orderBy('hold_key')->get();
        foreach ($holds as $hold) {
            $prefix = 'pos:'.$this->store->runtime().':';
            if (! str_starts_with($hold->hold_key, $prefix)) {
                continue;
            }
            $id = substr($hold->hold_key, strlen($prefix));
            $sale = $this->store->rows('sales', $tenant)->where('id', $id)->where('state', 'quoted')->whereNull('attempt_id')->whereNull('descriptor_issued_at')->lockForUpdate()->first();
            if (! $sale || $this->stock->rows('reservations', $tenant)->where('hold_key', $hold->hold_key)->whereNotNull('collection_locked_at')->exists()) {
                continue;
            }
            $this->stock->settle($tenant, $hold->hold_key, false);
            $this->store->rows('sales', $tenant)->where('id', $id)->update(['state' => 'cancelled', 'stock_state' => 'released']);
            $this->store->event($tenant, $id, 'quote_stock_changed', ['reason' => 'accepted_online_order']);
        }
    }

    public function finalize(object $sale, array $snapshot, array $operation): void
    {
        if ($operation['state'] !== 'succeeded' || ! $operation['provider_id'] || $operation['sale_id'] !== $sale->id) {
            throw new PosException('paid_evidence_required');
        }
        if ($snapshot['terms']['stock']) {
            $this->stock->settle((int) $sale->tenant_id, $this->hold($sale), true, $operation['provider_id']);
        }
        $this->store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id)->update(['stock_state' => 'committed']);
    }

    public function release(object $sale, array $snapshot): void
    {
        if (! in_array($sale->state, ['failed', 'cancelled'], true)) {
            throw new PosException('unpaid_evidence_required');
        }
        $reserved = $this->stock->rows('operations', (int) $sale->tenant_id)->where('request_hash', hash('sha256', 'reserve:'.$this->hold($sale)))->exists();
        if ($reserved) {
            $this->stock->settle((int) $sale->tenant_id, $this->hold($sale), false);
        }
        $this->store->rows('sales', (int) $sale->tenant_id)->where('id', $sale->id)->update(['stock_state' => 'released']);
    }

    private function hold(object $sale): string
    {
        return 'pos:'.$this->store->runtime().':'.$sale->id;
    }
}
