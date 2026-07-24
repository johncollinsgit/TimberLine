<?php

namespace App\Console\Commands;

use App\Services\Automation\V2\WorkflowDomainEventRetentionService;
use Illuminate\Console\Command;

class AutomationPruneDomainEvents extends Command
{
    protected $signature = 'automation:prune-domain-events
        {--tenant-id= : Limit maintenance to one tenant ID}
        {--days= : Override the event retention window}
        {--consumed-grace-days= : Override the acknowledgement grace window}
        {--dry-run : Report eligible rows without changing them}';

    protected $description = 'Acknowledge and prune old native workflow events only after every cursor-based consumer has advanced.';

    public function handle(WorkflowDomainEventRetentionService $retention): int
    {
        $tenantId = $this->positiveIntegerOption('tenant-id');
        $days = $this->positiveIntegerOption('days');
        $consumedGraceDays = $this->positiveIntegerOption('consumed-grace-days');
        if ($this->invalidOption) {
            return self::FAILURE;
        }

        $result = $retention->prune(
            tenantId: $tenantId,
            retentionDays: $days,
            consumedGraceDays: $consumedGraceDays,
            dryRun: (bool) $this->option('dry-run'),
        );

        $this->line('mode='.($result['dry_run'] ? 'dry-run' : 'execute'));
        $this->line('retention_cutoff='.$result['retention_cutoff']->toDateTimeString());
        $this->line('consumed_cutoff='.$result['consumed_cutoff']->toDateTimeString());
        $this->line('eligible='.$result['eligible']);
        $this->line('acknowledged='.$result['acknowledged']);
        $this->line('pruned='.$result['pruned']);

        $this->info($result['dry_run']
            ? 'Dry-run complete. No native workflow events were changed.'
            : 'Native workflow event retention complete.');

        return self::SUCCESS;
    }

    protected bool $invalidOption = false;

    protected function positiveIntegerOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 1) {
            $this->error("--{$name} must be a positive integer.");
            $this->invalidOption = true;

            return null;
        }

        return (int) $value;
    }
}
