<?php

use App\Jobs\ExecuteAutomationWorkflowRunItemJob;
use App\Jobs\RunAutomationWorkflowJob;
use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunItem;
use App\Models\AutomationWorkflowState;
use App\Models\AutomationWorkflowVersion;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\CalendarEventPresentationService;
use App\Services\Automation\Drivers\AsanaGoogleCalendarWorkflowDriver;
use App\Services\Automation\V2\LegacyV2WorkflowPromotionService;
use App\Services\Automation\V2\LegacyWorkflowDefinitionConverter;
use App\Services\Automation\V2\WorkflowDefinitionCompiler;
use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use App\Services\Automation\V2\WorkflowStudioProductService;
use App\Services\Automation\WorkflowProductService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config()->set('automation_workflows.v2_enabled', true);
    config()->set('automation_workflows.v2_tenant_ids', []);
    config()->set('services.asana.api_base', 'https://app.asana.com/api/1.0');
});

/**
 * @return array{
 *   tenant:Tenant,
 *   actor:User,
 *   workflow:AutomationWorkflow,
 *   version:AutomationWorkflowVersion,
 *   state:AutomationWorkflowState,
 *   link:AutomationWorkflowLink,
 *   asana:IntegrationConnection,
 *   calendar:IntegrationConnection,
 *   task:array<string,mixed>,
 *   definition:array<string,mixed>
 * }
 */
function workflowLegacyV2PromotionFixture(string $slug): array
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'workflow_automations',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'entitlement',
        'price_source' => 'test',
    ]);
    $actor = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $actor->tenants()->attach($tenant->id, [
        'role' => 'admin',
        'membership_active' => true,
    ]);
    $asana = IntegrationConnection::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'asana',
        'external_account_id' => 'asana-'.$tenant->id,
        'external_account_label' => 'Asana migration account',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'asana-access-token',
        'scopes' => ['projects:read', 'tasks:read'],
        'connected_by_user_id' => $actor->id,
        'connected_at' => now()->subMinute(),
    ]);
    $calendar = IntegrationConnection::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'google_calendar',
        'external_account_id' => 'calendar-'.$tenant->id,
        'external_account_label' => 'Calendar migration account',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'calendar-access-token',
        'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
        'connected_by_user_id' => $actor->id,
        'connected_at' => now(),
    ]);
    $definition = [
        'template_key' => 'asana_to_google_calendar',
        'driver' => 'asana_google_calendar',
        'trigger' => [
            'provider' => 'asana',
            'event' => 'New or updated dated task',
            'connection_id' => $asana->id,
            'project_gid' => 'promotion-project',
            'modified_overlap_minutes' => 5,
            'bootstrap_lookback_days' => 14,
            'poll_limit' => 100,
            'max_tasks_per_run' => 100,
            'schedule_source' => 'source_date',
        ],
        'action' => [
            'provider' => 'google_calendar',
            'event' => 'Create or update event',
            'connection_id' => $calendar->id,
            'calendar_id' => 'operations@example.com',
            'timezone' => 'America/New_York',
            'default_start_time' => '12:00:00',
            'default_duration_minutes' => 60,
            'skip_completed_tasks' => true,
            'date_only_mode' => 'all_day',
            'presentation' => app(CalendarEventPresentationService::class)->defaults('asana'),
        ],
    ];
    $hash = app(WorkflowProductService::class)->definitionHash($definition);
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'asana_to_google_calendar',
        'name' => 'Legacy Asana calendar workflow',
        'status' => AutomationWorkflow::STATUS_ACTIVE,
        'draft_definition' => $definition,
        'definition_schema_version' => 1,
        'draft_revision' => 1,
        'test_state' => [],
        'created_by_user_id' => $actor->id,
        'updated_by_user_id' => $actor->id,
        'published_at' => now()->subDay(),
        'next_run_at' => now()->addHour(),
    ]);
    $version = AutomationWorkflowVersion::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'version' => 1,
        'definition_hash' => $hash,
        'definition' => $definition,
        'published_by_user_id' => $actor->id,
        'published_at' => now()->subDay(),
    ]);
    $workflow->forceFill(['published_version_id' => $version->id])->save();
    $state = AutomationWorkflowState::query()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'workflow_key' => 'asana_to_google_calendar::tenant:'.$tenant->id,
        'status' => 'idle',
        'cursor' => '2026-07-24T11:00:00+00:00',
    ]);
    $task = [
        'gid' => 'task-promotion-1',
        'name' => 'Install launch partner software',
        'notes' => 'Confirm the customer appointment.',
        'due_on' => '2026-07-28',
        'due_at' => null,
        'completed' => false,
        'modified_at' => '2026-07-24T12:00:00+00:00',
        'permalink_url' => 'https://app.asana.com/0/promotion/task-promotion-1',
    ];
    $preview = app(AsanaGoogleCalendarWorkflowDriver::class)->previewMapping(
        $task,
        'workflow:'.$workflow->id,
        $definition,
    );
    $link = AutomationWorkflowLink::query()->create([
        'tenant_id' => $tenant->id,
        'automation_workflow_id' => $workflow->id,
        'step_key' => 'action',
        'workflow_key' => 'asana_to_google_calendar::tenant:'.$tenant->id,
        'source_system' => 'asana_task',
        'source_id' => $task['gid'],
        'destination_system' => 'google_calendar_event',
        'destination_id' => 'existing-calendar-event',
        'source_fingerprint' => $preview['legacy_link_fingerprint'],
        'metadata' => ['task_name' => $task['name']],
        'last_synced_at' => now()->subHour(),
    ]);

    return compact(
        'tenant',
        'actor',
        'workflow',
        'version',
        'state',
        'link',
        'asana',
        'calendar',
        'task',
        'definition',
    );
}

/**
 * @param  array<string,mixed>|list<array<string,mixed>>  $task
 */
function workflowLegacyV2PromotionFakeAsana(array $task): void
{
    $tasks = array_is_list($task) ? $task : [$task];
    Http::fake(function (Request $request) use ($tasks) {
        if (str_contains($request->url(), 'app.asana.com/api/1.0/tasks')) {
            return Http::response(['data' => $tasks, 'next_page' => null]);
        }

        return Http::response(['error' => 'Unexpected external write in promotion test.'], 500);
    });
}

/** @param array<string,mixed> $fixture */
function workflowLegacyV2PromotionActivateV2(array $fixture): AutomationWorkflowVersion
{
    $candidate = app(LegacyWorkflowDefinitionConverter::class)->convert(
        $fixture['definition'],
    );
    data_set($candidate, 'trigger.connection_id', $fixture['asana']->id);
    data_set($candidate, 'steps.0.connection_id', $fixture['calendar']->id);
    $candidate = app(WorkflowDefinitionCompiler::class)->compileForPublish(
        $candidate,
        $fixture['tenant']->id,
    );
    $version = AutomationWorkflowVersion::query()->forAllTenants()->create([
        'tenant_id' => $fixture['tenant']->id,
        'automation_workflow_id' => $fixture['workflow']->id,
        'version' => 2,
        'definition_hash' => hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR)),
        'definition' => $candidate,
        'published_at' => now(),
    ]);
    $fixture['workflow']->forceFill([
        'published_version_id' => $version->id,
        'draft_definition' => $candidate,
        'definition_schema_version' => 2,
    ])->save();

    return $version;
}

test('three matching shadows atomically promote v1 while preserving cursor links and rollback evidence', function (): void {
    Queue::fake();
    $fixture = workflowLegacyV2PromotionFixture('legacy-v2-promotion');
    $overlapTask = [
        ...$fixture['task'],
        'gid' => 'task-promotion-overlap',
        'name' => 'Legacy overlap task',
        'modified_at' => '2026-07-24T10:58:00+00:00',
    ];
    $overlapPreview = app(AsanaGoogleCalendarWorkflowDriver::class)->previewMapping(
        $overlapTask,
        'workflow:'.$fixture['workflow']->id,
        $fixture['definition'],
    );
    AutomationWorkflowLink::query()->create([
        'tenant_id' => $fixture['tenant']->id,
        'automation_workflow_id' => $fixture['workflow']->id,
        'step_key' => 'action',
        'workflow_key' => 'asana_to_google_calendar::tenant:'.$fixture['tenant']->id,
        'source_system' => 'asana_task',
        'source_id' => $overlapTask['gid'],
        'destination_system' => 'google_calendar_event',
        'destination_id' => 'existing-overlap-event',
        'source_fingerprint' => $overlapPreview['legacy_link_fingerprint'],
        'metadata' => ['task_name' => $overlapTask['name']],
        'last_synced_at' => now()->subHour(),
    ]);
    workflowLegacyV2PromotionFakeAsana([$overlapTask, $fixture['task']]);
    $workflow = $fixture['workflow'];
    $legacyVersion = $fixture['version'];
    $legacyDefinition = $legacyVersion->definition;
    $legacyHash = $legacyVersion->definition_hash;
    $cursor = $fixture['state']->cursor;
    $destinationId = $fixture['link']->destination_id;

    $this->artisan('automation:promote-legacy-v2 legacy-v2-promotion --shadow')
        ->expectsOutputToContain('v2_shadow_parity_streak=1/3')
        ->assertSuccessful();
    $workflow = $workflow->fresh('publishedVersion');
    expect((int) data_get($workflow->draft_definition, 'schema_version'))->toBe(2)
        ->and($workflow->published_version_id)->toBe($legacyVersion->id)
        ->and((int) data_get($workflow->publishedVersion->definition, 'schema_version', 1))->toBe(1);

    expect(fn () => app(WorkflowStudioProductService::class)->publish(
        $workflow,
        (int) $workflow->draft_revision,
        $fixture['actor'],
    ))->toThrow(
        AutomationWorkflowException::class,
        'guarded legacy v2 promotion command',
    );

    $this->artisan('automation:promote-legacy-v2 legacy-v2-promotion --shadow')
        ->expectsOutputToContain('v2_shadow_parity_streak=2/3')
        ->assertSuccessful();
    $this->artisan('automation:promote-legacy-v2 legacy-v2-promotion --confirm')
        ->expectsOutputToContain('V2 promotion blocked')
        ->assertFailed();
    expect($workflow->fresh()->published_version_id)->toBe($legacyVersion->id)
        ->and($fixture['link']->fresh()->step_key)->toBe('action');

    $this->artisan('automation:promote-legacy-v2 legacy-v2-promotion --shadow')
        ->expectsOutputToContain('v2_shadow_parity_streak=3/3')
        ->assertSuccessful();
    $this->artisan('automation:promote-legacy-v2 legacy-v2-promotion --confirm')
        ->expectsOutputToContain('V2 promotion completed')
        ->assertSuccessful();

    $promoted = $workflow->fresh(['publishedVersion', 'versions']);
    $actionStepId = (string) data_get($promoted->publishedVersion->definition, 'steps.0.id');
    $promotedLink = $fixture['link']->fresh();
    $preservedState = $fixture['state']->fresh();
    $preservedLegacy = AutomationWorkflowVersion::query()
        ->forAllTenants()
        ->findOrFail($legacyVersion->id);
    $promotionAudit = AutomationWorkflowAuditEvent::query()
        ->forAllTenants()
        ->where('automation_workflow_id', $workflow->id)
        ->where('event_type', 'legacy_v2_promoted')
        ->sole();

    expect((int) data_get($promoted->publishedVersion->definition, 'schema_version'))->toBe(2)
        ->and($promoted->versions)->toHaveCount(2)
        ->and($preservedLegacy->definition)->toBe($legacyDefinition)
        ->and($preservedLegacy->definition_hash)->toBe($legacyHash)
        ->and($preservedState->cursor)->toBe($cursor)
        ->and($promotedLink->step_key)->toBe($actionStepId)
        ->and($promotedLink->destination_id)->toBe($destinationId)
        ->and($promotedLink->source_fingerprint)->not->toBe($fixture['link']->source_fingerprint)
        ->and(data_get($promotedLink->metadata, 'legacy_v2_promotion.previous_step_key'))->toBe('action')
        ->and(data_get($promotionAudit->context, 'rollback.legacy_version_id'))->toBe($legacyVersion->id)
        ->and(data_get($promotionAudit->context, 'rollback.v2_action_step_id'))->toBe($actionStepId)
        ->and(AutomationWorkflowAuditEvent::query()
            ->forAllTenants()
            ->where('automation_workflow_id', $workflow->id)
            ->where('event_type', 'legacy_v2_shadow_passed')
            ->count())->toBe(3)
        ->and(AutomationWorkflowAuditEvent::query()
            ->forAllTenants()
            ->where('automation_workflow_id', $workflow->id)
            ->where('event_type', 'legacy_v2_rollback_evidence_recorded')
            ->exists())->toBeTrue();

    $run = app(WorkflowProductService::class)->run(
        $promoted->fresh('publishedVersion'),
        'manual',
        $fixture['actor'],
    );
    $items = AutomationWorkflowRunItem::query()->forAllTenants()
        ->where('automation_workflow_run_id', $run->id)
        ->orderBy('id')
        ->get();
    $executions = $items->map(
        fn (AutomationWorkflowRunItem $item) => app(WorkflowRunItemExecutionService::class)
            ->execute($item),
    );

    expect($items)->toHaveCount(2)
        ->and($executions->pluck('status')->all())->toBe(['succeeded', 'succeeded'])
        ->and($promotedLink->fresh()->destination_id)->toBe($destinationId)
        ->and($promotedLink->fresh()->source_fingerprint)->toBe($promotedLink->source_fingerprint);
    Http::assertNotSent(
        fn (Request $request): bool => str_contains($request->url(), 'googleapis.com/calendar'),
    );
});

test('a shadow mismatch resets the consecutive promotion gate', function (): void {
    $fixture = workflowLegacyV2PromotionFixture('legacy-v2-mismatch');
    workflowLegacyV2PromotionFakeAsana($fixture['task']);
    $promotion = app(LegacyV2WorkflowPromotionService::class);

    expect($promotion->qualify($fixture['workflow'])['streak'])->toBe(1);
    $workflow = $fixture['workflow']->fresh();
    $changed = (array) $workflow->draft_definition;
    data_set(
        $changed,
        'steps.0.config.presentation.title_template',
        'Changed {{task_name}}',
    );
    $workflow->forceFill([
        'draft_definition' => $changed,
        'draft_revision' => (int) $workflow->draft_revision + 1,
    ])->save();

    expect(fn () => $promotion->qualify($workflow->fresh('publishedVersion')))
        ->toThrow(AutomationWorkflowException::class, 'mapping_mismatch')
        ->and($promotion->gate($workflow->fresh())['count'])->toBe(0)
        ->and(AutomationWorkflowAuditEvent::query()
            ->forAllTenants()
            ->where('automation_workflow_id', $workflow->id)
            ->where('event_type', 'legacy_v2_shadow_failed')
            ->count())->toBe(1);
});

test('v2 rollout and tenant gates block v2 execution without affecting v1 runs', function (): void {
    $fixture = workflowLegacyV2PromotionFixture('v2-runtime-gate');
    workflowLegacyV2PromotionFakeAsana($fixture['task']);
    config()->set('automation_workflows.v2_enabled', false);

    $legacyRun = app(WorkflowProductService::class)->run(
        $fixture['workflow']->fresh('publishedVersion'),
        'manual',
        $fixture['actor'],
        dryRun: true,
    );
    expect($legacyRun->status)->toBe('success');

    workflowLegacyV2PromotionActivateV2($fixture);
    $runsBefore = AutomationWorkflowRun::query()
        ->forAllTenants()
        ->where('automation_workflow_id', $fixture['workflow']->id)
        ->count();

    expect(fn () => app(WorkflowProductService::class)->run(
        $fixture['workflow']->fresh('publishedVersion'),
        'manual',
        $fixture['actor'],
    ))->toThrow(AutomationWorkflowException::class, 'not enabled')
        ->and(AutomationWorkflowRun::query()
            ->forAllTenants()
            ->where('automation_workflow_id', $fixture['workflow']->id)
            ->count())->toBe($runsBefore);

    config()->set('automation_workflows.v2_enabled', true);
    config()->set('automation_workflows.v2_tenant_ids', [$fixture['tenant']->id + 1000]);
    expect(fn () => app(WorkflowProductService::class)->run(
        $fixture['workflow']->fresh('publishedVersion'),
        'scheduled',
    ))->toThrow(AutomationWorkflowException::class, 'not enabled')
        ->and(AutomationWorkflowRunItem::query()->forAllTenants()->count())->toBe(0);
});

test('scheduler leaves disabled v2 due without advancing cadence while dispatching v1', function (): void {
    Queue::fake();
    $legacy = workflowLegacyV2PromotionFixture('runtime-scheduler-v1');
    $v2 = workflowLegacyV2PromotionFixture('runtime-scheduler-v2');
    workflowLegacyV2PromotionActivateV2($v2);
    $legacyDueAt = now()->subMinutes(2);
    $v2DueAt = now()->subMinute();
    $legacy['workflow']->forceFill(['next_run_at' => $legacyDueAt])->save();
    $v2['workflow']->forceFill(['next_run_at' => $v2DueAt])->save();
    config()->set('automation_workflows.v2_enabled', false);

    $this->artisan('automation:dispatch')
        ->expectsOutput('Dispatched 1 workflow(s).')
        ->assertSuccessful();

    Queue::assertPushed(RunAutomationWorkflowJob::class, 1);
    Queue::assertPushed(
        RunAutomationWorkflowJob::class,
        fn (RunAutomationWorkflowJob $job): bool => $job->workflowId === $legacy['workflow']->id,
    );
    Queue::assertNotPushed(
        RunAutomationWorkflowJob::class,
        fn (RunAutomationWorkflowJob $job): bool => $job->workflowId === $v2['workflow']->id,
    );
    expect($legacy['workflow']->fresh()->next_run_at?->isFuture())->toBeTrue()
        ->and($v2['workflow']->fresh()->next_run_at?->toIso8601String())
        ->toBe($v2DueAt->toIso8601String());
});

test('disabled runtime holds executing and due v2 items without auto release', function (): void {
    Queue::fake();
    $fixture = workflowLegacyV2PromotionFixture('runtime-held-items');
    workflowLegacyV2PromotionFakeAsana($fixture['task']);
    $version = workflowLegacyV2PromotionActivateV2($fixture);
    $workflow = $fixture['workflow']->fresh('publishedVersion');
    $firstRun = app(WorkflowProductService::class)->run(
        $workflow,
        'scheduled',
        $fixture['actor'],
    );
    $executing = AutomationWorkflowRunItem::query()->forAllTenants()
        ->where('automation_workflow_run_id', $firstRun->id)
        ->sole();
    $dueRun = AutomationWorkflowRun::query()->forAllTenants()->create([
        'tenant_id' => $fixture['tenant']->id,
        'automation_workflow_id' => $workflow->id,
        'automation_workflow_version_id' => $version->id,
        'mode' => 'scheduled',
        'status' => 'running',
        'started_at' => now(),
    ]);
    $due = AutomationWorkflowRunItem::query()->forAllTenants()->create([
        'tenant_id' => $fixture['tenant']->id,
        'automation_workflow_id' => $workflow->id,
        'automation_workflow_run_id' => $dueRun->id,
        'automation_workflow_version_id' => $version->id,
        'trigger_step_id' => (string) data_get($version->definition, 'trigger.id'),
        'source_system' => 'asana_task',
        'source_id' => 'runtime-due-item',
        'source_fingerprint' => hash('sha256', 'runtime-due-item'),
        'event_key' => 'runtime-due-item',
        'status' => AutomationWorkflowRunItem::STATUS_DELAYED,
        'payload' => $fixture['task'],
        'context' => ['step_outputs' => [], 'branch_errors' => []],
        'execution_stack' => app(\App\Services\Automation\V2\V2WorkflowInterpreter::class)
            ->initialStack((array) $version->definition),
        'available_at' => now()->subMinute(),
    ]);
    Queue::fake();
    config()->set('automation_workflows.v2_enabled', false);
    $execution = app(WorkflowRunItemExecutionService::class);

    expect($execution->execute($executing)->status)->toBe('held')
        ->and($execution->releaseDue())->toBe(0)
        ->and($executing->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and(data_get($executing->fresh()->context, 'held_from_status'))
        ->toBe(AutomationWorkflowRunItem::STATUS_PENDING)
        ->and($firstRun->fresh()->status)->toBe('held')
        ->and($due->fresh()->status)->toBe(AutomationWorkflowRunItem::STATUS_HELD)
        ->and(data_get($due->fresh()->context, 'held_from_status'))
        ->toBe(AutomationWorkflowRunItem::STATUS_DELAYED)
        ->and($dueRun->fresh()->status)->toBe('held')
        ->and($execution->releaseDue())->toBe(0);
    Queue::assertNotPushed(ExecuteAutomationWorkflowRunItemJob::class);
});
