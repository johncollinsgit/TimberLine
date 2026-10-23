<?php

namespace App\Enterprise\Conversation;

use Illuminate\Console\Command;

class MemoryPruneCommand extends Command
{
    protected $signature = 'enterprise:assistant-memory-prune {--limit=100 : Bounded expired private memories, maximum 1000}';

    protected $description = 'Scrub expired private assistant memory while retaining content-free audit and costs';

    public function handle(MemoryRetention $retention): int
    {
        abort_unless(config('enterprise.runtime') === true && \App\Http\Middleware\EnsureEnterpriseRuntime::configured(), 503);
        app(\App\Enterprise\Provisioning\ClientSilo::class)->assertReady();
        $count = $retention->prune((int) $this->option('limit'));
        $this->info("Scrubbed {$count} expired private memory rows.");

        return self::SUCCESS;
    }
}
