<?php

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowLink;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Automation\V2\Data\ActionOperationContext;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;
use App\Services\Automation\V2\Operations\GoogleCalendarUpsertEventActionOperation;
use App\Services\Automation\V2\WorkflowNativeActionService;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('separate calendar actions create separate idempotent destination links', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Provider operation tenant',
        'slug' => 'provider-operation-'.Str::lower((string) Str::ulid()),
    ]);
    $connection = IntegrationConnection::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'google_calendar',
        'external_account_id' => 'google-account',
        'external_account_label' => 'Operations calendar',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'access_token' => 'google-access-token',
        'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
        'connected_at' => now(),
    ]);
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'blank',
        'name' => 'Two calendar actions',
        'status' => AutomationWorkflow::STATUS_ACTIVE,
        'draft_definition' => [
            'schema_version' => 2,
            'trigger' => null,
            'steps' => [],
            'settings' => [],
        ],
        'definition_schema_version' => 2,
        'draft_revision' => 1,
    ]);
    $stepIds = [(string) Str::ulid(), (string) Str::ulid()];
    $responseNumber = 0;
    Http::fake(function (Request $request) use (&$responseNumber) {
        $responseNumber++;

        return Http::response([
            'id' => 'calendar-event-'.$responseNumber,
            'htmlLink' => 'https://calendar.google.com/event?eid='.$responseNumber,
        ]);
    });
    $operation = app(GoogleCalendarUpsertEventActionOperation::class);
    $execution = new WorkflowExecutionContext(
        tenantId: (int) $tenant->id,
        workflowId: (int) $workflow->id,
        workflowVersionId: 101,
        runId: 202,
        runItemId: 303,
        triggerOutput: [
            'gid' => 'asana-task-1',
            'name' => 'Install launch partner software',
            'notes' => 'Confirm the final field mapping.',
            'due_on' => '2026-07-28',
        ],
        metadata: [
            'source_system' => 'asana_task',
            'source_id' => 'asana-task-1',
        ],
    );
    $context = static fn (string $stepId): ActionOperationContext => new ActionOperationContext(
        execution: $execution,
        stepId: $stepId,
        componentKey: 'google_calendar.event.upsert',
        connectionId: (int) $connection->id,
        config: [
            'calendar_id' => 'operations@example.com',
            'timezone' => 'America/New_York',
            'default_duration_minutes' => 60,
            'date_only_mode' => 'default_time',
            'default_start_time' => '14:30',
            'presentation' => [
                'title_template' => 'Launch · {{task_name}}',
                'description_fields' => ['notes', 'source_link'],
                'location_source' => 'none',
                'color_id' => '10',
                'availability' => 'busy',
                'visibility' => 'private',
                'reminders' => 'none',
            ],
        ],
        input: [
            'source_id' => 'asana-task-1',
            'starts_at' => '2026-07-28',
        ],
        idempotencyKey: hash('sha256', $stepId),
    );

    $first = $operation->execute($context($stepIds[0]));
    $unchanged = $operation->execute($context($stepIds[0]));
    $second = $operation->execute($context($stepIds[1]));

    expect($first->output)->toMatchArray([
        'event_id' => 'calendar-event-1',
        'event_url' => 'https://calendar.google.com/event?eid=1',
        'operation' => 'created',
    ])->and($unchanged->status)->toBe('unchanged')
        ->and($second->output['event_id'] ?? null)->toBe('calendar-event-2')
        ->and(AutomationWorkflowLink::query()
            ->where('automation_workflow_id', $workflow->id)
            ->count())->toBe(2)
        ->and(AutomationWorkflowLink::query()
            ->where('automation_workflow_id', $workflow->id)
            ->pluck('step_key')
            ->sort()
            ->values()
            ->all())->toBe(collect($stepIds)->sort()->values()->all());

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->hasHeader(
        'Authorization',
        'Bearer google-access-token'
    )
        && str_contains($request->url(), '/calendars/operations%40example.com/events')
        && data_get($request->data(), 'summary') === 'Launch · Install launch partner software'
        && data_get($request->data(), 'description') === 'Confirm the final field mapping.'
        && data_get($request->data(), 'start.dateTime') === '2026-07-28T14:30:00-04:00'
        && data_get($request->data(), 'end.dateTime') === '2026-07-28T15:30:00-04:00'
        && data_get($request->data(), 'colorId') === '10'
        && data_get($request->data(), 'visibility') === 'private'
        && data_get($request->data(), 'reminders.useDefault') === false);
});

test('native workflow email validates and forwards reply-to and idempotency metadata', function (): void {
    $dispatch = $this->mock(TenantEmailDispatchService::class);
    $dispatch->shouldReceive('sendEmail')
        ->once()
        ->with(
            'customer@example.test',
            'Your appointment',
            'We will see you Tuesday.',
            \Mockery::on(fn (array $options): bool => $options['tenant_id'] === 42
                && $options['dry_run'] === false
                && $options['tracking_enabled'] === false
                && $options['reply_to_email'] === 'john@evergrovesoftware.com'
                && data_get($options, 'headers.X-Everbranch-Idempotency-Key') === 'workflow-action-key'
                && data_get($options, 'metadata.workflow_idempotency_key') === 'workflow-action-key'),
        )
        ->andReturn([
            'success' => true,
            'provider' => 'postmark',
            'status' => 'sent',
            'message_id' => 'message-123',
        ]);

    $result = app(WorkflowNativeActionService::class)->sendEmail(42, [
        'to' => 'customer@example.test',
        'subject' => 'Your appointment',
        'body' => 'We will see you Tuesday.',
        'reply_to' => 'john@evergrovesoftware.com',
    ], false, 'workflow-action-key');

    expect($result)->toMatchArray([
        'provider' => 'postmark',
        'status' => 'sent',
        'message_id' => 'message-123',
        'recipient' => 'customer@example.test',
        'dry_run' => false,
    ]);

    expect(fn () => app(WorkflowNativeActionService::class)->sendEmail(42, [
        'to' => 'customer@example.test',
        'subject' => 'Your appointment',
        'body' => 'We will see you Tuesday.',
        'reply_to' => 'not-an-email',
    ], false, 'another-key'))->toThrow(\InvalidArgumentException::class, 'valid reply-to');
});
