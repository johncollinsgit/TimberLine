<?php

namespace App\Services\Team\Studio;

use App\Models\MarketingImportRun;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Marketing\SquareSourceRefresh;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
        $runs = DB::connection()->getDriverName() === 'mysql' ? $this->ancestry($tenant, $id, $type) : null;
        for ($depth = 0; $id > 0 && $depth < 10000; $depth++) {
            if (isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $run = $runs === null ? MarketingImportRun::forTenantId($tenant)->where('type', $type)->find($id) : ($runs[$id] ?? null);
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
                // Earlier locations may have been read before later resumptions;
                // finishing after the offline window cannot repair that first read.
                if (! $run->started_at || $run->started_at->lt($end)) {
                    return null;
                }

                return $this->firstPageProven($tenant, $type, $summary) ? $binding : null;
            }
        }

        return null;
    }

    /** One coherent statement; node/head/root validation remains in run(). */
    private function ancestry(int $tenant, int $id, string $type): array
    {
        if ($id <= 0) {
            return [];
        }
        $parent = "CAST(JSON_UNQUOTE(JSON_EXTRACT(receipt.summary, '$.resumed_from_run_id')) AS SIGNED)";
        $rows = DB::select('WITH RECURSIVE square_proof_ancestry AS ('
            .'SELECT receipt.id, '.$parent.' AS parent_id FROM marketing_import_runs AS receipt '
            .'WHERE receipt.tenant_id = ? AND receipt.type = ? AND receipt.id = ? '
            .'UNION DISTINCT SELECT receipt.id, '.$parent.' AS parent_id FROM marketing_import_runs AS receipt '
            .'INNER JOIN square_proof_ancestry AS child ON receipt.id = child.parent_id '
            .'WHERE receipt.tenant_id = ? AND receipt.type = ? LIMIT 10001) '
            .'SELECT /*+ SET_VAR(cte_max_recursion_depth=10001) */ receipt.id, receipt.tenant_id, receipt.type, receipt.status, receipt.started_at, receipt.summary '
            .'FROM marketing_import_runs AS receipt INNER JOIN square_proof_ancestry AS chain ON chain.id = receipt.id '
            .'WHERE receipt.tenant_id = ? AND receipt.type = ?', [$tenant, $type, $id, $tenant, $type, $tenant, $type]);
        if (count($rows) > 10000) {
            return [];
        }

        return MarketingImportRun::hydrate(array_map(fn ($row) => (array) $row, $rows))->keyBy('id')->all();
    }

    private function firstPageProven(int $tenant, string $type, array $summary): bool
    {
        if (array_key_exists('initial_cursor', $summary)) {
            return $summary['initial_cursor'] === null;
        }
        // Older immutable runs bind their original starting cursor in the intent hash.
        // Recompute only the null-cursor form; never infer first-page coverage from EOF.
        if (! isset($summary['intent_hash'], $summary['source_binding'], $summary['since']) || ! array_key_exists('limit', $summary)) {
            return false;
        }
        $binding = $summary['source_binding'];
        $known = ['environment', 'merchant_id', 'location_ids', 'connection_id', 'runtime_id', 'connection_generation', 'source_purpose'];
        if (! is_array($binding) || array_diff(array_keys($binding), $known)
            || isset($summary['provider_page_limit']) && (! is_int($summary['provider_page_limit']) || $summary['provider_page_limit'] < 1 || $summary['provider_page_limit'] > 100)) {
            return false;
        }
        if ($this->matchesNullCursorIntent($type, $summary, $binding)) {
            return true;
        }

        // The pre-initial_cursor builder (abe465494) hashed insertion order.
        // MySQL JSON sorts object keys. Rebuild only that original reader's
        // proven public projection, with fresh local ownership/policy checks.
        // Never sort arbitrary permutations or alter the retained intent hash.
        $context = app(SquareConnectionContext::class);
        $reader = collect($context->available($tenant))->firstWhere('id', $binding['connection_id'] ?? '');
        if (! $reader || ! in_array($reader['kind'] ?? '', ['original_square', 'original_merchant_verified_legacy_reader'], true)) {
            return false;
        }
        $projection = ['environment' => $reader['environment'] ?? null, 'merchant_id' => $reader['merchant_id'] ?? null,
            'location_ids' => $reader['location_ids'] ?? [$reader['location_id'] ?? null], 'connection_id' => $reader['id'],
            'runtime_id' => (string) config('pos_live.runtime_id', ''), 'connection_generation' => $reader['connection_generation'] ?? null];
        if (! empty($summary['source_refresh_policy_version'])) {
            $projection = app(SquareSourceRefresh::class)->configForPolicy($tenant, $summary['source_refresh_policy_version'], $projection);
        }
        $projection = $context->publicBinding($projection);
        $stored = $binding;
        $trusted = $projection;
        ksort($stored);
        ksort($trusted);
        if ($stored !== $trusted) {
            return false;
        }
        $context->assertCurrent($tenant, $projection);

        return $this->matchesNullCursorIntent($type, $summary, $projection);
    }

    private function matchesNullCursorIntent(string $type, array $summary, array $binding): bool
    {
        $intent = [$type, $binding, $summary['limit'], $summary['since'], null, false,
            (bool) ($summary['create_unmatched'] ?? false), (bool) ($summary['historical_source_only'] ?? false)];
        if (! empty($summary['request_context'])) {
            $intent[] = $summary['request_context'];
        }
        if (array_key_exists('provider_page_limit', $summary)) {
            $intent[] = ['provider_page_limit' => $summary['provider_page_limit']];
        }

        return hash_equals((string) $summary['intent_hash'], hash('sha256', json_encode($intent, JSON_THROW_ON_ERROR)));
    }
}
