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
        // Explicit line identities keep multiple configurations of the same bundle
        // separate; legacy clients continue to use their catalog ID as the key.
        $seenItems = [];
        $seenLines = [];
        foreach ($input['items'] ?? [] as $selection) {
            $itemId = $selection['item_id'] ?? null;
            $lineId = $selection['line_id'] ?? $itemId;
            if (! is_string($itemId) || ! is_string($lineId) || ! preg_match('/^[a-f0-9]{32}$/D', $lineId)
                || isset($seenLines[$lineId]) || (isset($seenItems[$itemId]) && (! isset($selection['line_id']) || ! $seenItems[$itemId]))) {
                throw new PosException('duplicate_catalog_item', 422);
            }
            $seenItems[$itemId] = isset($selection['line_id']);
            $seenLines[$lineId] = true;
        }
        foreach ($input['items'] ?? [] as $selection) {
            if (isset($selection['line_id']) && isset($seenItems[$selection['line_id']])) {
                throw new PosException('basket_line_invalid', 422);
            }
        }
        $settings = $this->settings($scope);
        $catalog = array_column($this->catalog($scope), null, 'item_id');
        $quantities = [];
        $lines = [];
        $display = [];
        $expected = [];
        $gross = [];
        $taxable = [];
        $lineKeys = [];
        $providerKeys = [];
        foreach ($input['items'] ?? [] as $selection) {
            $item = $catalog[$selection['item_id']] ?? null;
            if (! $item || $selection['revision'] !== $item['revision']) {
                throw new PosException('catalog_changed');
            }
            $key = $selection['line_id'] ?? $item['item_id'];
            if ($key !== $item['item_id'] && $item['kind'] !== 'bundle') {
                throw new PosException('basket_line_invalid', 422);
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
            $gross[$key] = $item['unit_amount_minor'] * $selection['quantity'];
            $taxable[$key] = ($metadata['taxable'] ?? true) === true;
            $display[] = ['item_id' => $item['item_id'], 'line_id' => $key, 'title' => $item['title'].($item['variant_label'] && $item['variant_label'] !== 'Default Title' ? ' · '.$item['variant_label'] : ''),
                'sku' => $item['sku'], 'quantity' => $selection['quantity'], 'subtotal_minor' => $item['unit_amount_minor'] * $selection['quantity'], 'components' => $components];
            if ($scope['register']->catalog_source === 'shopify') {
                $m = $this->stock->rows('provider_items', $scope['tenant_id'])->where('store_id', $settings['shopify']['store_id'])
                    ->where('item_id', $item['item_id'])->where('active', true)->firstOrFail();
                $lines[] = ['variantId' => $m->variant_id, 'quantity' => $selection['quantity']];
                $lineKeys[] = $key;
                $providerKeys[$key] = $m->variant_id;
                if (isset($expected[$m->variant_id]) && $expected[$m->variant_id]['price'] !== $item['unit_amount_minor']) {
                    throw new PosException('catalog_changed');
                }
                $expected[$m->variant_id] = ['quantity' => ($expected[$m->variant_id]['quantity'] ?? 0) + $selection['quantity'], 'price' => $item['unit_amount_minor']];
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
            $lineKeys[] = 'custom';
            $gross['custom'] = $custom['amount_minor'];
            $taxable['custom'] = ($settings['custom_taxable'] ?? true) === true;
            $display[] = ['title' => $custom['description'], 'quantity' => 1, 'subtotal_minor' => $custom['amount_minor'], 'custom_charge' => true];
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
        $offers = app(PosCheckoutOffers::class)->apply($scope, $input, $catalog, $gross);
        $reward = $scope['reward_redemption'] ?? null;
        if ($reward !== null) {
            if (! is_array($reward) || ! is_int($reward['amount_minor'] ?? null) || ! is_int($reward['profile_id'] ?? null)
                || ! is_string($reward['policy_version'] ?? null) || ! is_string($reward['refund_policy'] ?? null)) {
                throw new PosException('reward_quote_invalid', 422);
            }
            $eligible = array_sum($offers['lines_minor']);
            $offers = app(PosRewardAllocation::class)->apply($offers, $reward['amount_minor']);
            $reward['eligible_minor'] = $eligible;
        }
        $netSubtotal = array_sum($offers['lines_minor']);
        if ($netSubtotal < 1) {
            throw new PosException('zero_total_checkout_not_supported', 422);
        }
        foreach ($display as &$displayLine) {
            $key = $displayLine['line_id'] ?? 'custom';
            $displayLine['discount_minor'] = $gross[$key] - $offers['lines_minor'][$key];
            $displayLine['net_subtotal_minor'] = $offers['lines_minor'][$key];
            if (($offers['unit_lines_minor'] ?? null) !== null) {
                $displayLine['unit_net_minor'] = $offers['unit_lines_minor'][$key];
            }
        }
        unset($displayLine);
        $taxableSubtotal = 0;
        foreach ($offers['lines_minor'] as $key => $amount) {
            if ($taxable[$key]) {
                $taxableSubtotal += $amount;
            }
        }
        $tax = 0;
        $total = $netSubtotal;
        if ($scope['register']->catalog_source === 'shopify') {
            $expectedUnits = [];
            if ($offers['version'] !== null || $reward !== null) {
                $lines = $this->discountedProviderLines($lines, $lineKeys, $gross, $offers['lines_minor'], $offers['unit_lines_minor'] ?? null);
                foreach ($lines as $line) {
                    $variant = $line['variantId'] ?? 'custom';
                    $original = $variant === 'custom' ? $gross['custom'] : $expected[$variant]['price'];
                    $discount = isset($line['appliedDiscount']) ? ShopifyPosCommerce::minor(number_format($line['appliedDiscount']['value'], 2, '.', '')) : 0;
                    $signature = $variant.'|'.$original.'|'.($original - $discount);
                    $expectedUnits[$signature] = ($expectedUnits[$signature] ?? 0) + $line['quantity'];
                }
            }
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
            $actualNet = [];
            $actualUnits = [];
            foreach ($calculated['lineItems'] as $line) {
                if ($offers['version'] !== null || $reward !== null) {
                    if (! isset($line['quantity'], $line['originalUnitPriceSet']['shopMoney']['amount'], $line['discountedTotalSet']['shopMoney']['amount'])
                        || ! is_int($line['quantity']) || $line['quantity'] < 1) {
                        throw new PosException('total_unavailable', 503);
                    }
                    $original = ShopifyPosCommerce::minor($line['originalUnitPriceSet']['shopMoney']['amount']);
                    $net = ShopifyPosCommerce::minor($line['discountedTotalSet']['shopMoney']['amount']);
                    if ($net % $line['quantity'] !== 0) {
                        throw new PosException('total_changed');
                    }
                    $signature = ($line['variant']['id'] ?? 'custom').'|'.$original.'|'.intdiv($net, $line['quantity']);
                    $actualUnits[$signature] = ($actualUnits[$signature] ?? 0) + $line['quantity'];
                }
                if (isset($line['variant']['id'])) {
                    $variant = $line['variant']['id'];
                    $price = ShopifyPosCommerce::minor($line['originalUnitPriceSet']['shopMoney']['amount']);
                    if (isset($actual[$variant]) && $actual[$variant]['price'] !== $price) {
                        throw new PosException('catalog_changed');
                    }
                    $actual[$variant] = ['quantity' => ($actual[$variant]['quantity'] ?? 0) + $line['quantity'], 'price' => $price];
                    if ($offers['version'] !== null || $reward !== null) {
                        if (! isset($line['discountedTotalSet']['shopMoney']['amount']) || ($line['discountedTotalSet']['shopMoney']['currencyCode'] ?? null) !== 'USD'
                            || ($line['originalUnitPriceSet']['shopMoney']['currencyCode'] ?? null) !== 'USD') {
                            throw new PosException('total_unavailable', 503);
                        }
                        $actualNet[$variant] = ($actualNet[$variant] ?? 0) + ShopifyPosCommerce::minor($line['discountedTotalSet']['shopMoney']['amount']);
                    }
                } elseif ($offers['version'] !== null || $reward !== null) {
                    if (! isset($line['discountedTotalSet']['shopMoney']['amount'], $line['originalUnitPriceSet']['shopMoney']['amount'])
                        || ($line['discountedTotalSet']['shopMoney']['currencyCode'] ?? null) !== 'USD'
                        || ($line['originalUnitPriceSet']['shopMoney']['currencyCode'] ?? null) !== 'USD') {
                        throw new PosException('total_unavailable', 503);
                    }
                    $actualNet['custom'] = ($actualNet['custom'] ?? 0) + ShopifyPosCommerce::minor($line['discountedTotalSet']['shopMoney']['amount']);
                    if (! isset($gross['custom']) || ShopifyPosCommerce::minor($line['originalUnitPriceSet']['shopMoney']['amount']) * $line['quantity'] !== $gross['custom']) {
                        throw new PosException('total_changed');
                    }
                }
            }
            ksort($expected);
            ksort($actual);
            if ($expected !== $actual) {
                throw new PosException('catalog_changed');
            }
            if ($offers['version'] !== null || $reward !== null) {
                $expectedNet = [];
                foreach ($lineKeys as $key) {
                    $providerKey = $key === 'custom' ? 'custom' : $providerKeys[$key];
                    $expectedNet[$providerKey] = ($expectedNet[$providerKey] ?? 0) + $offers['lines_minor'][$key];
                }
                ksort($expectedNet);
                ksort($actualNet);
                if ($expectedNet !== $actualNet) {
                    throw new PosException('total_changed');
                }
                ksort($expectedUnits);
                ksort($actualUnits);
                if ($expectedUnits !== $actualUnits) {
                    throw new PosException('total_changed');
                }
            }
            $total = ShopifyPosCommerce::minor($calculated['totalPriceSet']['shopMoney']['amount']);
            if (($offers['version'] !== null || $reward !== null) && ($calculated['totalTaxSet']['shopMoney']['currencyCode'] ?? null) !== 'USD') {
                throw new PosException('tax_unavailable', 503);
            }
            $tax = ShopifyPosCommerce::minor($calculated['totalTaxSet']['shopMoney']['amount']);
            if ($total - $tax !== $netSubtotal) {
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
            'subtotal_minor' => $subtotal, 'discount_minor' => $offers['discount_minor'], 'applied_offers' => $offers['offers'], 'checkout_rules_version' => $offers['version'],
            'rewardable_subtotal_minor' => $netSubtotal - ($offers['lines_minor']['custom'] ?? 0),
            ...($reward !== null ? ['pos_candle_cash' => $reward] : []),
            'lines' => [['line_id' => 'counter_basket', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => $netSubtotal, 'tax_amount_minor' => $tax]]];
    }

    /** Shopify's fixed line discount is per unit; preserve exact unit prices when specific units are discounted. */
    private function discountedProviderLines(array $lines, array $keys, array $gross, array $net, ?array $unitLines = null): array
    {
        $result = [];
        foreach ($lines as $index => $line) {
            $key = $keys[$index];
            $quantity = $line['quantity'];
            if ($unitLines !== null) {
                $units = $unitLines[$key] ?? [];
                if (count($units) !== $quantity || array_sum($units) !== $net[$key] || $gross[$key] % $quantity !== 0) {
                    throw new PosException('total_unavailable', 503);
                }
                $original = intdiv($gross[$key], $quantity);
                $groups = [];
                foreach ($units as $unit) {
                    if (! is_int($unit) || $unit < 0 || $unit > $original) {
                        throw new PosException('total_unavailable', 503);
                    }
                    $discount = $original - $unit;
                    $groups[$discount] = ($groups[$discount] ?? 0) + 1;
                }
                $parts = [];
                foreach ($groups as $unitDiscount => $count) {
                    $parts[] = [$count, $unitDiscount];
                }
            } else {
                $discount = $gross[$key] - $net[$key];
                $perUnit = intdiv($discount, $quantity);
                $extra = $discount % $quantity;
                $parts = [[$quantity - $extra, $perUnit], [$extra, $perUnit + 1]];
            }
            foreach ($parts as [$count, $unitDiscount]) {
                if ($count === 0) {
                    continue;
                }
                $part = $line;
                $part['quantity'] = $count;
                if ($unitDiscount > 0) {
                    $part['appliedDiscount'] = ['valueType' => 'FIXED_AMOUNT', 'value' => $unitDiscount / 100, 'description' => 'Everbranch checkout offers'];
                }
                $result[] = $part;
            }
        }

        return $result;
    }

    public function prepare(array $scope, object $sale, array $snapshot): void
    {
        $this->assertOffersCurrent($scope, $snapshot);
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

    /** Validate a frozen offer quote before a new collection attempt; never modify an existing attempt. */
    public function assertOffersCurrent(array $scope, array $snapshot): void
    {
        $version = $snapshot['terms']['checkout_rules_version'] ?? null;
        if ($version === null) {
            return;
        }
        if (! is_string($version) || config('pos_live.checkout_rules_enabled') !== true) {
            throw new PosException('checkout_rules_unavailable', 503);
        }
        $register = $this->store->rows('registers', $scope['tenant_id'])->where('id', $scope['register']->id)->first();
        if (! $register) {
            throw new PosException('register_unavailable', 404);
        }
        $available = app(PosCheckoutOffers::class)->available([...$scope, 'register' => $register]);
        if (! is_string($available['version']) || ! hash_equals($version, $available['version'])) {
            throw new PosException('checkout_rules_changed');
        }
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
            // Rule edits lock the same tenant row; authorize frozen offers at the stock collection boundary.
            if (($snapshot['terms']['checkout_rules_version'] ?? null) !== null) {
                $this->store->db->table('tenants')->where('id', $scope['tenant_id'])->lockForUpdate()->firstOrFail();
                $this->assertOffersCurrent($scope, $snapshot);
            }
            $register = $this->store->rows('registers', $scope['tenant_id'])->where('id', $scope['register']->id)->where('active', true)->lockForUpdate()->first();
            if (! $register) {
                throw new PosException('register_unavailable', 404);
            }
            $current = $this->store->decrypt($register->configuration);
            foreach (['tax_location_review', 'tax_valid_until', 'shopify.tax_address'] as $field) {
                if (! hash_equals(\App\Services\PaymentsCore\Contract::hash([data_get($snapshot['configuration'], $field)]),
                    \App\Services\PaymentsCore\Contract::hash([data_get($current, $field)]))) {
                    throw new PosException('checkout_settings_changed');
                }
            }
            app(PosTaxLocations::class)->assertCurrentReview([...$scope, 'register' => $register], true);
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
            if (config('pos_live.durable_start_claims_enabled', false) === true) {
                try {
                    // Runs within the stock transaction and uses the same sale
                    // row lock as collection before any inventory is released.
                    app(PosPaymentStartClaim::class)->cancel($tenant, $id);
                } catch (PosException $error) {
                    if (in_array($error->blocker, ['payment_claim_conflict', 'payment_claim_unavailable', 'payment_recovery_required'], true)) {
                        continue;
                    }
                    throw $error;
                }
            } else {
                // Do not let a disabled rollout bypass an already durable
                // claim or an existing customer reward hold.
                $schema = $this->store->db->getSchemaBuilder();
                if (($schema->hasTable('pos_payment_start_claims') && $this->store->db->table('pos_payment_start_claims')
                    ->where('runtime_id', $this->store->runtime())->where('tenant_id', $tenant)->where('sale_id', $id)->exists())
                    || ($schema->hasTable('pos_candle_cash_reservations') && $this->store->db->table('pos_candle_cash_reservations')
                        ->where('runtime_id', $this->store->runtime())->where('tenant_id', $tenant)->where('sale_id', $id)->exists())) {
                    continue;
                }
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
