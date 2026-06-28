<?php

namespace App\Console\Commands;

use App\Mail\ModernForestryScentQuizWeeklyReportMail;
use App\Services\Marketing\ModernForestryScentQuizAnalyticsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class MarketingSendModernForestryScentQuizReport extends Command
{
    protected $signature = 'marketing:send-modern-forestry-scent-quiz-report
        {--days=7 : Rolling recent window to summarize}
        {--email=info@theforestrystudio.com : Destination inbox for the report}
        {--dry-run : Build the report without sending email}';

    protected $description = 'Send the Modern Forestry scent quiz weekly summary email.';

    public function handle(ModernForestryScentQuizAnalyticsService $analyticsService): int
    {
        $days = max(1, (int) $this->option('days'));
        $email = trim((string) $this->option('email'));
        $dryRun = (bool) $this->option('dry-run');

        if ($email === '') {
            $this->error('A destination email address is required.');

            return self::FAILURE;
        }

        $report = $analyticsService->reportSnapshot(1, now(), $days);

        if ($dryRun) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        Mail::to($email)->send(new ModernForestryScentQuizWeeklyReportMail($report));

        $this->info(sprintf(
            'Sent Modern Forestry scent quiz report to %s (%d recent takers, %d total takers).',
            $email,
            (int) data_get($report, 'quiz.recent_takers', 0),
            (int) data_get($report, 'quiz.total_takers', 0)
        ));

        return self::SUCCESS;
    }
}
