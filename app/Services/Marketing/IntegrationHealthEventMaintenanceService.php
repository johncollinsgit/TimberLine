<?php

namespace App\Services\Marketing;

use App\Models\IntegrationHealthEvent;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class IntegrationHealthEventMaintenanceService
{
    /**
     * @param  array<string,mixed>  $filters
     * @return array{cutoff:\Carbon\CarbonImmutable,matched:int,pruned:int,dry_run:bool}
     */
    public function pruneResolvedEvents(array $filters = []): array
    {
        $retentionDays = $this->retentionDays($filters['days'] ?? null);
        $cutoff = now()->subDays($retentionDays)->toImmutable();
        $dryRun = (bool) ($filters['dry_run'] ?? false);

        $query = $this->baseFilteredQuery($filters)
            ->where('status', 'resolved')
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<', $cutoff);

        $matched = (int) $query->count();
        $pruned = 0;
        if (! $dryRun && $matched > 0) {
            $pruned = (int) $query->delete();
        }

        return [
            'cutoff' => $cutoff,
            'matched' => $matched,
            'pruned' => $pruned,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param  array<string,mixed>  $filters
     * @return \Illuminate\Support\Collection<int,IntegrationHealthEvent>
     */
    public function listOpenEvents(array $filters = [], int $limit = 100)
    {
        $max = max(1, min(500, $limit));

        return $this->baseFilteredQuery($filters)
            ->where('status', 'open')
            ->when(($filters['severity'] ?? null) !== null, function (Builder $query) use ($filters): void {
                $severity = $this->normalizeToken($filters['severity'] ?? null);
                if ($severity !== null) {
                    $query->where('severity', $severity);
                }
            })
            ->when(($filters['event_type'] ?? null) !== null, function (Builder $query) use ($filters): void {
                $eventType = $this->normalizeToken($filters['event_type'] ?? null);
                if ($eventType !== null) {
                    $query->where('event_type', $eventType);
                }
            })
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->limit($max)
            ->get();
    }

    /**
     * @param  array<string,mixed>  $filters
     */
    protected function baseFilteredQuery(array $filters): Builder
    {
        $provider = $this->normalizeToken($filters['provider'] ?? null);
        $storeKey = $this->normalizeToken($filters['store_key'] ?? null);
        $tenantId = $this->positiveInt($filters['tenant_id'] ?? null);

        return IntegrationHealthEvent::query()
            ->when($provider !== null, fn (Builder $query) => $query->where('provider', $provider))
            ->when($storeKey !== null, fn (Builder $query) => $query->where('store_key', $storeKey))
            ->when($tenantId !== null, fn (Builder $query) => $query->where('tenant_id', $tenantId));
    }

    protected function retentionDays(mixed $daysOverride): int
    {
        $fallback = (int) config('marketing.integration_health.resolved_retention_days', 45);
        $normalizedFallback = max(1, min(3650, $fallback));

        if (! is_numeric($daysOverride)) {
            return $normalizedFallback;
        }

        return max(1, min(3650, (int) $daysOverride));
    }

    protected function normalizeToken(mixed $value): ?string
    {
        $token = strtolower(trim((string) $value));

        return $token !== '' ? $token : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $cast = (int) $value;

        return $cast > 0 ? $cast : null;
    }
}
