<?php

namespace App\Services\Shopify;

use App\Models\ShopifyImportRun;
use App\Services\Marketing\IntegrationHealthEventRecorder;
use Carbon\CarbonImmutable;

/**
 * Reports how fresh each storefront's Shopify order import is, and raises/clears
 * integration health events so a silently-stopped import (expired token, broken
 * cron, revoked scopes) becomes visible instead of quietly starving the pouring room.
 */
class ShopifyImportHealthService
{
    public const EVENT_TYPE = 'order_import_stale';

    /** Storefronts we expect to import automatically. */
    private const STORE_KEYS = ['retail', 'wholesale'];

    public function __construct(private readonly IntegrationHealthEventRecorder $healthEvents) {}

    /**
     * Per-store freshness snapshot. status is one of:
     * healthy | stale | never | not_installed.
     *
     * @param  array<int,string>|null  $storeKeys
     * @return array<int,array<string,mixed>>
     */
    public function report(int $staleAfterMinutes = 90, ?array $storeKeys = null): array
    {
        $keys = $storeKeys ?: self::STORE_KEYS;
        $now = CarbonImmutable::now();
        $rows = [];

        foreach ($keys as $key) {
            $installed = ShopifyStores::find($key) !== null;

            $lastSuccess = ShopifyImportRun::query()
                ->where('store_key', $key)
                ->where('is_dry_run', false)
                ->whereNotNull('finished_at')
                ->latest('finished_at')
                ->first();

            $lastSuccessAt = $lastSuccess?->finished_at
                ? CarbonImmutable::parse($lastSuccess->finished_at)
                : null;

            $ageMinutes = $lastSuccessAt !== null
                ? abs((int) $lastSuccessAt->diffInMinutes($now))
                : null;

            $rows[] = [
                'store_key' => $key,
                'installed' => $installed,
                'last_success_at' => $lastSuccessAt,
                'age_minutes' => $ageMinutes,
                'status' => $this->classify($installed, $ageMinutes, $staleAfterMinutes),
            ];
        }

        return $rows;
    }

    /**
     * Same as report(), but records an open health event for stale/never stores and
     * resolves the open event once a store is healthy again (self-healing alert).
     *
     * @param  array<int,string>|null  $storeKeys
     * @return array<int,array<string,mixed>>
     */
    public function evaluate(int $staleAfterMinutes = 90, ?array $storeKeys = null): array
    {
        $rows = $this->report($staleAfterMinutes, $storeKeys);

        foreach ($rows as $row) {
            if ($row['status'] === 'not_installed') {
                // A store we haven't connected yet is a config choice, not an alert.
                continue;
            }

            if (in_array($row['status'], ['stale', 'never'], true)) {
                $this->healthEvents->record([
                    'provider' => 'shopify',
                    'event_type' => self::EVENT_TYPE,
                    'severity' => $this->severityFor($row['status'], $row['age_minutes'], $staleAfterMinutes),
                    'status' => 'open',
                    'store_key' => $row['store_key'],
                    'context' => [
                        'reason' => $row['status'],
                        'last_success_at' => $row['last_success_at']?->toIso8601String(),
                        'age_minutes' => $row['age_minutes'],
                        'stale_after_minutes' => $staleAfterMinutes,
                    ],
                ], dedupeWindowMinutes: 24 * 60);

                continue;
            }

            // healthy -> clear any open stale alert for this store.
            $this->healthEvents->resolve([
                'provider' => 'shopify',
                'event_type' => self::EVENT_TYPE,
                'store_key' => $row['store_key'],
            ]);
        }

        return $rows;
    }

    private function classify(bool $installed, ?int $ageMinutes, int $staleAfterMinutes): string
    {
        if (! $installed) {
            return 'not_installed';
        }

        if ($ageMinutes === null) {
            return 'never';
        }

        return $ageMinutes > $staleAfterMinutes ? 'stale' : 'healthy';
    }

    private function severityFor(string $status, ?int $ageMinutes, int $staleAfterMinutes): string
    {
        if ($status === 'never') {
            return 'error';
        }

        // Mildly late -> warning; badly late (>= 2x the threshold) -> error.
        return $ageMinutes !== null && $ageMinutes < 2 * $staleAfterMinutes ? 'warning' : 'error';
    }
}
