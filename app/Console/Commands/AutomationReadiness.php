<?php

namespace App\Console\Commands;

use App\Services\Automation\WorkflowAutomationReadinessService;
use Illuminate\Console\Command;

class AutomationReadiness extends Command
{
    protected $signature = 'automation:readiness {--json : Emit machine-readable JSON}';

    protected $description = 'Check OAuth, scheduler, queue, encryption, and database gates for workflow automations.';

    public function handle(WorkflowAutomationReadinessService $readiness): int
    {
        $result = $readiness->evaluate();
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            foreach ($result['checks'] as $key => $check) {
                $this->line(sprintf('[%s] %s: %s', $check['ready'] ? 'ready' : 'blocked', str($key)->headline(), $check['message']));
            }
        }

        return $result['ready'] ? self::SUCCESS : self::FAILURE;
    }
}
