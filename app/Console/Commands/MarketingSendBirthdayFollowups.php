<?php

namespace App\Console\Commands;

use App\Services\Marketing\BirthdayEmailFollowupService;
use Illuminate\Console\Command;

class MarketingSendBirthdayFollowups extends Command
{
    protected $signature = 'marketing:send-birthday-followups
        {--tenant-id= : Restrict sends to a tenant id}
        {--limit=500 : Maximum eligible rewards to evaluate}
        {--dry-run : Evaluate only without sending}';

    protected $description = 'Send one consent-gated birthday reward reminder before a code expires.';

    public function handle(BirthdayEmailFollowupService $followups): int
    {
        $tenantId = is_numeric($this->option('tenant-id')) ? (int) $this->option('tenant-id') : 0;
        if ($tenantId <= 0) {
            $this->error('Missing required --tenant-id. Birthday follow-ups are tenant-scoped.');

            return self::FAILURE;
        }

        $summary = $followups->dispatchDue($tenantId, max(1, (int) $this->option('limit')), (bool) $this->option('dry-run'));
        foreach ($summary as $key => $value) {
            $this->line($key.'='.$value);
        }

        return self::SUCCESS;
    }
}
