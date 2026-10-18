<?php

namespace App\Console\Commands;

use App\Services\Billing\TenantUsageReceiptService;
use Illuminate\Console\Command;

class BillingDispatchUsageReceipts extends Command
{
    protected $signature = 'billing:dispatch-usage-receipts {--limit=50}';

    protected $description = 'Deliver pending usage statements for verified live payments; never resend ambiguous deliveries';

    public function handle(TenantUsageReceiptService $service): int
    {
        $this->info('Sent '.$service->dispatchPending((int) $this->option('limit')).' usage receipts.');

        return self::SUCCESS;
    }
}
