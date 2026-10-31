<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Services\PaymentsCore\Contract;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Str;

/** Canonical unit stock. All writes serialize per tenant and retain replay evidence. */
final class StockLedger
{
    public function __construct(public readonly Connection $db) {}

    public function rows(string $table, int $tenant): Builder
    {
        if ($tenant < 1 || ! in_array($table, StockSchema::TABLES, true)) {
            throw new StockException('stock_scope_invalid', 403);
        }

        return $this->db->table('commerce_stock_'.$table)->where('tenant_id', $tenant);
    }

    public function operation(int $tenant, string $request, string $kind, int $actor, array $input, \Closure $work): array
    {
        if ($request === '' || strlen($request) > 190 || preg_match('/[\x00-\x1F]/', $request)) {
            throw new StockException('stock_request_invalid', 422);
        }
        $key = hash('sha256', $request);
        $hash = Contract::hash([$tenant, $kind, $actor, $input]);
        // Duplicate INSERT IGNORE locks must not be upgraded together by competing cashiers.
        if (! $this->rows('mutexes', $tenant)->exists()) {
            $this->rows('mutexes', $tenant)->insertOrIgnore(['tenant_id' => $tenant]);
        }

        return $this->db->transaction(function () use ($tenant, $key, $hash, $kind, $actor, $work): array {
            $this->rows('mutexes', $tenant)->lockForUpdate()->firstOrFail();
            $old = $this->rows('operations', $tenant)->where('request_hash', $key)->first();
            if ($old) {
                if (! hash_equals($old->input_hash, $hash)) {
                    throw new StockException('stock_request_conflict');
                }

                return json_decode($old->result, true, 64, JSON_THROW_ON_ERROR);
            }
            $result = $work($key);
            $this->rows('operations', $tenant)->insert(['tenant_id' => $tenant, 'request_hash' => $key,
                'input_hash' => $hash, 'kind' => $kind, 'actor_id' => $actor, 'result' => json_encode($result, JSON_THROW_ON_ERROR), 'created_at' => now()]);

            return $result;
        }, 3);
    }

    public function createPool(int $tenant, string $request, int $actor, string $name, string $kind, ?string $event = null): array
    {
        if (trim($name) === '' || strlen($name) > 150 || ! in_array($kind, ['shared', 'warehouse', 'show'], true)
            || ($kind === 'show' && (! $event || strlen($event) > 150))) {
            throw new StockException('stock_pool_invalid', 422);
        }

        return $this->operation($tenant, $request, 'pool', $actor, [$name, $kind, $event], function () use ($tenant, $actor, $name, $kind, $event): array {
            if ($event && $this->rows('pools', $tenant)->where('event_key', $event)->exists()) {
                throw new StockException('show_stock_pool_exists');
            }
            $id = bin2hex(random_bytes(16));
            $this->rows('pools', $tenant)->insert(['id' => $id, 'tenant_id' => $tenant, 'name' => trim($name), 'kind' => $kind,
                'event_key' => $event, 'active' => true, 'reviewed_by' => $actor, 'created_at' => now(), 'updated_at' => now()]);

            return ['pool_id' => $id];
        });
    }

    public function pool(int $tenant, string $pool, bool $includeArchived = false): object
    {
        return $this->rows('pools', $tenant)->where('id', $pool)->when(! $includeArchived, fn ($q) => $q->where('active', true))->first()
            ?? throw new StockException('stock_pool_unavailable', 404);
    }

    public function item(int $tenant, string $item, bool $includeArchived = false): object
    {
        return $this->rows('items', $tenant)->where('id', $item)->when(! $includeArchived, fn ($q) => $q->where('active', true))->first()
            ?? throw new StockException('stock_item_unavailable', 404);
    }

    public function balance(int $tenant, string $pool, string $item, bool $includeArchived = false): object
    {
        $this->pool($tenant, $pool, $includeArchived);
        $this->item($tenant, $item, $includeArchived);
        $this->rows('balances', $tenant)->insertOrIgnore(['tenant_id' => $tenant, 'pool_id' => $pool, 'item_id' => $item,
            'on_hand' => 0, 'reserved' => 0, 'version' => 0, 'created_at' => now(), 'updated_at' => now()]);

        return $this->rows('balances', $tenant)->where('pool_id', $pool)->where('item_id', $item)->lockForUpdate()->firstOrFail();
    }

    /** Count only physically reviewed goods. Draft packing plans cannot call this implicitly. */
    public function count(int $tenant, string $request, int $actor, string $pool, array $counts, string $reason, array $expectedVersions = []): array
    {
        if (! in_array($reason, ['opening_count', 'physical_count', 'received', 'damage', 'return'], true)) {
            throw new StockException('stock_count_reason_required', 422);
        }
        $counts = $this->quantities($counts, true);

        return $this->operation($tenant, $request, 'count', $actor, [$pool, $counts, $reason, $expectedVersions], function ($key) use ($tenant, $actor, $pool, $counts, $reason, $expectedVersions): array {
            foreach ($counts as $item => $quantity) {
                if (json_decode($this->item($tenant, $item)->metadata ?? '{}', true)['requires_components'] ?? false) {
                    throw new StockException('stock_bundle_components_review_required');
                }
                $b = $this->balance($tenant, $pool, $item);
                if (isset($expectedVersions[$item]) && (! is_int($expectedVersions[$item]) || $expectedVersions[$item] !== (int) $b->version)) {
                    throw new StockException('stock_count_changed_by_activity');
                }
                if ($quantity < $b->reserved) {
                    throw new StockException('count_below_reserved_stock');
                }
                $this->change($tenant, $b, $quantity - $b->on_hand, 0, $key, $actor, $reason);
                $this->rows('balances', $tenant)->where('id', $b->id)->update(['counted_at' => now()]);
            }

            return ['pool_id' => $pool, 'counted_items' => count($counts)];
        });
    }

    public function transfer(int $tenant, string $request, int $actor, string $from, string $to, array $items, string $reason = 'transfer'): array
    {
        if ($from === $to || ! in_array($reason, ['transfer', 'packed', 'returned'], true)) {
            throw new StockException('stock_transfer_invalid', 422);
        }
        $items = $this->quantities($items);

        return $this->operation($tenant, $request, 'transfer', $actor, [$from, $to, $items, $reason], function ($key) use ($tenant, $actor, $from, $to, $items, $reason): array {
            $this->pool($tenant, $from);
            $this->pool($tenant, $to);
            foreach ($items as $item => $quantity) {
                $a = $this->balance($tenant, $from, $item);
                $b = $this->balance($tenant, $to, $item);
                if (! $a->counted_at || $a->on_hand - $a->reserved < $quantity) {
                    throw new StockException('stock_unavailable');
                }
                $this->change($tenant, $a, -$quantity, 0, $key, $actor, $reason);
                $this->change($tenant, $b, $quantity, 0, $key, $actor, $reason);
                $this->rows('balances', $tenant)->where('id', $b->id)->update(['counted_at' => now()]);
            }

            return ['from_pool_id' => $from, 'to_pool_id' => $to, 'items' => $items];
        });
    }

    public function reserve(int $tenant, string $hold, int $actor, string $pool, array $items, \DateTimeInterface $expires): array
    {
        if (strlen($hold) > 160) {
            throw new StockException('stock_hold_invalid', 422);
        }
        $items = $this->quantities($items);

        return $this->operation($tenant, 'reserve:'.$hold, 'reserve', $actor, [$pool, $items], function ($key) use ($tenant, $hold, $actor, $pool, $items, $expires): array {
            $this->pool($tenant, $pool);
            foreach ($items as $item => $quantity) {
                $i = $this->item($tenant, $item);
                if (! $i->tracked) {
                    continue;
                }
                $b = $this->balance($tenant, $pool, $item);
                if (! $b->counted_at || $b->on_hand - $b->reserved < $quantity) {
                    throw new StockException('stock_unavailable');
                }
                $this->change($tenant, $b, 0, $quantity, $key, $actor, 'reserved', $hold);
                $this->rows('reservations', $tenant)->insert(['tenant_id' => $tenant, 'hold_key' => $hold, 'pool_id' => $pool,
                    'item_id' => $item, 'quantity' => $quantity, 'state' => 'held', 'expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
                $this->rows('reservations', $tenant)->where('hold_key', $hold)->where('item_id', $item)->update(['balance_version' => (int) $b->version + 1]);
            }

            return ['hold_key' => $hold, 'pool_id' => $pool, 'state' => 'held'];
        });
    }

    /** Only callers with fresh confirmed provider outcome may consume/release a payment hold. */
    public function settle(int $tenant, string $hold, bool $paid, ?string $paymentReference = null): array
    {
        if ($paid && ! $paymentReference) {
            throw new StockException('stock_paid_reference_required');
        }

        return $this->operation($tenant, 'settle:'.$hold, 'settle', 0, [$paid, $paymentReference], function ($key) use ($tenant, $hold, $paid, $paymentReference): array {
            if (! $this->rows('operations', $tenant)->where('request_hash', hash('sha256', 'reserve:'.$hold))->exists()) {
                throw new StockException('stock_hold_missing');
            }
            $holds = $this->rows('reservations', $tenant)->where('hold_key', $hold)->orderBy('item_id')->lockForUpdate()->get();
            foreach ($holds as $h) {
                if ($h->state !== 'held') {
                    throw new StockException('stock_hold_already_settled');
                }
                $b = $this->balance($tenant, $h->pool_id, $h->item_id, true);
                if ($b->reserved < $h->quantity || ($paid && $b->on_hand < $h->quantity)) {
                    throw new StockException('stock_hold_conflict');
                }
                $this->change($tenant, $b, $paid ? -$h->quantity : 0, -$h->quantity, $key, 0, $paid ? 'sale' : 'released', $hold);
                $this->rows('reservations', $tenant)->where('id', $h->id)->update(['state' => $paid ? 'consumed' : 'released',
                    'payment_ref' => $paymentReference, 'updated_at' => now()]);
            }

            return ['hold_key' => $hold, 'state' => $paid ? 'consumed' : 'released'];
        });
    }

    public function bind(int $tenant, string $request, int $actor, string $channel, string $channelKey, string $pool, array $settings = [], bool $publish = false): array
    {
        if (! in_array($channel, ['pos', 'shopify', 'website'], true) || $channelKey === '' || strlen($channelKey) > 150) {
            throw new StockException('stock_channel_invalid', 422);
        }

        return $this->operation($tenant, $request, 'binding', $actor, [$channel, $channelKey, $pool, $settings, $publish], function () use ($tenant, $actor, $channel, $channelKey, $pool, $settings, $publish): array {
            $this->pool($tenant, $pool);
            $old = $this->rows('bindings', $tenant)->where('channel', $channel)->where('channel_key', $channelKey)->first();
            if ($old && $old->pool_id !== $pool && $this->rows('reservations', $tenant)->where('pool_id', $old->pool_id)->where('state', 'held')->exists()) {
                throw new StockException('stock_binding_has_active_holds');
            }
            if ($old && $old->pool_id !== $pool && $this->rows('publications', $tenant)->where('binding_id', $old->id)->whereNotIn('state', ['completed', 'superseded'])->exists()) {
                throw new StockException('stock_binding_has_pending_publications');
            }
            $this->rows('bindings', $tenant)->updateOrInsert(['tenant_id' => $tenant, 'channel' => $channel, 'channel_key' => $channelKey],
                ['pool_id' => $pool, 'active' => true, 'publish_enabled' => $publish, 'reviewed_by' => $actor,
                    'settings' => json_encode($settings, JSON_THROW_ON_ERROR), 'created_at' => $old?->created_at ?? now(), 'updated_at' => now()]);

            return ['channel' => $channel, 'channel_key' => $channelKey, 'pool_id' => $pool];
        });
    }

    public function listing(int $tenant, string $pool): array
    {
        $this->pool($tenant, $pool);

        return $this->rows('items', $tenant)->where('active', true)->orderBy('title')->get()->map(function ($item) use ($tenant, $pool): array {
            $b = $this->rows('balances', $tenant)->where('pool_id', $pool)->where('item_id', $item->id)->first();
            $bundleReview = json_decode($item->metadata ?? '{}', true)['requires_components'] ?? false;
            $available = $item->tracked ? max(0, (int) ($b->on_hand ?? 0) - (int) ($b->reserved ?? 0)) : null;

            return ['item_id' => $item->id, 'product_key' => $item->product_key, 'sku' => $item->sku,
                'title' => $item->title, 'variant_label' => $item->variant_label, 'image_url' => $item->image_url,
                'kind' => $bundleReview ? 'bundle' : 'merchandise', 'unit_amount_minor' => (int) $item->unit_amount_minor, 'currency' => $item->currency,
                'revision' => $item->revision, 'available_quantity' => $available, 'stock_pool_id' => $pool,
                'on_hand' => (int) ($b->on_hand ?? 0), 'reserved_quantity' => (int) ($b->reserved ?? 0),
                'counted_at' => $b?->counted_at, 'balance_version' => (int) ($b->version ?? 0),
                'availability' => $bundleReview ? 'review_required' : (! $item->tracked || $available > 0 ? 'available' : ($b?->counted_at ? 'sold_out' : 'count_required'))];
        })->all();
    }

    /** Internal channel adapters call this inside operation(), never from untrusted HTTP input. */
    public function receiveProduction(int $tenant, string $pool, string $item, int $quantity, string $operation, int $actor, string $source): void
    {
        if ($this->db->transactionLevel() < 1 || $quantity < 1 || $quantity > 10000000) {
            throw new StockException('stock_production_invalid');
        }
        $b = $this->balance($tenant, $pool, $item);
        // Production receipts are additions, never an absolute copy of a plan or provider count.
        $this->change($tenant, $b, $quantity, 0, $operation, $actor, 'production_ready', $source);
        $this->rows('balances', $tenant)->where('id', $b->id)->update(['counted_at' => now()]);
    }

    /** Internal channel adapters call this inside operation(), never from untrusted HTTP input. */
    public function externalChange(int $tenant, string $pool, string $item, int $onHand, int $reserved, string $operation, string $source): void
    {
        if ($this->db->transactionLevel() < 1) {
            throw new StockException('stock_transaction_required');
        }
        if ($onHand === 0 && $reserved === 0) {
            return;
        }
        $b = $this->balance($tenant, $pool, $item, true);
        if (! $b->counted_at) {
            throw new StockException('stock_opening_count_required');
        }
        $this->change($tenant, $b, $onHand, $reserved, $operation, 0, 'online_order', $source);
    }

    private function quantities(array $values, bool $zero = false): array
    {
        if (! $values || count($values) > 500) {
            throw new StockException('stock_items_required', 422);
        }
        foreach ($values as $id => $q) {
            if (! is_string($id) || ! preg_match('/^[a-f0-9]{32}$/D', $id) || ! is_int($q) || $q < ($zero ? 0 : 1) || $q > 10000000) {
                throw new StockException('stock_quantity_invalid', 422);
            }
        }
        ksort($values);

        return $values;
    }

    private function change(int $tenant, object $b, int $onHand, int $reserved, string $operation, int $actor, string $reason, ?string $source = null): void
    {
        $next = (int) $b->on_hand + $onHand;
        $holds = (int) $b->reserved + $reserved;
        if ($next < 0 || $holds < 0 || $holds > $next) {
            throw new StockException('stock_balance_conflict');
        }
        $version = (int) $b->version + 1;
        $this->rows('balances', $tenant)->where('id', $b->id)->update(['on_hand' => $next, 'reserved' => $holds, 'version' => $version, 'updated_at' => now()]);
        $this->rows('movements', $tenant)->insert(['tenant_id' => $tenant, 'pool_id' => $b->pool_id, 'item_id' => $b->item_id,
            'operation_hash' => $operation, 'reason' => $reason, 'delta' => $onHand, 'before_quantity' => $b->on_hand,
            'after_quantity' => $next, 'actor_id' => $actor, 'source_ref' => $source, 'created_at' => now()]);
        foreach ($this->rows('bindings', $tenant)->where('pool_id', $b->pool_id)->where('channel', 'shopify')->where('active', true)->where('publish_enabled', true)->get() as $binding) {
            if (! $this->rows('provider_items', $tenant)->where('store_id', (int) $binding->channel_key)->where('item_id', $b->item_id)->where('active', true)->exists()) {
                continue;
            }
            $this->rows('publications', $tenant)->insertOrIgnore(['tenant_id' => $tenant, 'binding_id' => $binding->id,
                'pool_id' => $b->pool_id, 'item_id' => $b->item_id, 'version' => $version, 'quantity' => $next - $holds,
                'idempotency_key' => (string) Str::uuid(), 'state' => 'pending', 'attempts' => 0, 'created_at' => now(), 'updated_at' => now()]);
        }
    }
}
