<?php

namespace App\Console\Commands;

use App\Services\Team\Operations\OperationsReminders;
use Illuminate\Console\Command;

class RemindTeamOperationsCommand extends Command
{
    protected $signature = 'team:remind-operations';

    protected $description = 'Create permission-filtered internal operation reminders from reviewed rules';

    public function handle(OperationsReminders $service): int
    {
        $service->run();

        return self::SUCCESS;
    }
}
