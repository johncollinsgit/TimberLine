<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Services\PaymentsCore\Contract;

/** Validates a tenant's configurable checkout rules before they can be saved or used. */
final class PosCommerceRules
{
    /** @param list<array<string, mixed>> $rules @param array<string, mixed> $rule */
    public function save(array $rules, array $rule, string $expectedVersion): array
    {
        $current = $this->validate($rules);
        if (! hash_equals($current['version'], $expectedVersion)) {
            throw new PosException('checkout_rules_changed');
        }
        $id = $rule['id'] ?? null;
        $found = false;
        foreach ($rules as $index => $existing) {
            if ($existing['id'] === $id) {
                $rules[$index] = $rule;
                $found = true;
                break;
            }
        }
        if (! $found) { $rules[] = $rule; }

        return $this->validate($rules);
    }

    /** @param list<array<string, mixed>> $rules */
    public function delete(array $rules, string $id, string $expectedVersion): array
    {
        $current = $this->validate($rules);
        if (! hash_equals($current['version'], $expectedVersion)) {
            throw new PosException('checkout_rules_changed');
        }
        $updated = array_values(array_filter($rules, fn ($rule) => $rule['id'] !== $id));
        if (count($updated) === count($rules)) {
            throw new PosException('checkout_rule_not_found', 404);
        }

        return $this->validate($updated);
    }

    /** @param list<array<string, mixed>> $rules */
    public function validate(array $rules): array
    {
        if (! array_is_list($rules) || count($rules) > 200) {
            throw new PosException('checkout_rules_invalid', 422);
        }
        $ids = [];
        foreach ($rules as $rule) {
            if (! is_array($rule) || array_diff(array_keys($rule), ['id', 'name', 'kind', 'value', 'scopes', 'channels', 'active', 'eligible_item_ids', 'requires_other_item'])
                || ! is_string($rule['id'] ?? null) || ! preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $rule['id'])
                || isset($ids[$rule['id']]) || ! is_string($rule['name'] ?? null)
                || trim($rule['name']) !== $rule['name'] || mb_strlen($rule['name']) < 1 || mb_strlen($rule['name']) > 80
                || preg_match('/[\p{C}]/u', $rule['name']) || ! is_bool($rule['active'] ?? null)
                || ! in_array($rule['kind'] ?? null, ['fixed', 'percent', 'addon'], true)
                || ! is_int($rule['value'] ?? null) || $rule['value'] < 1
                || $rule['value'] > (($rule['kind'] ?? null) === 'percent' ? 10000 : 100000000)
                || ! $this->choices($rule['scopes'] ?? null, ['item', 'sale'])
                || ! $this->choices($rule['channels'] ?? null, ['in_person', 'online'])) {
                throw new PosException('checkout_rules_invalid', 422);
            }
            $ids[$rule['id']] = true;
            if ($rule['kind'] === 'addon') {
                $items = $rule['eligible_item_ids'] ?? null;
                if ($rule['scopes'] !== ['item'] || ! is_bool($rule['requires_other_item'] ?? null)
                    || ! is_array($items) || ! array_is_list($items) || count($items) < 1 || count($items) > 500
                    || count(array_unique($items, SORT_REGULAR)) !== count($items)
                    || count(array_filter($items, fn ($id) => is_string($id) && preg_match('/^[a-f0-9]{32}$/D', $id))) !== count($items)) {
                    throw new PosException('checkout_rules_invalid', 422);
                }
            } elseif (array_key_exists('eligible_item_ids', $rule) || array_key_exists('requires_other_item', $rule)) {
                throw new PosException('checkout_rules_invalid', 422);
            }
        }

        // MySQL's JSON representation may reorder object keys. Normalize objects,
        // but never reorder lists: rule and selected-option order remain material.
        $normalized = $this->normalize($rules);

        return ['rules' => $normalized, 'version' => Contract::hash($normalized)];
    }

    private function normalize(array $value): array
    {
        if (! array_is_list($value)) { ksort($value, SORT_STRING); }
        foreach ($value as $key => $child) {
            if (is_array($child)) { $value[$key] = $this->normalize($child); }
        }

        return $value;
    }

    private function choices(mixed $values, array $allowed): bool
    {
        return is_array($values) && array_is_list($values) && count($values) > 0
            && count($values) === count(array_unique($values, SORT_REGULAR))
            && count(array_filter($values, fn ($value) => is_string($value) && in_array($value, $allowed, true))) === count($values);
    }
}
