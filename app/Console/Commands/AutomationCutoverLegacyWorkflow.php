<?php

namespace App\Console\Commands;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowLink;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Automation\WorkflowProductService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class AutomationCutoverLegacyWorkflow extends Command
{
    protected $signature = 'automation:cutover-legacy
        {tenant : Tenant id or slug}
        {--dry-run : Read source data and preview the new runtime without calendar writes}
        {--confirm : Disable legacy execution and activate the verified product workflow}';

    protected $description = 'Safely cut a tenant from the legacy Asana→Google runner to the productized workflow while preserving links and cursor.';

    public function handle(WorkflowProductService $workflows): int
    {
        $tenant = Tenant::query()
            ->where('slug', (string) $this->argument('tenant'))
            ->orWhere('id', ctype_digit((string) $this->argument('tenant')) ? (int) $this->argument('tenant') : 0)
            ->first();
        if (! $tenant) {
            $this->error('Tenant not found.');

            return self::FAILURE;
        }
        $legacy = TenantMarketingSetting::query()->where('tenant_id', $tenant->id)
            ->where('key', 'workflow_automation_asana_google_calendar')->first();
        $workflow = AutomationWorkflow::query()->forAllTenants()->where('tenant_id', $tenant->id)
            ->where('template_key', 'asana_to_google_calendar')->with('publishedVersion')->first();
        if (! $legacy || ! $workflow?->publishedVersion) {
            $this->error('Run automation:migrate-legacy-settings for this tenant before cutover.');

            return self::FAILURE;
        }

        $lock = Cache::lock('automation:legacy-cutover:'.$tenant->id, 300);
        if (! $lock->get()) {
            $this->error('Another cutover or verification is already running.');

            return self::FAILURE;
        }

        try {
            $preview = $workflows->run($workflow, 'cutover_test', null, dryRun: true);
            $this->line('preview_status='.$preview->status);
            $this->line('preview_counts='.json_encode((array) $preview->counts, JSON_UNESCAPED_SLASHES));
            if ($preview->status !== 'success') {
                $this->error('Preview failed. Legacy execution remains unchanged.');

                return self::FAILURE;
            }
            if ($this->option('dry-run')) {
                $this->info('Preview passed. No settings or calendar events were changed.');

                return self::SUCCESS;
            }
            if (! $this->option('confirm')) {
                $this->error('Re-run with --confirm after reviewing the successful preview.');

                return self::FAILURE;
            }

            $legacyValue = (array) $legacy->value;
            $legacyWasEnabled = (bool) ($legacyValue['enabled'] ?? false);
            $legacyValue['enabled'] = false;
            $legacyValue['productized_cutover_at'] = now()->toIso8601String();
            $legacy->forceFill(['value' => $legacyValue])->save();
            $workflow->forceFill(['status' => AutomationWorkflow::STATUS_PAUSED])->save();

            $live = $workflows->run($workflow->fresh('publishedVersion'), 'cutover_verify');
            if ($live->status !== 'success') {
                $legacyValue['enabled'] = $legacyWasEnabled;
                unset($legacyValue['productized_cutover_at']);
                $legacy->forceFill(['value' => $legacyValue])->save();
                $this->audit($workflow, 'cutover_rolled_back', ['run_id' => $live->id, 'error' => $live->error_summary]);
                $this->error('Live verification failed. Legacy execution was restored and the new workflow remains paused.');

                return self::FAILURE;
            }

            $workflow->forceFill(['status' => AutomationWorkflow::STATUS_ACTIVE])->save();
            $this->audit($workflow, 'legacy_cutover_completed', [
                'run_id' => $live->id,
                'legacy_setting_id' => $legacy->id,
                'preserved_destination_links' => AutomationWorkflowLink::query()->where('automation_workflow_id', $workflow->id)->count(),
            ]);
            $this->info('Cutover verified. Legacy execution is disabled and the productized workflow is active.');

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }

    /** @param array<string,mixed> $context */
    protected function audit(AutomationWorkflow $workflow, string $event, array $context): void
    {
        AutomationWorkflowAuditEvent::query()->forAllTenants()->create([
            'tenant_id' => $workflow->tenant_id,
            'automation_workflow_id' => $workflow->id,
            'event_type' => $event,
            'after_state' => ['status' => $workflow->fresh()->status],
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
