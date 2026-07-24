<?php

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowVersion;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\AsanaWorkflowConnectionService;
use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\CommerceWorkflowConnectionService;
use App\Services\Automation\GoogleCalendarWorkflowConnectionService;
use App\Services\Automation\V2\PayloadFingerprint;
use App\Services\Automation\V2\WorkflowDefinitionCompiler;
use App\Services\Automation\V2\WorkflowDraftConflictException;
use App\Services\Automation\V2\WorkflowDraftService;
use App\Services\Automation\V2\WorkflowRunItemExecutionService;
use App\Services\Automation\V2\WorkflowStudioBootstrapService;
use App\Services\Automation\V2\WorkflowStudioProductService;
use App\Services\Automation\WorkflowProductService;
use Illuminate\Support\Str;
use Mockery\MockInterface;

/** @return array{Tenant,User} */
function workflowStudioServerSupportTenant(string $slug): array
{
    config()->set('automation_workflows.v2_enabled', true);
    config()->set('automation_workflows.v2_tenant_ids', []);

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
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($tenant->id, [
        'role' => 'admin',
        'membership_active' => true,
    ]);

    return [$tenant, $user];
}

test('provider options hydrate both public configuration representations', function (): void {
    [$tenant] = workflowStudioServerSupportTenant('studio-provider-options');

    $this->mock(AsanaWorkflowConnectionService::class, function (MockInterface $mock) use ($tenant): void {
        $mock->shouldReceive('status')->with($tenant->id)->andReturn([
            'projects' => [[
                'gid' => 'project-123',
                'workspace_name' => 'Evergrove',
                'name' => 'Launch',
            ]],
        ]);
    });
    $this->mock(GoogleCalendarWorkflowConnectionService::class, function (MockInterface $mock) use ($tenant): void {
        $mock->shouldReceive('status')->with($tenant->id)->andReturn([
            'calendars' => [[
                'id' => 'calendar@example.com',
                'summary' => 'Appointments',
            ]],
        ]);
    });

    $payload = app(WorkflowStudioBootstrapService::class)->forNew($tenant->id, 'templates');
    $components = collect(data_get($payload, 'catalog.components', []));
    $asana = (array) $components->firstWhere('key', 'asana.task.created_or_updated');
    $calendar = (array) $components->firstWhere('key', 'google_calendar.event.upsert');
    $projectField = (array) collect($asana['config_fields'])->firstWhere('key', 'project_gid');
    $projectSchemaField = (array) collect(data_get($asana, 'config_schema.fields', []))
        ->firstWhere('key', 'project_gid');
    $calendarField = (array) collect($calendar['config_fields'])->firstWhere('key', 'calendar_id');
    $calendarSchemaField = (array) collect(data_get($calendar, 'config_schema.fields', []))
        ->firstWhere('key', 'calendar_id');

    expect($payload['initial_picker'])->toBe('templates')
        ->and($projectField['type'])->toBe('select')
        ->and($projectField['options'])->toBe([[
            'value' => 'project-123',
            'label' => 'Evergrove · Launch',
        ]])
        ->and($projectSchemaField)->toBe($projectField)
        ->and($calendarField['type'])->toBe('select')
        ->and($calendarField['options'])->toBe([[
            'value' => 'calendar@example.com',
            'label' => 'Appointments',
        ]])
        ->and($calendarSchemaField)->toBe($calendarField);
});

test('template creation keeps the catalog name unless a user supplies a real name', function (): void {
    [$tenant, $user] = workflowStudioServerSupportTenant('studio-template-name');

    $this->mock(AsanaWorkflowConnectionService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('status')->andReturn(['projects' => []]);
    });
    $this->mock(GoogleCalendarWorkflowConnectionService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('status')->andReturn(['calendars' => []]);
    });

    $template = collect(app(WorkflowStudioBootstrapService::class)->forNew($tenant->id)['templates'])
        ->firstWhere('key', 'asana_to_google_calendar');

    $defaultNameResponse = $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->postJson(route('workflows.store'), [
            'name' => 'Untitled workflow',
            'template_key' => $template['key'],
            'definition' => $template['definition'],
        ])
        ->assertCreated();

    expect($defaultNameResponse->json('workflow.name'))->toBe($template['name'])
        ->and(
            AutomationWorkflow::query()
                ->forAllTenants()
                ->findOrFail($defaultNameResponse->json('workflow.id'))
                ->name
        )->toBe($template['name']);

    $customNameResponse = $this->postJson(route('workflows.store'), [
        'name' => 'Launch partner appointments',
        'template_key' => $template['key'],
        'definition' => $template['definition'],
    ])->assertCreated();

    expect($customNameResponse->json('workflow.name'))->toBe('Launch partner appointments');
});

test('a failed single step test persists truthful state and an audit event', function (): void {
    [$tenant, $user] = workflowStudioServerSupportTenant('studio-failed-step');
    $workflow = app(WorkflowDraftService::class)->createBlank(
        $tenant->id,
        $user,
        'Failed step state',
        'everbranch.customer.created',
    );
    $stepId = (string) data_get($workflow->draft_definition, 'trigger.id');

    $this->mock(WorkflowRunItemExecutionService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('testStep')->once()->andReturn([
            'ok' => false,
            'message' => 'The provider rejected the test sample.',
        ]);
    });

    expect(fn () => app(WorkflowStudioProductService::class)->testStep(
        $workflow,
        $stepId,
        $user,
    ))->toThrow(AutomationWorkflowException::class, 'The provider rejected the test sample.');

    $fresh = $workflow->fresh();
    $audit = AutomationWorkflowAuditEvent::query()
        ->forAllTenants()
        ->where('automation_workflow_id', $workflow->id)
        ->where('event_type', 'step_test_failed')
        ->latest('id')
        ->first();

    expect(data_get($fresh->test_state, "{$stepId}.ok"))->toBeFalse()
        ->and(data_get($fresh->test_state, "{$stepId}.summary.message"))
        ->toBe('The provider rejected the test sample.')
        ->and(data_get($fresh->test_state, "{$stepId}.definition_hash"))
        ->toBeString()
        ->and($fresh->updated_by_user_id)->toBe($user->id)
        ->and($audit)->not->toBeNull()
        ->and(data_get($audit?->context, 'step_id'))->toBe($stepId)
        ->and(data_get($audit?->context, 'message'))->toBe('The provider rejected the test sample.');
});

test('connections usage follows component keys through nested v2 paths', function (): void {
    [$tenant, $user] = workflowStudioServerSupportTenant('studio-recursive-usage');
    $actionId = (string) Str::ulid();
    $workflow = AutomationWorkflow::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'template_key' => 'blank',
        'name' => 'Deep calendar branch',
        'status' => AutomationWorkflow::STATUS_DRAFT,
        'definition_schema_version' => 2,
        'draft_revision' => 1,
        'draft_definition' => [
            'schema_version' => 2,
            'trigger' => [
                'id' => (string) Str::ulid(),
                'kind' => 'trigger',
                'component_key' => 'everbranch.customer.created',
                'connection_id' => null,
                'config' => [],
            ],
            'steps' => [[
                'id' => (string) Str::ulid(),
                'kind' => 'paths',
                'component_key' => 'core.paths',
                'connection_id' => null,
                'config' => [
                    'branches' => [[
                        'id' => (string) Str::ulid(),
                        'rule_type' => 'always',
                        'steps' => [[
                            'id' => (string) Str::ulid(),
                            'kind' => 'paths',
                            'component_key' => 'core.paths',
                            'connection_id' => null,
                            'config' => [
                                'branches' => [[
                                    'id' => (string) Str::ulid(),
                                    'rule_type' => 'always',
                                    'steps' => [[
                                        'id' => $actionId,
                                        'kind' => 'action',
                                        'component_key' => 'google_calendar.event.upsert',
                                        'connection_id' => null,
                                        'config' => [],
                                    ]],
                                ]],
                            ],
                        ]],
                    ]],
                ],
            ]],
            'settings' => [
                'poll_interval_minutes' => 10,
                'max_items_per_poll' => 100,
            ],
        ],
        'test_state' => [],
        'created_by_user_id' => $user->id,
        'updated_by_user_id' => $user->id,
    ]);

    $this->mock(AsanaWorkflowConnectionService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('status')->andReturn([
            'oauth_connected' => false,
            'connection_status' => 'disconnected',
        ]);
    });
    $this->mock(GoogleCalendarWorkflowConnectionService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('status')->andReturn([
            'connected' => false,
            'connection_status' => 'disconnected',
        ]);
    });
    $this->mock(CommerceWorkflowConnectionService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('statuses')->andReturn([]);
    });

    $connectionsResponse = $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->get(route('workflows.connections'))
        ->assertOk()
        ->assertSeeText($workflow->name)
        ->assertSee(route('workflows.create', ['picker' => 'templates']), false);

    expect(substr_count($connectionsResponse->getContent(), $workflow->name))->toBe(1);

    $this->get(route('workflows.create', ['picker' => 'templates']))
        ->assertOk()
        ->assertSee('initial_picker&quot;:&quot;templates', false);
});

test('publish rechecks the revision from the locked workflow row', function (): void {
    [$tenant, $user] = workflowStudioServerSupportTenant('studio-publish-lock');
    $drafts = app(WorkflowDraftService::class);
    $workflow = $drafts->createBlank(
        $tenant->id,
        $user,
        'Publish lock',
        'everbranch.customer.created',
    );
    $definition = (array) $workflow->draft_definition;
    $actionId = (string) Str::ulid();
    $definition['steps'][] = [
        'id' => $actionId,
        'kind' => 'action',
        'component_key' => 'everbranch.email.send',
        'connection_id' => null,
        'config' => [
            'to' => ['type' => 'mapping', 'path' => 'trigger.output.email'],
            'subject' => ['type' => 'literal', 'value' => 'Welcome'],
            'body' => ['type' => 'literal', 'value' => 'Thanks for joining us.'],
        ],
    ];
    $workflow = $drafts->save(
        $tenant->id,
        $workflow->id,
        $workflow->draft_revision,
        $definition,
        $user,
    );
    $compiled = app(WorkflowDefinitionCompiler::class)->compileForPublish(
        (array) $workflow->draft_definition,
        $tenant->id,
    );
    $definitionHash = app(PayloadFingerprint::class)->hash($compiled);
    $testedAt = now()->toIso8601String();
    $workflow->forceFill([
        'test_state' => [
            (string) data_get($compiled, 'trigger.id') => [
                'ok' => true,
                'definition_hash' => $definitionHash,
                'tested_at' => $testedAt,
                'summary' => [],
            ],
            $actionId => [
                'ok' => true,
                'definition_hash' => $definitionHash,
                'tested_at' => $testedAt,
                'summary' => [],
            ],
        ],
    ])->save();
    $staleWorkflow = $workflow->fresh();
    $expectedRevision = (int) $staleWorkflow->draft_revision;

    AutomationWorkflow::query()
        ->forAllTenants()
        ->whereKey($workflow->id)
        ->update(['draft_revision' => $expectedRevision + 1]);

    expect(fn () => app(WorkflowStudioProductService::class)->publish(
        $staleWorkflow,
        $expectedRevision,
        $user,
    ))->toThrow(WorkflowDraftConflictException::class);

    $fresh = $workflow->fresh();
    expect($fresh->draft_revision)->toBe($expectedRevision + 1)
        ->and($fresh->status)->toBe(AutomationWorkflow::STATUS_DRAFT)
        ->and($fresh->published_version_id)->toBeNull()
        ->and(
            AutomationWorkflowVersion::query()
                ->forAllTenants()
                ->where('automation_workflow_id', $workflow->id)
                ->count()
        )->toBe(0);
});

test('legacy form mutations fail closed for schema v2 workflows', function (): void {
    [$tenant, $user] = workflowStudioServerSupportTenant('studio-legacy-mutation-guard');
    $workflow = app(WorkflowDraftService::class)->createBlank(
        $tenant->id,
        $user,
        'Studio-only workflow',
        'everbranch.customer.created',
    );
    $service = app(WorkflowProductService::class);

    $mutations = [
        fn () => $service->updateDraft($workflow, [], $user),
        fn () => $service->testTrigger($workflow, $user),
        fn () => $service->testAction($workflow, $user),
        fn () => $service->publish($workflow, $user),
        fn () => $service->pause($workflow, $user),
        fn () => $service->resume($workflow, $user),
    ];

    foreach ($mutations as $mutation) {
        expect($mutation)->toThrow(
            AutomationWorkflowException::class,
            'This workflow must be changed through Workflow Studio.',
        );
    }

    $fresh = $workflow->fresh();
    expect($fresh->status)->toBe(AutomationWorkflow::STATUS_DRAFT)
        ->and($fresh->published_version_id)->toBeNull()
        ->and($fresh->definition_schema_version)->toBe(2)
        ->and($fresh->draft_definition)->toBe($workflow->draft_definition);
});
