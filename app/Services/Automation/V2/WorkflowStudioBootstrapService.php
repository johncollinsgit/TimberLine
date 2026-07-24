<?php

namespace App\Services\Automation\V2;

use App\Models\AutomationWorkflow;
use App\Models\IntegrationConnection;
use App\Services\Automation\AsanaWorkflowConnectionService;
use App\Services\Automation\GoogleCalendarWorkflowConnectionService;
use Illuminate\Support\Str;
use Throwable;

class WorkflowStudioBootstrapService
{
    public function __construct(
        protected WorkflowComponentCatalog $catalog,
        protected AsanaWorkflowConnectionService $asana,
        protected GoogleCalendarWorkflowConnectionService $googleCalendar,
    ) {}

    /** @return array<string,mixed> */
    public function forNew(int $tenantId, string $initialPicker = 'home'): array
    {
        return $this->payload($tenantId, null, [
            'schema_version' => 2,
            'trigger' => null,
            'steps' => [],
            'settings' => $this->defaultSettings(),
        ], $initialPicker);
    }

    /**
     * @param  array<string,mixed>|null  $definition
     * @return array<string,mixed>
     */
    public function forWorkflow(AutomationWorkflow $workflow, ?array $definition = null): array
    {
        return $this->payload(
            (int) $workflow->tenant_id,
            $workflow,
            $definition ?? (array) $workflow->draft_definition,
        );
    }

    /** @return array<string,mixed> */
    public function apiPayload(AutomationWorkflow $workflow, ?array $definition = null): array
    {
        $bootstrap = $this->forWorkflow($workflow, $definition);

        return [
            'workflow' => $bootstrap['workflow'],
            'definition' => $definition ?? $bootstrap['definition'],
            'draft_revision' => $workflow->draft_revision,
            'test_state' => $workflow->test_state ?? [],
            'endpoints' => $bootstrap['endpoints'],
            'url' => route('workflows.show', $workflow),
        ];
    }

    /** @return array<string,string> */
    public function endpoints(?AutomationWorkflow $workflow = null): array
    {
        $workflowToken = $workflow?->getKey() ?? '{workflow}';

        return [
            'index' => route('workflows.index'),
            'create' => route('workflows.store'),
            'show' => $workflow
                ? route('workflows.show', $workflow)
                : url('/workflows/{workflow}'),
            'load' => $workflow
                ? route('workflows.studio.load', $workflow)
                : url('/workflows/'.$workflowToken.'/builder'),
            'save' => $workflow
                ? route('workflows.studio.save', $workflow)
                : url('/workflows/'.$workflowToken.'/draft'),
            'test_step' => $workflow
                ? url('/workflows/'.$workflow->getKey().'/steps/{step}/test')
                : url('/workflows/'.$workflowToken.'/steps/{step}/test'),
            'test_run' => $workflow
                ? route('workflows.studio.test-run', $workflow)
                : url('/workflows/'.$workflowToken.'/test-run'),
            'publish' => $workflow
                ? route('workflows.studio.publish', $workflow)
                : url('/workflows/'.$workflowToken.'/actions/publish'),
            'pause' => $workflow
                ? route('workflows.studio.pause', $workflow)
                : url('/workflows/'.$workflowToken.'/actions/pause'),
            'resume' => $workflow
                ? route('workflows.studio.resume', $workflow)
                : url('/workflows/'.$workflowToken.'/actions/resume'),
            'discard_held' => $workflow
                ? route('workflows.studio.discard-held', $workflow)
                : url('/workflows/'.$workflowToken.'/actions/discard-held'),
            'run' => $workflow
                ? route('workflows.run', $workflow)
                : url('/workflows/'.$workflowToken.'/run'),
            'connections' => route('workflows.connections', [
                'return_path' => $workflow
                    ? route('workflows.show', $workflow, absolute: false)
                    : route('workflows.create', absolute: false),
            ]),
            'history' => route('workflows.history'),
        ];
    }

    /**
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    protected function payload(
        int $tenantId,
        ?AutomationWorkflow $workflow,
        array $definition,
        string $initialPicker = 'home',
    ): array {
        $publicCatalog = $this->catalog->publicCatalog();

        return [
            'mode' => $workflow ? 'edit' : 'create',
            'csrf_token' => csrf_token(),
            'workflow' => $workflow
                ? $this->workflowRecord($workflow)
                : [
                    'id' => null,
                    'name' => 'Untitled workflow',
                    'status' => AutomationWorkflow::STATUS_DRAFT,
                    'draft_revision' => 0,
                    'published_version' => null,
                ],
            'definition' => $definition,
            'catalog' => [
                'components' => $this->withProviderOptions(
                    (array) $publicCatalog['components'],
                    $tenantId,
                ),
            ],
            'templates' => $this->templates(),
            'connections' => $this->connections($tenantId),
            'test_state' => $workflow?->test_state ?? [],
            'endpoints' => $this->endpoints($workflow),
            'initial_picker' => in_array($initialPicker, ['home', 'apps', 'controls', 'utilities', 'templates'], true)
                ? $initialPicker
                : 'home',
        ];
    }

    /**
     * Dynamic provider fields remain usable selects without placing provider
     * records or credentials in the public component registry.
     *
     * @param  list<array<string,mixed>>  $components
     * @return list<array<string,mixed>>
     */
    protected function withProviderOptions(array $components, int $tenantId): array
    {
        $projectOptions = [];
        $calendarOptions = [];

        try {
            $projectOptions = collect((array) data_get($this->asana->status($tenantId), 'projects', []))
                ->filter(fn (mixed $project): bool => is_array($project))
                ->map(fn (array $project): array => [
                    'value' => (string) ($project['gid'] ?? ''),
                    'label' => trim(implode(' · ', array_filter([
                        (string) ($project['workspace_name'] ?? ''),
                        (string) ($project['name'] ?? ''),
                    ]))),
                ])
                ->filter(fn (array $option): bool => $option['value'] !== '' && $option['label'] !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            // The connection panel remains the recovery path when discovery fails.
        }

        try {
            $calendarOptions = collect((array) data_get($this->googleCalendar->status($tenantId), 'calendars', []))
                ->filter(fn (mixed $calendar): bool => is_array($calendar))
                ->map(fn (array $calendar): array => [
                    'value' => (string) ($calendar['id'] ?? ''),
                    'label' => (string) ($calendar['summary'] ?? ''),
                ])
                ->filter(fn (array $option): bool => $option['value'] !== '' && $option['label'] !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            // A provider outage must not prevent the Studio itself from opening.
        }

        return array_values(array_map(
            function (array $component) use ($projectOptions, $calendarOptions): array {
                $fieldOptions = match ((string) ($component['key'] ?? '')) {
                    'asana.task.created_or_updated' => ['project_gid' => $projectOptions],
                    'google_calendar.event.upsert' => ['calendar_id' => $calendarOptions],
                    default => [],
                };
                if ($fieldOptions === []) {
                    return $component;
                }

                $hydrateFields = function (array $fields) use ($fieldOptions): array {
                    return array_values(array_map(
                        function (array $field) use ($fieldOptions): array {
                            $key = (string) ($field['key'] ?? '');
                            if (array_key_exists($key, $fieldOptions)) {
                                $field['type'] = 'select';
                                $field['options'] = $fieldOptions[$key];
                            }

                            return $field;
                        },
                        $fields,
                    ));
                };

                $component['config_fields'] = $hydrateFields(
                    (array) ($component['config_fields'] ?? []),
                );
                $configSchema = (array) ($component['config_schema'] ?? []);
                $configSchema['fields'] = $hydrateFields(
                    (array) ($configSchema['fields'] ?? []),
                );
                $component['config_schema'] = $configSchema;

                return $component;
            },
            $components,
        ));
    }

    /** @return array<string,mixed> */
    protected function workflowRecord(AutomationWorkflow $workflow): array
    {
        $publishedVersion = $workflow->relationLoaded('publishedVersion')
            ? $workflow->publishedVersion
            : $workflow->publishedVersion()->first();

        return [
            'id' => $workflow->getKey(),
            'name' => $workflow->name,
            'status' => $workflow->status,
            'draft_revision' => (int) $workflow->draft_revision,
            'published_version' => $publishedVersion?->version,
            'test_state' => $workflow->test_state ?? [],
            'draft_definition' => $workflow->draft_definition,
        ];
    }

    /** @return array<string,list<array<string,mixed>>> */
    protected function connections(int $tenantId): array
    {
        return IntegrationConnection::query()
            ->forTenantId($tenantId)
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->orderBy('provider')
            ->orderBy('external_account_label')
            ->get(['id', 'provider', 'external_account_label', 'status'])
            ->groupBy('provider')
            ->map(fn ($rows): array => $rows->map(fn (IntegrationConnection $connection): array => [
                'id' => $connection->getKey(),
                'provider' => $connection->provider,
                'label' => $connection->external_account_label ?: str($connection->provider)->headline().' account',
                'status' => $connection->status,
            ])->values()->all())
            ->all();
    }

    /** @return list<array<string,mixed>> */
    protected function templates(): array
    {
        return collect($this->catalog->templates())
            ->filter(fn (array $template): bool => (bool) ($template['available'] ?? false))
            ->map(function (array $template): array {
                $triggerKey = (string) $template['trigger_component_key'];
                $triggerComponent = $this->catalog->component($triggerKey) ?? [];
                $steps = [];
                $firstActionProvider = '';

                foreach ((array) ($template['step_component_keys'] ?? []) as $index => $componentKey) {
                    $component = $this->catalog->component((string) $componentKey) ?? [];
                    if ($firstActionProvider === '') {
                        $firstActionProvider = (string) ($component['provider'] ?? '');
                    }
                    $steps[] = [
                        'id' => (string) Str::ulid(),
                        'kind' => (string) ($component['kind'] ?? 'action'),
                        'component_key' => (string) $componentKey,
                        'connection_id' => null,
                        'config' => array_replace(
                            $this->defaultConfig($component),
                            (array) data_get($template, "step_configs.{$index}", []),
                        ),
                    ];
                }

                return [
                    'key' => (string) $template['key'],
                    'name' => (string) $template['name'],
                    'description' => (string) $template['description'],
                    'available' => true,
                    'trigger_provider' => (string) ($triggerComponent['provider'] ?? ''),
                    'action_provider' => $firstActionProvider,
                    'definition' => [
                        'schema_version' => 2,
                        'trigger' => [
                            'id' => (string) Str::ulid(),
                            'kind' => 'trigger',
                            'component_key' => $triggerKey,
                            'connection_id' => null,
                            'config' => array_replace(
                                $this->defaultConfig($triggerComponent),
                                (array) ($template['trigger_config'] ?? []),
                            ),
                        ],
                        'steps' => $steps,
                        'settings' => $this->defaultSettings(),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $component
     * @return array<string,mixed>
     */
    protected function defaultConfig(array $component): array
    {
        return collect((array) ($component['config_fields'] ?? []))
            ->filter(fn (array $field): bool => array_key_exists('default', $field))
            ->mapWithKeys(fn (array $field): array => [(string) $field['key'] => $field['default']])
            ->all();
    }

    /** @return array{poll_interval_minutes:int,max_items_per_poll:int} */
    protected function defaultSettings(): array
    {
        return [
            'poll_interval_minutes' => max(1, (int) config('automation_workflows.default_poll_interval_minutes', 10)),
            'max_items_per_poll' => 100,
        ];
    }
}
