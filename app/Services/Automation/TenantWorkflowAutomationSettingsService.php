<?php

namespace App\Services\Automation;

use App\Models\AutomationWorkflowLink;
use App\Models\AutomationWorkflowState;
use App\Models\TenantMarketingSetting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class TenantWorkflowAutomationSettingsService
{
    /**
     * @var array<string,array{setting_key:string,title:string,description:string}>
     */
    protected const WORKFLOW_CATALOG = [
        'asana_to_google_calendar' => [
            'setting_key' => 'workflow_automation_asana_google_calendar',
            'title' => 'Asana to Google Calendar',
            'description' => 'Watch Asana task updates and create or update matching Google Calendar events without Zapier.',
        ],
    ];

    public function forTenant(int $tenantId, string $workflowKey = 'asana_to_google_calendar'): array
    {
        $workflowKey = $this->normalizeBaseWorkflowKey($workflowKey);
        $catalog = $this->catalogEntry($workflowKey);
        $stored = $this->storedValue($tenantId, $workflowKey);
        $definition = $this->buildRuntimeDefinition($workflowKey, $tenantId, $stored);
        $instanceKey = $this->instanceKey($workflowKey, $tenantId);

        $storedAsanaToken = $this->decryptNullable($stored['credentials']['asana_personal_access_token_encrypted'] ?? null);
        $storedAsanaClientId = $this->decryptNullable($stored['credentials']['asana_oauth_client_id_encrypted'] ?? null);
        $storedAsanaClientSecret = $this->decryptNullable($stored['credentials']['asana_oauth_client_secret_encrypted'] ?? null);
        $storedAsanaRefreshToken = $this->decryptNullable($stored['credentials']['asana_oauth_refresh_token_encrypted'] ?? null);
        $storedGoogleClientId = $this->decryptNullable($stored['credentials']['google_calendar_client_id_encrypted'] ?? null);
        $storedGoogleClientSecret = $this->decryptNullable($stored['credentials']['google_calendar_client_secret_encrypted'] ?? null);
        $storedGoogleRefreshToken = $this->decryptNullable($stored['credentials']['google_calendar_refresh_token_encrypted'] ?? null);

        $fallbackAsanaToken = $this->nullableString(config('services.asana.personal_access_token'));
        $fallbackAsanaClientId = $this->nullableString(config('services.asana.oauth_client_id'));
        $fallbackAsanaClientSecret = $this->nullableString(config('services.asana.oauth_client_secret'));
        $fallbackAsanaRefreshToken = $this->nullableString(config('services.asana.oauth_refresh_token'));
        $fallbackGoogleClientId = $this->nullableString(config('services.google_calendar.oauth_client_id'));
        $fallbackGoogleClientSecret = $this->nullableString(config('services.google_calendar.oauth_client_secret'));
        $fallbackGoogleRefreshToken = $this->nullableString(config('services.google_calendar.oauth_refresh_token'));

        $state = $this->workflowState($instanceKey);
        $lastResult = is_array($state?->last_result) ? $state->last_result : [];
        $counts = is_array($lastResult['counts'] ?? null) ? (array) $lastResult['counts'] : [];
        $dryRunCounts = is_array($lastResult['dry_run_counts'] ?? null) ? (array) $lastResult['dry_run_counts'] : [];

        return [
            'workflow_key' => $workflowKey,
            'instance_key' => $instanceKey,
            'setting_key' => $catalog['setting_key'],
            'title' => $catalog['title'],
            'description' => $catalog['description'],
            'enabled' => (bool) ($definition['enabled'] ?? false),
            'required_module' => (string) ($definition['required_module'] ?? ''),
            'trigger' => [
                'project_gid' => $this->nullableString(data_get($definition, 'trigger.project_gid')),
                'modified_overlap_minutes' => (int) data_get($definition, 'trigger.modified_overlap_minutes', 5),
                'bootstrap_lookback_days' => (int) data_get($definition, 'trigger.bootstrap_lookback_days', 14),
                'poll_limit' => (int) data_get($definition, 'trigger.poll_limit', 100),
                'max_tasks_per_run' => (int) data_get($definition, 'trigger.max_tasks_per_run', 500),
            ],
            'action' => [
                'calendar_id' => $this->nullableString(data_get($definition, 'action.calendar_id')),
                'timezone' => $this->nullableString(data_get($definition, 'action.timezone')) ?? 'America/New_York',
                'default_start_time' => $this->normalizeDisplayTime(
                    $this->nullableString(data_get($definition, 'action.default_start_time')) ?? '12:00:00'
                ),
                'default_duration_minutes' => (int) data_get($definition, 'action.default_duration_minutes', 60),
                'skip_completed_tasks' => (bool) data_get($definition, 'action.skip_completed_tasks', true),
            ],
            'credentials' => [
                'asana_personal_access_token' => $this->credentialPreview($storedAsanaToken, $fallbackAsanaToken),
                'asana_oauth_client_id' => $this->credentialPreview($storedAsanaClientId, $fallbackAsanaClientId),
                'asana_oauth_client_secret' => $this->credentialPreview($storedAsanaClientSecret, $fallbackAsanaClientSecret),
                'asana_oauth_refresh_token' => $this->credentialPreview($storedAsanaRefreshToken, $fallbackAsanaRefreshToken),
                'google_calendar_client_id' => $this->credentialPreview($storedGoogleClientId, $fallbackGoogleClientId),
                'google_calendar_client_secret' => $this->credentialPreview($storedGoogleClientSecret, $fallbackGoogleClientSecret),
                'google_calendar_refresh_token' => $this->credentialPreview($storedGoogleRefreshToken, $fallbackGoogleRefreshToken),
            ],
            'state' => [
                'status' => $this->nullableString($state?->status) ?? 'idle',
                'last_status' => $this->nullableString($state?->last_status),
                'last_started_at' => $state?->last_started_at?->toIso8601String(),
                'last_finished_at' => $state?->last_finished_at?->toIso8601String(),
                'last_error' => $this->nullableString($state?->last_error),
                'cursor' => $this->nullableString($state?->cursor),
                'last_result' => $lastResult,
                'counts' => $counts,
                'dry_run_counts' => $dryRunCounts,
            ],
            'link_count' => $this->workflowLinkCount($instanceKey),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function saveForTenant(int $tenantId, string $workflowKey, array $payload): TenantMarketingSetting
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            throw new AutomationWorkflowException('tenant_marketing_settings table is required for tenant workflow automation setup.');
        }

        $workflowKey = $this->normalizeBaseWorkflowKey($workflowKey);
        $catalog = $this->catalogEntry($workflowKey);
        $existing = $this->storedValue($tenantId, $workflowKey);

        $trigger = [
            'project_gid' => $this->nullableString(data_get($payload, 'trigger.project_gid')),
            'modified_overlap_minutes' => max(0, (int) data_get($payload, 'trigger.modified_overlap_minutes', data_get($existing, 'trigger.modified_overlap_minutes', 5))),
            'bootstrap_lookback_days' => max(1, (int) data_get($payload, 'trigger.bootstrap_lookback_days', data_get($existing, 'trigger.bootstrap_lookback_days', 14))),
            'poll_limit' => min(100, max(1, (int) data_get($payload, 'trigger.poll_limit', data_get($existing, 'trigger.poll_limit', 100)))),
            'max_tasks_per_run' => max(1, (int) data_get($payload, 'trigger.max_tasks_per_run', data_get($existing, 'trigger.max_tasks_per_run', 500))),
        ];

        $action = [
            'calendar_id' => $this->nullableString(data_get($payload, 'action.calendar_id')),
            'timezone' => $this->nullableString(data_get($payload, 'action.timezone')) ?? 'America/New_York',
            'default_start_time' => $this->normalizeStoredTime(
                $this->nullableString(data_get($payload, 'action.default_start_time'))
                    ?? $this->nullableString(data_get($existing, 'action.default_start_time'))
                    ?? '12:00'
            ),
            'default_duration_minutes' => max(1, (int) data_get($payload, 'action.default_duration_minutes', data_get($existing, 'action.default_duration_minutes', 60))),
            'skip_completed_tasks' => (bool) data_get($payload, 'action.skip_completed_tasks', data_get($existing, 'action.skip_completed_tasks', true)),
        ];

        $credentials = [
            'asana_personal_access_token_encrypted' => $this->resolvedEncryptedSecret(
                newValue: $payload['credentials']['asana_personal_access_token'] ?? null,
                clearValue: $payload['credentials']['clear_asana_personal_access_token'] ?? false,
                existingEncryptedValue: $existing['credentials']['asana_personal_access_token_encrypted'] ?? null,
            ),
            'asana_oauth_client_id_encrypted' => $this->resolvedEncryptedSecret(
                newValue: $payload['credentials']['asana_oauth_client_id'] ?? null,
                clearValue: $payload['credentials']['clear_asana_oauth_client_id'] ?? false,
                existingEncryptedValue: $existing['credentials']['asana_oauth_client_id_encrypted'] ?? null,
            ),
            'asana_oauth_client_secret_encrypted' => $this->resolvedEncryptedSecret(
                newValue: $payload['credentials']['asana_oauth_client_secret'] ?? null,
                clearValue: $payload['credentials']['clear_asana_oauth_client_secret'] ?? false,
                existingEncryptedValue: $existing['credentials']['asana_oauth_client_secret_encrypted'] ?? null,
            ),
            'asana_oauth_refresh_token_encrypted' => $this->resolvedEncryptedSecret(
                newValue: $payload['credentials']['asana_oauth_refresh_token'] ?? null,
                clearValue: $payload['credentials']['clear_asana_oauth_refresh_token'] ?? false,
                existingEncryptedValue: $existing['credentials']['asana_oauth_refresh_token_encrypted'] ?? null,
            ),
            'google_calendar_client_id_encrypted' => $this->resolvedEncryptedSecret(
                newValue: $payload['credentials']['google_calendar_client_id'] ?? null,
                clearValue: $payload['credentials']['clear_google_calendar_client_id'] ?? false,
                existingEncryptedValue: $existing['credentials']['google_calendar_client_id_encrypted'] ?? null,
            ),
            'google_calendar_client_secret_encrypted' => $this->resolvedEncryptedSecret(
                newValue: $payload['credentials']['google_calendar_client_secret'] ?? null,
                clearValue: $payload['credentials']['clear_google_calendar_client_secret'] ?? false,
                existingEncryptedValue: $existing['credentials']['google_calendar_client_secret_encrypted'] ?? null,
            ),
            'google_calendar_refresh_token_encrypted' => $this->resolvedEncryptedSecret(
                newValue: $payload['credentials']['google_calendar_refresh_token'] ?? null,
                clearValue: $payload['credentials']['clear_google_calendar_refresh_token'] ?? false,
                existingEncryptedValue: $existing['credentials']['google_calendar_refresh_token_encrypted'] ?? null,
            ),
        ];

        return TenantMarketingSetting::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => $catalog['setting_key'],
            ],
            [
                'value' => [
                    'workflow_key' => $workflowKey,
                    'enabled' => (bool) ($payload['enabled'] ?? false),
                    'trigger' => $trigger,
                    'action' => $action,
                    'credentials' => $credentials,
                ],
                'description' => $catalog['description'],
            ]
        );
    }

    /**
     * @return array<string,array<string,mixed>>
     */
    public function runtimeDefinitions(?string $workflowFilter = null, bool $includeDisabled = false): array
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            return [];
        }

        $workflowFilter = $this->nullableString($workflowFilter);
        $filteredInstance = $workflowFilter !== null ? $this->parseInstanceKey($workflowFilter) : null;
        $filteredWorkflowKey = $workflowFilter !== null
            ? ($filteredInstance['workflow_key'] ?? $this->normalizeBaseWorkflowKey($workflowFilter))
            : null;

        $query = TenantMarketingSetting::query()
            ->whereIn('key', array_values(array_column(self::WORKFLOW_CATALOG, 'setting_key')));

        if ($filteredWorkflowKey !== null && isset(self::WORKFLOW_CATALOG[$filteredWorkflowKey])) {
            $query->where('key', self::WORKFLOW_CATALOG[$filteredWorkflowKey]['setting_key']);
        }

        $definitions = [];

        foreach ($query->get() as $setting) {
            $tenantId = (int) $setting->tenant_id;
            if ($tenantId <= 0) {
                continue;
            }

            $workflowKey = $this->workflowKeyForSettingKey((string) $setting->key);
            if ($workflowKey === null) {
                continue;
            }

            if (
                $filteredInstance !== null
                && isset($filteredInstance['tenant_id'])
                && (int) $filteredInstance['tenant_id'] !== $tenantId
            ) {
                continue;
            }

            $definition = $this->buildRuntimeDefinition(
                $workflowKey,
                $tenantId,
                is_array($setting->value) ? $setting->value : []
            );

            if (! $includeDisabled && ! (bool) ($definition['enabled'] ?? false)) {
                continue;
            }

            $definitions[$this->instanceKey($workflowKey, $tenantId)] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function runtimeDefinitionForTenant(string $workflowKey, int $tenantId): ?array
    {
        $workflowKey = $this->normalizeBaseWorkflowKey($workflowKey);
        $stored = $this->storedValue($tenantId, $workflowKey);

        if ($stored === []) {
            return null;
        }

        return $this->buildRuntimeDefinition($workflowKey, $tenantId, $stored);
    }

    /**
     * @return array<string,mixed>
     */
    public function storedValueForTenant(int $tenantId, string $workflowKey = 'asana_to_google_calendar'): array
    {
        return $this->storedValue($tenantId, $this->normalizeBaseWorkflowKey($workflowKey));
    }

    /**
     * @return array<string,mixed>
     */
    public function effectiveCredentials(int $tenantId, string $workflowKey = 'asana_to_google_calendar'): array
    {
        $workflowKey = $this->normalizeBaseWorkflowKey($workflowKey);
        $stored = $this->storedValue($tenantId, $workflowKey);

        $tenantAsanaToken = $this->decryptNullable($stored['credentials']['asana_personal_access_token_encrypted'] ?? null);
        $tenantAsanaClientId = $this->decryptNullable($stored['credentials']['asana_oauth_client_id_encrypted'] ?? null);
        $tenantAsanaClientSecret = $this->decryptNullable($stored['credentials']['asana_oauth_client_secret_encrypted'] ?? null);
        $tenantAsanaRefreshToken = $this->decryptNullable($stored['credentials']['asana_oauth_refresh_token_encrypted'] ?? null);
        $tenantGoogleClientId = $this->decryptNullable($stored['credentials']['google_calendar_client_id_encrypted'] ?? null);
        $tenantGoogleClientSecret = $this->decryptNullable($stored['credentials']['google_calendar_client_secret_encrypted'] ?? null);
        $tenantGoogleRefreshToken = $this->decryptNullable($stored['credentials']['google_calendar_refresh_token_encrypted'] ?? null);

        $globalAsanaToken = $this->nullableString(config('services.asana.personal_access_token'));
        $globalAsanaClientId = $this->nullableString(config('services.asana.oauth_client_id'));
        $globalAsanaClientSecret = $this->nullableString(config('services.asana.oauth_client_secret'));
        $globalAsanaRefreshToken = $this->nullableString(config('services.asana.oauth_refresh_token'));
        $globalAsanaAccessToken = $this->nullableString(config('services.asana.oauth_access_token'));
        $globalGoogleClientId = $this->nullableString(config('services.google_calendar.oauth_client_id'));
        $globalGoogleClientSecret = $this->nullableString(config('services.google_calendar.oauth_client_secret'));
        $globalGoogleRefreshToken = $this->nullableString(config('services.google_calendar.oauth_refresh_token'));

        return [
            'asana_personal_access_token' => $tenantAsanaToken ?? $globalAsanaToken,
            'asana_oauth_client_id' => $tenantAsanaClientId ?? $globalAsanaClientId,
            'asana_oauth_client_secret' => $tenantAsanaClientSecret ?? $globalAsanaClientSecret,
            'asana_oauth_refresh_token' => $tenantAsanaRefreshToken ?? $globalAsanaRefreshToken,
            'asana_access_token' => $globalAsanaAccessToken,
            'google_calendar_client_id' => $tenantGoogleClientId ?? $globalGoogleClientId,
            'google_calendar_client_secret' => $tenantGoogleClientSecret ?? $globalGoogleClientSecret,
            'google_calendar_refresh_token' => $tenantGoogleRefreshToken ?? $globalGoogleRefreshToken,
            'sources' => [
                'asana_personal_access_token' => $tenantAsanaToken !== null ? 'tenant' : ($globalAsanaToken !== null ? 'global' : 'missing'),
                'asana_oauth_client_id' => $tenantAsanaClientId !== null ? 'tenant' : ($globalAsanaClientId !== null ? 'global' : 'missing'),
                'asana_oauth_client_secret' => $tenantAsanaClientSecret !== null ? 'tenant' : ($globalAsanaClientSecret !== null ? 'global' : 'missing'),
                'asana_oauth_refresh_token' => $tenantAsanaRefreshToken !== null ? 'tenant' : ($globalAsanaRefreshToken !== null ? 'global' : 'missing'),
                'google_calendar_client_id' => $tenantGoogleClientId !== null ? 'tenant' : ($globalGoogleClientId !== null ? 'global' : 'missing'),
                'google_calendar_client_secret' => $tenantGoogleClientSecret !== null ? 'tenant' : ($globalGoogleClientSecret !== null ? 'global' : 'missing'),
                'google_calendar_refresh_token' => $tenantGoogleRefreshToken !== null ? 'tenant' : ($globalGoogleRefreshToken !== null ? 'global' : 'missing'),
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $patch
     */
    public function mergeStoredValue(int $tenantId, string $workflowKey, array $patch): TenantMarketingSetting
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            throw new AutomationWorkflowException('tenant_marketing_settings table is required for tenant workflow automation setup.');
        }

        $workflowKey = $this->normalizeBaseWorkflowKey($workflowKey);
        $catalog = $this->catalogEntry($workflowKey);
        $existing = $this->storedValue($tenantId, $workflowKey);
        $merged = $this->mergeArrays($existing, $patch);
        $merged['workflow_key'] = $workflowKey;

        return TenantMarketingSetting::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'key' => $catalog['setting_key'],
            ],
            [
                'value' => $merged,
                'description' => $catalog['description'],
            ]
        );
    }

    public function instanceKey(string $workflowKey, int $tenantId): string
    {
        return $this->normalizeBaseWorkflowKey($workflowKey).'::tenant:'.$tenantId;
    }

    public function normalizeBaseWorkflowKey(string $workflowKey): string
    {
        $workflowKey = strtolower(trim($workflowKey));
        if ($workflowKey === '') {
            return '';
        }

        $instance = $this->parseInstanceKey($workflowKey);

        return $instance['workflow_key'] ?? $workflowKey;
    }

    /**
     * @return array{workflow_key:string,tenant_id:int}|null
     */
    public function parseInstanceKey(string $workflowKey): ?array
    {
        $workflowKey = strtolower(trim($workflowKey));
        if (! str_contains($workflowKey, '::tenant:')) {
            return null;
        }

        [$baseWorkflowKey, $tenantId] = array_pad(explode('::tenant:', $workflowKey, 2), 2, null);
        $baseWorkflowKey = strtolower(trim((string) $baseWorkflowKey));
        $tenantId = (int) $tenantId;

        if ($baseWorkflowKey === '' || $tenantId <= 0) {
            return null;
        }

        return [
            'workflow_key' => $baseWorkflowKey,
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function buildRuntimeDefinition(string $workflowKey, int $tenantId, array $stored): array
    {
        $baseDefinition = $this->baseWorkflowDefinition($workflowKey);
        if ($baseDefinition === []) {
            return [];
        }

        return [
            ...$baseDefinition,
            'enabled' => (bool) data_get($stored, 'enabled', data_get($baseDefinition, 'enabled', false)),
            'tenant_id' => $tenantId,
            'base_workflow_key' => $workflowKey,
            'instance_key' => $this->instanceKey($workflowKey, $tenantId),
            'trigger' => array_merge(
                (array) data_get($baseDefinition, 'trigger', []),
                $this->normalizeTriggerOverrides((array) data_get($stored, 'trigger', []))
            ),
            'action' => array_merge(
                (array) data_get($baseDefinition, 'action', []),
                $this->normalizeActionOverrides((array) data_get($stored, 'action', []))
            ),
            'credentials' => $this->runtimeCredentials($stored),
        ];
    }

    /**
     * @param  array<string,mixed>  $stored
     * @return array<string,mixed>
     */
    protected function runtimeCredentials(array $stored): array
    {
        return array_filter([
            'asana_personal_access_token' => $this->decryptNullable($stored['credentials']['asana_personal_access_token_encrypted'] ?? null),
            'asana_oauth_client_id' => $this->decryptNullable($stored['credentials']['asana_oauth_client_id_encrypted'] ?? null),
            'asana_oauth_client_secret' => $this->decryptNullable($stored['credentials']['asana_oauth_client_secret_encrypted'] ?? null),
            'asana_oauth_refresh_token' => $this->decryptNullable($stored['credentials']['asana_oauth_refresh_token_encrypted'] ?? null),
            'google_calendar_client_id' => $this->decryptNullable($stored['credentials']['google_calendar_client_id_encrypted'] ?? null),
            'google_calendar_client_secret' => $this->decryptNullable($stored['credentials']['google_calendar_client_secret_encrypted'] ?? null),
            'google_calendar_refresh_token' => $this->decryptNullable($stored['credentials']['google_calendar_refresh_token_encrypted'] ?? null),
        ], static fn (mixed $value): bool => is_string($value) && trim($value) !== '');
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function normalizeTriggerOverrides(array $overrides): array
    {
        return array_filter([
            'project_gid' => $this->nullableString($overrides['project_gid'] ?? null),
            'modified_overlap_minutes' => array_key_exists('modified_overlap_minutes', $overrides) ? max(0, (int) $overrides['modified_overlap_minutes']) : null,
            'bootstrap_lookback_days' => array_key_exists('bootstrap_lookback_days', $overrides) ? max(1, (int) $overrides['bootstrap_lookback_days']) : null,
            'poll_limit' => array_key_exists('poll_limit', $overrides) ? min(100, max(1, (int) $overrides['poll_limit'])) : null,
            'max_tasks_per_run' => array_key_exists('max_tasks_per_run', $overrides) ? max(1, (int) $overrides['max_tasks_per_run']) : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    protected function normalizeActionOverrides(array $overrides): array
    {
        return array_filter([
            'calendar_id' => $this->nullableString($overrides['calendar_id'] ?? null),
            'timezone' => $this->nullableString($overrides['timezone'] ?? null),
            'default_start_time' => array_key_exists('default_start_time', $overrides)
                ? $this->normalizeStoredTime($this->nullableString($overrides['default_start_time']) ?? '12:00')
                : null,
            'default_duration_minutes' => array_key_exists('default_duration_minutes', $overrides) ? max(1, (int) $overrides['default_duration_minutes']) : null,
            'skip_completed_tasks' => array_key_exists('skip_completed_tasks', $overrides) ? (bool) $overrides['skip_completed_tasks'] : null,
        ], static fn (mixed $value): bool => $value !== null);
    }

    /**
     * @return array<string,mixed>
     */
    protected function baseWorkflowDefinition(string $workflowKey): array
    {
        $definition = config('automation_workflows.workflows.'.$workflowKey, []);

        return is_array($definition) ? $definition : [];
    }

    /**
     * @return array{setting_key:string,title:string,description:string}
     */
    protected function catalogEntry(string $workflowKey): array
    {
        if (! isset(self::WORKFLOW_CATALOG[$workflowKey])) {
            throw new AutomationWorkflowException("Workflow automation [{$workflowKey}] is not supported.");
        }

        return self::WORKFLOW_CATALOG[$workflowKey];
    }

    /**
     * @return array<string,mixed>
     */
    protected function storedValue(int $tenantId, string $workflowKey): array
    {
        if (! Schema::hasTable('tenant_marketing_settings')) {
            return [];
        }

        $catalog = $this->catalogEntry($workflowKey);
        $setting = TenantMarketingSetting::query()
            ->where('tenant_id', $tenantId)
            ->where('key', $catalog['setting_key'])
            ->first();

        return is_array($setting?->value) ? $setting->value : [];
    }

    protected function workflowKeyForSettingKey(string $settingKey): ?string
    {
        foreach (self::WORKFLOW_CATALOG as $workflowKey => $catalog) {
            if ((string) $catalog['setting_key'] === $settingKey) {
                return $workflowKey;
            }
        }

        return null;
    }

    /**
     * @return array{has_value:bool,masked_value:?string,source:string,source_label:string}
     */
    protected function credentialPreview(?string $tenantValue, ?string $fallbackValue): array
    {
        if ($tenantValue !== null) {
            return [
                'has_value' => true,
                'masked_value' => $this->maskedSecret($tenantValue),
                'source' => 'tenant',
                'source_label' => 'Saved for this tenant',
            ];
        }

        if ($fallbackValue !== null) {
            return [
                'has_value' => true,
                'masked_value' => $this->maskedSecret($fallbackValue),
                'source' => 'global',
                'source_label' => 'Using server fallback',
            ];
        }

        return [
            'has_value' => false,
            'masked_value' => null,
            'source' => 'missing',
            'source_label' => 'Not configured yet',
        ];
    }

    protected function workflowState(string $workflowKey): ?AutomationWorkflowState
    {
        if (! Schema::hasTable('automation_workflow_states')) {
            return null;
        }

        return AutomationWorkflowState::query()
            ->where('workflow_key', $workflowKey)
            ->first();
    }

    protected function workflowLinkCount(string $workflowKey): int
    {
        if (! Schema::hasTable('automation_workflow_links')) {
            return 0;
        }

        return AutomationWorkflowLink::query()
            ->where('workflow_key', $workflowKey)
            ->count();
    }

    protected function normalizeStoredTime(string $value): string
    {
        $value = trim($value);

        if (preg_match('/^\d{2}:\d{2}$/', $value) === 1) {
            return $value.':00';
        }

        return preg_match('/^\d{2}:\d{2}:\d{2}$/', $value) === 1 ? $value : '12:00:00';
    }

    protected function normalizeDisplayTime(string $value): string
    {
        $value = $this->normalizeStoredTime($value);

        return substr($value, 0, 5);
    }

    protected function resolvedEncryptedSecret(mixed $newValue, mixed $clearValue, mixed $existingEncryptedValue): ?string
    {
        if ((bool) $clearValue) {
            return null;
        }

        $newValue = $this->nullableString($newValue);
        if ($newValue !== null) {
            return Crypt::encryptString($newValue);
        }

        $existingEncryptedValue = $this->nullableString($existingEncryptedValue);

        return $existingEncryptedValue;
    }

    protected function decryptNullable(mixed $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        try {
            return $this->nullableString(Crypt::decryptString($value));
        } catch (DecryptException) {
            return $value;
        }
    }

    protected function maskedSecret(?string $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4).str_repeat('*', max(4, strlen($value) - 8)).substr($value, -4);
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $patch
     * @return array<string,mixed>
     */
    protected function mergeArrays(array $existing, array $patch): array
    {
        foreach ($patch as $key => $value) {
            if (is_array($value) && is_array($existing[$key] ?? null)) {
                $existing[$key] = $this->mergeArrays((array) $existing[$key], $value);
                continue;
            }

            $existing[$key] = $value;
        }

        return $existing;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
