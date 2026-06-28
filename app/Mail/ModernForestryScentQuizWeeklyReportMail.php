<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class ModernForestryScentQuizWeeklyReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $report
     */
    public function __construct(
        public array $report
    ) {}

    public function build(): self
    {
        $recentDays = (int) ($this->report['recent_window_days'] ?? 7);
        $recentTakers = (int) data_get($this->report, 'quiz.recent_takers', 0);
        $totalTakers = (int) data_get($this->report, 'quiz.total_takers', 0);

        return $this->subject(sprintf(
            'Modern Forestry scent quiz report: %d recent takers, %d total',
            $recentTakers,
            $totalTakers
        ))
            ->view('emails.marketing.modern-forestry-scent-quiz-weekly-report')
            ->with([
                'report' => $this->report,
                'recentDays' => $recentDays,
            ]);
    }
}
