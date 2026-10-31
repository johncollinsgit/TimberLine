<?php

declare(strict_types=1);

namespace App\Services\Inventory;

use App\Models\ShopifyStore;
use App\Services\PaymentsCore\Contract;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;

/** Durable, encrypted inventory work. It neither copies orders nor changes their financial state. */
final class StockOnlineInbox
{
    public function __construct(private StockLedger $stock, private StockOnlineOrders $orders) {}

    public function enqueue(int $tenant, string $storeKey, array $payload): void
    {
        $store = ShopifyStore::forTenantId($tenant)->where('store_key', $storeKey)->first();
        if (! $store || empty($payload['id']) || ($payload['source_name'] ?? '') === 'everbranch_pos') {
            return;
        }
        if (! $this->stock->rows('bindings', $tenant)->where('channel', 'shopify')->where('channel_key', (string) $store->id)->where('active', true)->exists()) {
            return;
        }
        $this->stock->rows('online_inbox', $tenant)->insertOrIgnore(['tenant_id' => $tenant, 'store_id' => $store->id,
            'order_id' => (string) $payload['id'], 'event_hash' => Contract::hash($payload), 'payload' => Crypt::encryptString(Contract::json($payload)),
            'state' => 'pending', 'attempts' => 0, 'next_attempt_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    }

    public function drain(int $tenant, int $limit = 100): array
    {
        return Cache::lock('stock-inbox-'.$tenant, 180)->block(5, function () use ($tenant, $limit): array {
            $processed = 0;
            $review = 0;
            $rows = $this->stock->rows('online_inbox', $tenant)->whereIn('state', ['pending', 'review'])->where('attempts', '<', 5)
                ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))->orderBy('id')->limit(min(500, max(1, $limit)))->get();
            foreach ($rows as $row) {
                try {
                    $store = ShopifyStore::forTenantId($tenant)->find($row->store_id);
                    if (! $store) {
                        throw new StockException('stock_store_unavailable');
                    }
                    $payload = json_decode(Crypt::decryptString($row->payload), true, 512, JSON_THROW_ON_ERROR);
                    $result = $this->orders->ingest($tenant, $store->store_key, $payload);
                    if (($result['managed'] ?? false) !== true) {
                        throw new StockException('stock_channel_binding_required');
                    }
                    $this->stock->rows('online_inbox', $tenant)->where('id', $row->id)->update(['state' => 'completed', 'error_code' => null, 'updated_at' => now()]);
                    $processed++;
                } catch (StockException $error) {
                    $this->stock->rows('online_inbox', $tenant)->where('id', $row->id)->update(['state' => 'review', 'attempts' => $row->attempts + 1,
                        'error_code' => $error->reason, 'next_attempt_at' => now()->addMinutes(5), 'updated_at' => now()]);
                    $review++;
                }
            }

            return ['processed' => $processed, 'review' => $review];
        });
    }
}
