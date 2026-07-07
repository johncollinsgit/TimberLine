<?php

namespace App\Console\Commands;

use App\Services\Operations\OperationalStatusService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Stamps the "last database backup" timestamp surfaced on the landlord Developer
 * Control Center. Intended to be called from the Forge database-backup completion
 * hook (Server > Database > Backups > "run a command after backup") or a scheduled
 * task, so the operator can see, at a glance, that backups are actually running.
 */
class OpsRecordBackup extends Command
{
    protected $signature = 'ops:record-backup {--at= : ISO-8601 timestamp of the backup (defaults to now)}';

    protected $description = 'Record that a database backup completed (feeds the operator dashboard "Last backup" widget).';

    public function handle(): int
    {
        $at = $this->option('at')
            ? CarbonImmutable::parse((string) $this->option('at'))
            : CarbonImmutable::now();

        Cache::forever(OperationalStatusService::LAST_BACKUP_CACHE_KEY, $at->toIso8601String());

        $this->info('Recorded last backup at '.$at->toDayDateTimeString().'.');

        return self::SUCCESS;
    }
}
