<?php

namespace App\Services\Automation\V2;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

class LegacyWorkflowDefinitionConverter
{
    /** @param array<string,mixed> $definition @return array<string,mixed> */
    public function convert(array $definition): array
    {
        if ((int) ($definition['schema_version'] ?? 0) === 2) {
            return $definition;
        }

        $legacyTrigger = is_array($definition['trigger'] ?? null)
            ? (array) $definition['trigger']
            : [];
        $legacyAction = is_array($definition['action'] ?? null)
            ? (array) $definition['action']
            : [];

        $triggerComponent = $this->triggerComponent(
            (string) ($legacyTrigger['provider'] ?? '')
        );
        $actionComponent = $this->actionComponent(
            (string) ($legacyAction['provider'] ?? '')
        );

        $errors = [];
        if ($triggerComponent === null && $legacyTrigger !== []) {
            $errors['trigger.provider'] = ['This legacy trigger cannot be converted to Workflow Studio.'];
        }
        if ($actionComponent === null && $legacyAction !== []) {
            $errors['action.provider'] = ['This legacy action cannot be converted to Workflow Studio.'];
        }
        if ($errors !== []) {
            throw new WorkflowDefinitionException($errors, 'This legacy workflow cannot be converted automatically.');
        }

        $trigger = $triggerComponent === null ? null : [
            'id' => (string) Str::ulid(),
            'kind' => 'trigger',
            'component_key' => $triggerComponent,
            'connection_id' => $this->connectionId($legacyTrigger['connection_id'] ?? null),
            'config' => Arr::except($legacyTrigger, [
                'provider',
                'event',
                'connection_id',
            ]),
        ];

        $steps = $actionComponent === null ? [] : [[
            'id' => (string) Str::ulid(),
            'kind' => 'action',
            'component_key' => $actionComponent,
            'connection_id' => $this->connectionId($legacyAction['connection_id'] ?? null),
            'config' => Arr::except($legacyAction, [
                'provider',
                'event',
                'connection_id',
            ]),
        ]];

        return [
            'schema_version' => 2,
            'trigger' => $trigger,
            'steps' => $steps,
            'settings' => [
                'poll_interval_minutes' => 10,
                'max_items_per_poll' => min(
                    1000,
                    max(1, (int) ($legacyTrigger['max_tasks_per_run'] ?? $legacyTrigger['poll_limit'] ?? 100))
                ),
            ],
            'metadata' => array_filter([
                'source_template_key' => $this->nullableString(
                    $definition['template_key'] ?? null
                ),
                'converted_from_schema_version' => 1,
            ], fn (mixed $value): bool => $value !== null),
        ];
    }

    private function triggerComponent(string $provider): ?string
    {
        return match (strtolower(trim($provider))) {
            'asana' => 'asana.task.created_or_updated',
            'shopify' => 'shopify.order.created_or_updated',
            'square' => 'square.order.created_or_updated',
            default => null,
        };
    }

    private function actionComponent(string $provider): ?string
    {
        return match (strtolower(trim($provider))) {
            'google_calendar' => 'google_calendar.event.upsert',
            default => null,
        };
    }

    private function connectionId(mixed $value): ?int
    {
        $connectionId = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1],
        ]);

        return $connectionId === false ? null : (int) $connectionId;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
