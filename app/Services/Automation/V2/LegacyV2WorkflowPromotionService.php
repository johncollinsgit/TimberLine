<?php

namespace App\Services\Automation\V2;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowState;
use App\Models\AutomationWorkflowVersion;
use App\Models\IntegrationConnection;
use App\Models\User;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\Drivers\AsanaGoogleCalendarWorkflowDriver;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\TriggerOperationContext;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;
use App\Services\Automation\V2\Operations\AsanaTaskTriggerOperation;
use App\Services\Automation\V2\Operations\GoogleCalendarUpsertEventActionOperation;
use App\Services\Automation\WorkflowProductService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Fail-closed promotion of the one supported legacy runtime. Qualification
 * reads both engines without advancing the shared cursor or writing Calendar
 * events. Publication and link remapping happen in one database transaction.
 */
class LegacyV2WorkflowPromotionService
{
    private const PASS_EVENT = 'legacy_v2_shadow_passed';

    private const FAIL_EVENT = 'legacy_v2_shadow_failed';

    public function __construct(
        protected LegacyWorkflowDefinitionConverter $converter,
        protected WorkflowDefinitionCompiler $compiler,
        protected PayloadFingerprint $fingerprints,
        protected TypedValueMapper $mapper,
        protected WorkflowStudioRuntimeAccess $runtimeAccess,
        protected WorkflowProductService $legacyWorkflows,
        protected AsanaTaskTriggerOperation $asanaTrigger,
        protected GoogleCalendarUpsertEventActionOperation $calendarAction,
        protected AsanaGoogleCalendarWorkflowDriver $legacyDriver,
    ) {}

    /**
     * Persist one immutable candidate draft when needed and record one shadow
     * comparison. A mismatch is evidence, never a partial promotion.
     *
     * @return array<string,mixed>
     */
    public function qualify(
        AutomationWorkflow $workflow,
        ?User $actor = null,
    ): array {
        $result = $this->shadow($workflow, $actor, 'qualification');
        $gate = $this->gate($result['workflow']);

        return [
            ...$this->publicResult($result),
            'streak' => $gate['count'],
            'qualified' => $gate['count'] >= 3,
        ];
    }

    /**
     * Publish v2 only after three matching qualification shadows plus one
     * matching confirmation shadow.
     */
    public function promote(
        AutomationWorkflow $workflow,
        ?User $actor = null,
    ): AutomationWorkflow {
        $workflow = $this->eligibleWorkflow($workflow);
        $this->runtimeAccess->ensure((int) $workflow->tenant_id);
        $gate = $this->gate($workflow);
        if ($gate['count'] < 3 || blank($gate['signature'])) {
            throw new AutomationWorkflowException(
                sprintf(
                    'V2 promotion is blocked: %d of 3 consecutive matching shadow previews are recorded.',
                    $gate['count'],
                )
            );
        }

        $confirmation = $this->shadow($workflow, $actor, 'confirmation');
        if (
            ! (bool) ($confirmation['matched'] ?? false)
            || ! hash_equals(
                (string) $gate['signature'],
                (string) ($confirmation['signature'] ?? ''),
            )
        ) {
            $this->audit(
                $confirmation['workflow'],
                $actor,
                self::FAIL_EVENT,
                [
                    ...$this->auditEvidence($confirmation),
                    'mode' => 'confirmation',
                    'reason' => 'confirmation_evidence_changed',
                    'qualified_signature' => $gate['signature'],
                ],
            );
            throw new AutomationWorkflowException(
                'The confirmation shadow changed. Record three new matching previews before promotion.'
            );
        }

        /** @var array<string,string> $fingerprintRewrites */
        $fingerprintRewrites = $confirmation['fingerprint_rewrites'];

        return DB::transaction(function () use (
            $workflow,
            $actor,
            $gate,
            $confirmation,
            $fingerprintRewrites,
        ): AutomationWorkflow {
            $locked = AutomationWorkflow::query()
                ->forAllTenants()
                ->where('tenant_id', $workflow->tenant_id)
                ->lockForUpdate()
                ->with('publishedVersion')
                ->findOrFail($workflow->id);
            $locked = $this->eligibleWorkflow($locked);
            $this->runtimeAccess->ensure((int) $locked->tenant_id);

            $legacyVersion = AutomationWorkflowVersion::query()
                ->forAllTenants()
                ->where('automation_workflow_id', $locked->id)
                ->lockForUpdate()
                ->findOrFail($locked->published_version_id);
            if (
                (int) $legacyVersion->id !== (int) $confirmation['legacy_version_id']
                || ! hash_equals(
                    (string) $legacyVersion->definition_hash,
                    (string) $confirmation['legacy_definition_hash'],
                )
            ) {
                throw new AutomationWorkflowException(
                    'The published legacy version changed during v2 qualification.'
                );
            }

            $candidate = $this->compiler->compileForPublish(
                (array) $locked->draft_definition,
                (int) $locked->tenant_id,
            );
            $candidateHash = $this->fingerprints->hash($candidate);
            if (! hash_equals($candidateHash, (string) $confirmation['candidate_definition_hash'])) {
                throw new AutomationWorkflowException(
                    'The v2 draft changed after its confirmation shadow.'
                );
            }
            [$actionStep] = $this->candidateSteps($candidate);

            $state = AutomationWorkflowState::query()
                ->where('automation_workflow_id', $locked->id)
                ->lockForUpdate()
                ->first();
            $cursor = filled($state?->cursor) ? (string) $state->cursor : null;
            $cursorHash = $this->fingerprints->hash($cursor);
            if (! hash_equals($cursorHash, (string) $confirmation['cursor_hash'])) {
                throw new AutomationWorkflowException(
                    'The workflow cursor changed after its confirmation shadow.'
                );
            }
            if (AutomationWorkflowRun::query()
                ->forAllTenants()
                ->where('automation_workflow_id', $locked->id)
                ->where('status', 'running')
                ->exists()) {
                throw new AutomationWorkflowException(
                    'A workflow run is still active. Wait for it to finish before promotion.'
                );
            }

            $nextVersionNumber = ((int) AutomationWorkflowVersion::query()
                ->forAllTenants()
                ->where('automation_workflow_id', $locked->id)
                ->max('version')) + 1;
            $version = AutomationWorkflowVersion::query()->forAllTenants()->create([
                'tenant_id' => $locked->tenant_id,
                'automation_workflow_id' => $locked->id,
                'version' => $nextVersionNumber,
                'definition_hash' => $candidateHash,
                'definition' => $candidate,
                'published_by_user_id' => $actor?->id,
                'published_at' => now(),
            ]);

            $linkEvidence = $this->remapLinks(
                $locked,
                (string) $actionStep['id'],
                $legacyVersion,
                $version,
                $fingerprintRewrites,
            );
            $before = [
                'status' => $locked->status,
                'published_version_id' => $legacyVersion->id,
                'published_definition_hash' => $legacyVersion->definition_hash,
                'published_schema_version' => 1,
                'cursor_hash' => $cursorHash,
            ];
            $locked->forceFill([
                'draft_definition' => $candidate,
                'published_version_id' => $version->id,
                'status' => AutomationWorkflow::STATUS_ACTIVE,
                'definition_schema_version' => 2,
                'published_at' => now(),
                'next_run_at' => now(),
                'updated_by_user_id' => $actor?->id,
            ])->save();

            $afterCursor = AutomationWorkflowState::query()
                ->where('automation_workflow_id', $locked->id)
                ->value('cursor');
            $afterCursorHash = $this->fingerprints->hash(
                filled($afterCursor) ? (string) $afterCursor : null,
            );
            if (! hash_equals($cursorHash, $afterCursorHash)) {
                throw new AutomationWorkflowException(
                    'The cursor could not be preserved during v2 promotion.'
                );
            }

            $rollback = [
                'legacy_version_id' => $legacyVersion->id,
                'legacy_version_number' => $legacyVersion->version,
                'legacy_definition_hash' => $legacyVersion->definition_hash,
                'legacy_schema_version' => 1,
                'preserved_cursor_hash' => $cursorHash,
                'prior_link_step_key' => 'action',
                'v2_action_step_id' => (string) $actionStep['id'],
                'fingerprint_backup_location' => 'automation_workflow_links.metadata.legacy_v2_promotion',
            ];
            $this->audit(
                $locked,
                $actor,
                'legacy_v2_promoted',
                [
                    'qualified_shadow_count' => $gate['count'],
                    'shadow_signature' => $gate['signature'],
                    'confirmation_run_id' => $confirmation['shadow_run_id'],
                    'candidate_definition_hash' => $candidateHash,
                    'published_v2_version_id' => $version->id,
                    'published_v2_version_number' => $version->version,
                    ...$linkEvidence,
                    'rollback' => $rollback,
                ],
                $before,
                [
                    'status' => $locked->status,
                    'published_version_id' => $version->id,
                    'published_definition_hash' => $candidateHash,
                    'published_schema_version' => 2,
                    'cursor_hash' => $afterCursorHash,
                ],
            );
            $this->audit(
                $locked,
                $actor,
                'legacy_v2_rollback_evidence_recorded',
                ['rollback' => $rollback],
            );

            return $locked->fresh(['publishedVersion', 'versions']);
        });
    }

    /** @return array{count:int,signature:?string,event_ids:list<int>} */
    public function gate(AutomationWorkflow $workflow): array
    {
        $events = AutomationWorkflowAuditEvent::query()
            ->forAllTenants()
            ->where('tenant_id', $workflow->tenant_id)
            ->where('automation_workflow_id', $workflow->id)
            ->whereIn('event_type', [self::PASS_EVENT, self::FAIL_EVENT])
            ->latest('id')
            ->limit(50)
            ->get(['id', 'event_type', 'context']);

        $count = 0;
        $signature = null;
        $eventIds = [];
        foreach ($events as $event) {
            if ($event->event_type !== self::PASS_EVENT) {
                break;
            }
            $candidate = trim((string) data_get($event->context, 'signature'));
            if (
                $candidate === ''
                || ($signature !== null && ! hash_equals($signature, $candidate))
            ) {
                break;
            }
            $signature ??= $candidate;
            $eventIds[] = (int) $event->id;
            $count++;
        }

        return [
            'count' => $count,
            'signature' => $signature,
            'event_ids' => array_reverse($eventIds),
        ];
    }

    /** @return array<string,mixed> */
    protected function shadow(
        AutomationWorkflow $workflow,
        ?User $actor,
        string $mode,
    ): array {
        $workflow = $this->prepareCandidate($workflow, $actor);
        $legacyVersion = $workflow->publishedVersion;
        if (! $legacyVersion) {
            throw new AutomationWorkflowException('The legacy published version is missing.');
        }
        $candidate = $this->compiler->compileForPublish(
            (array) $workflow->draft_definition,
            (int) $workflow->tenant_id,
        );
        $candidateHash = $this->fingerprints->hash($candidate);
        $legacyDefinition = (array) $legacyVersion->definition;
        $shadowRun = null;

        try {
            $legacyRun = $this->legacyWorkflows->run(
                $workflow->fresh('publishedVersion'),
                'v2_shadow_legacy',
                $actor,
                dryRun: true,
            );
            if ($legacyRun->status !== 'success') {
                throw new AutomationWorkflowException(
                    'The legacy shadow run failed: '.($legacyRun->error_summary ?: $legacyRun->status)
                );
            }

            $shadowRun = AutomationWorkflowRun::query()->forAllTenants()->create([
                'tenant_id' => $workflow->tenant_id,
                'automation_workflow_id' => $workflow->id,
                'automation_workflow_version_id' => null,
                'mode' => 'shadow',
                'status' => 'running',
                'initiated_by_user_id' => $actor?->id,
                'started_at' => now(),
                'context' => [
                    'legacy_run_id' => $legacyRun->id,
                    'legacy_version_id' => $legacyVersion->id,
                    'candidate_definition_hash' => $candidateHash,
                ],
            ]);
            $snapshot = $this->v2Snapshot(
                $workflow,
                $candidate,
                $legacyDefinition,
            );
            $legacySourceHash = trim((string) data_get(
                $legacyRun->context,
                'shadow_parity.source_selection_hash',
            ));
            if ($legacySourceHash === '') {
                throw new AutomationWorkflowException(
                    'The legacy shadow did not return source-selection evidence.'
                );
            }
            $legacyRuntimeMappingHash = trim((string) data_get(
                $legacyRun->context,
                'shadow_parity.mapping_hash',
            ));
            if ($legacyRuntimeMappingHash === '') {
                throw new AutomationWorkflowException(
                    'The legacy shadow did not return mapping evidence.'
                );
            }
            $legacyActualCounts = [
                'would_create' => (int) data_get($legacyRun->context, 'dry_run_counts.would_create', 0),
                'would_update' => (int) data_get($legacyRun->context, 'dry_run_counts.would_update', 0),
                'unchanged' => (int) data_get($legacyRun->counts, 'unchanged', 0),
                'skipped' => (int) data_get($legacyRun->counts, 'skipped', 0),
            ];
            $reasons = [];
            if (! hash_equals($legacySourceHash, $snapshot['source_selection_hash'])) {
                $reasons[] = 'source_selection_mismatch';
            }
            if (! hash_equals(
                $snapshot['legacy_mapping_hash'],
                $snapshot['v2_mapping_hash'],
            )) {
                $reasons[] = 'mapping_mismatch';
            }
            if (! hash_equals(
                $legacyRuntimeMappingHash,
                $snapshot['legacy_runtime_mapping_hash'],
            )) {
                $reasons[] = 'legacy_mapping_model_mismatch';
            }
            if ($legacyActualCounts !== $snapshot['legacy_counts']) {
                $reasons[] = 'legacy_count_model_mismatch';
            }
            if ($legacyActualCounts !== $snapshot['v2_counts']) {
                $reasons[] = 'expected_action_count_mismatch';
            }

            $cursor = AutomationWorkflowState::query()
                ->where('automation_workflow_id', $workflow->id)
                ->value('cursor');
            $cursorHash = $this->fingerprints->hash(
                filled($cursor) ? (string) $cursor : null,
            );
            $evidence = [
                'legacy_version_id' => (int) $legacyVersion->id,
                'legacy_definition_hash' => (string) $legacyVersion->definition_hash,
                'candidate_definition_hash' => $candidateHash,
                'cursor_hash' => $cursorHash,
                'source_selection_hash' => $snapshot['source_selection_hash'],
                'legacy_runtime_mapping_hash' => $legacyRuntimeMappingHash,
                'legacy_mapping_hash' => $snapshot['legacy_mapping_hash'],
                'v2_mapping_hash' => $snapshot['v2_mapping_hash'],
                'expected_action_counts' => $legacyActualCounts,
                'selected_source_count' => $snapshot['selected_source_count'],
                'has_more' => $snapshot['has_more'],
            ];
            $signature = $this->fingerprints->hash($evidence);
            $matched = $reasons === [];
            $shadowRun->forceFill([
                'status' => $matched ? 'success' : 'failed',
                'counts' => [
                    'selected' => $snapshot['selected_source_count'],
                    ...$snapshot['v2_counts'],
                ],
                'context' => [
                    'legacy_run_id' => $legacyRun->id,
                    'parity' => [
                        ...$evidence,
                        'signature' => $signature,
                        'matched' => $matched,
                        'reasons' => $reasons,
                    ],
                ],
                'error_summary' => $matched
                    ? null
                    : 'The v1 and v2 shadow results did not match.',
                'finished_at' => now(),
            ])->save();

            $result = [
                'workflow' => $workflow,
                'matched' => $matched,
                'reasons' => $reasons,
                'signature' => $signature,
                'legacy_run_id' => (int) $legacyRun->id,
                'shadow_run_id' => (int) $shadowRun->id,
                'legacy_version_id' => (int) $legacyVersion->id,
                'legacy_definition_hash' => (string) $legacyVersion->definition_hash,
                'candidate_definition_hash' => $candidateHash,
                'cursor_hash' => $cursorHash,
                'evidence' => $evidence,
                'fingerprint_rewrites' => $snapshot['fingerprint_rewrites'],
            ];
            $eventType = $matched
                ? ($mode === 'confirmation'
                    ? 'legacy_v2_shadow_confirmation_passed'
                    : self::PASS_EVENT)
                : self::FAIL_EVENT;
            $this->audit(
                $workflow,
                $actor,
                $eventType,
                [
                    ...$this->auditEvidence($result),
                    'mode' => $mode,
                ],
            );

            if (! $matched) {
                throw new AutomationWorkflowException(
                    'V2 shadow parity failed: '.implode(', ', $reasons).'.'
                );
            }

            return $result;
        } catch (Throwable $exception) {
            if ($shadowRun && $shadowRun->status === 'running') {
                $shadowRun->forceFill([
                    'status' => 'failed',
                    'error_summary' => Str::limit($exception->getMessage(), 500, ''),
                    'finished_at' => now(),
                ])->save();
            }
            if (! $exception instanceof AutomationWorkflowException) {
                $exception = new AutomationWorkflowException(
                    'V2 shadow parity could not be completed safely: '.$exception->getMessage(),
                    previous: $exception,
                );
            }
            $alreadyAudited = $shadowRun
                && AutomationWorkflowAuditEvent::query()
                    ->forAllTenants()
                    ->where('automation_workflow_id', $workflow->id)
                    ->where('event_type', self::FAIL_EVENT)
                    ->where('context->shadow_run_id', $shadowRun->id)
                    ->exists();
            if (! $alreadyAudited) {
                $this->audit($workflow, $actor, self::FAIL_EVENT, [
                    'mode' => $mode,
                    'shadow_run_id' => $shadowRun?->id,
                    'reason' => Str::limit($exception->getMessage(), 500, ''),
                    'candidate_definition_hash' => $candidateHash,
                    'legacy_version_id' => $legacyVersion->id,
                ]);
            }

            throw $exception;
        }
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @param  array<string,mixed>  $legacyDefinition
     * @return array<string,mixed>
     */
    protected function v2Snapshot(
        AutomationWorkflow $workflow,
        array $candidate,
        array $legacyDefinition,
    ): array {
        [$actionStep, $trigger] = $this->candidateSteps($candidate);
        $state = AutomationWorkflowState::query()
            ->where('automation_workflow_id', $workflow->id)
            ->first();
        $limit = min(1000, max(1, (int) data_get(
            $candidate,
            'settings.max_items_per_poll',
            100,
        )));
        $poll = $this->asanaTrigger->poll(new TriggerOperationContext(
            tenantId: (int) $workflow->tenant_id,
            workflowId: (int) $workflow->id,
            workflowVersionId: 0,
            stepId: (string) $trigger['id'],
            componentKey: (string) $trigger['component_key'],
            connectionId: isset($trigger['connection_id'])
                ? (int) $trigger['connection_id']
                : null,
            config: (array) $trigger['config'],
            cursor: filled($state?->cursor) ? (string) $state->cursor : null,
            limit: $limit,
            dryRun: true,
        ));

        $sourceIds = [];
        $legacyRuntimeMappings = [];
        $legacyMappings = [];
        $v2Mappings = [];
        $legacyCounts = $this->emptyCounts();
        $v2Counts = $this->emptyCounts();
        $fingerprintRewrites = [];
        foreach ($poll->events as $event) {
            $sourceIds[] = $event->sourceId;
            $legacy = $this->legacyDriver->previewMapping(
                $event->payload,
                'workflow:'.$workflow->id,
                $legacyDefinition,
            );
            $execution = new WorkflowExecutionContext(
                tenantId: (int) $workflow->tenant_id,
                workflowId: (int) $workflow->id,
                workflowVersionId: 0,
                runId: 0,
                runItemId: 0,
                triggerOutput: $event->payload,
                metadata: [
                    'source_system' => $event->sourceSystem,
                    'source_id' => $event->sourceId,
                    'source_fingerprint' => $event->sourceFingerprint,
                    'event_key' => $event->eventKey,
                ],
                dryRun: true,
            );
            $rawConfig = (array) $actionStep['config'];
            $presentation = $rawConfig['presentation'] ?? null;
            unset($rawConfig['presentation']);
            $resolvedConfig = $this->mapper->resolveInputs($rawConfig, $execution);
            if ($presentation !== null) {
                $resolvedConfig['presentation'] = $presentation;
            }
            $inputs = is_array($resolvedConfig['inputs'] ?? null)
                ? (array) $resolvedConfig['inputs']
                : $resolvedConfig;
            $v2 = $this->calendarAction->previewMapping(new ActionOperationContext(
                execution: $execution,
                stepId: (string) $actionStep['id'],
                componentKey: (string) $actionStep['component_key'],
                connectionId: isset($actionStep['connection_id'])
                    ? (int) $actionStep['connection_id']
                    : null,
                config: $resolvedConfig,
                input: $inputs,
                idempotencyKey: hash('sha256', 'shadow|'.$workflow->id.'|'.$event->eventKey),
                dryRun: true,
            ));

            if (($legacy['status'] ?? null) === 'skipped') {
                $legacyCounts['skipped']++;
            }
            if (($v2['status'] ?? null) === 'skipped') {
                $v2Counts['skipped']++;
            }
            if (
                ($legacy['status'] ?? null) !== 'ready'
                || ($v2['status'] ?? null) !== 'ready'
            ) {
                continue;
            }

            $legacySemantic = (string) $legacy['semantic_fingerprint'];
            $v2Semantic = (string) $v2['semantic_fingerprint'];
            $legacyRuntimeMappings[] = $event->sourceId.':'.(string) $legacy['legacy_link_fingerprint'];
            $legacyMappings[] = $event->sourceId.':'.$legacySemantic;
            $v2Mappings[] = $event->sourceId.':'.$v2Semantic;
            $links = AutomationWorkflowLink::query()
                ->where('automation_workflow_id', $workflow->id)
                ->where('source_system', $event->sourceSystem)
                ->where('source_id', $event->sourceId)
                ->get();
            if ($links->count() > 1) {
                throw new AutomationWorkflowException(
                    'Duplicate legacy destination links must be resolved before v2 promotion.'
                );
            }
            $link = $links->first();
            if (
                $link
                && ! in_array((string) $link->step_key, ['action', (string) $actionStep['id']], true)
            ) {
                throw new AutomationWorkflowException(
                    'A legacy destination link belongs to an unexpected workflow step.'
                );
            }
            $operation = ! $link || blank($link->destination_id)
                ? 'would_create'
                : (hash_equals(
                    (string) $link->source_fingerprint,
                    (string) $legacy['legacy_link_fingerprint'],
                ) ? 'unchanged' : 'would_update');
            $legacyCounts[$operation]++;
            $v2Counts[$operation]++;
            if ($operation === 'unchanged') {
                $fingerprintRewrites[$event->sourceSystem.'|'.$event->sourceId] =
                    (string) $v2['link_fingerprint'];
            }
        }
        sort($sourceIds, SORT_STRING);
        sort($legacyRuntimeMappings, SORT_STRING);
        sort($legacyMappings, SORT_STRING);
        sort($v2Mappings, SORT_STRING);

        return [
            'source_selection_hash' => hash(
                'sha256',
                json_encode($sourceIds, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'legacy_runtime_mapping_hash' => hash(
                'sha256',
                json_encode($legacyRuntimeMappings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'legacy_mapping_hash' => hash(
                'sha256',
                json_encode($legacyMappings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'v2_mapping_hash' => hash(
                'sha256',
                json_encode($v2Mappings, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            ),
            'legacy_counts' => $legacyCounts,
            'v2_counts' => $v2Counts,
            'selected_source_count' => count($sourceIds),
            'has_more' => $poll->hasMore,
            'fingerprint_rewrites' => $fingerprintRewrites,
        ];
    }

    protected function prepareCandidate(
        AutomationWorkflow $workflow,
        ?User $actor,
    ): AutomationWorkflow {
        $workflow = $this->eligibleWorkflow($workflow);
        $this->runtimeAccess->ensure((int) $workflow->tenant_id);
        $stored = (array) $workflow->draft_definition;
        if ((int) ($stored['schema_version'] ?? 0) === 2) {
            $this->compiler->compileForPublish($stored, (int) $workflow->tenant_id);

            return $workflow;
        }

        $candidate = $this->converter->convert(
            (array) $workflow->publishedVersion?->definition,
        );
        $candidate['trigger']['connection_id'] = $this->connectionId(
            (int) $workflow->tenant_id,
            'asana',
            data_get($candidate, 'trigger.connection_id'),
        );
        $candidate['steps'][0]['connection_id'] = $this->connectionId(
            (int) $workflow->tenant_id,
            'google_calendar',
            data_get($candidate, 'steps.0.connection_id'),
        );
        $candidate = $this->compiler->compileForPublish(
            $candidate,
            (int) $workflow->tenant_id,
        );

        return DB::transaction(function () use ($workflow, $actor, $candidate): AutomationWorkflow {
            $locked = AutomationWorkflow::query()
                ->forAllTenants()
                ->where('tenant_id', $workflow->tenant_id)
                ->lockForUpdate()
                ->with('publishedVersion')
                ->findOrFail($workflow->id);
            $this->eligibleWorkflow($locked);
            if ((int) data_get($locked->draft_definition, 'schema_version', 1) === 2) {
                return $locked;
            }
            $before = [
                'definition_schema_version' => (int) $locked->definition_schema_version,
                'draft_revision' => (int) $locked->draft_revision,
                'published_version_id' => $locked->published_version_id,
            ];
            $locked->forceFill([
                'draft_definition' => $candidate,
                'definition_schema_version' => 2,
                'draft_revision' => max(1, (int) $locked->draft_revision) + 1,
                'test_state' => [],
                'updated_by_user_id' => $actor?->id,
            ])->save();
            $this->audit(
                $locked,
                $actor,
                'legacy_v2_draft_prepared',
                [
                    'candidate_definition_hash' => $this->fingerprints->hash($candidate),
                    'published_v1_version_id' => $locked->published_version_id,
                    'published_v1_preserved' => true,
                ],
                $before,
                [
                    'definition_schema_version' => 2,
                    'draft_revision' => (int) $locked->draft_revision,
                    'published_version_id' => $locked->published_version_id,
                ],
            );

            return $locked->fresh('publishedVersion');
        });
    }

    protected function eligibleWorkflow(
        AutomationWorkflow $workflow,
    ): AutomationWorkflow {
        $workflow = AutomationWorkflow::query()
            ->forAllTenants()
            ->with(['publishedVersion', 'versions'])
            ->where('tenant_id', $workflow->tenant_id)
            ->findOrFail($workflow->id);
        if ($workflow->status !== AutomationWorkflow::STATUS_ACTIVE) {
            throw new AutomationWorkflowException(
                'Only an active legacy workflow can be promoted to v2.'
            );
        }
        $published = $workflow->publishedVersion;
        if (! $published || (int) data_get($published->definition, 'schema_version', 1) === 2) {
            throw new AutomationWorkflowException(
                'This workflow does not have an active schema-v1 version to promote.'
            );
        }
        if (
            $workflow->template_key !== 'asana_to_google_calendar'
            || strtolower((string) data_get($published->definition, 'trigger.provider')) !== 'asana'
            || strtolower((string) data_get($published->definition, 'action.provider')) !== 'google_calendar'
        ) {
            throw new AutomationWorkflowException(
                'Automatic v2 promotion supports only legacy Asana to Google Calendar workflows.'
            );
        }
        if ($workflow->versions->contains(
            fn (AutomationWorkflowVersion $version): bool => (int) data_get(
                $version->definition,
                'schema_version',
                1,
            ) === 2
        )) {
            throw new AutomationWorkflowException(
                'This workflow already has a v2 version. Use the normal rollback or publish process.'
            );
        }

        return $workflow;
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array{0:array<string,mixed>,1:array<string,mixed>}
     */
    protected function candidateSteps(array $candidate): array
    {
        $trigger = (array) ($candidate['trigger'] ?? []);
        $steps = array_values(array_filter(
            (array) ($candidate['steps'] ?? []),
            'is_array',
        ));
        if (
            ($trigger['component_key'] ?? null) !== 'asana.task.created_or_updated'
            || count($steps) !== 1
            || ($steps[0]['component_key'] ?? null) !== 'google_calendar.event.upsert'
            || ($steps[0]['kind'] ?? null) !== 'action'
        ) {
            throw new AutomationWorkflowException(
                'Automatic promotion requires exactly one Asana trigger and one Google Calendar action.'
            );
        }

        return [$steps[0], $trigger];
    }

    protected function connectionId(
        int $tenantId,
        string $provider,
        mixed $preferred,
    ): int {
        $query = IntegrationConnection::query()
            ->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('provider', $provider)
            ->where('status', IntegrationConnection::STATUS_CONNECTED);
        $preferredId = filter_var($preferred, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);
        $connection = $preferredId === false
            ? $query->oldest('connected_at')->oldest('id')->first()
            : $query->whereKey((int) $preferredId)->first();
        if (! $connection) {
            throw new AutomationWorkflowException(
                'Reconnect the tenant-owned '.Str::headline($provider).' account before v2 qualification.'
            );
        }

        return (int) $connection->id;
    }

    /**
     * @param  array<string,string>  $fingerprintRewrites
     * @return array{remapped_link_count:int,rewritten_fingerprint_count:int}
     */
    protected function remapLinks(
        AutomationWorkflow $workflow,
        string $actionStepId,
        AutomationWorkflowVersion $legacyVersion,
        AutomationWorkflowVersion $v2Version,
        array $fingerprintRewrites,
    ): array {
        $legacyKeys = [
            'workflow:'.$workflow->id,
            'asana_to_google_calendar::tenant:'.$workflow->tenant_id,
        ];
        $links = AutomationWorkflowLink::query()
            ->where(function ($query) use ($workflow, $legacyKeys): void {
                $query->where('automation_workflow_id', $workflow->id)
                    ->orWhere(function ($legacy) use ($legacyKeys): void {
                        $legacy->whereNull('automation_workflow_id')
                            ->whereIn('workflow_key', $legacyKeys);
                    });
            })
            ->where('source_system', 'asana_task')
            ->where('destination_system', 'google_calendar_event')
            ->lockForUpdate()
            ->get();
        $duplicate = $links
            ->groupBy(fn (AutomationWorkflowLink $link): string => $link->source_system.'|'.$link->source_id)
            ->first(fn ($group): bool => $group->count() > 1);
        if ($duplicate) {
            throw new AutomationWorkflowException(
                'Duplicate destination links block atomic v2 promotion.'
            );
        }

        $remapped = 0;
        $rewritten = 0;
        foreach ($links as $link) {
            if (! in_array((string) $link->step_key, ['action', $actionStepId], true)) {
                throw new AutomationWorkflowException(
                    'A destination link belongs to an unexpected workflow step.'
                );
            }
            $key = $link->source_system.'|'.$link->source_id;
            $previousStepKey = (string) $link->step_key;
            $previousFingerprint = (string) $link->source_fingerprint;
            $metadata = (array) $link->metadata;
            $metadata['legacy_v2_promotion'] = [
                'legacy_version_id' => $legacyVersion->id,
                'v2_version_id' => $v2Version->id,
                'previous_step_key' => $previousStepKey,
                'previous_source_fingerprint' => $previousFingerprint,
                'promoted_at' => now()->toIso8601String(),
            ];
            $attributes = [
                'tenant_id' => $workflow->tenant_id,
                'automation_workflow_id' => $workflow->id,
                'step_key' => $actionStepId,
                'metadata' => $metadata,
            ];
            if (isset($fingerprintRewrites[$key])) {
                $attributes['source_fingerprint'] = $fingerprintRewrites[$key];
                $rewritten++;
            }
            $link->forceFill($attributes)->save();
            $remapped++;
        }

        return [
            'remapped_link_count' => $remapped,
            'rewritten_fingerprint_count' => $rewritten,
        ];
    }

    /** @return array{would_create:int,would_update:int,unchanged:int,skipped:int} */
    protected function emptyCounts(): array
    {
        return [
            'would_create' => 0,
            'would_update' => 0,
            'unchanged' => 0,
            'skipped' => 0,
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    protected function publicResult(array $result): array
    {
        return [
            'matched' => (bool) ($result['matched'] ?? false),
            'reasons' => (array) ($result['reasons'] ?? []),
            'signature' => $result['signature'] ?? null,
            'legacy_run_id' => $result['legacy_run_id'] ?? null,
            'shadow_run_id' => $result['shadow_run_id'] ?? null,
            'evidence' => (array) ($result['evidence'] ?? []),
        ];
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    protected function auditEvidence(array $result): array
    {
        return [
            'matched' => (bool) ($result['matched'] ?? false),
            'reasons' => array_values((array) ($result['reasons'] ?? [])),
            'signature' => $result['signature'] ?? null,
            'legacy_run_id' => $result['legacy_run_id'] ?? null,
            'shadow_run_id' => $result['shadow_run_id'] ?? null,
            ...(array) ($result['evidence'] ?? []),
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>|null  $before
     * @param  array<string,mixed>|null  $after
     */
    protected function audit(
        AutomationWorkflow $workflow,
        ?User $actor,
        string $eventType,
        array $context,
        ?array $before = null,
        ?array $after = null,
    ): void {
        AutomationWorkflowAuditEvent::query()->forAllTenants()->create([
            'tenant_id' => $workflow->tenant_id,
            'automation_workflow_id' => $workflow->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $eventType,
            'before_state' => $before,
            'after_state' => $after,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }
}
