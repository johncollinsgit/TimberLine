<?php

namespace App\Console\Commands;

use App\Services\Mobile\ModernForestryMobileBagReminderService;
use Illuminate\Console\Command;

class MarketingSendModernForestryBagReminders extends Command
{
    protected $signature = 'marketing:send-modern-forestry-bag-reminders
        {--tenant=1 : Tenant id to process}
        {--limit=100 : Maximum reminders to send}';

    protected $description = 'Send scheduled Modern Forestry mobile bag reminder emails.';

    public function handle(ModernForestryMobileBagReminderService $service): int
    {
        $tenantId = (int) $this->option('tenant');
        $limit = max(1, (int) $this->option('limit'));
        $result = $service->sendDueReminders($tenantId > 0 ? $tenantId : null, $limit);

        $this->info(sprintf(
            'Modern Forestry bag reminders sent: %d, skipped: %d',
            (int) ($result['sent'] ?? 0),
            (int) ($result['skipped'] ?? 0)
        ));

        return self::SUCCESS;
    }
}
