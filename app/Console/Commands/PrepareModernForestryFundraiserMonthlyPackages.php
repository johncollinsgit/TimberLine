<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Shopify\ModernForestryFundraiserInvoicePreparationService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PrepareModernForestryFundraiserMonthlyPackages extends Command
{
    protected $signature = 'modern-forestry:prepare-fundraiser-monthly-packages {--month= : Month to prepare (YYYY-MM; defaults to the prior calendar month)}';

    protected $description = 'Prepare idempotent monthly fundraiser packages for manual QuickBooks review and send.';

    public function handle(ModernForestryFundraiserInvoicePreparationService $preparation): int
    {
        $month = $this->option('month')
            ? CarbonImmutable::createFromFormat('!Y-m', (string) $this->option('month'))
            : CarbonImmutable::now('America/New_York')->startOfMonth()->subMonth();
        if (! $month) {
            $this->error('The --month value must use YYYY-MM.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
        $packages = $preparation->prepareApprovedMonth($tenant, $month, 'scheduled_monthly_fundraiser_review');
        $this->line('packages_prepared='.count($packages));
        $this->line('quickbooks=queued_for_manual_review');

        return self::SUCCESS;
    }
}
