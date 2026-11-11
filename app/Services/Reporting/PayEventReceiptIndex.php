<?php

namespace App\Services\Reporting;

use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use App\Services\Pos\StockPosCommerce;
use Illuminate\Support\Facades\Cache;

/** Rebuildable lookup of immutable original pool membership, never cached payment/customer authority. */
final class PayEventReceiptIndex
{
    public function __construct(private PosStore $store) {}

    public function forPools(int $tenant, array $pools, bool $rebuild = false): array
    {
        if (! $pools) {
            return ['ids' => [], 'review_items' => []];
        }
        $key = 'pay-event-receipt-index-v1:'.hash('sha256', implode(':', [$this->store->db->getName(), $this->store->db->getDatabaseName(), $this->store->runtime(), $tenant]));
        if ($rebuild) {
            Cache::forget($key);
        }
        $source = fn () => $this->store->rows('sales', $tenant)->where('commerce_source', StockPosCommerce::VERSION);
        $count = $source()->count();
        $index = Cache::get($key);
        if (! is_array($index) || ! isset($index['entries']) || count($index['entries']) !== $count) {
            $index = Cache::lock($key.':lock', 60)->block(5, function () use ($source, $key): array {
                $count = $source()->count();
                $index = Cache::get($key);
                if (! is_array($index) || ! isset($index['entries'])) {
                    $index = ['entries' => [], 'watermark' => null];
                }
                if (count($index['entries']) === $count) {
                    Cache::put($key, $index, now()->addDay());

                    return $index;
                }
                if ($index['watermark'] !== null && count($index['entries']) < $count) {
                    // All states are indexed: a delayed confirmation keeps its original membership.
                    foreach ($source()->where('created_at', '>=', $index['watermark'])->orderBy('id')->cursor() as $sale) {
                        $this->index($index, $sale);
                    }
                }
                if (count($index['entries']) !== $count) {
                    // Includes backdated imports, a lost cache and retained source repair. No date/name inference.
                    $index = ['entries' => [], 'watermark' => null];
                    foreach ($source()->orderBy('id')->cursor() as $sale) {
                        $this->index($index, $sale);
                    }
                }
                Cache::put($key, $index, now()->addDay());

                return $index;
            });
        }
        $ids = [];
        $unavailable = false;
        foreach ($index['entries'] as $id => $entry) {
            if ($entry['unavailable']) {
                $unavailable = true;
            } elseif ($entry['environment'] === 'live' && in_array($entry['pool'], $pools, true)) {
                $ids[] = (string) $id;
            }
        }

        return ['ids' => $ids, 'review_items' => $unavailable ? ['Some retained original Pay event identities are unavailable; coverage requires review.'] : []];
    }

    private function index(array &$index, object $sale): void
    {
        try {
            $snapshot = app(PosService::class)->snapshot($sale);
            $index['entries'][$sale->id] = ['pool' => $snapshot['terms']['stock_pool_id'] ?? null, 'environment' => $snapshot['binding']['environment'] ?? null, 'unavailable' => false];
        } catch (\Throwable) {
            $index['entries'][$sale->id] = ['pool' => null, 'environment' => null, 'unavailable' => true];
        }
        if ($index['watermark'] === null || (string) $sale->created_at > $index['watermark']) {
            $index['watermark'] = (string) $sale->created_at;
        }
    }
}
