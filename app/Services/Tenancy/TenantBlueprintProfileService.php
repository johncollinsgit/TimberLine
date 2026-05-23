<?php

namespace App\Services\Tenancy;

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSetupStatus;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TenantBlueprintProfileService
{
    /**
     * @var array<int,string>
     */
    protected const WORK_MANAGEMENT_INTENT_KEYS = [
        'wants_project_workspace',
        'wants_task_management',
        'wants_user_assignments',
        'wants_team_communication',
        'wants_client_communication',
        'wants_photo_uploads',
        'wants_file_uploads',
        'wants_mobile_field_capture',
    ];

    /**
     * @var array<string,string>
     */
    protected const WORK_MANAGEMENT_INTENT_LABELS = [
        'wants_project_workspace' => 'Project/work tracking',
        'wants_task_management' => 'Task management',
        'wants_user_assignments' => 'User assignments',
        'wants_team_communication' => 'Team communication',
        'wants_client_communication' => 'Client communication',
        'wants_photo_uploads' => 'Photo uploads',
        'wants_file_uploads' => 'File uploads',
        'wants_mobile_field_capture' => 'Mobile field capture',
    ];

    /**
     * @var array<string,string>
     */
    protected const REVIEW_STATUS_LABELS = [
        'unreviewed' => 'Unreviewed',
        'needs_follow_up' => 'Needs follow-up',
        'reviewed' => 'Reviewed',
        'archived' => 'Archived',
    ];

    /**
     * @return array<string,string>
     */
    public function accountModeOptions(): array
    {
        return $this->stringMap((array) config('tenant_blueprints.account_modes', []));
    }

    /**
     * @return array<string,string>
     */
    public function operatingModeOptions(): array
    {
        return $this->stringMap((array) config('tenant_blueprints.operating_modes', []));
    }

    /**
     * @return array<string,string>
     */
    public function dataSourcePreferenceOptions(): array
    {
        return $this->stringMap((array) config('tenant_blueprints.data_source_preferences', []));
    }

    /**
     * @return array<string,string>
     */
    public function templateOptions(): array
    {
        return collect((array) config('tenant_blueprints.templates', []))
            ->filter(fn (mixed $definition): bool => is_array($definition))
            ->mapWithKeys(fn (array $definition, string $key): array => [
                $key => (string) ($definition['label'] ?? Str::headline($key)),
            ])
            ->all();
    }

    /**
     * @return array<int,string>
     */
    public function acceptedTemplateKeys(): array
    {
        $keys = [];

        foreach ((array) config('tenant_blueprints.templates', []) as $key => $definition) {
            $keys[] = (string) $key;

            foreach ((array) data_get($definition, 'aliases', []) as $alias) {
                $keys[] = Str::slug(strtolower(trim((string) $alias)), '_');
            }
        }

        return array_values(array_unique(array_filter($keys)));
    }

    /**
     * @return array<string,string>
     */
    public function starterModuleOptions(): array
    {
        return $this->stringMap((array) config('tenant_blueprints.starter_modules', []));
    }

    /**
     * @return array<string,mixed>
     */
    public function formOptions(): array
    {
        return [
            'account_modes' => $this->accountModeOptions(),
            'operating_modes' => $this->operatingModeOptions(),
            'data_source_preferences' => $this->dataSourcePreferenceOptions(),
            'business_templates' => $this->templateOptions(),
            'starter_modules' => $this->starterModuleOptions(),
            'work_management_intents' => self::WORK_MANAGEMENT_INTENT_LABELS,
            'blueprint_review_statuses' => $this->reviewStatusOptions(),
        ];
    }

    /**
     * @return array<string,string>
     */
    public function reviewStatusOptions(): array
    {
        return self::REVIEW_STATUS_LABELS;
    }

    /**
     * @return array<string,mixed>
     */
    public function validationRules(bool $includeReview = false): array
    {
        $rules = [
            'account_mode' => ['nullable', 'string', Rule::in(array_keys($this->accountModeOptions()))],
            'business_template' => ['nullable', 'string', Rule::in($this->acceptedTemplateKeys())],
            'operating_mode' => ['nullable', 'string', Rule::in(array_keys($this->operatingModeOptions()))],
            'data_source_preference' => ['nullable', 'string', Rule::in(array_keys($this->dataSourcePreferenceOptions()))],
            'primary_outcome' => ['nullable', 'string', 'max:500'],
            'customer_label' => ['nullable', 'string', 'max:80'],
            'work_label' => ['nullable', 'string', 'max:80'],
            'money_label' => ['nullable', 'string', 'max:80'],
            'material_label' => ['nullable', 'string', 'max:80'],
            'stage_label' => ['nullable', 'string', 'max:80'],
            'project_label' => ['nullable', 'string', 'max:80'],
            'task_label' => ['nullable', 'string', 'max:80'],
            'assignee_label' => ['nullable', 'string', 'max:80'],
            'communication_label' => ['nullable', 'string', 'max:80'],
            'upload_label' => ['nullable', 'string', 'max:80'],
            'wants_project_workspace' => ['nullable', 'boolean'],
            'wants_task_management' => ['nullable', 'boolean'],
            'wants_user_assignments' => ['nullable', 'boolean'],
            'wants_team_communication' => ['nullable', 'boolean'],
            'wants_client_communication' => ['nullable', 'boolean'],
            'wants_photo_uploads' => ['nullable', 'boolean'],
            'wants_file_uploads' => ['nullable', 'boolean'],
            'wants_mobile_field_capture' => ['nullable', 'boolean'],
            'work_management_notes' => ['nullable', 'string', 'max:2000'],
            'starter_modules' => ['nullable', 'array'],
            'starter_modules.*' => ['string', Rule::in(array_keys($this->starterModuleOptions()))],
            'setup_notes' => ['nullable', 'string', 'max:5000'],
            'onboarding_next_action' => ['nullable', 'string', 'max:500'],
        ];

        if ($includeReview) {
            $rules = array_merge($rules, [
                'blueprint_review_status' => ['required', 'string', Rule::in(array_keys($this->reviewStatusOptions()))],
                'blueprint_internal_notes' => ['nullable', 'string', 'max:5000'],
                'blueprint_next_action' => ['nullable', 'string', 'max:500'],
            ]);
        }

        return $rules;
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function blueprintFromInput(array $input): array
    {
        $templateKey = $this->normalizeTemplate((string) ($input['business_template'] ?? 'generic'));
        $template = $this->templateDefinition($templateKey);

        $operatingMode = $this->optionOrDefault(
            (string) ($input['operating_mode'] ?? ''),
            array_keys($this->operatingModeOptions()),
            'custom_or_unknown'
        );
        $dataSourcePreference = $this->optionOrDefault(
            (string) ($input['data_source_preference'] ?? ''),
            array_keys($this->dataSourcePreferenceOptions()),
            $this->defaultDataSourceForOperatingMode($operatingMode)
        );

        $starterModules = $this->normalizeStarterModules((array) ($input['starter_modules'] ?? []), $template);
        $workManagementModuleKeys = $this->normalizeWorkManagementModules($template, $starterModules);
        $workManagementIntent = $this->workManagementIntentFromInput($input, $template);

        return [
            'business_template' => $templateKey,
            'business_template_label' => (string) ($template['label'] ?? Str::headline($templateKey)),
            'operating_mode' => $operatingMode,
            'operating_mode_label' => (string) data_get($this->operatingModeOptions(), $operatingMode, Str::headline($operatingMode)),
            'data_source_preference' => $dataSourcePreference,
            'data_source_preference_label' => (string) data_get($this->dataSourcePreferenceOptions(), $dataSourcePreference, Str::headline($dataSourcePreference)),
            'primary_outcome' => $this->nullableText((string) ($input['primary_outcome'] ?? ''), 500)
                ?: (string) ($template['primary_outcome'] ?? ''),
            'customer_label' => $this->labelOrDefault((string) ($input['customer_label'] ?? ''), (string) ($template['customer_label'] ?? 'Customer')),
            'work_label' => $this->labelOrDefault((string) ($input['work_label'] ?? ''), (string) ($template['work_label'] ?? 'Work')),
            'money_label' => $this->labelOrDefault((string) ($input['money_label'] ?? ''), (string) ($template['money_label'] ?? 'Revenue')),
            'material_label' => $this->labelOrDefault((string) ($input['material_label'] ?? ''), (string) ($template['material_label'] ?? 'Resources')),
            'stage_label' => $this->labelOrDefault((string) ($input['stage_label'] ?? ''), (string) ($template['stage_label'] ?? 'Stage')),
            'project_label' => $this->labelOrDefault((string) ($input['project_label'] ?? ''), (string) ($template['project_label'] ?? 'Project')),
            'task_label' => $this->labelOrDefault((string) ($input['task_label'] ?? ''), (string) ($template['task_label'] ?? 'Task')),
            'assignee_label' => $this->labelOrDefault((string) ($input['assignee_label'] ?? ''), (string) ($template['assignee_label'] ?? 'Assignee')),
            'communication_label' => $this->labelOrDefault((string) ($input['communication_label'] ?? ''), (string) ($template['communication_label'] ?? 'Updates')),
            'upload_label' => $this->labelOrDefault((string) ($input['upload_label'] ?? ''), (string) ($template['upload_label'] ?? 'Files / Photos')),
            'starter_modules' => $starterModules,
            'starter_module_labels' => $this->starterModuleLabels($starterModules),
            'work_management_module_keys' => $workManagementModuleKeys,
            'work_management_module_labels' => $this->starterModuleLabels($workManagementModuleKeys),
            'work_management_intent' => $workManagementIntent,
            'work_management_intent_labels' => $this->workManagementIntentLabels($workManagementIntent),
            'has_work_management_intent' => in_array(true, $workManagementIntent, true),
            'work_management_notes' => $this->nullableText((string) ($input['work_management_notes'] ?? ''), 2000),
            'setup_notes' => $this->nullableText((string) ($input['setup_notes'] ?? ''), 5000),
            'onboarding_next_action' => $this->nullableText((string) ($input['onboarding_next_action'] ?? ''), 500)
                ?: $this->defaultNextAction($operatingMode, $dataSourcePreference),
        ];
    }

    public function applyBlueprint(
        Tenant $tenant,
        TenantAccessProfile $profile,
        TenantSetupStatus $status,
        array $blueprint,
        string $accountMode,
        bool $refreshSetupProjection = false
    ): void {
        $metadata = is_array($profile->metadata) ? $profile->metadata : [];
        $existingBlueprint = is_array($metadata['tenant_blueprint'] ?? null) ? (array) $metadata['tenant_blueprint'] : [];
        $reviewSource = array_key_exists('blueprint_review_status', $blueprint)
            ? $blueprint
            : $existingBlueprint;

        $metadata['account_mode'] = $this->optionOrDefault($accountMode, array_keys($this->accountModeOptions()), 'production');
        $metadata['tenant_blueprint'] = $this->withReviewMetadata(
            blueprint: $blueprint,
            input: [],
            existingBlueprint: $reviewSource,
            operator: null
        );

        $profile->metadata = $metadata;
        $profile->operating_mode = (string) ($metadata['tenant_blueprint']['operating_mode'] ?? $profile->operating_mode ?? 'custom_or_unknown');
        $profile->save();

        $blueprint = (array) $metadata['tenant_blueprint'];
        $blueprintImportPath = $this->importPathForBlueprint($blueprint);
        $dataSourcePreference = (string) ($blueprint['data_source_preference'] ?? '');
        $existingImportPath = (string) ($status->import_path ?: 'undecided');
        $existingSquareStatus = (string) ($status->square_status ?: 'not_requested');
        $existingCsvManualStatus = (string) ($status->csv_manual_status ?: 'not_started');

        $existingNextAction = trim((string) $status->next_recommended_action);

        $status->forceFill([
            'business_profile_status' => (string) ($status->business_profile_status ?: 'not_started') === 'not_started'
                ? 'in_progress'
                : (string) $status->business_profile_status,
            'import_path' => $refreshSetupProjection || in_array($existingImportPath, ['', 'undecided', 'other'], true)
                ? $blueprintImportPath
                : $existingImportPath,
            'square_status' => $existingSquareStatus !== 'not_requested'
                ? $existingSquareStatus
                : ($dataSourcePreference === 'square' ? 'requested' : 'not_requested'),
            'csv_manual_status' => $existingCsvManualStatus !== 'not_started'
                ? $existingCsvManualStatus
                : (in_array($dataSourcePreference, ['csv', 'manual'], true) ? 'requested' : 'not_started'),
            'landlord_review_status' => 'waiting_on_everbranch',
            'next_recommended_action' => (! $refreshSetupProjection && $this->shouldPreserveNextAction($existingNextAction))
                ? (string) $status->next_recommended_action
                : (string) ($blueprint['onboarding_next_action'] ?? $this->defaultNextAction(
                    (string) ($blueprint['operating_mode'] ?? ''),
                    (string) ($blueprint['data_source_preference'] ?? '')
                )),
            'internal_notes' => $this->appendBlueprintInternalNotes((string) $status->internal_notes, $blueprint),
        ])->save();
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function updateBlueprint(
        Tenant $tenant,
        TenantAccessProfile $profile,
        TenantSetupStatus $status,
        array $input,
        User $operator
    ): array {
        $metadata = is_array($profile->metadata) ? $profile->metadata : [];
        $existingBlueprint = is_array($metadata['tenant_blueprint'] ?? null)
            ? (array) $metadata['tenant_blueprint']
            : [];

        $accountMode = (string) ($metadata['account_mode'] ?? $input['account_mode'] ?? 'production');
        $blueprint = $this->withReviewMetadata(
            blueprint: $this->blueprintFromInput($input),
            input: $input,
            existingBlueprint: $existingBlueprint,
            operator: $operator
        );

        $this->applyBlueprint(
            tenant: $tenant,
            profile: $profile,
            status: $status,
            blueprint: $blueprint,
            accountMode: $accountMode,
            refreshSetupProjection: true
        );

        return $blueprint;
    }

    /**
     * @return array<string,mixed>
     */
    public function payloadForTenant(Tenant $tenant): array
    {
        $profile = $tenant->accessProfile;
        $metadata = is_array($profile?->metadata) ? $profile->metadata : [];
        $blueprint = is_array($metadata['tenant_blueprint'] ?? null)
            ? (array) $metadata['tenant_blueprint']
            : $this->blueprintFromInput([
                'business_template' => 'generic',
                'operating_mode' => (string) ($profile?->operating_mode ?? 'custom_or_unknown'),
                'data_source_preference' => 'undecided',
            ]);

        $starterModules = array_values((array) ($blueprint['starter_modules'] ?? []));
        $workManagementModuleKeys = array_values((array) ($blueprint['work_management_module_keys'] ?? []));
        $workManagementIntent = is_array($blueprint['work_management_intent'] ?? null)
            ? (array) $blueprint['work_management_intent']
            : $this->workManagementIntentFromInput([], $this->templateDefinition((string) ($blueprint['business_template'] ?? 'generic')));

        $blueprint['account_mode'] = (string) ($metadata['account_mode'] ?? 'production');
        $blueprint['account_mode_label'] = (string) data_get($this->accountModeOptions(), $blueprint['account_mode'], Str::headline((string) $blueprint['account_mode']));
        $blueprint = $this->withReviewMetadata($blueprint, [], $blueprint, null);
        $blueprint['blueprint_review_status_label'] = (string) data_get($this->reviewStatusOptions(), (string) $blueprint['blueprint_review_status'], 'Unreviewed');
        $blueprint['blueprint_reviewed_by_label'] = $this->reviewedByLabel($blueprint['blueprint_reviewed_by'] ?? null);
        $blueprint['blueprint_reviewed_at_label'] = $this->reviewedAtLabel($blueprint['blueprint_reviewed_at'] ?? null);
        $blueprint['starter_modules'] = $starterModules;
        $blueprint['starter_module_labels'] = $this->starterModuleLabels($starterModules);
        $blueprint['work_management_module_keys'] = $workManagementModuleKeys;
        $blueprint['work_management_module_labels'] = $this->starterModuleLabels($workManagementModuleKeys);
        $blueprint['work_management_intent'] = $this->normalizeWorkManagementIntent($workManagementIntent);
        $blueprint['work_management_intent_labels'] = $this->workManagementIntentLabels((array) $blueprint['work_management_intent']);
        $blueprint['has_work_management_intent'] = in_array(true, (array) $blueprint['work_management_intent'], true);

        return $blueprint;
    }

    /**
     * @param  array<string,mixed>  $blueprint
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $existingBlueprint
     * @return array<string,mixed>
     */
    public function withReviewMetadata(array $blueprint, array $input = [], array $existingBlueprint = [], ?User $operator = null): array
    {
        $reviewStatus = $this->optionOrDefault(
            (string) ($input['blueprint_review_status'] ?? $existingBlueprint['blueprint_review_status'] ?? 'unreviewed'),
            array_keys($this->reviewStatusOptions()),
            'unreviewed'
        );

        $blueprint['blueprint_review_status'] = $reviewStatus;
        $blueprint['blueprint_review_status_label'] = (string) data_get($this->reviewStatusOptions(), $reviewStatus, 'Unreviewed');
        $blueprint['blueprint_internal_notes'] = array_key_exists('blueprint_internal_notes', $input)
            ? $this->nullableText((string) $input['blueprint_internal_notes'], 5000)
            : $this->nullableText((string) ($existingBlueprint['blueprint_internal_notes'] ?? ''), 5000);
        $blueprint['blueprint_next_action'] = array_key_exists('blueprint_next_action', $input)
            ? $this->nullableText((string) $input['blueprint_next_action'], 500)
            : $this->nullableText((string) ($existingBlueprint['blueprint_next_action'] ?? ''), 500);

        if ($reviewStatus === 'reviewed') {
            $blueprint['blueprint_reviewed_by'] = $operator
                ? (int) $operator->id
                : (is_numeric($existingBlueprint['blueprint_reviewed_by'] ?? null) ? (int) $existingBlueprint['blueprint_reviewed_by'] : null);
            $blueprint['blueprint_reviewed_at'] = $operator
                ? now()->toIso8601String()
                : ($existingBlueprint['blueprint_reviewed_at'] ?? null);
        } else {
            $blueprint['blueprint_reviewed_by'] = null;
            $blueprint['blueprint_reviewed_at'] = null;
        }

        return $blueprint;
    }

    public function importPathForBlueprint(array $blueprint): string
    {
        $source = (string) ($blueprint['data_source_preference'] ?? 'undecided');
        if (in_array($source, ['shopify', 'square', 'csv', 'manual'], true)) {
            return $source;
        }

        return match ((string) ($blueprint['operating_mode'] ?? '')) {
            'shopify' => 'shopify',
            'csv' => 'csv',
            'manual', 'direct', 'demo', 'sandbox' => 'manual',
            'square_pending' => 'square',
            default => 'other',
        };
    }

    protected function defaultDataSourceForOperatingMode(string $operatingMode): string
    {
        return match ($operatingMode) {
            'shopify' => 'shopify',
            'csv' => 'csv',
            'manual', 'direct', 'demo', 'sandbox' => 'manual',
            'square_pending' => 'square',
            default => 'undecided',
        };
    }

    protected function defaultNextAction(string $operatingMode, string $dataSourcePreference): string
    {
        if ($operatingMode === 'shopify' || $dataSourcePreference === 'shopify') {
            return 'Confirm Shopify store connection plan, then keep setup review and billing disabled until approved.';
        }

        if ($dataSourcePreference === 'square') {
            return 'Review Square setup request and decide whether manual export, CSV, or a future connector is the safest path.';
        }

        if ($dataSourcePreference === 'csv') {
            return 'Collect sample CSV/spreadsheet data and define the import mapping before any import execution.';
        }

        if ($dataSourcePreference === 'manual') {
            return 'Review manual setup details and confirm the first customer/work/money/material data to capture.';
        }

        return 'Review the tenant blueprint and choose the safest setup path before activating any automation.';
    }

    /**
     * @return array<string,mixed>
     */
    protected function templateDefinition(string $templateKey): array
    {
        return (array) data_get((array) config('tenant_blueprints.templates', []), $templateKey, []);
    }

    protected function normalizeTemplate(string $template): string
    {
        $normalized = Str::slug(strtolower(trim($template)), '_');
        $templates = (array) config('tenant_blueprints.templates', []);

        if (array_key_exists($normalized, $templates)) {
            return $normalized;
        }

        foreach ($templates as $key => $definition) {
            $aliases = array_map(
                static fn (mixed $alias): string => Str::slug(strtolower(trim((string) $alias)), '_'),
                (array) ($definition['aliases'] ?? [])
            );

            if (in_array($normalized, $aliases, true)) {
                return (string) $key;
            }
        }

        return 'generic';
    }

    /**
     * @param  array<string,mixed>  $template
     * @return array<int,string>
     */
    protected function normalizeStarterModules(array $values, array $template): array
    {
        $allowed = array_keys($this->starterModuleOptions());
        $selected = array_values(array_filter(array_unique(array_map(
            static fn (mixed $value): string => Str::slug(strtolower(trim((string) $value)), '_'),
            $values
        ))));

        $selected = array_values(array_intersect($selected, $allowed));
        $templateModules = array_values(array_intersect(array_values((array) ($template['starter_modules'] ?? [])), $allowed));

        return array_values(array_unique(array_merge($templateModules, $selected)));
    }

    /**
     * @param  array<string,mixed>  $template
     * @param  array<int,string>  $starterModules
     * @return array<int,string>
     */
    protected function normalizeWorkManagementModules(array $template, array $starterModules): array
    {
        $allowed = array_keys($this->starterModuleOptions());
        $templateModules = array_values(array_intersect(array_values((array) ($template['work_management_modules'] ?? [])), $allowed));

        return array_values(array_unique(array_merge($templateModules, array_intersect($starterModules, $templateModules))));
    }

    /**
     * @param  array<int,string>  $modules
     * @return array<int,string>
     */
    protected function starterModuleLabels(array $modules): array
    {
        $options = $this->starterModuleOptions();

        return array_values(array_map(
            static fn (string $module): string => (string) ($options[$module] ?? Str::headline($module)),
            $modules
        ));
    }

    /**
     * @param  array<int,string>  $allowed
     */
    protected function optionOrDefault(string $value, array $allowed, string $default): string
    {
        $normalized = Str::slug(strtolower(trim($value)), '_');

        return in_array($normalized, $allowed, true) ? $normalized : $default;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,mixed>  $template
     * @return array<string,bool>
     */
    protected function workManagementIntentFromInput(array $input, array $template): array
    {
        $intent = [];

        foreach (self::WORK_MANAGEMENT_INTENT_KEYS as $key) {
            $intent[$key] = array_key_exists($key, $input)
                ? $this->truthy($input[$key])
                : (bool) ($template[$key] ?? false);
        }

        return $intent;
    }

    /**
     * @param  array<string,mixed>  $intent
     * @return array<string,bool>
     */
    protected function normalizeWorkManagementIntent(array $intent): array
    {
        return collect(self::WORK_MANAGEMENT_INTENT_KEYS)
            ->mapWithKeys(fn (string $key): array => [$key => $this->truthy($intent[$key] ?? false)])
            ->all();
    }

    /**
     * @param  array<string,bool>  $intent
     * @return array<int,string>
     */
    protected function workManagementIntentLabels(array $intent): array
    {
        return collect(self::WORK_MANAGEMENT_INTENT_LABELS)
            ->filter(fn (string $label, string $key): bool => (bool) ($intent[$key] ?? false))
            ->values()
            ->all();
    }

    protected function truthy(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower(trim((string) $value)), ['1', 'true', 'yes', 'on'], true);
    }

    protected function reviewedByLabel(mixed $reviewedBy): ?string
    {
        if (! is_numeric($reviewedBy)) {
            return null;
        }

        $user = User::query()->find((int) $reviewedBy);

        return $user ? (string) ($user->name ?: $user->email) : 'User #'.(int) $reviewedBy;
    }

    protected function reviewedAtLabel(mixed $reviewedAt): ?string
    {
        $value = trim((string) $reviewedAt);
        if ($value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->toDayDateTimeString();
        } catch (\Throwable) {
            return $value;
        }
    }

    protected function labelOrDefault(string $value, string $default): string
    {
        $normalized = trim($value);

        return $normalized !== '' ? Str::limit($normalized, 80, '') : $default;
    }

    protected function nullableText(string $value, int $limit): ?string
    {
        $normalized = trim($value);

        return $normalized !== '' ? Str::limit($normalized, $limit, '') : null;
    }

    /**
     * @param  array<string,string>  $values
     * @return array<string,string>
     */
    protected function stringMap(array $values): array
    {
        return collect($values)
            ->mapWithKeys(fn (mixed $label, mixed $key): array => [(string) $key => (string) $label])
            ->all();
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    protected function blueprintInternalNotes(array $blueprint): string
    {
        $parts = [
            'Tenant blueprint saved by landlord.',
            'Template: '.(string) ($blueprint['business_template_label'] ?? $blueprint['business_template'] ?? 'Generic'),
            'Operating mode: '.(string) ($blueprint['operating_mode_label'] ?? $blueprint['operating_mode'] ?? 'Unknown'),
            'Data source: '.(string) ($blueprint['data_source_preference_label'] ?? $blueprint['data_source_preference'] ?? 'Undecided'),
        ];

        if (filled($blueprint['setup_notes'] ?? null)) {
            $parts[] = 'Notes: '.(string) $blueprint['setup_notes'];
        }

        return implode("\n", $parts);
    }

    /**
     * @param  array<string,mixed>  $blueprint
     */
    protected function appendBlueprintInternalNotes(string $existingNotes, array $blueprint): string
    {
        $existingNotes = trim($existingNotes);
        $blueprintNotes = $this->blueprintInternalNotes($blueprint);

        if (str_contains($existingNotes, 'Tenant blueprint saved by landlord.')) {
            return $existingNotes;
        }

        return trim($existingNotes."\n\n".$blueprintNotes);
    }

    protected function shouldPreserveNextAction(string $nextAction): bool
    {
        if ($nextAction === '') {
            return false;
        }

        return $nextAction !== 'Finish the business profile so setup guidance can stay specific.';
    }
}
