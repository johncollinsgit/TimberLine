<?php

namespace App\Console\Commands;

use App\Services\MarketEventSyncCoordinator;
use Illuminate\Console\Command;

class MarketsSyncEvents extends Command
{
    protected $signature = 'markets:sync-events {--weeks=8 : Number of weeks ahead to sync} {--force : Bypass cooldown}';

    protected $description = 'Sync upcoming market events from the Asana/Google Calendar feed into the events table.';

    public function handle(MarketEventSyncCoordinator $coordinator): int
    {
        $weeks = max(1, (int) $this->option('weeks'));
        $force = (bool) $this->option('force');

        $this->line("Starting market event sync (weeks={$weeks}, force=".($force ? 'yes' : 'no').')');

        $result = $coordinator->runSync(
            weeks: $weeks,
            force: $force,
            trigger: 'command'
        );

        $state = (array) ($result['state'] ?? []);
        $status = (string) ($result['status'] ?? 'unknown');

        if (($result['ok'] ?? false) === true) {
            $payload = (array) ($result['result'] ?? []);
            $this->info('Sync complete.');
            $this->line('fetched='.(int) ($payload['fetched'] ?? 0));
            $this->line('upserted='.(int) ($payload['upserted'] ?? 0));
            $this->line('last_sync_at='.(string) ($state['last_sync_at'] ?? ''));

            return self::SUCCESS;
        }

        if ($status === 'cooldown') {
            $this->warn('Sync skipped due to cooldown (use --force to bypass).');
            $this->line('last_sync_at='.(string) ($state['last_sync_at'] ?? ''));

            return self::SUCCESS;
        }

        if ($status === 'running') {
            $this->warn('Sync is already running.');

            return self::SUCCESS;
        }

        if ($status === 'missing_table') {
            $this->warn('Sync state table is missing. Run migrations first.');

            return self::FAILURE;
        }

        $this->error('Sync failed: '.(string) ($result['error'] ?? $state['last_error'] ?? 'unknown error'));

        return $status === 'disabled' ? self::SUCCESS : self::FAILURE;
    }
}
