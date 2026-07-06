<?php

namespace App\Services\Operations;

use App\Models\IntegrationHealthEvent;
use App\Models\ShopifyImportRun;
use App\Services\SchedulerHeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Read-only aggregator of live operational signals for the landlord Developer
 * Control Center. Every probe is wrapped in rescue() so a single failing source
 * never blanks the operator dashboard.
 */
class OperationalStatusService
{
    /**
     * Cache key stamped by `php artisan ops:record-backup` (wire it to the Forge
     * database-backup completion hook). Read here to show "Last backup".
     */
    public const LAST_BACKUP_CACHE_KEY = 'ops:last_backup_at';

    public function __construct(private readonly SchedulerHeartbeatService $heartbeat) {}

    /**
     * @return array<string, mixed>
     */
    public function snapshot(): array
    {
        return [
            'scheduler' => $this->schedulerStatus(),
            'backup' => $this->backupStatus(),
            'issues' => $this->openIssues(),
            'import' => $this->lastImport(),
            'generated_at' => CarbonImmutable::now(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function schedulerStatus(): array
    {
        $threshold = (int) config('scheduler_heartbeat.threshold_minutes', 10);
        $age = rescue(fn (): ?int => $this->heartbeat->minutesSinceHeartbeat(), null, false);
        $lastAt = rescue(fn (): ?CarbonImmutable => $this->heartbeat->lastHeartbeatAt(), null, false);

        return [
            'online' => $age !== null && $age <= $threshold,
            'known' => $age !== null,
            'age_minutes' => $age,
            'last_at' => $lastAt,
            'threshold_minutes' => $threshold,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function backupStatus(): array
    {
        $raw = rescue(fn () => Cache::get(self::LAST_BACKUP_CACHE_KEY), null, false);
        $at = $raw ? rescue(fn (): CarbonImmutable => CarbonImmutable::parse($raw), null, false) : null;

        return [
            'at' => $at,
            'reported' => $at !== null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function openIssues(): array
    {
        $total = rescue(
            fn (): int => IntegrationHealthEvent::query()->where('status', 'open')->count(),
            0,
            false
        );

        $bySeverity = rescue(
            fn (): array => IntegrationHealthEvent::query()
                ->where('status', 'open')
                ->selectRaw('severity, count(*) as aggregate')
                ->groupBy('severity')
                ->pluck('aggregate', 'severity')
                ->toArray(),
            [],
            false
        );

        $recent = rescue(
            fn () => IntegrationHealthEvent::query()
                ->where('status', 'open')
                ->latest('occurred_at')
                ->limit(6)
                ->get(['id', 'provider', 'event_type', 'severity', 'store_key', 'occurred_at']),
            collect(),
            false
        );

        return [
            'total' => $total,
            'by_severity' => $bySeverity,
            'recent' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function lastImport(): array
    {
        $run = rescue(
            fn () => ShopifyImportRun::query()
                ->where('is_dry_run', false)
                ->whereNotNull('finished_at')
                ->latest('finished_at')
                ->first(),
            null,
            false
        );

        return [
            'at' => $run?->finished_at ? CarbonImmutable::parse($run->finished_at) : null,
            'store' => $run?->store_key,
            'imported' => (int) ($run?->imported_count ?? 0),
        ];
    }
}
