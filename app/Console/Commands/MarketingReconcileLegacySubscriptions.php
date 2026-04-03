<?php

namespace App\Console\Commands;

use App\Services\Marketing\LegacyImportSubscriptionReconciliationService;
use Illuminate\Console\Command;

class MarketingReconcileLegacySubscriptions extends Command
{
    protected $signature = 'marketing:reconcile-legacy-subscriptions
        {--tenant-id= : Restrict reconciliation to a tenant id (required)}
        {--limit= : Maximum candidate profiles to scan}
        {--dry-run : Preview changes without persisting consent updates}';

    protected $description = 'Reconcile legacy imported subscribed customers to canonical marketing consent state (tenant-scoped).';

    public function handle(LegacyImportSubscriptionReconciliationService $service): int
    {
        $tenantId = $this->tenantIdOption();
        if ($tenantId === null) {
            $this->error('Missing required --tenant-id. Legacy subscription reconciliation is tenant-scoped.');

            return self::FAILURE;
        }

        $limit = $this->optionalInt($this->option('limit'));
        $dryRun = (bool) $this->option('dry-run');

        $summary = $service->reconcile([
            'tenant_id' => $tenantId,
            'limit' => $limit,
            'dry_run' => $dryRun,
        ]);

        $this->line('tenant_id=' . $tenantId);
        $this->line('mode=' . ($dryRun ? 'dry-run' : 'live'));

        foreach ([
            'scanned_profiles',
            'candidates',
            'reconciled_profiles',
            'reconciled_email',
            'reconciled_sms',
            'dry_run_candidates',
            'skipped_no_legacy_signal',
            'skipped_recent_opt_out',
            'skipped_no_changes',
            'reward_paths_suppressed',
        ] as $key) {
            $this->line($key . '=' . (int) ($summary[$key] ?? 0));
        }

        return self::SUCCESS;
    }

    protected function tenantIdOption(): ?int
    {
        $value = $this->option('tenant-id');
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $tenantId = (int) $value;

        return $tenantId > 0 ? $tenantId : null;
    }

    protected function optionalInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
