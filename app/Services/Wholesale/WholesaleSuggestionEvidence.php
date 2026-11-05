<?php

namespace App\Services\Wholesale;

use App\Models\Order;
use Carbon\CarbonImmutable;

class WholesaleSuggestionEvidence
{
    /** Preserve decisions made before imported sales dates were corrected. */
    public static function matchesLatestOrder(array $evidence, Order $order): bool
    {
        if (filled($evidence['last_wholesale_order_id'] ?? null)) {
            return (string) $evidence['last_wholesale_order_id'] === (string) $order->shopify_order_id;
        }
        try {
            $prior = $evidence['last_wholesale_order_at'] ?? null;
            if (! is_string($prior) || trim($prior) === '') {
                return false;
            }
            $timestamp = CarbonImmutable::parse($prior)->timestamp;
            if ($order->ordered_at?->timestamp === $timestamp) {
                return true;
            }
            $created = data_get($order->attribution_meta, 'wholesale_reporting.provider_created_at');
            if (! is_string($created) || $created === '') {
                return false;
            }
            $created = CarbonImmutable::parse($created);
            // Older ingestion stored the provider's local wall time in a UTC
            // column. Exact original creation time is a conservative adoption
            // bridge only within the same scoped customer/latest-order context.
            $legacy = CarbonImmutable::parse($created->format('Y-m-d H:i:s'), config('app.timezone', 'UTC'));

            return $timestamp === $created->timestamp || $timestamp === $legacy->timestamp;
        } catch (\Throwable) {
            return false;
        }
    }
}
