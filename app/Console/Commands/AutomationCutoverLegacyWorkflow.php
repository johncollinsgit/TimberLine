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
        $isDryRun = (bool) $this->option('dry-run');
        $isConfirm = (bool) $this->option('confirm');
        if ($isDryRun === $isConfirm) {
            $this->error('Choose exactly one mode: --dry-run to build parity evidence or --confirm to cut over.');

            return self::FAILURE;
        }

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
            $qualifiedGate = $this->shadowPreviewGate($workflow);
            if ($isConfirm && $qualifiedGate['count'] < 3) {
                $this->error(sprintf(
                    'Cutover blocked: %d of 3 consecutive matching shadow previews are recorded. Run --dry-run until the gate passes.',
                    $qualifiedGate['count'],
                ));

                return self::FAILURE;
            }

            $preview = $workflows->run($workflow, 'cutover_test', null, dryRun: true);
            $this->line('preview_status='.$preview->status);
            $this->line('preview_counts='.json_encode((array) $preview->counts, JSON_UNESCAPED_SLASHES));
            if ($preview->status !== 'success') {
                $this->audit($workflow, 'legacy_shadow_preview_failed', [
                    'mode' => $isConfirm ? 'confirmation' : 'qualification',
                    'run_id' => $preview->id,
                    'status' => $preview->status,
                    'error' => $preview->error_summary,
                ]);
                $this->error('Preview failed. Legacy execution remains unchanged.');

                return self::FAILURE;
            }

            $parity = $this->shadowParityEvidence($workflow, $preview);
            if ($parity === null) {
                $this->audit($workflow, 'legacy_shadow_preview_failed', [
                    'mode' => $isConfirm ? 'confirmation' : 'qualification',
                    'run_id' => $preview->id,
                    'reason' => 'missing_parity_evidence',
                ]);
                $this->error('Preview did not produce complete source-selection and mapping evidence. Legacy execution remains unchanged.');

                return self::FAILURE;
            }

            if ($isDryRun) {
                $this->audit($workflow, 'legacy_shadow_preview_passed', $parity + [
                    'mode' => 'qualification',
                    'run_id' => $preview->id,
                ]);
                $gate = $this->shadowPreviewGate($workflow);
                $this->line(sprintf('shadow_parity_streak=%d/3', min(3, $gate['count'])));
                $this->info($gate['count'] >= 3
                    ? 'Preview passed. The three-preview parity gate is ready for --confirm. No workflow settings or calendar events were changed.'
                    : 'Preview passed. Repeat --dry-run until three consecutive matching previews are recorded. No workflow settings or calendar events were changed.');

                return self::SUCCESS;
            }

            if (! hash_equals((string) $qualifiedGate['signature'], (string) $parity['signature'])) {
                $this->audit($workflow, 'legacy_shadow_preview_failed', $parity + [
                    'mode' => 'confirmation',
                    'run_id' => $preview->id,
                    'reason' => 'confirmation_parity_changed',
                    'qualified_signature' => $qualifiedGate['signature'],
                ]);
                $this->error('Confirmation preview changed source selection, mappings, or expected action counts. Run three new matching --dry-run previews.');

                return self::FAILURE;
            }
            $this->audit($workflow, 'legacy_shadow_confirmation_passed', $parity + [
                'mode' => 'confirmation',
                'run_id' => $preview->id,
                'qualified_preview_count' => $qualifiedGate['count'],
            ]);

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
                'shadow_preview_count' => $qualifiedGate['count'],
                'shadow_parity_signature' => $qualifiedGate['signature'],
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

    /**
     * @return array{count:int,signature:?string}
     */
    protected function shadowPreviewGate(AutomationWorkflow $workflow): array
    {
        $events = AutomationWorkflowAuditEvent::query()
            ->forAllTenants()
            ->where('tenant_id', $workflow->tenant_id)
            ->where('automation_workflow_id', $workflow->id)
            ->whereIn('event_type', ['legacy_shadow_preview_passed', 'legacy_shadow_preview_failed'])
            ->latest('id')
            ->limit(20)
            ->get(['event_type', 'context']);

        $count = 0;
        $signature = null;
        foreach ($events as $event) {
            if ($event->event_type !== 'legacy_shadow_preview_passed') {
                break;
            }

            $candidate = trim((string) data_get($event->context, 'signature'));
            if ($candidate === '' || ($signature !== null && ! hash_equals($signature, $candidate))) {
                break;
            }

            $signature ??= $candidate;
            $count++;
        }

        return ['count' => $count, 'signature' => $signature];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function shadowParityEvidence(
        AutomationWorkflow $workflow,
        \App\Models\AutomationWorkflowRun $preview,
    ): ?array {
        $sourceSelectionHash = trim((string) data_get($preview->context, 'shadow_parity.source_selection_hash'));
        $mappingHash = trim((string) data_get($preview->context, 'shadow_parity.mapping_hash'));
        if ($sourceSelectionHash === '' || $mappingHash === '') {
            return null;
        }

        $evidence = [
            'definition_hash' => (string) $workflow->publishedVersion?->definition_hash,
            'source_selection_hash' => $sourceSelectionHash,
            'mapping_hash' => $mappingHash,
            'selected_source_count' => (int) data_get($preview->context, 'shadow_parity.selected_source_count', 0),
            'mapped_action_count' => (int) data_get($preview->context, 'shadow_parity.mapped_action_count', 0),
            'expected_action_counts' => [
                'would_create' => (int) data_get($preview->context, 'dry_run_counts.would_create', 0),
                'would_update' => (int) data_get($preview->context, 'dry_run_counts.would_update', 0),
            ],
        ];

        return $evidence + [
            'signature' => hash('sha256', json_encode($evidence, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)),
        ];
    }
}
