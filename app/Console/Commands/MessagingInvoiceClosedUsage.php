<?php

namespace App\Console\Commands;

use App\Services\Billing\MessagingUsageInvoiceService;
use Illuminate\Console\Command;

class MessagingInvoiceClosedUsage extends Command
{
    protected $signature = 'messaging:invoice-closed-usage
        {--tenant= : Limit invoicing to one tenant slug}
        {--send : Finalize and send eligible Stripe invoices instead of leaving drafts}';

    protected $description = 'Create idempotent invoices for messaging usage above accepted monthly allowances.';

    public function handle(MessagingUsageInvoiceService $invoices): int
    {
        $summary = $invoices->invoiceClosedPeriods(
            tenantSlug: filled($this->option('tenant')) ? (string) $this->option('tenant') : null,
            send: (bool) $this->option('send'),
        );

        foreach (['groups', 'drafts_created', 'invoices_sent', 'blocked', 'failures'] as $key) {
            $this->line($key.'='.$summary[$key]);
        }
        $this->line('invoice_ids='.implode(',', $summary['invoice_ids']));
        foreach ($summary['errors'] as $error) {
            $this->error($error);
        }

        return $summary['failures'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
