<?php

namespace App\Console\Commands;

use App\Models\HighLevel\Authorization;
use App\Services\HighLevel\InstallationService;
use Illuminate\Console\Command;
use Throwable;

class ReconcileHighLevelFleet extends Command
{
    protected $signature = 'highlevel:fleet-reconcile';

    protected $description = 'Recover incomplete bulk installations from HighLevel-authoritative installed accounts.';

    public function handle(InstallationService $service): int
    {
        if (! config('highlevel.enabled')) {
            return self::SUCCESS;
        }
        $failed = false;
        foreach (Authorization::where('app_id', config('highlevel.app_id'))->where('status', 'authorized')->cursor() as $agency) {
            try {
                $service->synchronize($agency, recoverOnly: true);
            } catch (Throwable) {
                // Never report provider responses or credentials through scheduler logs.
                $this->error('Agency installation reconciliation failed; the next scheduled run will retry.');
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
