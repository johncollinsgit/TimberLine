<?php

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Automation\V2\LegacyWorkflowDefinitionConverter;
use App\Services\Automation\V2\WorkflowComponentCatalog;
use App\Services\Automation\V2\WorkflowDefinitionCompiler;
use App\Services\Automation\V2\WorkflowDefinitionException;
use App\Services\Automation\V2\WorkflowDraftConflictException;
use App\Services\Automation\V2\WorkflowDraftService;
use App\Services\Automation\V2\WorkflowStudioFeatureGate;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/** @return array{Tenant,User} */
function workflowStudioFoundationTenant(string $slug): array
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

function workflowStudioConnection(
    Tenant $tenant,
    string $provider,
    ?User $actor = null,
): IntegrationConnection {
    return IntegrationConnection::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'provider' => $provider,
        'external_account_id' => $provider.'-'.$tenant->id.'-'.Str::lower((string) Str::ulid()),
        'external_account_label' => Str::headline($provider).' account',
        'status' => IntegrationConnection::STATUS_CONNECTED,
        'scopes' => match ($provider) {
            'google_calendar' => ['https://www.googleapis.com/auth/calendar.events'],
            'asana' => ['projects:read', 'tasks:read'],
            'shopify' => ['read_orders'],
            'square' => ['ORDERS_READ'],
            default => [],
        },
        'connected_by_user_id' => $actor?->id,
        'connected_at' => now(),
    ]);
}

/** @return array<string,mixed> */
function workflowStudioDefinition(
    IntegrationConnection $triggerConnection,
    IntegrationConnection $calendarConnection,
): array {
    $filterId = (string) Str::ulid();
    $delayId = (string) Str::ulid();
    $pathsId = (string) Str::ulid();

    $calendarAction = fn (): array => [
        'id' => (string) Str::ulid(),
        'kind' => 'action',
        'component_key' => 'google_calendar.event.upsert',
        'connection_id' => $calendarConnection->id,
        'config' => [
            'calendar_id' => 'operations@example.com',
            'timezone' => 'America/New_York',
            'source_id' => ['type' => 'mapping', 'path' => 'trigger.output.id'],
            'title' => ['type' => 'mapping', 'path' => 'trigger.output.name'],
            'starts_at' => ['type' => 'mapping', 'path' => 'trigger.output.due_on'],
        ],
    ];

    return [
        'schema_version' => 2,
        'trigger' => [
            'id' => (string) Str::ulid(),
            'kind' => 'trigger',
            'component_key' => 'asana.task.created_or_updated',
            'connection_id' => $triggerConnection->id,
            'config' => ['project_gid' => 'asana-project-1'],
        ],
        'steps' => [
            [
                'id' => $filterId,
                'kind' => 'filter',
                'component_key' => 'core.filter',
                'connection_id' => null,
                'config' => [
                    'logic' => 'and',
                    'conditions' => [[
                        'field' => 'trigger.output.completed',
                        'operator' => 'equals',
                        'value' => ['type' => 'literal', 'value' => false],
                    ]],
                ],
            ],
            [
                'id' => $delayId,
                'kind' => 'delay',
                'component_key' => 'core.delay_for',
                'connection_id' => null,
                'config' => [
                    'duration' => ['type' => 'literal', 'value' => 1],
                    'unit' => 'minutes',
                ],
            ],
            [
                'id' => $pathsId,
                'kind' => 'paths',
                'component_key' => 'core.paths',
                'connection_id' => null,
                'branches' => [
                    [
                        'id' => (string) Str::ulid(),
                        'name' => 'Incomplete tasks',
                        'type' => 'custom',
                        'logic' => 'and',
                        'conditions' => [[
                            'field' => 'trigger.output.completed',
                            'operator' => 'equals',
                            'value' => ['type' => 'literal', 'value' => false],
                        ]],
                        'steps' => [$calendarAction()],
                    ],
                    [
                        'id' => (string) Str::ulid(),
                        'name' => 'Everything else',
                        'type' => 'fallback',
                        'steps' => [$calendarAction()],
                    ],
                ],
                'config' => [],
            ],
        ],
        'settings' => [
            'poll_interval_minutes' => 10,
            'max_items_per_poll' => 100,
        ],
    ];
}

test('the public catalog exposes only executable launch components and hides handlers', function (): void {
    $catalog = app(WorkflowComponentCatalog::class);
    $components = $catalog->components();
    $public = $catalog->publicCatalog();
    $publicGoogle = collect($public['components'])->firstWhere(
        'key',
        'google_calendar.event.upsert'
    );
    $publicDelayUntil = collect($public['components'])->firstWhere(
        'key',
        'core.delay_until'
    );
    $pastDateField = collect($publicDelayUntil['config_fields'])->firstWhere(
        'key',
        'past_date_behavior'
    );
    $googleFieldKeys = collect($publicGoogle['config_fields'])->pluck('key');

    expect(array_keys($components))
        ->toContain(
            'everbranch.customer.created',
            'everbranch.job.created',
            'everbranch.job.status_changed',
            'everbranch.task.completed',
            'asana.task.created_or_updated',
            'shopify.order.created_or_updated',
            'square.order.created_or_updated',
            'everbranch.email.send',
            'everbranch.customer_loop.draft.prepare',
            'everbranch.job.task.create',
            'everbranch.job.note.add',
            'everbranch.job.status.change',
            'google_calendar.event.upsert',
            'core.filter',
            'core.delay_for',
            'core.delay_until',
            'core.paths',
        )
        ->not->toContain(
            'gmail.email.send',
            'google_sheets.row.create',
            'core.code',
            'core.webhook',
            'core.loop',
        )
        ->and($catalog->handlerClass('core.filter'))
        ->toBe(\App\Services\Automation\V2\Operations\FilterControlHandler::class)
        ->and($public['components'])->not->toBeEmpty()
        ->and(collect($public['components'])->contains(
            fn (array $component): bool => array_key_exists('handler', $component)
        ))->toBeFalse()
        ->and($public['components'][0])->toHaveKeys([
            'key',
            'label',
            'kind',
            'provider',
            'available',
            'config_fields',
            'connection_required',
            'test_policy',
            'category',
        ])
        ->and($googleFieldKeys->all())
        ->toContain('source_id', 'title', 'description', 'starts_at', 'ends_at', 'location')
        ->not->toContain('inputs', 'presentation')
        ->and($googleFieldKeys->duplicates()->all())->toBe([])
        ->and($googleFieldKeys->filter(
            fn (string $key): bool => $key === 'default_start_time'
        )->count())->toBe(1)
        ->and($pastDateField['default'])->toBe('continue_if_within_1_day')
        ->and(collect($pastDateField['options'])->pluck('value')->all())->toBe([
            'continue_if_within_15_minutes',
            'continue_if_within_1_hour',
            'continue_if_within_1_day',
            'continue',
        ])
        ->and(array_keys($catalog->templates()))
        ->toBe([
            'asana_to_google_calendar',
            'shopify_order_to_google_calendar',
            'square_order_to_google_calendar',
            'completed_job_follow_up_draft',
            'shopify_order_review_request_draft',
        ]);
});

test('the v2 rollout gate is tenant explicit and treats an empty allowlist as all tenants', function (): void {
    $gate = app(WorkflowStudioFeatureGate::class);

    config()->set('automation_workflows.v2_enabled', true);
    config()->set('automation_workflows.v2_tenant_ids', [1]);
    expect($gate->enabledForTenant(1))->toBeTrue()
        ->and($gate->enabledForTenant(2))->toBeFalse();

    config()->set('automation_workflows.v2_tenant_ids', []);
    expect($gate->enabledForTenant(2))->toBeTrue();

    config()->set('automation_workflows.v2_enabled', false);
    expect($gate->enabledForTenant(1))->toBeFalse();
});

test('the compiler canonicalizes paths and rejects unsafe or forward mappings', function (): void {
    [$tenant, $user] = workflowStudioFoundationTenant('studio-compiler');
    $asana = workflowStudioConnection($tenant, 'asana', $user);
    $calendar = workflowStudioConnection($tenant, 'google_calendar', $user);
    $compiler = app(WorkflowDefinitionCompiler::class);

    $compiled = $compiler->compileForPublish(
        workflowStudioDefinition($asana, $calendar),
        $tenant->id
    );

    expect($compiled['schema_version'])->toBe(2)
        ->and(data_get($compiled, 'steps.0.config.conditions.0.field.type'))->toBe('mapping')
        ->and(data_get($compiled, 'steps.0.config.conditions.0.field.path'))->toBe('trigger.output.completed')
        ->and(data_get($compiled, 'steps.2.config.branches.0.rule_type'))->toBe('custom')
        ->and(data_get($compiled, 'steps.2.config.branches.0.condition.logic'))->toBe('and')
        ->and(data_get($compiled, 'steps.2.config.branches.1.rule_type'))->toBe('fallback')
        ->and(data_get($compiled, 'steps.2.branches'))->toBeNull()
        ->and(data_get($compiled, 'steps.0.handler'))->toBeNull();

    $uiAliasDefinition = workflowStudioDefinition($asana, $calendar);
    data_set($uiAliasDefinition, 'steps.1.config.value_source', 'mapped');
    data_set($uiAliasDefinition, 'steps.1.config.duration', '{{ trigger.output.wait_minutes }}');
    $uiAliasCompiled = $compiler->compileForPublish($uiAliasDefinition, $tenant->id);
    expect(data_get($uiAliasCompiled, 'steps.1.config.duration'))->toBe([
        'type' => 'mapping',
        'path' => 'trigger.output.wait_minutes',
    ])->and(data_get($uiAliasCompiled, 'steps.1.config.value_source'))->toBeNull();

    $operatorAliases = [
        'after',
        'before',
        'date_equals',
        'is_in',
        'is_not_in',
        'does_not_start_with',
        'does_not_end_with',
        'contains_any',
        'contains_all',
    ];
    $operatorDefinition = workflowStudioDefinition($asana, $calendar);
    data_set($operatorDefinition, 'steps.0.config.conditions', array_map(
        fn (string $operator): array => [
            'field' => 'trigger.output.name',
            'operator' => $operator,
            'value' => 'comparison',
        ],
        $operatorAliases
    ));
    $operatorCompiled = $compiler->compileForPublish($operatorDefinition, $tenant->id);
    expect(data_get($operatorCompiled, 'steps.0.config.conditions'))->toHaveCount(count($operatorAliases));

    $unsafe = workflowStudioDefinition($asana, $calendar);
    $futureStepId = (string) data_get($unsafe, 'steps.2.branches.0.steps.0.id');
    data_set($unsafe, 'steps.0.config.conditions.0.field', "steps.{$futureStepId}.output.event_id");
    data_set($unsafe, 'steps.0.handler', 'App\\Unsafe\\ArbitraryHandler');

    try {
        $compiler->compileDraft($unsafe, $tenant->id);
        $this->fail('The compiler accepted a mapping to a future branch step.');
    } catch (WorkflowDefinitionException $exception) {
        expect($exception->errors())->toHaveKey('steps.0.config.conditions.0.field');
    }
});

test('publishing requires an executable action in every path branch', function (): void {
    [$tenant, $user] = workflowStudioFoundationTenant('studio-path-readiness');
    $asana = workflowStudioConnection($tenant, 'asana', $user);
    $calendar = workflowStudioConnection($tenant, 'google_calendar', $user);
    $definition = workflowStudioDefinition($asana, $calendar);
    data_set($definition, 'steps.2.branches.0.steps', []);

    try {
        app(WorkflowDefinitionCompiler::class)->compileForPublish($definition, $tenant->id);
        $this->fail('The compiler published a Paths branch with no action.');
    } catch (WorkflowDefinitionException $exception) {
        expect($exception->errors())
            ->toHaveKey('steps.2.config.branches.0.steps');
    }
});

test('legacy definitions convert without changing their published v1 shape', function (): void {
    $converted = app(LegacyWorkflowDefinitionConverter::class)->convert([
        'template_key' => 'asana_to_google_calendar',
        'driver' => 'asana_google_calendar',
        'trigger' => [
            'provider' => 'asana',
            'event' => 'New or updated dated task',
            'connection_id' => 12,
            'project_gid' => 'project-12',
            'poll_limit' => 100,
        ],
        'action' => [
            'provider' => 'google_calendar',
            'event' => 'Create or update event',
            'connection_id' => 18,
            'calendar_id' => 'calendar@example.com',
            'timezone' => 'America/New_York',
        ],
    ]);

    expect($converted['schema_version'])->toBe(2)
        ->and(data_get($converted, 'trigger.component_key'))->toBe('asana.task.created_or_updated')
        ->and(data_get($converted, 'trigger.connection_id'))->toBe(12)
        ->and(data_get($converted, 'steps.0.component_key'))->toBe('google_calendar.event.upsert')
        ->and(data_get($converted, 'steps.0.connection_id'))->toBe(18)
        ->and(data_get($converted, 'metadata.converted_from_schema_version'))->toBe(1)
        ->and(Str::isUlid((string) data_get($converted, 'trigger.id')))->toBeTrue()
        ->and(Str::isUlid((string) data_get($converted, 'steps.0.id')))->toBeTrue();
});

test('template drafts use the same canonical v2 contract as blank workflows', function (): void {
    [$tenant, $user] = workflowStudioFoundationTenant('studio-template-draft');
    $workflow = app(WorkflowDraftService::class)->createFromTemplate(
        $tenant->id,
        'asana_to_google_calendar',
        $user,
    );

    expect($workflow->template_key)->toBe('asana_to_google_calendar')
        ->and($workflow->draft_revision)->toBe(1)
        ->and($workflow->definition_schema_version)->toBe(2)
        ->and(data_get($workflow->draft_definition, 'schema_version'))->toBe(2)
        ->and(data_get($workflow->draft_definition, 'trigger.component_key'))->toBe('asana.task.created_or_updated')
        ->and(data_get($workflow->draft_definition, 'steps.0.component_key'))->toBe('google_calendar.event.upsert')
        ->and(data_get($workflow->draft_definition, 'metadata.source_template_key'))->toBe('asana_to_google_calendar');
});

test('draft saves enforce optimistic revisions and clear stale tests', function (): void {
    [$tenant, $user] = workflowStudioFoundationTenant('studio-revisions');
    $asana = workflowStudioConnection($tenant, 'asana', $user);
    $calendar = workflowStudioConnection($tenant, 'google_calendar', $user);
    $drafts = app(WorkflowDraftService::class);

    $workflow = $drafts->createBlank(
        $tenant->id,
        $user,
        'Customer scheduling',
        'asana.task.created_or_updated',
        $asana->id,
    );
    expect($workflow->draft_revision)->toBe(1)
        ->and($workflow->definition_schema_version)->toBe(2);

    $definition = (array) $workflow->draft_definition;
    data_set($definition, 'trigger.config.project_gid', 'project-1');
    $definition['steps'][] = [
        'id' => (string) Str::ulid(),
        'kind' => 'action',
        'component_key' => 'google_calendar.event.upsert',
        'connection_id' => $calendar->id,
        'config' => ['calendar_id' => 'calendar@example.com'],
    ];
    $workflow->forceFill(['test_state' => ['old-step' => ['ok' => true]]])->save();

    $saved = $drafts->save(
        $tenant->id,
        $workflow->id,
        1,
        $definition,
        $user,
        'Customer scheduling v2',
    );
    expect($saved->draft_revision)->toBe(2)
        ->and($saved->name)->toBe('Customer scheduling v2')
        ->and($saved->test_state)->toBe([])
        ->and(AutomationWorkflowAuditEvent::query()->forAllTenants()
            ->where('automation_workflow_id', $workflow->id)
            ->where('event_type', 'draft_updated')
            ->count())->toBe(1);

    expect(fn () => $drafts->save(
        $tenant->id,
        $workflow->id,
        1,
        $definition,
        $user,
    ))->toThrow(WorkflowDraftConflictException::class);
});

test('drafts reject cross-tenant connection and workflow identifiers', function (): void {
    [$tenant, $user] = workflowStudioFoundationTenant('studio-owner');
    [$otherTenant, $otherUser] = workflowStudioFoundationTenant('studio-forgery-target');
    $otherAsana = workflowStudioConnection($otherTenant, 'asana', $otherUser);
    $drafts = app(WorkflowDraftService::class);

    $workflow = $drafts->createBlank($tenant->id, $user);
    $forgedDefinition = [
        'schema_version' => 2,
        'trigger' => [
            'id' => (string) Str::ulid(),
            'kind' => 'trigger',
            'component_key' => 'asana.task.created_or_updated',
            'connection_id' => $otherAsana->id,
            'config' => ['project_gid' => 'forged-project'],
        ],
        'steps' => [],
        'settings' => [
            'poll_interval_minutes' => 10,
            'max_items_per_poll' => 100,
        ],
    ];

    try {
        $drafts->save(
            $tenant->id,
            $workflow->id,
            1,
            $forgedDefinition,
            $user,
        );
        $this->fail('A connection owned by another tenant was accepted.');
    } catch (WorkflowDefinitionException $exception) {
        expect($exception->errors())->toHaveKey('trigger.connection_id');
    }

    expect($workflow->fresh()->draft_revision)->toBe(1)
        ->and(fn () => $drafts->load($tenant->id, AutomationWorkflow::query()
            ->forAllTenants()
            ->create([
                'tenant_id' => $otherTenant->id,
                'template_key' => 'blank',
                'name' => 'Other tenant workflow',
                'status' => AutomationWorkflow::STATUS_DRAFT,
                'draft_definition' => $drafts->blankDefinition(),
                'definition_schema_version' => 2,
                'draft_revision' => 1,
            ])->id))
        ->toThrow(ModelNotFoundException::class);
});

test('the draft API returns field errors for invalid definitions', function (): void {
    [$tenant, $user] = workflowStudioFoundationTenant('studio-api-errors');
    $workflow = app(WorkflowDraftService::class)->createBlank($tenant->id, $user);

    $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->putJson(route('workflows.studio.save', $workflow), [
            'draft_revision' => 1,
            'definition' => [
                'schema_version' => 2,
                'trigger' => [
                    'id' => (string) Str::ulid(),
                    'kind' => 'trigger',
                    'component_key' => 'asana.task.created_or_updated',
                    'connection_id' => 999999,
                    'config' => ['project_gid' => 'project-1'],
                ],
                'steps' => [],
                'settings' => [
                    'poll_interval_minutes' => 10,
                    'max_items_per_poll' => 100,
                ],
            ],
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', 'The workflow definition is invalid.')
        ->assertJsonPath('errors', [
            'trigger.connection_id' => [
                'The selected connection is not available in this workspace.',
            ],
        ]);
});
