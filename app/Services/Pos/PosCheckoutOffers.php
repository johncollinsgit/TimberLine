<?php

declare(strict_types=1);

namespace App\Services\Pos;

/** Resolves server-owned, versioned in-person offers. Values are never supplied by a cashier. */
final class PosCheckoutOffers
{
    public function __construct(private PosStore $store, private PosCommerceRules $rules, private PosDiscountMath $math) {}

    /** Read-only register capability for an assigned cashier; draft edits are never exposed. */
    public function available(array $scope): array
    {
        if (config('pos_live.checkout_rules_enabled') !== true) {
            return ['version' => null, 'rules' => []];
        }
        $row = $this->store->db->table('tenant_commerce_rule_sets')->where('tenant_id', $scope['tenant_id'])->first();
        if (! $row) {
            return ['version' => null, 'rules' => []];
        }
        try {
            $saved = json_decode($row->rules, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new PosException('checkout_rules_corrupt', 503);
        }
        if (! is_array($saved)) {
            throw new PosException('checkout_rules_corrupt', 503);
        }
        $validated = $this->rules->validate($saved);
        $settings = $this->store->decrypt($scope['register']->configuration);
        if (! hash_equals($row->version, $validated['version']) || ! is_string($settings['checkout_rules_reviewed_version'] ?? null)
            || ! hash_equals($validated['version'], $settings['checkout_rules_reviewed_version'])) {
            throw new PosException('checkout_rules_review_required', 503);
        }

        return ['version' => $validated['version'], 'rules' => array_values(array_filter($validated['rules'], fn ($rule) => $rule['active'] && in_array('in_person', $rule['channels'], true)))];
    }

    public function apply(array $scope, array $input, array $catalog, array $gross): array
    {
        $discounts = $input['discounts'] ?? [];
        $addons = $input['addons'] ?? [];
        if (! $discounts && ! $addons) {
            return ['lines_minor' => $gross, 'discount_minor' => 0, 'offers' => [], 'version' => null];
        }
        // Draft rule edits must never turn into live discounts without a reviewed rollout.
        $validated = $this->available($scope);
        if (! is_string($validated['version'])) {
            throw new PosException('checkout_rules_unavailable', 503);
        }
        if (! is_string($input['checkout_rules_version'] ?? null) || ! hash_equals($validated['version'], $input['checkout_rules_version'])) {
            throw new PosException('checkout_rules_changed');
        }
        $ruleMap = array_column($validated['rules'], null, 'id');
        $selected = [];
        $catalogByLine = [];
        $linesByItem = [];
        foreach ($input['items'] ?? [] as $selection) {
            $key = $selection['line_id'] ?? $selection['item_id'];
            if (isset($selected[$key]) || ! isset($catalog[$selection['item_id']])) {
                throw new PosException('checkout_discount_invalid', 422);
            }
            $selected[$key] = $selection;
            $catalogByLine[$key] = $catalog[$selection['item_id']];
            $linesByItem[$selection['item_id']][] = $key;
        }
        $net = $gross;
        $entries = [];
        $taken = [];
        $addonPrices = [];
        $addonUnits = 0;
        $requiresOther = false;
        foreach ($addons as $selection) {
            $rule = $ruleMap[$selection['rule_id']] ?? null;
            $id = $selection['item_id'];
            $quantity = $selection['quantity'];
            if (! $rule || ! $rule['active'] || $rule['kind'] !== 'addon' || ! in_array('in_person', $rule['channels'], true)
                || ! isset($catalog[$id], $selected[$id], $net[$id]) || $catalog[$id]['kind'] === 'bundle'
                || ! in_array($id, $rule['eligible_item_ids'], true) || $rule['value'] > $catalog[$id]['unit_amount_minor']
                || ! is_int($quantity) || $quantity < 1 || $quantity > 99) {
                throw new PosException('checkout_addon_invalid', 422);
            }
            $taken[$id] = ($taken[$id] ?? 0) + $quantity;
            if ($taken[$id] > $selected[$id]['quantity']) {
                throw new PosException('checkout_addon_invalid', 422);
            }
            $amount = ($catalog[$id]['unit_amount_minor'] - $rule['value']) * $quantity;
            $net[$id] -= $amount;
            foreach (range(1, $quantity) as $_) {
                $addonPrices[$id][] = $rule['value'];
            }
            $addonUnits += $quantity;
            $requiresOther = $requiresOther || $rule['requires_other_item'];
            $entries[] = ['rule_id' => $rule['id'], 'name' => $rule['name'], 'kind' => 'addon', 'value' => $rule['value'],
                'requires_other_item' => $rule['requires_other_item'], 'item_id' => $id, 'quantity' => $quantity, 'amount_minor' => $amount];
        }
        $otherUnits = array_sum(array_column($selected, 'quantity')) - $addonUnits + (isset($input['custom_charge']) ? 1 : 0);
        if ($requiresOther && $otherUnits < 1) {
            throw new PosException('checkout_addon_requires_purchase', 422);
        }
        $unitMode = count(array_filter($discounts, fn ($selection) => array_key_exists('quantity', $selection))) > 0;
        $unitGroups = [];
        $unitLines = [];
        if ($unitMode) {
            foreach ($net as $id => $amount) {
                $quantity = $id === 'custom' ? 1 : $selected[$id]['quantity'];
                $original = $id === 'custom' ? $gross[$id] : $catalogByLine[$id]['unit_amount_minor'];
                $addPrices = $addonPrices[$id] ?? [];
                $prices = [...array_fill(0, $quantity - count($addPrices), $original), ...$addPrices];
                if (array_sum($prices) !== $amount) {
                    throw new PosException('checkout_discount_invalid', 422);
                }
                foreach ($prices as $index => $price) {
                    $key = $id.':'.$index;
                    $unitGroups[$id][] = $key;
                    $unitLines[$key] = $price;
                }
            }
        }
        $specifications = [];
        foreach ($discounts as $selection) {
            $rule = $ruleMap[$selection['rule_id']] ?? null;
            $scopeName = $selection['scope'];
            $item = $selection['item_id'] ?? null;
            if ($scopeName === 'item' && is_string($item) && $item !== 'custom' && ! isset($selected[$item])) {
                // Old clients may identify an unambiguous line by catalog item ID.
                // A repeated bundle requires an explicit line ID.
                $matching = $linesByItem[$item] ?? [];
                if (count($matching) !== 1) {
                    throw new PosException('checkout_discount_line_ambiguous', 422);
                }
                $item = $matching[0];
            }
            if (! $rule || ! $rule['active'] || ! in_array($rule['kind'], ['fixed', 'percent'], true)
                || ! in_array('in_person', $rule['channels'], true) || ! in_array($scopeName, $rule['scopes'], true)
                || ($scopeName === 'item' && (! is_string($item) || (! isset($selected[$item]) && ! ($item === 'custom' && isset($net['custom'])))))
                || ($scopeName === 'sale' && ($item !== null || array_key_exists('quantity', $selection)))) {
                throw new PosException('checkout_discount_invalid', 422);
            }
            $spec = ['scope' => $scopeName, 'item_id' => $item, 'kind' => $rule['kind'], 'value' => $rule['value']];
            if ($unitMode && $scopeName === 'item') {
                $quantity = $selection['quantity'] ?? count($unitGroups[$item]);
                if (! is_int($quantity) || $quantity < 1 || $quantity > count($unitGroups[$item])) {
                    throw new PosException('checkout_discount_invalid', 422);
                }
                $spec['item_ids'] = array_slice($unitGroups[$item], 0, $quantity);
            }
            $specifications[] = $spec;
        }
        if ($specifications) {
            $calculated = $this->math->apply($unitMode ? $unitLines : $net, $specifications);
            if ($unitMode) {
                $unitLines = $calculated['lines_minor'];
                foreach ($unitGroups as $id => $keys) {
                    $net[$id] = array_sum(array_map(fn ($key) => $unitLines[$key], $keys));
                }
            } else {
                $net = $calculated['lines_minor'];
            }
            foreach ($discounts as $index => $selection) {
                $rule = $ruleMap[$selection['rule_id']];
                $entries[] = ['rule_id' => $rule['id'], 'name' => $rule['name'], 'kind' => $rule['kind'], 'value' => $rule['value'],
                    'scope' => $selection['scope'], 'item_id' => $selection['item_id'] ?? null,
                    ...(isset($selection['quantity']) ? ['quantity' => $selection['quantity']] : []),
                    'amount_minor' => $calculated['applications'][$index]['amount_minor']];
            }
        }

        $units = [];
        foreach ($unitGroups as $id => $keys) {
            $units[$id] = array_map(fn ($key) => $unitLines[$key], $keys);
        }

        return ['lines_minor' => $net, 'unit_lines_minor' => $unitMode ? $units : null,
            'discount_minor' => array_sum($gross) - array_sum($net), 'offers' => $entries, 'version' => $validated['version']];
    }
}
