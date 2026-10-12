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
        {--calendar-week : Report the previous Monday through Sunday in America/New_York}
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

        $report = $summaryService->reportSnapshot($tenant, now(), $days, (bool) $this->option('calendar-week'));

        if ((bool) $this->option('dry-run')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (! in_array(config('mail.mailers.'.config('mail.default').'.transport'), ['smtp', 'sendmail', 'ses', 'ses-v2', 'mailgun', 'postmark', 'resend'], true) && ! app()->runningUnitTests()) {
            $this->error('Recap delivery requires a real mail transport.');

            return self::FAILURE;
        }
        $key = 'weekly-recap:'.$tenant->id.':'.sha1(strtolower($email).data_get($report, 'window.started_at').data_get($report, 'window.ended_at'));
        $lock = \Illuminate\Support\Facades\Cache::lock($key, 600);
        if (! $lock->get()) {
            $this->info('Recap is already being processed.');

            return self::SUCCESS;
        }
        try {
            if (\App\Models\MarketingEmailDelivery::query()->forTenantId((int) $tenant->id)->where('batch_id', $key)->whereIn('status', ['sending', 'sent'])->exists()) {
                $this->info('Recap was already sent or is awaiting delivery reconciliation.');

                return self::SUCCESS;
            }
            $delivery = \App\Models\MarketingEmailDelivery::query()->create([
                'tenant_id' => $tenant->id, 'email' => $email, 'batch_id' => $key,
                'campaign_type' => 'operator_recap', 'template_key' => 'everbranch_weekly_recap',
                'provider' => config('mail.default'), 'status' => 'sending',
                'metadata' => ['window' => $report['window']],
            ]);
            try {
                Mail::to($email)->send(new TenantRewardsWishlistWeeklySummaryMail($report));
                $delivery->update(['status' => 'sent', 'sent_at' => now()]);
            } catch (\Throwable $exception) {
                // Keep sending state: the provider may have accepted before the connection failed.
                report($exception);
                $this->error('Recap delivery is uncertain; reconcile before retrying.');

                return self::FAILURE;
            }
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'Sent %s rewards and wishlist summary to %s (%s).',
            $tenant->name,
            $email,
            data_get($report, 'health.label', 'complete')
        ));

        return self::SUCCESS;
    }
}
