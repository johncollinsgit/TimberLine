<?php

namespace App\Console\Commands;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Snapshot;
use App\Models\Trajectory\Space;
use App\Models\Trajectory\Transaction;
use App\Services\Trajectory\DashboardService;
use App\Services\Trajectory\SmsService;
use Illuminate\Console\Command;

class RefreshTrajectory extends Command
{
    protected $signature = 'trajectory:refresh {--notify : Send opted-in reminders through configured delivery gates}';

    protected $description = 'Store observed daily valuations and optionally deliver eligible financial reminders.';

    public function handle(DashboardService $dashboard, SmsService $sms): int
    {
        if (! config('trajectory.enabled')) {
            return self::SUCCESS;
        }
        foreach (Space::where('enabled', true)->cursor() as $space) {
            if (! app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($space->tenant_id, 'trajectory')) {
                continue;
            }
            $data = $dashboard->build($space);
            $today = now($space->timezone);
            if (count($data['accounts']) > 0) {
                $prior = Snapshot::where('space_id', $space->id)->where('observed_on', $today->copy()->subDay()->toDateString())->first();
                $expected = $prior?->data['next_day_cash_cents'] ?? null;
                Snapshot::updateOrCreate(['space_id' => $space->id, 'observed_on' => $today->toDateString()], ['data' => ['net_worth_cents' => $data['summary']['net_worth_cents'], 'cash_cents' => $data['summary']['cash_cents'], 'complete' => $data['summary']['net_worth_complete'], 'forecast_provisional' => $data['coverage']['provisional'], 'next_day_cash_cents' => $data['forecast']['daily'][0]['cash_cents'] ?? null, 'forecast_error_cents' => $expected === null ? null : $data['summary']['cash_cents'] - $expected]]);
            }
            if (! $this->option('notify')) {
                continue;
            }
            foreach (Record::where('space_id', $space->id)->where('kind', 'sms')->where('active', true)->get() as $preference) {
                $prefix = 'trajectory:'.$space->id.':'.$preference->id.':'.$today->toDateString();
                foreach ($data['bills'] as $bill) {
                    if ($bill['amount_cents'] < 0 && $bill['date'] === $today->copy()->addDays(3)->toDateString()) {
                        $sms->send($space, $preference, 'Upcoming: '.$bill['name'].' is due '.$bill['date'].'.', $prefix.':bill:'.$bill['record_id']);
                    }
                }
                if (! $data['coverage']['provisional'] && $data['forecast']['first_shortfall_on'] && $data['forecast']['first_shortfall_on'] <= $today->copy()->addDays(14)->toDateString()) {
                    $sms->send($space, $preference, 'Your plan projects a cash shortfall by '.$data['forecast']['first_shortfall_on'].'. Review Trajectory.', $prefix.':shortfall');
                }
                if ($today->isMonday()) {
                    $sms->send($space, $preference, 'Your Trajectory summary is ready. '.$data['summary']['review_count'].' transactions need review. Open Everbranch to see your cash forecast.', $prefix.':weekly');
                }
                $tx = Transaction::where('space_id', $space->id)->where('reviewed', false)->where('pending', false)->where('removed', false)->where('amount_cents', '<', 0)->latest('posted_on')->first();
                if ($tx) {
                    $sms->send($space, $preference, 'Review expense: '.$tx->merchant.' ($'.number_format(abs($tx->amount_cents) / 100, 2).').', 'trajectory:'.$space->id.':'.$preference->id.':review:'.$tx->id.':'.$tx->version, $tx);
                }
            }
        }

        return self::SUCCESS;
    }
}
