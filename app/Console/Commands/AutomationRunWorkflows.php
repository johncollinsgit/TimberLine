<?php

namespace App\Console\Commands;

use App\Services\Automation\AutomationWorkflowEngine;
use Illuminate\Console\Command;

class AutomationRunWorkflows extends Command
{
    protected $signature = 'automation:run {--workflow= : Optional workflow key to run} {--dry-run : Evaluate without writing links or state}';

    protected $description = 'Run configured automation workflows (Zap-style internal automations).';

    public function handle(AutomationWorkflowEngine $engine): int
    {
        $workflow = $this->option('workflow');
        $workflow = is_string($workflow) && trim($workflow) !== '' ? trim($workflow) : null;
        $dryRun = (bool) $this->option('dry-run');

        $result = $engine->run($workflow, $dryRun);

        $this->line('status='.(string) ($result['status'] ?? 'unknown'));
        $this->line('dry_run='.(($result['dry_run'] ?? false) ? 'true' : 'false'));

        $workflows = is_array($result['workflows'] ?? null) ? (array) $result['workflows'] : [];
        foreach ($workflows as $key => $workflowResult) {
            if (! is_array($workflowResult)) {
                continue;
            }

            $this->line(sprintf(
                'workflow=%s ok=%s status=%s',
                (string) $key,
                (bool) ($workflowResult['ok'] ?? false) ? 'true' : 'false',
                (string) ($workflowResult['status'] ?? 'unknown')
            ));

            $counts = is_array($workflowResult['counts'] ?? null) ? (array) $workflowResult['counts'] : [];
            if ($counts !== []) {
                $this->line(sprintf(
                    'counts fetched=%d processed=%d created=%d updated=%d unchanged=%d skipped=%d failed=%d',
                    (int) ($counts['fetched'] ?? 0),
                    (int) ($counts['processed'] ?? 0),
                    (int) ($counts['created'] ?? 0),
                    (int) ($counts['updated'] ?? 0),
                    (int) ($counts['unchanged'] ?? 0),
                    (int) ($counts['skipped'] ?? 0),
                    (int) ($counts['failed'] ?? 0)
                ));
            }

            $dryRunCounts = is_array($workflowResult['dry_run_counts'] ?? null) ? (array) $workflowResult['dry_run_counts'] : [];
            if ($dryRunCounts !== []) {
                $this->line(sprintf(
                    'dry_run_counts would_create=%d would_update=%d',
                    (int) ($dryRunCounts['would_create'] ?? 0),
                    (int) ($dryRunCounts['would_update'] ?? 0)
                ));
            }

            $message = trim((string) ($workflowResult['message'] ?? ''));
            if ($message !== '') {
                $this->line('message='.$message);
            }
        }

        return (bool) ($result['ok'] ?? false) ? self::SUCCESS : self::FAILURE;
    }
}
