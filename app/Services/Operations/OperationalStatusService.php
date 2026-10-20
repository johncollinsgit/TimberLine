<?php

namespace App\Services\Operations;

use App\Models\IntegrationHealthEvent;
use App\Models\ShopifyImportRun;
use App\Services\SchedulerHeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only aggregator of live operational signals for the landlord Developer
 * Control Center. Probes isolate failures so one source never blanks the dashboard.
 * Issue-read failures are unknown; only successful-check provenance is cached.
 */
class OperationalStatusService
{
    /**
     * Cache key stamped by `php artisan ops:record-backup` (wire it to the Forge
     * database-backup completion hook). Read here to show "Last backup".
     */
    public const LAST_BACKUP_CACHE_KEY = 'ops:last_backup_at';

    public const LAST_ISSUES_SUCCESS_CACHE_KEY = 'ops:integration_health:last_success_at';

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
        $checkedAt = CarbonImmutable::now();
        $lastSuccess = rescue(fn () => Cache::get(self::LAST_ISSUES_SUCCESS_CACHE_KEY), null, false);
        $lastSuccessAt = $lastSuccess ? rescue(fn () => CarbonImmutable::parse($lastSuccess), null, false) : null;

        try {
            // Treat the three reads as one observation: partial results are not a clean bill of health.
            $total = IntegrationHealthEvent::query()->where('status', 'open')->count();
            $bySeverity = IntegrationHealthEvent::query()->where('status', 'open')
                ->selectRaw('severity, count(*) as aggregate')->groupBy('severity')
                ->pluck('aggregate', 'severity')->toArray();
            $recent = IntegrationHealthEvent::query()->where('status', 'open')
                ->latest('occurred_at')->limit(6)
                ->get(['id', 'provider', 'event_type', 'severity', 'store_key', 'occurred_at']);
        } catch (Throwable $exception) {
            // Do not log SQL, bindings, credentials or exception messages from this probe.
            rescue(fn () => Log::warning('operations.integration_health.read_failed', [
                'error_code' => 'integration_health_read_failed',
                'exception_type' => class_basename($exception),
            ]), null, false);

            return [
                'known' => false, 'total' => null, 'by_severity' => [], 'recent' => collect(),
                'error_code' => 'integration_health_read_failed', 'checked_at' => $checkedAt,
                'last_success_at' => $lastSuccessAt,
            ];
        }

        // Provenance only; a cache failure must not invalidate a successful database observation.
        rescue(fn () => Cache::put(self::LAST_ISSUES_SUCCESS_CACHE_KEY, $checkedAt->toIso8601String(), now()->addDays(7)), null, false);

        return [
            'known' => true, 'total' => $total, 'by_severity' => $bySeverity, 'recent' => $recent,
            'error_code' => null, 'checked_at' => $checkedAt, 'last_success_at' => $checkedAt,
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
