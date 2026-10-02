<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Shopify\ModernForestryFundraiserInvoicePreparationService;
use App\Services\Shopify\ModernForestryFundraiserQuickBooksService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class PrepareModernForestryFundraiserMonthlyPackages extends Command
{
    protected $signature = 'modern-forestry:prepare-fundraiser-monthly-packages {--month= : Month to prepare (YYYY-MM; defaults to the prior calendar month)} {--send : Create and send the QuickBooks invoice when both production gates are enabled}';

    protected $description = 'Prepare idempotent monthly fundraiser packages and, when production gates are enabled, create or send the matching QuickBooks invoice.';

    public function handle(ModernForestryFundraiserInvoicePreparationService $preparation, ModernForestryFundraiserQuickBooksService $quickBooks): int
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
        if (! $this->option('send')) {
            $this->line('quickbooks=queued_for_manual_review');

            return self::SUCCESS;
        }
        if (! config('services.quickbooks.fundraiser_writes_enabled')) {
            $this->line('quickbooks=disabled');

            return self::SUCCESS;
        }
        foreach ($packages as $package) {
            $delivered = $quickBooks->createAndMaybeSend($package, (bool) $this->option('send'));
            $this->line('package='.$delivered->package_reference.' status='.$delivered->status);
        }

        return self::SUCCESS;
    }
}
