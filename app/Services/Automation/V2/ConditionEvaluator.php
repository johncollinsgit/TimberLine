<?php

namespace App\Services\Automation\V2;

use App\Services\Automation\V2\Data\WorkflowExecutionContext;
use Carbon\CarbonImmutable;

/**
 * Implements Filter/Paths conditions, including Zapier-compatible line-item
 * behavior: all-positive rules need one item to satisfy the group; once a
 * negative rule is present every item must satisfy the group.
 */
class ConditionEvaluator
{
    /** @var array<int,string> */
    protected const NEGATIVE_OPERATORS = [
        'text_does_not_contain',
        'text_does_not_exactly_match',
        'text_is_not_in',
        'text_does_not_start_with',
        'text_does_not_end_with',
        'does_not_exist',
        'is_empty',
        'not_equals',
    ];

    public function __construct(protected TypedValueMapper $mapper) {}

    /**
     * @param  array<string,mixed>  $condition
     */
    public function evaluate(array $condition, WorkflowExecutionContext $context): bool
    {
        $groups = array_values(array_filter((array) ($condition['groups'] ?? []), 'is_array'));
        if ($groups !== []) {
            $results = array_map(fn (array $group): bool => $this->evaluateGroup($group, $context), $groups);

            return $this->combine($results, $this->logic($condition));
        }

        return $this->evaluateGroup($condition, $context);
    }

    /**
     * @param  array<string,mixed>  $group
     */
    protected function evaluateGroup(array $group, WorkflowExecutionContext $context): bool
    {
        $rules = array_values(array_filter(
            (array) ($group['conditions'] ?? $group['rules'] ?? []),
            'is_array'
        ));
        if ($rules === []) {
            return false;
        }

        $resolved = [];
        $lineItemCount = 0;
        $hasNegative = false;

        foreach ($rules as $rule) {
            $operator = $this->operator((string) ($rule['operator'] ?? $rule['condition'] ?? ''));
            $leftMapping = $rule['field'] ?? $rule['left'] ?? $rule['input'] ?? null;
            $rightMapping = $rule['value'] ?? $rule['right'] ?? $rule['expected'] ?? null;
            $left = $this->mapper->resolve($leftMapping, $context);
            $right = $this->mapper->resolve($rightMapping, $context);

            if (is_array($left) && array_is_list($left)) {
                $lineItemCount = max($lineItemCount, count($left));
            }

            $hasNegative = $hasNegative || in_array($operator, self::NEGATIVE_OPERATORS, true);
            $resolved[] = compact('operator', 'left', 'right');
        }

        if ($lineItemCount === 0) {
            return $this->combine(
                array_map(fn (array $rule): bool => $this->compare($rule['left'], $rule['operator'], $rule['right']), $resolved),
                $this->logic($group)
            );
        }

        $itemResults = [];
        for ($index = 0; $index < $lineItemCount; $index++) {
            $ruleResults = [];
            foreach ($resolved as $rule) {
                $left = is_array($rule['left']) && array_is_list($rule['left'])
                    ? ($rule['left'][$index] ?? null)
                    : $rule['left'];
                $ruleResults[] = $this->compare($left, $rule['operator'], $rule['right']);
            }
            $itemResults[] = $this->combine($ruleResults, $this->logic($group));
        }

        return $hasNegative
            ? ! in_array(false, $itemResults, true)
            : in_array(true, $itemResults, true);
    }

    /**
     * @param  array<int,bool>  $results
     */
    protected function combine(array $results, string $logic): bool
    {
        if ($results === []) {
            return false;
        }

        return $logic === 'or'
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true);
    }

    /**
     * @param  array<string,mixed>  $group
     */
    protected function logic(array $group): string
    {
        return strtolower((string) ($group['logic'] ?? $group['mode'] ?? $group['match'] ?? 'and')) === 'or'
            ? 'or'
            : 'and';
    }

    protected function compare(mixed $left, string $operator, mixed $right): bool
    {
        return match ($operator) {
            'exists' => $this->exists($left),
            'does_not_exist' => ! $this->exists($left),
            'is_empty' => ! $this->exists($left),
            'is_not_empty' => $this->exists($left),
            'is_true' => $this->boolean($left) === true,
            'is_false' => $this->boolean($left) === false,
            'equals' => $this->equals($left, $right),
            'not_equals' => ! $this->equals($left, $right),
            'text_contains' => $this->text($left, $right, static fn (string $a, string $b): bool => str_contains($a, $b)),
            'text_does_not_contain' => $this->text($left, $right, static fn (string $a, string $b): bool => ! str_contains($a, $b)),
            'text_exactly_matches' => $this->text($left, $right, static fn (string $a, string $b): bool => $a === $b),
            'text_does_not_exactly_match' => $this->text($left, $right, static fn (string $a, string $b): bool => $a !== $b),
            'text_starts_with' => $this->text($left, $right, static fn (string $a, string $b): bool => str_starts_with($a, $b)),
            'text_does_not_start_with' => $this->text($left, $right, static fn (string $a, string $b): bool => ! str_starts_with($a, $b)),
            'text_ends_with' => $this->text($left, $right, static fn (string $a, string $b): bool => str_ends_with($a, $b)),
            'text_does_not_end_with' => $this->text($left, $right, static fn (string $a, string $b): bool => ! str_ends_with($a, $b)),
            'text_is_in' => $this->inList($left, $right),
            'text_is_not_in' => ! $this->inList($left, $right),
            'contains_any' => $this->containsValues($left, $right, false),
            'contains_all' => $this->containsValues($left, $right, true),
            'number_equals' => $this->numbers($left, $right, static fn (float $a, float $b): bool => $a === $b),
            'number_greater_than' => $this->numbers($left, $right, static fn (float $a, float $b): bool => $a > $b),
            'number_greater_than_or_equal' => $this->numbers($left, $right, static fn (float $a, float $b): bool => $a >= $b),
            'number_less_than' => $this->numbers($left, $right, static fn (float $a, float $b): bool => $a < $b),
            'number_less_than_or_equal' => $this->numbers($left, $right, static fn (float $a, float $b): bool => $a <= $b),
            'date_after' => $this->dates($left, $right, static fn (CarbonImmutable $a, CarbonImmutable $b): bool => $a->gt($b)),
            'date_before' => $this->dates($left, $right, static fn (CarbonImmutable $a, CarbonImmutable $b): bool => $a->lt($b)),
            'date_equals' => $this->dates($left, $right, static fn (CarbonImmutable $a, CarbonImmutable $b): bool => $a->equalTo($b)),
            default => false,
        };
    }

    protected function exists(mixed $value): bool
    {
        return $value !== null
            && $value !== ''
            && (! is_array($value) || $value !== []);
    }

    protected function boolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }

    protected function equals(mixed $left, mixed $right): bool
    {
        if ($left === null || $right === null) {
            return $left === $right;
        }

        if (is_bool($left) || is_bool($right)) {
            $leftBoolean = $this->boolean($left);
            $rightBoolean = $this->boolean($right);

            return $leftBoolean !== null
                && $rightBoolean !== null
                && $leftBoolean === $rightBoolean;
        }

        if (is_numeric($left) && is_numeric($right)) {
            return (float) $left === (float) $right;
        }

        if ((is_scalar($left) || $left === null) && (is_scalar($right) || $right === null)) {
            return mb_strtolower(trim((string) $left)) === mb_strtolower(trim((string) $right));
        }

        return $left === $right;
    }

    protected function text(mixed $left, mixed $right, callable $comparison): bool
    {
        if (! is_string($left) || ! is_string($right)) {
            return false;
        }

        return $comparison(mb_strtolower($left), mb_strtolower($right));
    }

    protected function inList(mixed $left, mixed $right): bool
    {
        if (! is_string($left)) {
            return false;
        }

        $values = is_array($right)
            ? $right
            : preg_split('/\\s*,\\s*/', (string) $right);
        $normalized = array_map(
            static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
            array_values(array_filter((array) $values, static fn (mixed $value): bool => is_scalar($value)))
        );

        return in_array(mb_strtolower(trim($left)), $normalized, true);
    }

    protected function containsValues(mixed $left, mixed $right, bool $requireAll): bool
    {
        $needles = is_array($right)
            ? $right
            : preg_split('/\s*,\s*/', (string) $right);
        $needles = array_values(array_filter(array_map(
            static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
            (array) $needles
        ), static fn (string $value): bool => $value !== ''));
        if ($needles === []) {
            return false;
        }

        if (is_array($left)) {
            $haystack = array_map(
                static fn (mixed $value): string => mb_strtolower(trim((string) $value)),
                array_values(array_filter($left, 'is_scalar'))
            );
            $matches = array_map(
                static fn (string $needle): bool => in_array($needle, $haystack, true),
                $needles
            );
        } elseif (is_scalar($left)) {
            $haystack = mb_strtolower((string) $left);
            $matches = array_map(
                static fn (string $needle): bool => str_contains($haystack, $needle),
                $needles
            );
        } else {
            return false;
        }

        return $requireAll
            ? ! in_array(false, $matches, true)
            : in_array(true, $matches, true);
    }

    protected function numbers(mixed $left, mixed $right, callable $comparison): bool
    {
        return is_numeric($left) && is_numeric($right)
            ? $comparison((float) $left, (float) $right)
            : false;
    }

    protected function dates(mixed $left, mixed $right, callable $comparison): bool
    {
        try {
            return $comparison(CarbonImmutable::parse((string) $left), CarbonImmutable::parse((string) $right));
        } catch (\Throwable) {
            return false;
        }
    }

    protected function operator(string $operator): string
    {
        $normalized = strtolower(trim(str_replace([' ', '-'], '_', $operator)));

        return match ($normalized) {
            'contains', 'text_contains' => 'text_contains',
            'does_not_contain', 'not_contains', 'text_does_not_contain' => 'text_does_not_contain',
            'equals' => 'equals',
            'not_equals' => 'not_equals',
            'exactly_matches', 'text_equals', 'text_exactly_matches' => 'text_exactly_matches',
            'does_not_exactly_match', 'text_does_not_exactly_match' => 'text_does_not_exactly_match',
            'is_in', 'text_is_in' => 'text_is_in',
            'is_not_in', 'text_is_not_in' => 'text_is_not_in',
            'starts_with', 'text_starts_with' => 'text_starts_with',
            'does_not_start_with', 'not_starts_with', 'text_does_not_start_with' => 'text_does_not_start_with',
            'ends_with', 'text_ends_with' => 'text_ends_with',
            'does_not_end_with', 'not_ends_with', 'text_does_not_end_with' => 'text_does_not_end_with',
            'greater_than', 'number_greater_than' => 'number_greater_than',
            'greater_than_or_equal', 'number_greater_than_or_equal' => 'number_greater_than_or_equal',
            'less_than', 'number_less_than' => 'number_less_than',
            'less_than_or_equal', 'number_less_than_or_equal' => 'number_less_than_or_equal',
            'number_equals' => 'number_equals',
            'after', 'date_after' => 'date_after',
            'before', 'date_before' => 'date_before',
            'date_equals' => 'date_equals',
            'true', 'is_true' => 'is_true',
            'false', 'is_false' => 'is_false',
            'exists' => 'exists',
            'does_not_exist', 'not_exists' => 'does_not_exist',
            'is_empty', 'empty' => 'is_empty',
            'is_not_empty', 'not_empty' => 'is_not_empty',
            'contains_any' => 'contains_any',
            'contains_all' => 'contains_all',
            default => $normalized,
        };
    }
}
