<?php

namespace App\Console\Commands;

use App\Models\Trajectory\Space;
use App\Models\User;
use App\Services\Trajectory\FinanceAccess;
use App\Services\Trajectory\SourceCoverageReconciliationService;
use Illuminate\Console\Command;

class ReconcileTrajectoryImportSources extends Command
{
    protected $signature = 'trajectory:reconcile-import-sources
        {--owner= : Existing verified household owner email}
        {--space= : Household space ID}
        {--apply : Exclude historical rows superseded by live Plaid coverage}
        {--restore : Restore rows excluded by this reconciliation}
        {--json : Machine-readable output}';

    protected $description = 'Preview or reconcile overlapping Monarch and Plaid transaction history for one household.';

    public function handle(SourceCoverageReconciliationService $reconciliation, FinanceAccess $access): int
    {
        if (! $this->option('owner') || ! $this->option('space') || ($this->option('apply') && $this->option('restore'))) {
            $this->error('Specify --owner and --space, with at most one of --apply or --restore.');

            return self::FAILURE;
        }
        $owner = User::where('email', $this->option('owner'))->firstOrFail();
        $space = Space::where('kind', 'household')->findOrFail($this->option('space'));
        $access->authorize($owner, $space);

        if ($this->option('restore')) {
            $result = ['restored_count' => $reconciliation->restore($owner, $space)];
        } elseif ($this->option('apply')) {
            $result = ['applied' => $reconciliation->apply($owner, $space)];
        } else {
            $result = ['preview' => $reconciliation->preview($owner, $space)];
        }
        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT));
        } else {
            $this->table(array_keys($result), [$result]);
        }

        return self::SUCCESS;
    }
}
