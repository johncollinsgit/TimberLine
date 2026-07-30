<?php

namespace App\Services\Automation\V2;

use App\Services\Automation\AutomationWorkflowException;
use App\Services\Automation\V2\Data\WorkflowExecutionContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * Resolves the deliberately small, typed mapping language used by workflow
 * definitions. A saved workflow can only read trigger output or output from a
 * preceding step; it can never invoke PHP, container services, or expressions.
 */
class TypedValueMapper
{
    public function resolve(mixed $mapping, WorkflowExecutionContext $context): mixed
    {
        if (! is_array($mapping)) {
            return is_string($mapping)
                ? $this->interpolate($mapping, $context)
                : $mapping;
        }

        $type = strtolower(trim((string) ($mapping['type'] ?? '')));
        if ($type === 'literal') {
            return $this->cast($mapping['value'] ?? null, $mapping['cast'] ?? null);
        }

        if (in_array($type, ['mapping', 'reference', 'field'], true)) {
            $path = trim((string) ($mapping['path'] ?? $mapping['reference'] ?? ''));
            $value = $this->reference($path, $context, array_key_exists('default', $mapping), $mapping['default'] ?? null);

            return $this->cast($value, $mapping['cast'] ?? null);
        }

        $resolved = [];
        foreach ($mapping as $key => $value) {
            $resolved[$key] = $this->resolve($value, $context);
        }

        return $resolved;
    }

    /**
     * @param  array<string,mixed>  $mappings
     * @return array<string,mixed>
     */
    public function resolveInputs(array $mappings, WorkflowExecutionContext $context): array
    {
        $resolved = $this->resolve($mappings, $context);

        return is_array($resolved) ? $resolved : [];
    }

    public function isAllowedReference(string $path): bool
    {
        return preg_match('/^trigger\\.output(?:\\.[A-Za-z0-9_-]+)*$/', $path) === 1
            || preg_match('/^steps\\.[0-9A-HJKMNP-TV-Z]{26}\\.output(?:\\.[A-Za-z0-9_-]+)*$/i', $path) === 1;
    }

    protected function reference(
        string $path,
        WorkflowExecutionContext $context,
        bool $hasDefault = false,
        mixed $default = null
    ): mixed {
        if (! $this->isAllowedReference($path)) {
            throw new AutomationWorkflowException('Workflow mapping contains an unsupported field reference.');
        }

        $root = $context->valueRoot();
        if (! Arr::has($root, $path)) {
            if ($hasDefault) {
                return $default;
            }

            throw new AutomationWorkflowException('Workflow mapping references data that is not available yet.');
        }

        return Arr::get($root, $path);
    }

    protected function interpolate(string $value, WorkflowExecutionContext $context): mixed
    {
        if (preg_match('/^\\{\\{\\s*([^{}]+)\\s*\\}\\}$/', $value, $match) === 1) {
            return $this->reference(trim($match[1]), $context);
        }

        return preg_replace_callback('/\\{\\{\\s*([^{}]+)\\s*\\}\\}/', function (array $match) use ($context): string {
            $resolved = $this->reference(trim((string) $match[1]), $context);
            if ($resolved === null) {
                return '';
            }
            if (! is_scalar($resolved)) {
                throw new AutomationWorkflowException('Only scalar workflow values can be inserted into text.');
            }

            return (string) $resolved;
        }, $value) ?? $value;
    }

    protected function cast(mixed $value, mixed $cast): mixed
    {
        $cast = strtolower(trim((string) $cast));
        if ($cast === '') {
            return $value;
        }

        return match ($cast) {
            'string' => is_scalar($value) || $value === null
                ? ($value === null ? null : (string) $value)
                : throw new AutomationWorkflowException('A workflow value could not be converted to text.'),
            'integer', 'int' => is_numeric($value)
                ? (int) $value
                : throw new AutomationWorkflowException('A workflow value could not be converted to an integer.'),
            'number', 'float' => is_numeric($value)
                ? (float) $value
                : throw new AutomationWorkflowException('A workflow value could not be converted to a number.'),
            'boolean', 'bool' => $this->boolean($value),
            'array' => is_array($value)
                ? $value
                : throw new AutomationWorkflowException('A workflow value is not a list or object.'),
            'date' => $this->date($value)->toDateString(),
            'datetime' => $this->date($value)->toIso8601String(),
            default => throw new AutomationWorkflowException('Workflow mapping requested an unsupported value type.'),
        };
    }

    protected function boolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));

        return match ($normalized) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off', '' => false,
            default => throw new AutomationWorkflowException('A workflow value could not be converted to true or false.'),
        };
    }

    protected function date(mixed $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            throw new AutomationWorkflowException('A workflow value could not be converted to a date.');
        }
    }
}
