<?php

namespace App\Console\Commands;

use App\Jobs\RunAutomationWorkflowJob;
use App\Models\AutomationWorkflow;
use App\Services\Automation\V2\WorkflowStudioRuntimeAccess;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AutomationDispatchWorkflows extends Command
{
    protected $signature = 'automation:dispatch {--sync : Run jobs synchronously for diagnostics}';

    protected $description = 'Dispatch one tenant-scoped job for each active published workflow.';

    public function handle(WorkflowStudioRuntimeAccess $runtimeAccess): int
    {
        if (! Schema::hasTable('automation_workflows')) {
            $this->warn('Productized workflow tables are not installed; using the legacy runner.');

            return $this->call('automation:run');
        }

        $count = 0;
        AutomationWorkflow::query()->forAllTenants()
            ->where('status', AutomationWorkflow::STATUS_ACTIVE)
            ->whereNotNull('published_version_id')
            ->where(function ($query): void {
                $query->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            })
            ->orderBy('id')
            ->pluck('id')
            ->each(function ($id) use (&$count, $runtimeAccess): void {
                $workflow = DB::transaction(function () use ($id, $runtimeAccess): ?AutomationWorkflow {
                    $workflow = AutomationWorkflow::query()
                        ->forAllTenants()
                        ->with('publishedVersion')
                        ->whereKey((int) $id)
                        ->where('status', AutomationWorkflow::STATUS_ACTIVE)
                        ->whereNotNull('published_version_id')
                        ->where(function ($query): void {
                            $query->whereNull('next_run_at')
                                ->orWhere('next_run_at', '<=', now());
                        })
                        ->lockForUpdate()
                        ->first();
                    if (! $workflow) {
                        return null;
                    }

                    $schemaVersion = (int) data_get(
                        $workflow->publishedVersion?->definition,
                        'schema_version',
                        1,
                    );
                    if (
                        $schemaVersion === 2
                        && ! $runtimeAccess->allows((int) $workflow->tenant_id)
                    ) {
                        // Keep the workflow due. Restoring its rollout or
                        // entitlement makes it eligible without retry storms
                        // or silently advancing its polling cadence.
                        return null;
                    }

                    $interval = (int) data_get(
                        $workflow->publishedVersion?->definition,
                        'settings.poll_interval_minutes',
                        config('automation_workflows.default_poll_interval_minutes', 10),
                    );
                    $workflow->forceFill([
                        'next_run_at' => now()->addMinutes(max(1, min(1440, $interval))),
                    ])->save();

                    return $workflow;
                });
                if (! $workflow) {
                    return;
                }

                $job = new RunAutomationWorkflowJob((int) $workflow->id);
                (bool) $this->option('sync') ? dispatch_sync($job) : dispatch($job);
                $count++;
            });

        $this->info("Dispatched {$count} workflow(s).");

        return self::SUCCESS;
    }
}
