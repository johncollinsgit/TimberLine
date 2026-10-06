<?php

namespace App\Console\Commands;

use App\Services\Billing\TenantAiUsageInvoiceService;
use Illuminate\Console\Command;

class AiInvoiceClosedUsage extends Command
{
    protected $signature = 'ai:invoice-closed-usage {--tenant= : Limit invoicing to one tenant slug} {--send : Send eligible Stripe invoices instead of leaving drafts}';

    protected $description = 'Create idempotent monthly tenant invoices for settled paid AI usage.';

    public function handle(TenantAiUsageInvoiceService $invoices): int
    {
        $summary = $invoices->invoiceClosedMonths(
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
