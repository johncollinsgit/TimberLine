<?php

namespace App\Console\Commands;

use App\Mail\TenantRewardsWishlistWeeklySummaryMail;
use App\Models\Tenant;
use App\Services\Marketing\TenantRewardsWishlistWeeklySummaryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MarketingSendWeeklyRewardsWishlistSummary extends Command
{
    protected $signature = 'marketing:send-weekly-rewards-wishlist-summary
        {--tenant=modern-forestry : Tenant slug or numeric tenant ID}
        {--email=info@theforestrystudio.com : Destination inbox for the report}
        {--days=7 : Rolling reporting window}
        {--dry-run : Build and print the report without sending email}';

    protected $description = 'Send a tenant-scoped weekly Candle Cash and wishlist health summary.';

    public function handle(TenantRewardsWishlistWeeklySummaryService $summaryService): int
    {
        $tenantReference = trim((string) $this->option('tenant'));
        $email = trim((string) $this->option('email'));
        $days = max(1, min(90, (int) $this->option('days')));

        $tenant = is_numeric($tenantReference)
            ? Tenant::query()->find((int) $tenantReference)
            : Tenant::query()->where('slug', $tenantReference)->first();

        if (! $tenant) {
            $this->error('The requested tenant could not be found.');

            return self::FAILURE;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('A valid destination email address is required.');

            return self::FAILURE;
        }

        $report = $summaryService->reportSnapshot($tenant, now(), $days);

        if ((bool) $this->option('dry-run')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        Mail::to($email)->send(new TenantRewardsWishlistWeeklySummaryMail($report));

        $this->info(sprintf(
            'Sent %s rewards and wishlist summary to %s (%s).',
            $tenant->name,
            $email,
            data_get($report, 'health.label', 'complete')
        ));

        return self::SUCCESS;
    }
}
