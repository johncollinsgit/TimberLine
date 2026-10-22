<?php

namespace App\Enterprise;

use Illuminate\Console\Command;

class VoicePruneCommand extends Command
{
    protected $signature = 'enterprise:voice-prune {--limit=100 : Bounded rows per invocation, maximum 1000}';

    protected $description = 'Scrub expired private voice content while retaining costs and audit evidence';

    public function handle(VoiceRetention $retention): int
    {
        $count = $retention->prune((int) $this->option('limit'));
        $this->info("Scrubbed {$count} expired voice content rows.");

        return self::SUCCESS;
    }
}
