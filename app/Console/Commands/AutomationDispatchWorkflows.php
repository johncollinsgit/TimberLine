<?php

namespace App\Console\Commands;

use App\Jobs\RunAutomationWorkflowJob;
use App\Models\AutomationWorkflow;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class AutomationDispatchWorkflows extends Command
{
    protected $signature = 'automation:dispatch {--sync : Run jobs synchronously for diagnostics}';

    protected $description = 'Dispatch one tenant-scoped job for each active published workflow.';

    public function handle(): int
    {
        if (! Schema::hasTable('automation_workflows')) {
            $this->warn('Productized workflow tables are not installed; using the legacy runner.');

            return $this->call('automation:run');
        }

        $count = 0;
        AutomationWorkflow::query()->forAllTenants()
            ->where('status', AutomationWorkflow::STATUS_ACTIVE)
            ->whereNotNull('published_version_id')
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$count): void {
                $job = new RunAutomationWorkflowJob((int) $id);
                (bool) $this->option('sync') ? dispatch_sync($job) : dispatch($job);
                $count++;
            });

        $this->info("Dispatched {$count} workflow(s).");

        return self::SUCCESS;
    }
}
