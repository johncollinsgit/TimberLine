<?php

namespace App\Console\Commands;

use App\Jobs\SendPlatformEmail;
use App\Models\PlatformEmailDelivery;
use Illuminate\Console\Command;

class DispatchPlatformEmail extends Command
{
    protected $signature = 'email:ses-platform-dispatch {--limit=100 : Maximum queued intents to recover}';

    protected $description = 'Recover queued platform outbox records; never replays submitted or uncertain sends';

    public function handle(): int
    {
        if (! config('ses_migration.platform_enabled')) {
            return self::SUCCESS;
        }
        PlatformEmailDelivery::where('state', 'queued')->oldest('id')->limit(max(1, min(100, (int) $this->option('limit'))))
            ->each(fn ($delivery) => SendPlatformEmail::dispatch($delivery->id)->afterCommit());

        return self::SUCCESS;
    }
}
