<?php

namespace App\Services\Automation\V2;

use App\Models\IntegrationConnection;
use Illuminate\Support\Str;

class WorkflowDefinitionCompiler
{
    private const MAX_STEPS = 100;

    private const MAX_PATH_BRANCHES = 10;

    private const MAX_PATH_DEPTH = 3;

    private const CONDITION_OPERATORS = [
        'equals',
        'not_equals',
        'contains',
        'not_contains',
        'does_not_contain',
        'starts_with',
        'does_not_start_with',
        'ends_with',
        'does_not_end_with',
        'exactly_matches',
        'does_not_exactly_match',
        'is_in',
        'is_not_in',
        'greater_than',
        'greater_than_or_equal',
        'less_than',
        'less_than_or_equal',
        'number_equals',
        'after',
        'before',
        'date_after',
        'date_before',
        'date_equals',
        'is_true',
        'is_false',
        'exists',
        'not_exists',
        'does_not_exist',
        'is_empty',
        'is_not_empty',
        'contains_any',
        'contains_all',
    ];

    /** @var array<string,list<string>> */
    private array $errors = [];

    /** @var array<string,true> */
    private array $definitionIds = [];

    private int $stepCount = 0;

    private int $actionCount = 0;

    public function __construct(
        protected WorkflowComponentCatalog $catalog,
    ) {}

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public function compileDraft(array $definition, int $tenantId): array
    {
        return $this->compile($definition, $tenantId, false);
    }

    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public function compileForPublish(array $definition, int $tenantId): array
    {
        return $this->compile($definition, $tenantId, true);
    }

    /**
     * Convert untrusted builder JSON into the canonical persisted definition.
     *
     * @param  array<string,mixed>  $definition
     * @return array<string,mixed>
     */
    public function compile(array $definition, int $tenantId, bool $publishReady = false): array
    {
        $this->errors = [];
        $this->definitionIds = [];
        $this->stepCount = 0;
        $this->actionCount = 0;

        try {
            $definitionBytes = strlen(json_encode(
                $definition,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
            ));
            if ($definitionBytes > max(1, (int) config('automation_workflows.max_definition_bytes', 1_048_576))) {
                $this->addError('definition', 'The workflow definition exceeds the supported size limit.');
            }
        } catch (\JsonException) {
            $this->addError('definition', 'The workflow definition contains invalid text or values.');
        }

        if ((int) ($definition['schema_version'] ?? 0) !== 2) {
            $this->addError('schema_version', 'Workflow Studio definitions must use schema version 2.');
        }

        $trigger = null;
        $rawTrigger = $definition['trigger'] ?? null;
        if ($rawTrigger !== null) {
            if (! is_array($rawTrigger)) {
                $this->addError('trigger', 'The trigger must be an object.');
            } else {
                $trigger = $this->normalizeStep(
                    $rawTrigger,
                    'trigger',
                    'trigger',
                    $tenantId,
                    $publishReady,
                    []
                );
            }
        } elseif ($publishReady) {
            $this->addError('trigger', 'Choose and configure a trigger before publishing.');
        }

        $rawSteps = $definition['steps'] ?? [];
        if (! is_array($rawSteps) || ! array_is_list($rawSteps)) {
            $this->addError('steps', 'Workflow steps must be an ordered list.');
            $rawSteps = [];
        }

        if ($trigger === null && $rawSteps !== []) {
            $this->addError('steps', 'Add a trigger before adding workflow steps.');
        }

        $steps = $this->normalizeStepList(
            $rawSteps,
            'steps',
            $tenantId,
            $publishReady,
            $trigger === null ? [] : ['trigger'],
            0
        );

        if ($this->stepCount > self::MAX_STEPS) {
            $this->addError('steps', 'A workflow can contain at most '.self::MAX_STEPS.' total steps.');
        }
        if ($publishReady && $this->actionCount === 0) {
            $this->addError('steps', 'Add at least one action before publishing.');
        }

        $settings = $this->normalizeSettings($definition['settings'] ?? [], $publishReady);

        if ($this->errors !== []) {
            throw new WorkflowDefinitionException($this->errors);
        }

        $compiled = [
            'schema_version' => 2,
            'trigger' => $trigger,
            'steps' => $steps,
            'settings' => $settings,
        ];

        $metadata = $definition['metadata'] ?? null;
        if (is_array($metadata)) {
            $compiled['metadata'] = array_filter([
                'source_template_key' => $this->nullableString($metadata['source_template_key'] ?? null),
                'converted_from_schema_version' => isset($metadata['converted_from_schema_version'])
                    ? (int) $metadata['converted_from_schema_version']
                    : null,
            ], fn (mixed $value): bool => $value !== null);
        }

        return $compiled;
    }

    /**
     * @param  list<mixed>  $steps
     * @param  list<string>  $knownStepIds
     * @return list<array<string,mixed>>
     */
    private function normalizeStepList(
        array $steps,
        string $path,
        int $tenantId,
        bool $publishReady,
        array $knownStepIds,
        int $pathDepth,
    ): array {
        $normalized = [];
        $lastIndex = count($steps) - 1;

        foreach ($steps as $index => $rawStep) {
            $stepPath = "{$path}.{$index}";
            if (! is_array($rawStep)) {
                $this->addError($stepPath, 'Each workflow step must be an object.');

                continue;
            }

            $kind = trim((string) ($rawStep['kind'] ?? ''));
            if (! in_array($kind, ['action', 'filter', 'delay', 'paths'], true)) {
                $this->addError("{$stepPath}.kind", 'Choose a supported action or flow-control kind.');
            }

            $step = $this->normalizeStep(
                $rawStep,
                $kind,
                $stepPath,
                $tenantId,
                $publishReady,
                $knownStepIds
            );
            if ($step === null) {
                continue;
            }

            if ($step['kind'] === 'paths') {
                if ($index !== $lastIndex) {
                    $this->addError($stepPath, 'Paths must be the final step in their sequence.');
                }
                if ($pathDepth >= self::MAX_PATH_DEPTH) {
                    $this->addError($stepPath, 'Paths can be nested at most '.self::MAX_PATH_DEPTH.' levels deep.');
                }

                $config = (array) $step['config'];
                $config['branches'] = $this->normalizeBranches(
                    $config['branches'] ?? [],
                    "{$stepPath}.config.branches",
                    $tenantId,
                    $publishReady,
                    $knownStepIds,
                    $pathDepth + 1
                );
                $step['config'] = $config;
            }

            $normalized[] = $step;
            $knownStepIds[] = (string) $step['id'];
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $rawStep
     * @param  list<string>  $knownStepIds
     * @return array<string,mixed>|null
     */
    private function normalizeStep(
        array $rawStep,
        string $expectedKind,
        string $path,
        int $tenantId,
        bool $publishReady,
        array $knownStepIds,
    ): ?array {
        $this->stepCount++;

        $id = trim((string) ($rawStep['id'] ?? ''));
        if (! Str::isUlid($id)) {
            $this->addError("{$path}.id", 'Every workflow step must have a stable ULID.');
        } elseif (isset($this->definitionIds[$id])) {
            $this->addError("{$path}.id", 'Workflow step and branch IDs must be unique.');
        } else {
            $this->definitionIds[$id] = true;
        }

        $componentKey = strtolower(trim((string) ($rawStep['component_key'] ?? '')));
        $component = $this->catalog->component($componentKey);
        if (! is_array($component) || ! (bool) ($component['available'] ?? false)) {
            $this->addError("{$path}.component_key", 'Choose an available workflow component.');

            return null;
        }

        $catalogKind = (string) $component['kind'];
        if ($catalogKind !== $expectedKind) {
            $this->addError(
                "{$path}.kind",
                "The selected component must be used as a {$catalogKind} step."
            );
        }

        if ($catalogKind === 'action') {
            $this->actionCount++;
        }

        $connectionId = $this->normalizeConnectionId(
            $rawStep['connection_id'] ?? null,
            $component,
            $tenantId,
            $publishReady,
            "{$path}.connection_id"
        );

        $rawConfig = $rawStep['config'] ?? [];
        if (! is_array($rawConfig)) {
            $this->addError("{$path}.config", 'Step configuration must be an object.');
            $rawConfig = [];
        }
        if ($catalogKind === 'paths'
            && ! array_key_exists('branches', $rawConfig)
            && is_array($rawStep['branches'] ?? null)) {
            $rawConfig['branches'] = $rawStep['branches'];
        }

        $config = $this->normalizeConfig(
            $rawConfig,
            $component,
            $publishReady,
            "{$path}.config",
            $knownStepIds
        );

        return [
            'id' => $id,
            'kind' => $catalogKind,
            'component_key' => $componentKey,
            'connection_id' => $connectionId,
            'config' => $config,
        ];
    }

    /**
     * @param  array<string,mixed>  $component
     */
    private function normalizeConnectionId(
        mixed $rawConnectionId,
        array $component,
        int $tenantId,
        bool $publishReady,
        string $path,
    ): ?int {
        $connectionRequired = (bool) ($component['connection_required'] ?? false);
        $connectionId = filter_var($rawConnectionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $connectionId = $connectionId === false ? null : (int) $connectionId;

        if (! $connectionRequired) {
            if ($rawConnectionId !== null && $rawConnectionId !== '') {
                $this->addError($path, 'This component does not use an external connection.');
            }

            return null;
        }

        if ($connectionId === null) {
            if ($publishReady) {
                $this->addError($path, 'Choose a connected account before publishing.');
            }

            return null;
        }

        $connection = IntegrationConnection::query()
            ->forAllTenants()
            ->where('tenant_id', $tenantId)
            ->whereKey($connectionId)
            ->first();
        $provider = (string) ($component['connection_provider'] ?? $component['provider'] ?? '');

        if (! $connection || $connection->provider !== $provider) {
            $this->addError($path, 'The selected connection is not available in this workspace.');

            return null;
        }

        if ($publishReady && ! $connection->isConnected()) {
            $this->addError($path, 'Reconnect this account before publishing.');
        }

        $requiredScopes = array_values(array_filter(
            (array) ($component['required_scopes'] ?? []),
            'is_string'
        ));
        $connectionScopes = array_values(array_filter((array) $connection->scopes, 'is_string'));
        if ($publishReady && $requiredScopes !== []) {
            $normalizedScopes = array_map(
                fn (string $scope): string => strtolower(trim($scope)),
                $connectionScopes
            );
            $missingScopes = array_filter(
                $requiredScopes,
                fn (string $scope): bool => ! in_array(strtolower(trim($scope)), $normalizedScopes, true)
            );
            if ($missingScopes !== []) {
                $this->addError($path, 'Reconnect this account with the permissions required by this step.');
            }
        }

        return $connectionId;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $component
     * @param  list<string>  $knownStepIds
     * @return array<string,mixed>
     */
    private function normalizeConfig(
        array $config,
        array $component,
        bool $publishReady,
        string $path,
        array $knownStepIds,
    ): array {
        $calendarPresentation = ($component['key'] ?? null) === 'google_calendar.event.upsert'
            && array_key_exists('presentation', $config)
            ? $config['presentation']
            : null;
        $config = $this->normalizeMappingAliases($config);
        if ($calendarPresentation !== null) {
            // This object uses CalendarEventPresentationService's constrained
            // template syntax, not Workflow Studio field mappings.
            $config['presentation'] = $calendarPresentation;
        }
        if (in_array(($component['key'] ?? null), ['core.delay_for', 'core.delay_until'], true)) {
            unset($config['value_source']);
        }

        $fields = collect((array) ($component['config_fields'] ?? []))
            ->filter(fn (mixed $field): bool => is_array($field))
            ->keyBy(fn (array $field): string => (string) ($field['key'] ?? ''));
        $allowedKeys = $fields->keys()->filter()->all();

        foreach (array_keys($config) as $key) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                $this->addError("{$path}.{$key}", 'This configuration field is not supported by the selected component.');
                unset($config[$key]);
            }
        }

        foreach ($fields as $key => $field) {
            if (! array_key_exists($key, $config) && array_key_exists('default', $field)) {
                $config[$key] = $field['default'];
            }

            if ($publishReady && (bool) ($field['required'] ?? false) && $this->isMissing($config[$key] ?? null)) {
                $this->addError("{$path}.{$key}", (string) ($field['label'] ?? $key).' is required.');

                continue;
            }

            if (! array_key_exists($key, $config) || $config[$key] === null) {
                continue;
            }

            $this->validateFieldValue($config[$key], $field, "{$path}.{$key}", $knownStepIds);
        }

        if (($component['key'] ?? null) === 'core.filter') {
            $config = $this->normalizeConditionGroup($config, $path, $knownStepIds, $publishReady);
        }
        if (($component['key'] ?? null) === 'core.delay_for') {
            $this->validateDelayFor($config, $path);
        }
        if (($component['key'] ?? null) === 'core.delay_until') {
            $this->validateDelayUntil($config, $path);
        }

        $mappingConfig = $config;
        if (($component['key'] ?? null) === 'core.paths') {
            unset($mappingConfig['branches']);
        }
        if (($component['key'] ?? null) === 'google_calendar.event.upsert') {
            // Calendar presentation templates use their own constrained
            // {{task_name}}-style renderer. They are not workflow expression
            // mappings and must not be interpreted as such by this compiler.
            unset($mappingConfig['presentation']);
        }
        $this->validateMappings($mappingConfig, $path, $knownStepIds);

        return $config;
    }

    /**
     * @param  array<string,mixed>  $field
     * @param  list<string>  $knownStepIds
     */
    private function validateFieldValue(mixed $value, array $field, string $path, array $knownStepIds): void
    {
        $type = (string) ($field['type'] ?? 'string');

        if ($type === 'string' && (! is_string($value) || mb_strlen($value) > 5000)) {
            $this->addError($path, 'Enter valid text no longer than 5,000 characters.');
        } elseif ($type === 'integer') {
            if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                $this->addError($path, 'Enter a whole number.');
            } else {
                $integer = (int) $value;
                if (isset($field['min']) && $integer < (int) $field['min']) {
                    $this->addError($path, 'The value is below the supported minimum.');
                }
                if (isset($field['max']) && $integer > (int) $field['max']) {
                    $this->addError($path, 'The value exceeds the supported maximum.');
                }
            }
        } elseif ($type === 'boolean' && ! is_bool($value)) {
            $this->addError($path, 'Choose either true or false.');
        } elseif ($type === 'select') {
            $options = collect((array) ($field['options'] ?? []))
                ->pluck('value')
                ->map(fn (mixed $option): string => (string) $option)
                ->all();
            if (! is_scalar($value) || ! in_array((string) $value, $options, true)) {
                $this->addError($path, 'Choose one of the supported options.');
            }
        } elseif (in_array($type, ['object'], true) && ! is_array($value)) {
            $this->addError($path, 'This configuration must be an object.');
        } elseif (in_array($type, ['condition_list', 'path_list'], true) && (! is_array($value) || ! array_is_list($value))) {
            $this->addError($path, 'This configuration must be an ordered list.');
        } elseif ($type === 'mapped_value') {
            $this->validateMappedValue($value, $path, $knownStepIds);
        }
    }

    /** @param array<string,mixed> $config */
    private function validateDelayFor(array $config, string $path): void
    {
        $duration = $config['duration'] ?? null;
        $unit = (string) ($config['unit'] ?? 'minutes');
        $literal = $this->literalValue($duration);

        if ($literal === null || ! is_numeric($literal)) {
            return;
        }

        $value = (float) $literal;
        $minutes = match ($unit) {
            'days' => $value * 1440,
            'hours' => $value * 60,
            default => $value,
        };
        if ($minutes < 1 || $minutes > 43200) {
            $this->addError("{$path}.duration", 'Delay duration must be between one minute and 30 days.');
        }
    }

    /** @param array<string,mixed> $config */
    private function validateDelayUntil(array $config, string $path): void
    {
        $literal = $this->literalValue($config['datetime'] ?? null);
        if ($literal === null || $literal === '') {
            return;
        }

        if (! is_string($literal) || strtotime($literal) === false) {
            $this->addError("{$path}.datetime", 'Enter a valid date and time.');

            return;
        }

        if (strtotime($literal) > now()->addDays(30)->getTimestamp()) {
            $this->addError("{$path}.datetime", 'Delay until must be no more than 30 days in the future.');
        }
    }

    /**
     * @param  array<string,mixed>  $group
     * @param  list<string>  $knownStepIds
     */
    private function normalizeConditionGroup(
        array $group,
        string $path,
        array $knownStepIds,
        bool $required,
    ): array {
        $logic = (string) ($group['logic'] ?? 'and');
        if (! in_array($logic, ['and', 'or'], true)) {
            $this->addError("{$path}.logic", 'Condition logic must match all conditions or any condition.');
            $logic = 'and';
        }

        $conditions = $group['conditions'] ?? [];
        if (! is_array($conditions) || ! array_is_list($conditions)) {
            $this->addError("{$path}.conditions", 'Conditions must be an ordered list.');

            return ['logic' => $logic, 'conditions' => []];
        }
        if ($required && $conditions === []) {
            $this->addError("{$path}.conditions", 'Add at least one condition.');
        }
        if (count($conditions) > 25) {
            $this->addError("{$path}.conditions", 'A condition group can contain at most 25 conditions.');
        }

        $normalizedConditions = [];
        foreach ($conditions as $index => $condition) {
            $conditionPath = "{$path}.conditions.{$index}";
            if (! is_array($condition)) {
                $this->addError($conditionPath, 'Each condition must be an object.');

                continue;
            }

            $field = $this->normalizeMappingAliases($condition['field'] ?? '');
            $fieldPath = $this->mappingPath($field);
            if ($fieldPath === null || $fieldPath === '') {
                if ($required) {
                    $this->addError("{$conditionPath}.field", 'Choose data from the trigger or an earlier step.');
                }
            } else {
                $this->validateMappingPath($fieldPath, "{$conditionPath}.field", $knownStepIds);
                $field = ['type' => 'mapping', 'path' => $fieldPath];
            }

            $operator = (string) ($condition['operator'] ?? 'equals');
            if (! in_array($operator, self::CONDITION_OPERATORS, true)) {
                $this->addError("{$conditionPath}.operator", 'Choose a supported condition operator.');
            }

            if (! in_array($operator, ['is_true', 'is_false', 'exists', 'not_exists', 'does_not_exist', 'is_empty', 'is_not_empty'], true)
                && ! array_key_exists('value', $condition)
                && $required) {
                $this->addError("{$conditionPath}.value", 'Enter a comparison value.');
            }

            $hasValue = array_key_exists('value', $condition);
            $value = $hasValue
                ? $this->normalizeMappingAliases($condition['value'])
                : null;
            if ($hasValue) {
                $this->validateMappedValue($value, "{$conditionPath}.value", $knownStepIds);
            }

            $normalizedCondition = [
                'field' => $field,
                'operator' => $operator,
            ];
            $conditionId = $this->nullableString($condition['id'] ?? null);
            if ($conditionId !== null) {
                $normalizedCondition['id'] = $conditionId;
            }
            if ($hasValue) {
                $normalizedCondition['value'] = $value;
            }
            $normalizedConditions[] = $normalizedCondition;
        }

        return ['logic' => $logic, 'conditions' => $normalizedConditions];
    }

    /**
     * @param  list<string>  $knownStepIds
     * @return list<array<string,mixed>>
     */
    private function normalizeBranches(
        mixed $rawBranches,
        string $path,
        int $tenantId,
        bool $publishReady,
        array $knownStepIds,
        int $pathDepth,
    ): array {
        if (! is_array($rawBranches) || ! array_is_list($rawBranches)) {
            $this->addError($path, 'Paths branches must be an ordered list.');

            return [];
        }
        if ($publishReady && count($rawBranches) < 2) {
            $this->addError($path, 'Paths requires at least two branches.');
        }
        if (count($rawBranches) > self::MAX_PATH_BRANCHES) {
            $this->addError($path, 'Paths can contain at most '.self::MAX_PATH_BRANCHES.' branches.');
        }

        $normalized = [];
        $fallbackSeen = false;
        $lastIndex = count($rawBranches) - 1;

        foreach ($rawBranches as $index => $rawBranch) {
            $branchPath = "{$path}.{$index}";
            if (! is_array($rawBranch)) {
                $this->addError($branchPath, 'Each path branch must be an object.');

                continue;
            }

            $id = trim((string) ($rawBranch['id'] ?? ''));
            if (! Str::isUlid($id)) {
                $this->addError("{$branchPath}.id", 'Every path branch must have a stable ULID.');
            } elseif (isset($this->definitionIds[$id])) {
                $this->addError("{$branchPath}.id", 'Workflow step and branch IDs must be unique.');
            } else {
                $this->definitionIds[$id] = true;
            }

            $ruleType = (string) ($rawBranch['rule_type'] ?? $rawBranch['type'] ?? 'custom');
            if (! in_array($ruleType, ['custom', 'always', 'fallback'], true)) {
                $this->addError("{$branchPath}.rule_type", 'Choose custom, always, or fallback branch rules.');
            }
            if ($ruleType === 'fallback') {
                if ($fallbackSeen) {
                    $this->addError("{$branchPath}.rule_type", 'Paths can contain only one fallback branch.');
                }
                if ($index !== $lastIndex) {
                    $this->addError("{$branchPath}.rule_type", 'The fallback branch must be last.');
                }
                $fallbackSeen = true;
            }

            $condition = null;
            if ($ruleType === 'custom') {
                $condition = is_array($rawBranch['condition'] ?? null)
                    ? (array) $rawBranch['condition']
                    : [
                        'logic' => (string) ($rawBranch['logic'] ?? 'and'),
                        'conditions' => is_array($rawBranch['conditions'] ?? null)
                            ? $rawBranch['conditions']
                            : [],
                    ];
                $condition = $this->normalizeConditionGroup(
                    $condition,
                    "{$branchPath}.condition",
                    $knownStepIds,
                    $publishReady
                );
            }

            $branchSteps = $rawBranch['steps'] ?? [];
            if (! is_array($branchSteps) || ! array_is_list($branchSteps)) {
                $this->addError("{$branchPath}.steps", 'Branch steps must be an ordered list.');
                $branchSteps = [];
            }
            $branchSteps = $this->normalizeStepList(
                $branchSteps,
                "{$branchPath}.steps",
                $tenantId,
                $publishReady,
                $knownStepIds,
                $pathDepth
            );
            if ($publishReady && ! $this->containsAction($branchSteps)) {
                $this->addError(
                    "{$branchPath}.steps",
                    'Add at least one action to every path branch before publishing.'
                );
            }

            $normalized[] = [
                'id' => $id,
                'name' => Str::limit(trim((string) ($rawBranch['name'] ?? 'Untitled path')), 80, ''),
                'rule_type' => $ruleType,
                'condition' => $condition,
                'steps' => $branchSteps,
            ];
        }

        return $normalized;
    }

    /** @param list<array<string,mixed>> $steps */
    private function containsAction(array $steps): bool
    {
        foreach ($steps as $step) {
            if (($step['kind'] ?? null) === 'action') {
                return true;
            }
            foreach ((array) data_get($step, 'config.branches', []) as $branch) {
                if (is_array($branch) && $this->containsAction((array) ($branch['steps'] ?? []))) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @return array{poll_interval_minutes:int,max_items_per_poll:int} */
    private function normalizeSettings(mixed $rawSettings, bool $publishReady): array
    {
        if (! is_array($rawSettings)) {
            $this->addError('settings', 'Workflow settings must be an object.');
            $rawSettings = [];
        }

        $pollInterval = filter_var(
            $rawSettings['poll_interval_minutes'] ?? 10,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1440]]
        );
        if ($pollInterval === false) {
            $this->addError('settings.poll_interval_minutes', 'Polling interval must be between 1 and 1,440 minutes.');
            $pollInterval = 10;
        }

        $maxItems = filter_var(
            $rawSettings['max_items_per_poll'] ?? 100,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 1000]]
        );
        if ($maxItems === false) {
            $this->addError('settings.max_items_per_poll', 'Items per poll must be between 1 and 1,000.');
            $maxItems = 100;
        }

        return [
            'poll_interval_minutes' => (int) $pollInterval,
            'max_items_per_poll' => (int) $maxItems,
        ];
    }

    /**
     * @param  list<string>  $knownStepIds
     */
    private function validateMappings(mixed $value, string $path, array $knownStepIds): void
    {
        if (is_string($value)) {
            $this->validateInterpolatedReferences($value, $path, $knownStepIds);

            return;
        }

        if (! is_array($value)) {
            return;
        }

        if (($value['type'] ?? null) === 'mapping') {
            $this->validateMappingPath(
                trim((string) ($value['path'] ?? '')),
                "{$path}.path",
                $knownStepIds
            );

            return;
        }

        foreach ($value as $key => $nested) {
            $this->validateMappings($nested, "{$path}.{$key}", $knownStepIds);
        }
    }

    /**
     * @param  list<string>  $knownStepIds
     */
    private function validateMappedValue(mixed $value, string $path, array $knownStepIds): void
    {
        if (! is_array($value)) {
            if (! is_scalar($value) && $value !== null) {
                $this->addError($path, 'Use a fixed value or a mapping from an earlier step.');
            } elseif (is_string($value)) {
                $this->validateInterpolatedReferences($value, $path, $knownStepIds);
            }

            return;
        }

        $type = (string) ($value['type'] ?? '');
        if ($type === 'literal') {
            if (! array_key_exists('value', $value)) {
                $this->addError("{$path}.value", 'Enter a fixed value.');
            }

            return;
        }
        if ($type === 'mapping') {
            $this->validateMappingPath(
                trim((string) ($value['path'] ?? '')),
                "{$path}.path",
                $knownStepIds
            );

            return;
        }

        $this->addError($path, 'Mapped values must declare either literal or mapping type.');
    }

    private function normalizeMappingAliases(mixed $value): mixed
    {
        if (is_string($value)) {
            $mappingPath = $this->exactInterpolatedPath($value);

            return $mappingPath === null
                ? $value
                : ['type' => 'mapping', 'path' => $mappingPath];
        }

        if (! is_array($value)) {
            return $value;
        }

        if (($value['type'] ?? null) === 'literal') {
            return $value;
        }
        if (in_array(($value['type'] ?? null), ['mapping', 'reference', 'field'], true)) {
            return [
                'type' => 'mapping',
                'path' => trim((string) ($value['path'] ?? $value['reference'] ?? '')),
            ];
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->normalizeMappingAliases($nested);
        }

        return $value;
    }

    private function mappingPath(mixed $value): ?string
    {
        if (is_array($value) && ($value['type'] ?? null) === 'mapping') {
            return trim((string) ($value['path'] ?? ''));
        }
        if (! is_string($value)) {
            return null;
        }

        return $this->exactInterpolatedPath($value) ?? trim($value);
    }

    /** @param list<string> $knownStepIds */
    private function validateInterpolatedReferences(string $value, string $path, array $knownStepIds): void
    {
        if (! str_contains($value, '{{') && ! str_contains($value, '}}')) {
            return;
        }

        preg_match_all('/\\{\\{\\s*([^{}]+)\\s*\\}\\}/', $value, $matches);
        $references = array_values(array_filter(
            (array) ($matches[1] ?? []),
            fn (mixed $reference): bool => is_string($reference)
        ));
        $stripped = preg_replace('/\\{\\{\\s*[^{}]+\\s*\\}\\}/', '', $value);

        if ($references === []
            || str_contains((string) $stripped, '{{')
            || str_contains((string) $stripped, '}}')) {
            $this->addError($path, 'Workflow mappings must contain complete field references.');

            return;
        }

        foreach ($references as $reference) {
            $this->validateMappingPath(trim($reference), $path, $knownStepIds);
        }
    }

    private function exactInterpolatedPath(string $value): ?string
    {
        if (preg_match('/^\\{\\{\\s*([^{}]+)\\s*\\}\\}$/', trim($value), $matches) !== 1) {
            return null;
        }

        return trim((string) $matches[1]);
    }

    /** @param list<string> $knownStepIds */
    private function validateMappingPath(string $mappingPath, string $path, array $knownStepIds): void
    {
        if (preg_match('/^trigger\\.output\\.[A-Za-z0-9_.-]+$/', $mappingPath) === 1) {
            return;
        }

        if (preg_match('/^steps\\.([0-9A-HJKMNP-TV-Z]{26})\\.output\\.[A-Za-z0-9_.-]+$/i', $mappingPath, $matches) === 1) {
            if (in_array($matches[1], $knownStepIds, true)) {
                return;
            }

            $this->addError($path, 'Mappings can reference only earlier steps in the same path.');

            return;
        }

        $this->addError($path, 'Use a typed mapping from trigger.output or an earlier steps.<id>.output value.');
    }

    private function literalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        return ($value['type'] ?? null) === 'literal' ? ($value['value'] ?? null) : null;
    }

    private function isMissing(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function addError(string $path, string $message): void
    {
        $this->errors[$path] ??= [];
        if (! in_array($message, $this->errors[$path], true)) {
            $this->errors[$path][] = $message;
        }
    }
}
