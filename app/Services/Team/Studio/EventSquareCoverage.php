<?php

namespace App\Services\Team\Studio;

use App\Models\MarketingImportRun;
use App\Services\Marketing\SquareConnectionContext;
use Carbon\CarbonImmutable;

/** A manager checkbox cannot substitute for original provider pagination evidence. */
class EventSquareCoverage
{
    public function proven(int $tenant, array $scope, string $timezone): bool
    {
        try {
            $start = CarbonImmutable::parse($scope['starts_on'], $timezone)->startOfDay()->utc();
            // Square may deliver offline transactions up to 72 hours later.
            $end = CarbonImmutable::parse($scope['ends_on'], $timezone)->addDay()->startOfDay()->utc()->addHours(72);
            $orders = $this->run($tenant, (int) ($scope['orders_run_id'] ?? 0), 'square_orders_sync', $scope['location_id'], $start, $end);
            $payments = $this->run($tenant, (int) ($scope['payments_run_id'] ?? 0), 'square_payments_sync', $scope['location_id'], $start, $end);
            if (! $orders || ! $payments || $orders !== $payments) {
                return false;
            }
            app(SquareConnectionContext::class)->assertCurrent($tenant, $orders);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function run(int $tenant, int $id, string $type, string $location, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $seen = [];
        $binding = null;
        for ($depth = 0; $id > 0 && $depth < 10000; $depth++) {
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $run = MarketingImportRun::forTenantId($tenant)->where('type', $type)->find($id);
            $summary = $run?->summary ?? [];
            $currentBinding = $summary['source_binding'] ?? [];
            if (! $run || ! in_array($run->status, ['completed', 'partial'], true) || ($summary['mode'] ?? '') !== 'live-sync'
                || (int) ($summary['errors'] ?? 0) !== 0 || (int) data_get($summary, 'checkpoint.errors', 0) !== 0
                || ($summary['request_context']['date_field'] ?? '') === 'updated_at'
                || ! isset($summary['since']) || CarbonImmutable::parse($summary['since'])->gt($start)
                || ! in_array($location, $currentBinding['location_ids'] ?? [], true)
                || ($currentBinding['environment'] ?? '') !== 'production' || empty($currentBinding['merchant_id'])
                || empty($currentBinding['connection_id']) || ! isset($currentBinding['connection_generation'])) {
                return null;
            }
            if ($depth === 0 && ($run->status !== 'completed' || data_get($summary, 'checkpoint.cursor') || ! $run->started_at || $run->started_at->lt($end))) {
                return null;
            }
            if ($binding !== null && $binding !== $currentBinding) {
                return null;
            }
            $binding = $currentBinding;
            $id = (int) ($summary['resumed_from_run_id'] ?? 0);
            if ($id === 0) {
                // A non-resumed import must have begun at the first provider page.
                return $this->firstPageProven($type, $summary) ? $binding : null;
            }
        }

        return null;
    }

    private function firstPageProven(string $type, array $summary): bool
    {
        if (array_key_exists('initial_cursor', $summary)) {
            return $summary['initial_cursor'] === null;
        }
        // Older immutable runs bind their original starting cursor in the intent hash.
        // Recompute only the null-cursor form; never infer first-page coverage from EOF.
        if (! isset($summary['intent_hash'], $summary['source_binding'], $summary['since']) || ! array_key_exists('limit', $summary)) {
            return false;
        }
        $intent = [$type, $summary['source_binding'], $summary['limit'], $summary['since'], null, false,
            (bool) ($summary['create_unmatched'] ?? false), (bool) ($summary['historical_source_only'] ?? false)];
        if (! empty($summary['request_context'])) {
            $intent[] = $summary['request_context'];
        }
        if (hash_equals((string) $summary['intent_hash'], hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)))) {
            return true;
        }

        return isset($summary['provider_page_limit']) && hash_equals((string) $summary['intent_hash'], hash('sha256', json_encode([...$intent, ['provider_page_limit' => $summary['provider_page_limit']]], JSON_THROW_ON_ERROR)));
    }
}
