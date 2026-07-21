<?php

namespace App\Console\Commands;

use App\Services\Operations\OperatorAlertService;
use App\Services\Operations\OperatorDashboardService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendOperatorWeeklySnapshot extends Command
{
    protected $signature = 'operator:send-weekly-snapshot';
    protected $description = 'Send the Everbranch weekly operator snapshot by email and SMS';

    public function handle(OperatorDashboardService $dashboard, OperatorAlertService $alerts): int
    {
        $snapshot = $dashboard->snapshot();
        $text = sprintf(
            "Everbranch weekly snapshot\nRevenue YTD: $%s\nRecurring cost/week: $%s\nPaying workspaces: %d\nBreak-even: %s\nOpen tickets: %d\nBud reviews: %d",
            number_format(($snapshot['ytd_revenue_cents'] ?? 0) / 100, 2),
            number_format(($snapshot['weekly_cost_cents'] ?? 0) / 100, 2),
            $snapshot['active_paying_tenants'] ?? 0,
            isset($snapshot['break_even_clients']) ? $snapshot['break_even_clients'].' clients' : 'needs revenue data',
            $snapshot['open_tickets'] ?? 0,
            collect($snapshot['bud_pending'] ?? [])->count(),
        );
        Mail::raw($text."\n\nOpen Everbranch Admin for the workspace usage and receipt ledger.", function ($mail): void {
            $mail->to((string) config('everbranch.operator_report_email'))->subject('Everbranch weekly snapshot');
        });
        $alerts->notify('operator.weekly_snapshot', $text, ['dedupe_key' => 'weekly-snapshot:'.now()->startOfWeek()->toDateString()]);
        $this->info('Weekly operator snapshot sent.');
        return self::SUCCESS;
    }
}
