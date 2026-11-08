<?php

namespace App\Services\Team\Studio;

use Illuminate\Support\Facades\Validator;

/** A bounded, deterministic condition tree. Missing facts stay unknown. */
class QualificationRules
{
    public function validate(array $condition, int $depth = 0, ?int &$remaining = null): void
    {
        $remaining ??= 1000;
        abort_if(--$remaining < 0, 422, 'Qualification policy exceeds 1000 conditions.');
        abort_if($depth > 4, 422, 'Qualification rules may be nested at most four levels.');
        if (isset($condition['all']) || isset($condition['any'])) {
            $key = isset($condition['all']) ? 'all' : 'any';
            abort_unless(count($condition) === 1 && is_array($condition[$key]) && count($condition[$key]) <= 30 && count($condition[$key]) > 0, 422, 'Use a bounded all/any condition.');
            foreach ($condition[$key] as $child) {
                abort_unless(is_array($child), 422);
                $this->validate($child, $depth + 1, $remaining);
            }

            return;
        }
        Validator::make($condition, ['field' => ['required', 'regex:/^(event|staff|day|trip)\.[a-z][a-z0-9_.]{0,100}$/D'], 'op' => 'required|in:eq,ne,gt,gte,lt,lte,in,contains,exists', 'value' => 'required_unless:op,exists'])->validate();
        abort_unless(count(array_diff(array_keys($condition), ['field', 'op', 'value'])) === 0, 422);
        if ($condition['op'] === 'in') {
            abort_unless(is_array($condition['value']) && count($condition['value']) <= 100, 422);
        }
    }

    public function matches(array $condition, array $facts): ?bool
    {
        foreach (['all', 'any'] as $key) {
            if (isset($condition[$key])) {
                $results = array_map(fn ($child) => $this->matches($child, $facts), $condition[$key]);
                if (in_array($key === 'any', $results, true)) {
                    return $key === 'any';
                }

                return in_array(null, $results, true) ? null : $key === 'all';
            }
        }
        $value = data_get($facts, $condition['field']);
        if ($condition['op'] === 'exists') {
            return $value !== null;
        }
        if ($value === null) {
            return null;
        }
        $expected = $condition['value'];

        return match ($condition['op']) {
            'eq' => $value === $expected,
            'ne' => $value !== $expected,
            'gt' => is_numeric($value) && is_numeric($expected) ? $value > $expected : false,
            'gte' => is_numeric($value) && is_numeric($expected) ? $value >= $expected : false,
            'lt' => is_numeric($value) && is_numeric($expected) ? $value < $expected : false,
            'lte' => is_numeric($value) && is_numeric($expected) ? $value <= $expected : false,
            'in' => in_array($value, $expected, true),
            'contains' => is_array($value) && in_array($expected, $value, true),
            default => false,
        };
    }
}
