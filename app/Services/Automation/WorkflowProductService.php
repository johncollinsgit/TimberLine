<?php

namespace App\Services\Automation;

use App\Models\AutomationWorkflow;
use App\Models\AutomationWorkflowAuditEvent;
use App\Models\AutomationWorkflowRun;
use App\Models\AutomationWorkflowRunStep;
use App\Models\AutomationWorkflowVersion;
use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class WorkflowProductService
{
    public function __construct(
        protected WorkflowTemplateCatalog $catalog,
        protected TenantWorkflowAutomationSettingsService $legacySettings,
        protected AsanaWorkflowConnectionService $asana,
        protected GoogleCalendarWorkflowConnectionService $google,
        protected AutomationWorkflowEngine $engine,
        protected CalendarEventPresentationService $calendarPresentation,
        protected CommerceWorkflowConnectionService $commerceConnections,
        protected CommerceOrderSourceService $commerceOrders,
    ) {}

    public function create(int $tenantId, string $templateKey, User $actor): AutomationWorkflow
    {
        $template = $this->catalog->template($templateKey);
        if (! (bool) ($template['launchable'] ?? false)) {
            throw new AutomationWorkflowException((string) ($template['name'] ?? 'This connector').' is in connector beta and cannot be published yet.');
        }

        $workflow = AutomationWorkflow::query()->create([
            'tenant_id' => $tenantId,
            'template_key' => $templateKey,
            'name' => (string) $template['name'],
            'status' => AutomationWorkflow::STATUS_DRAFT,
            'draft_definition' => $this->catalog->defaultDefinition($templateKey),
            'test_state' => [],
            'created_by_user_id' => $actor->id,
            'updated_by_user_id' => $actor->id,
        ]);
        $this->audit($workflow, $actor, 'created', null, $this->snapshot($workflow));

        return $workflow;
    }

    /** @param array<string,mixed> $payload */
    public function updateDraft(AutomationWorkflow $workflow, array $payload, User $actor): AutomationWorkflow
    {
        $before = $this->snapshot($workflow);
        $definition = (array) $workflow->draft_definition;
        $triggerProvider = (string) data_get($definition, 'trigger.provider', 'asana');
        if (in_array($triggerProvider, CommerceWorkflowConnectionService::PROVIDERS, true)) {
            $connectionId = (int) ($payload['trigger_connection_id'] ?? data_get($definition, 'trigger.connection_id', 0));
            $connection = $this->commerceConnections->connectionForTenant((int) $workflow->tenant_id, $connectionId, $triggerProvider);
            if ($triggerProvider === 'square') {
                $requestedLocations = array_values(array_unique(array_filter(array_map('strval', (array) ($payload['location_ids'] ?? [])))));
                $availableLocations = array_column($this->commerceConnections->sourceOptions($connection), 'id');
                if (array_diff($requestedLocations, $availableLocations) !== []) {
                    throw new AutomationWorkflowException('Choose only active locations from the connected Square account.');
                }
            }
        }
        data_set($definition, 'trigger.project_gid', $this->nullableString($payload['project_gid'] ?? null));
        data_set($definition, 'trigger.connection_id', isset($payload['trigger_connection_id']) ? (int) $payload['trigger_connection_id'] : data_get($definition, 'trigger.connection_id'));
        data_set($definition, 'trigger.location_ids', array_values(array_unique(array_filter(array_map('strval', (array) ($payload['location_ids'] ?? data_get($definition, 'trigger.location_ids', [])))))));
        data_set($definition, 'trigger.schedule_source', (string) ($payload['schedule_source'] ?? data_get($definition, 'trigger.schedule_source', 'source_date')));
        data_set($definition, 'action.calendar_id', $this->nullableString($payload['calendar_id'] ?? null));
        data_set($definition, 'action.timezone', trim((string) ($payload['timezone'] ?? config('app.timezone', 'UTC'))));
        data_set($definition, 'action.default_duration_minutes', min(1440, max(1, (int) ($payload['default_duration_minutes'] ?? 60))));
        data_set($definition, 'action.default_start_time', (string) ($payload['default_start_time'] ?? data_get($definition, 'action.default_start_time', '09:00')));
        data_set($definition, 'action.event_time_mode', in_array(($payload['event_time_mode'] ?? null), ['source_time', 'fixed_time', 'all_day'], true) ? (string) $payload['event_time_mode'] : 'source_time');
        data_set($definition, 'action.schedule_offset_days', min(365, max(-365, (int) ($payload['schedule_offset_days'] ?? 0))));
        data_set($definition, 'action.skip_completed_tasks', (bool) ($payload['skip_completed_tasks'] ?? false));
        data_set($definition, 'action.date_only_mode', 'all_day');
        data_set(
            $definition,
            'action.presentation',
            $this->calendarPresentation->fromPayload(
                $payload,
                (string) data_get($definition, 'trigger.provider'),
                (array) data_get($definition, 'action.presentation', [])
            )
        );

        $workflow->fill([
            'name' => trim((string) ($payload['name'] ?? $workflow->name)),
            'draft_definition' => $definition,
            'test_state' => [],
            'updated_by_user_id' => $actor->id,
        ])->save();
        $this->audit($workflow, $actor, 'draft_updated', $before, $this->snapshot($workflow));

        return $workflow->fresh();
    }

    /** @return array<string,mixed> */
    public function testTrigger(AutomationWorkflow $workflow, User $actor): array
    {
        $provider = (string) data_get($workflow->draft_definition, 'trigger.provider', 'asana');
        if (in_array($provider, CommerceWorkflowConnectionService::PROVIDERS, true)) {
            $connection = $this->selectedConnection($workflow, $provider, 'trigger.connection_id');
            $test = $this->commerceConnections->test($connection);
            $samples = in_array($provider, CommerceOrderSourceService::LIVE_PROVIDERS, true)
                ? $this->commerceOrders->sample(
                    $provider,
                    (int) $workflow->tenant_id,
                    (int) $connection->id,
                    (array) data_get($workflow->draft_definition, 'trigger.location_ids', []),
                )
                : [];

            return $this->recordTest($workflow, $actor, 'trigger', [
                'ok' => true,
                'definition_hash' => $this->definitionHash((array) $workflow->draft_definition),
                'tested_at' => now()->toIso8601String(),
                'summary' => count($samples) > 0
                    ? 'Connected to '.($connection->external_account_label ?: str($provider)->headline().' account').' and loaded a recent order sample.'
                    : 'Connected to '.($connection->external_account_label ?: str($provider)->headline().' account').'; no recent order sample was available.',
                'source_options' => (array) ($test['source_options'] ?? []),
            ]);
        }
        $projectGid = $this->nullableString(data_get($workflow->draft_definition, 'trigger.project_gid'));
        if ($projectGid === null) {
            throw new AutomationWorkflowException('Choose an Asana project before testing the trigger.');
        }
        $projects = $this->asana->projectOptions((int) $workflow->tenant_id, forceRefresh: true);
        $project = collect($projects)->first(fn (array $row): bool => (string) ($row['gid'] ?? '') === $projectGid);
        if (! is_array($project)) {
            throw new AutomationWorkflowException('The selected Asana project is not visible to the connected account.');
        }

        return $this->recordTest($workflow, $actor, 'trigger', [
            'ok' => true,
            'definition_hash' => $this->definitionHash((array) $workflow->draft_definition),
            'tested_at' => now()->toIso8601String(),
            'summary' => 'Connected to '.(string) ($project['name'] ?? 'the selected project').'.',
        ]);
    }

    /** @return array<string,mixed> */
    public function testAction(AutomationWorkflow $workflow, User $actor): array
    {
        $calendarId = $this->nullableString(data_get($workflow->draft_definition, 'action.calendar_id'));
        if ($calendarId === null) {
            throw new AutomationWorkflowException('Choose a Google Calendar before testing the action.');
        }
        $calendars = $this->google->calendarOptions((int) $workflow->tenant_id, forceRefresh: true);
        $calendar = collect($calendars)->first(fn (array $row): bool => (string) ($row['id'] ?? '') === $calendarId);
        if (! is_array($calendar)) {
            throw new AutomationWorkflowException('The selected calendar is not writable by the connected account.');
        }
        $write = $this->google->testCalendarWrite((int) $workflow->tenant_id, $calendarId);

        $result = $this->recordTest($workflow, $actor, 'action', [
            'ok' => (bool) ($write['ok'] ?? false) && (bool) ($write['cleanup_ok'] ?? false),
            'definition_hash' => $this->definitionHash((array) $workflow->draft_definition),
            'tested_at' => now()->toIso8601String(),
            'summary' => (bool) ($write['cleanup_ok'] ?? false)
                ? 'Created and safely removed a test event in '.(string) ($calendar['summary'] ?? 'Google Calendar').'.'
                : 'The test event was created, but automatic cleanup needs attention.',
        ]);

        if (! (bool) ($result['ok'] ?? false)) {
            throw new AutomationWorkflowException('The test event was created, but Everbranch could not remove it. Delete the labeled test event and try again.');
        }

        return $result;
    }

    public function publish(AutomationWorkflow $workflow, User $actor): AutomationWorkflow
    {
        if (! $this->connectionsReady($workflow)) {
            throw new AutomationWorkflowException('Reconnect the selected trigger and Google Calendar accounts before publishing.');
        }
        $hash = $this->definitionHash((array) $workflow->draft_definition);
        foreach (['trigger', 'action'] as $test) {
            if (! data_get($workflow->test_state, $test.'.ok') || data_get($workflow->test_state, $test.'.definition_hash') !== $hash) {
                throw new AutomationWorkflowException('Test both workflow steps after the latest change before publishing.');
            }
        }

        return DB::transaction(function () use ($workflow, $actor, $hash): AutomationWorkflow {
            $before = $this->snapshot($workflow);
            $nextVersion = ((int) $workflow->versions()->max('version')) + 1;
            $version = AutomationWorkflowVersion::query()->create([
                'tenant_id' => $workflow->tenant_id,
                'automation_workflow_id' => $workflow->id,
                'version' => $nextVersion,
                'definition_hash' => $hash,
                'definition' => $workflow->draft_definition,
                'published_by_user_id' => $actor->id,
                'published_at' => now(),
            ]);
            $workflow->fill([
                'published_version_id' => $version->id,
                'status' => AutomationWorkflow::STATUS_ACTIVE,
                'published_at' => now(),
                'updated_by_user_id' => $actor->id,
            ])->save();
            $this->audit($workflow, $actor, 'published', $before, $this->snapshot($workflow), ['version' => $nextVersion]);

            return $workflow->fresh(['publishedVersion']);
        });
    }

    public function pause(AutomationWorkflow $workflow, User $actor): AutomationWorkflow
    {
        return $this->setStatus($workflow, AutomationWorkflow::STATUS_PAUSED, $actor, 'paused');
    }

    public function resume(AutomationWorkflow $workflow, User $actor): AutomationWorkflow
    {
        if ($workflow->published_version_id === null) {
            throw new AutomationWorkflowException('Publish this workflow before turning it on.');
        }
        if (! $this->connectionsReady($workflow)) {
            throw new AutomationWorkflowException('Reconnect both accounts before turning this workflow on.');
        }

        return $this->setStatus($workflow, AutomationWorkflow::STATUS_ACTIVE, $actor, 'resumed');
    }

    public function run(AutomationWorkflow $workflow, string $mode = 'manual', ?User $actor = null, bool $dryRun = false): AutomationWorkflowRun
    {
        $version = $workflow->publishedVersion;
        if (! $version) {
            throw new AutomationWorkflowException('Publish this workflow before running it.');
        }

        $run = AutomationWorkflowRun::query()->create([
            'tenant_id' => $workflow->tenant_id,
            'automation_workflow_id' => $workflow->id,
            'automation_workflow_version_id' => $version->id,
            'mode' => $dryRun ? 'test' : $mode,
            'status' => 'running',
            'initiated_by_user_id' => $actor?->id,
            'started_at' => now(),
        ]);
        $started = microtime(true);
        $definition = $this->runtimeDefinition($workflow, (array) $version->definition);
        $result = $this->engine->runDefinition('workflow:'.$workflow->id, $definition, $dryRun);
        $ok = (bool) ($result['ok'] ?? false);
        $status = $ok ? 'success' : ((string) ($result['status'] ?? 'failed'));
        $safeError = $ok ? null : $this->safeError((string) ($result['message'] ?? data_get($result, 'errors.0', 'Workflow run failed.')));

        $run->fill([
            'status' => $status,
            'counts' => (array) ($result['counts'] ?? []),
            'context' => ['dry_run_counts' => (array) ($result['dry_run_counts'] ?? [])],
            'error_summary' => $safeError,
            'finished_at' => now(),
        ])->save();
        foreach ([
            [1, 'trigger', (string) data_get($definition, 'trigger.provider', 'asana')],
            [2, 'action', (string) data_get($definition, 'action.provider', 'google_calendar')],
        ] as [$position, $kind, $provider]) {
            AutomationWorkflowRunStep::query()->create([
                'tenant_id' => $workflow->tenant_id,
                'automation_workflow_run_id' => $run->id,
                'position' => $position,
                'step_key' => $kind,
                'provider' => $provider,
                'kind' => $kind,
                'status' => $status,
                'summary' => $kind === 'action' ? (array) ($result['counts'] ?? []) : ['fetched' => (int) data_get($result, 'counts.fetched', 0)],
                'error_message' => $safeError,
                'duration_ms' => (int) round((microtime(true) - $started) * 1000),
            ]);
        }
        $workflow->forceFill(['last_run_at' => now()])->save();
        $this->updateConnectionHealth($workflow, $definition, $ok, $safeError);

        return $run->fresh('steps');
    }

    public function pauseForProvider(int $tenantId, string $provider, ?User $actor = null): int
    {
        $workflows = AutomationWorkflow::query()->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->where('status', AutomationWorkflow::STATUS_ACTIVE)
            ->get()
            ->filter(fn (AutomationWorkflow $workflow): bool => in_array($provider, [
                (string) data_get($workflow->draft_definition, 'trigger.provider'),
                (string) data_get($workflow->draft_definition, 'action.provider'),
            ], true));

        foreach ($workflows as $workflow) {
            $before = $this->snapshot($workflow);
            $workflow->forceFill(['status' => AutomationWorkflow::STATUS_PAUSED, 'updated_by_user_id' => $actor?->id])->save();
            $this->audit($workflow, $actor, 'paused_connection_disconnected', $before, $this->snapshot($workflow), ['provider' => $provider]);
        }

        return $workflows->count();
    }

    /** @return array<string,mixed> */
    protected function runtimeDefinition(AutomationWorkflow $workflow, array $definition): array
    {
        $base = (array) config('automation_workflows.workflows.asana_to_google_calendar', []);
        $triggerProvider = (string) data_get($definition, 'trigger.provider', 'asana');
        $connections = IntegrationConnection::query()->forTenantId((int) $workflow->tenant_id)
            ->whereIn('provider', array_values(array_unique([$triggerProvider, 'google_calendar'])))
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->latest('connected_at')
            ->latest('id')
            ->get()
            ->groupBy('provider');
        $triggerConnectionId = (int) data_get($definition, 'trigger.connection_id', 0);
        $triggerConnection = $triggerConnectionId > 0
            ? $connections->get($triggerProvider)?->firstWhere('id', $triggerConnectionId)
            : $connections->get($triggerProvider)?->first();
        $googleConnection = $connections->get('google_calendar')?->first();
        $isLegacyMigration = AutomationWorkflowAuditEvent::query()->forAllTenants()
            ->where('tenant_id', $workflow->tenant_id)
            ->where('automation_workflow_id', $workflow->id)
            ->where('event_type', 'legacy_migrated')
            ->exists();
        $credentials = $this->legacySettings->effectiveCredentials((int) $workflow->tenant_id);
        if ($isLegacyMigration) {
            $legacyCredentials = $this->legacySettings->effectiveCredentials(
                (int) $workflow->tenant_id,
                preferLegacyOAuthClients: true,
            );
            foreach (['asana' => ['asana_oauth_client_id', 'asana_oauth_client_secret'], 'google_calendar' => ['google_calendar_client_id', 'google_calendar_client_secret']] as $provider => $keys) {
                $connection = $provider === $triggerProvider ? $triggerConnection : $googleConnection;
                if (data_get($connection?->metadata, 'credential_source') === 'shared_oauth') {
                    continue;
                }
                foreach ($keys as $key) {
                    $credentials[$key] = $legacyCredentials[$key] ?? $credentials[$key] ?? null;
                }
            }
        }
        if ($triggerProvider === 'asana' && $triggerConnection?->refresh_token) {
            $credentials['asana_oauth_refresh_token'] = $triggerConnection->refresh_token;
        }
        if ($triggerProvider === 'asana' && $triggerConnection?->access_token) {
            $credentials['asana_personal_access_token'] = $triggerConnection->access_token;
        }
        if ($googleConnection?->refresh_token) {
            $credentials['google_calendar_refresh_token'] = $googleConnection->refresh_token;
        }
        if ($googleConnection?->access_token) {
            $credentials['google_calendar_access_token'] = $googleConnection->access_token;
        }
        if ($triggerProvider !== 'asana') {
            foreach (array_keys($credentials) as $key) {
                if (str_starts_with((string) $key, 'asana_')) {
                    unset($credentials[$key]);
                }
            }
        }

        return [
            ...$base,
            'enabled' => true,
            'tenant_id' => (int) $workflow->tenant_id,
            'automation_workflow_id' => (int) $workflow->id,
            'required_module' => 'workflow_automations',
            'driver' => (string) ($definition['driver'] ?? 'asana_google_calendar'),
            'trigger' => array_merge((array) ($base['trigger'] ?? []), (array) ($definition['trigger'] ?? [])),
            'action' => array_merge((array) ($base['action'] ?? []), (array) ($definition['action'] ?? [])),
            'credentials' => $credentials,
        ];
    }

    protected function connectionsReady(AutomationWorkflow $workflow): bool
    {
        $tenantId = (int) $workflow->tenant_id;
        $triggerProvider = (string) data_get($workflow->draft_definition, 'trigger.provider', 'asana');
        if (in_array($triggerProvider, CommerceWorkflowConnectionService::PROVIDERS, true)) {
            try {
                $triggerReady = $this->selectedConnection($workflow, $triggerProvider, 'trigger.connection_id')->isConnected();
            } catch (AutomationWorkflowException) {
                $triggerReady = false;
            }
            $google = IntegrationConnection::query()->forTenantId($tenantId)
                ->where('provider', 'google_calendar')
                ->where('status', IntegrationConnection::STATUS_CONNECTED)
                ->latest('connected_at')
                ->latest('id')
                ->first();
            $googleReady = filled($google?->refresh_token) || filled($google?->access_token);

            return $triggerReady && $googleReady;
        }
        $credentials = $this->legacySettings->effectiveCredentials($tenantId);
        $connections = IntegrationConnection::query()->forTenantId($tenantId)
            ->whereIn('provider', ['asana', 'google_calendar'])
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->get()->keyBy('provider');

        $asanaReady = filled($connections->get('asana')?->refresh_token)
            || filled($connections->get('asana')?->access_token)
            || filled($credentials['asana_oauth_refresh_token'] ?? null)
            || filled($credentials['asana_personal_access_token'] ?? null);
        $googleReady = filled($connections->get('google_calendar')?->refresh_token)
            || filled($credentials['google_calendar_refresh_token'] ?? null);

        return $asanaReady && $googleReady;
    }

    protected function selectedConnection(AutomationWorkflow $workflow, string $provider, string $path): IntegrationConnection
    {
        $connectionId = (int) data_get($workflow->draft_definition, $path, 0);
        $query = IntegrationConnection::query()->forTenantId((int) $workflow->tenant_id)
            ->where('provider', $provider)->where('status', IntegrationConnection::STATUS_CONNECTED);
        $connection = $connectionId > 0 ? $query->whereKey($connectionId)->first() : $query->latest('connected_at')->first();
        if (! $connection) {
            throw new AutomationWorkflowException('Choose an active '.str($provider)->replace('_', ' ')->headline().' connection before testing.');
        }

        return $connection;
    }

    protected function updateConnectionHealth(AutomationWorkflow $workflow, array $definition, bool $ok, ?string $error): void
    {
        $providers = array_unique(array_filter([
            (string) data_get($definition, 'trigger.provider'),
            (string) data_get($definition, 'action.provider'),
        ]));
        $isAuthFailure = ! $ok && preg_match('/\b(401|403|unauthori[sz]ed|invalid[_ ]grant|authentication|credential|access token|refresh token|reconnect)\b/i', (string) $error) === 1;

        foreach ($providers as $provider) {
            $query = IntegrationConnection::query()->forTenantId((int) $workflow->tenant_id)
                ->where('provider', $provider)
                ->where('status', IntegrationConnection::STATUS_CONNECTED);
            $selectedTriggerConnectionId = $provider === (string) data_get($definition, 'trigger.provider')
                ? (int) data_get($definition, 'trigger.connection_id', 0)
                : 0;
            $connection = $selectedTriggerConnectionId > 0
                ? $query->whereKey($selectedTriggerConnectionId)->first()
                : $query->latest('connected_at')->latest('id')->first();
            if (! $connection) {
                continue;
            }
            $metadata = (array) $connection->metadata;
            if ($ok) {
                unset($metadata['auth_failure_signature'], $metadata['auth_failure_count']);
                $connection->forceFill(['metadata' => $metadata, 'last_synced_at' => now(), 'last_error_code' => null, 'last_error_message' => null, 'last_error_at' => null])->save();

                continue;
            }
            if (! $isAuthFailure) {
                continue;
            }

            $signature = hash('sha256', mb_strtolower((string) $error));
            $count = ($metadata['auth_failure_signature'] ?? null) === $signature ? ((int) ($metadata['auth_failure_count'] ?? 0)) + 1 : 1;
            $metadata['auth_failure_signature'] = $signature;
            $metadata['auth_failure_count'] = $count;
            $connection->forceFill([
                'metadata' => $metadata,
                'status' => $count >= 3 ? IntegrationConnection::STATUS_ERROR : $connection->status,
                'last_error_code' => $count >= 3 ? 'reconnect_required' : 'authentication_failed',
                'last_error_message' => $this->safeError((string) $error),
                'last_error_at' => now(),
            ])->save();

            if ($count >= 3 && $workflow->status === AutomationWorkflow::STATUS_ACTIVE) {
                $before = $this->snapshot($workflow);
                $workflow->forceFill(['status' => AutomationWorkflow::STATUS_PAUSED])->save();
                $this->audit($workflow, null, 'paused_reconnect_required', $before, $this->snapshot($workflow), ['provider' => $provider]);
            }
        }
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    protected function recordTest(AutomationWorkflow $workflow, User $actor, string $key, array $result): array
    {
        $state = (array) $workflow->test_state;
        $state[$key] = $result;
        $workflow->forceFill(['test_state' => $state, 'updated_by_user_id' => $actor->id])->save();
        $this->audit($workflow, $actor, $key.'_tested', null, [$key => $result]);

        return $result;
    }

    protected function setStatus(AutomationWorkflow $workflow, string $status, User $actor, string $event): AutomationWorkflow
    {
        $before = $this->snapshot($workflow);
        $workflow->forceFill(['status' => $status, 'updated_by_user_id' => $actor->id])->save();
        $this->audit($workflow, $actor, $event, $before, $this->snapshot($workflow));

        return $workflow->fresh();
    }

    /** @param array<string,mixed>|null $before @param array<string,mixed>|null $after @param array<string,mixed> $context */
    protected function audit(AutomationWorkflow $workflow, ?User $actor, string $type, ?array $before, ?array $after, array $context = []): void
    {
        AutomationWorkflowAuditEvent::query()->create([
            'tenant_id' => $workflow->tenant_id,
            'automation_workflow_id' => $workflow->id,
            'actor_user_id' => $actor?->id,
            'event_type' => $type,
            'before_state' => $before,
            'after_state' => $after,
            'context' => $context,
            'occurred_at' => now(),
        ]);
    }

    /** @return array<string,mixed> */
    protected function snapshot(AutomationWorkflow $workflow): array
    {
        return ['name' => $workflow->name, 'status' => $workflow->status, 'template_key' => $workflow->template_key, 'definition_hash' => $this->definitionHash((array) $workflow->draft_definition)];
    }

    /** @param array<string,mixed> $definition */
    public function definitionHash(array $definition): string
    {
        return hash('sha256', json_encode($definition, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    protected function safeError(string $message): string
    {
        return mb_substr(preg_replace('/(token|secret|authorization)[=: ]+\S+/i', '$1=[redacted]', $message) ?? 'Workflow run failed.', 0, 1500);
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
