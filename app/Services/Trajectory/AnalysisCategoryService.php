<?php

namespace App\Services\Trajectory;

use App\Models\Trajectory\Record;
use App\Models\Trajectory\Space;

/** Presentation hierarchy; legacy source category keys remain unchanged. */
class AnalysisCategoryService
{
    public function definitions(Space $space): array
    {
        $labels = ['health' => 'Medical & health', 'housing' => 'Home & rental', 'transport' => 'Transportation',
            'uncategorized' => 'Needs a category', 'unidentified_household_spending' => 'Unidentified purchases',
            'owner_payroll' => 'Owner payroll', 'studio_payroll' => 'Studio payroll', 'event_payroll' => 'Event payroll',
            'gold_and_silver' => 'Metals', 'debt_cash' => 'Debt payments', 'asset_purchases' => 'Investments',
            'needs_review' => 'Needs a purpose', 'food' => 'Food', 'payroll' => 'Payroll',
            'dining' => 'Dining', 'delivery_fees' => 'Delivery fees', 'fees' => 'Bank & service fees'];
        $parents = ['groceries' => 'food', 'dining' => 'food', 'delivery_fees' => 'food',
            'owner_payroll' => 'payroll', 'studio_payroll' => 'payroll', 'event_payroll' => 'payroll'];
        $custom = $space->settings['income_categories'] ?? [];
        $rows = Record::where('space_id', $space->id)->where('kind', 'category_definition')->where('active', true)->get();
        $byId = $rows->keyBy('id');
        $keys = array_unique([...app(WorkspaceService::class)->categories($space), ...array_keys($parents), ...array_values($parents)]);
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = ['key' => $key, 'label' => $custom[$key] ?? $labels[$key] ?? ucfirst(str_replace('_', ' ', $key)),
                'parent_key' => $parents[$key] ?? null, 'record_id' => null];
        }
        foreach ($rows as $row) {
            $key = $row->data['key'] ?? null;
            if (! is_string($key) || $key === '') {
                continue;
            }
            $parent = $byId->get($row->data['parent_record_id'] ?? null);
            $out[$key] = ['key' => $key, 'label' => $row->name, 'parent_key' => $parent?->data['key'] ?? null,
                'record_id' => $row->id, 'direction' => $row->data['direction'] ?? 'expense'];
        }

        return array_values($out);
    }

    public function group(array $entries, array $definitions, bool $cash = false): array
    {
        $catalog = collect($definitions)->keyBy('key');
        $groups = [];
        foreach ($entries as $entry) {
            $leaf = $entry['analysis_category'] ?? $entry['category'] ?? 'uncategorized';
            $definition = $catalog->get($leaf);
            $parent = $cash ? null : ($definition['parent_key'] ?? null);
            $key = $parent ?: $leaf;
            $label = $catalog->get($key)['label'] ?? $entry['analysis_label'] ?? ucfirst(str_replace('_', ' ', $key));
            $amount = -(int) $entry['amount_cents'];
            $groups[$key] ??= ['key' => $key, 'label' => $label, 'amount_cents' => 0, 'ids' => [], 'entry_keys' => [], 'children' => []];
            $groups[$key]['amount_cents'] += $amount;
            $groups[$key]['ids'][] = $entry['id'];
            $groups[$key]['entry_keys'][] = $entry['entry_key'];
            if ($parent) {
                $groups[$key]['children'][$leaf] ??= ['key' => $leaf, 'label' => $definition['label'], 'amount_cents' => 0, 'ids' => [], 'entry_keys' => []];
                $groups[$key]['children'][$leaf]['amount_cents'] += $amount;
                $groups[$key]['children'][$leaf]['ids'][] = $entry['id'];
                $groups[$key]['children'][$leaf]['entry_keys'][] = $entry['entry_key'];
            }
        }
        foreach ($groups as &$group) {
            $group['ids'] = array_values(array_unique($group['ids']));
            $group['children'] = array_values($group['children']);
            foreach ($group['children'] as &$child) {
                $child['ids'] = array_values(array_unique($child['ids']));
            }
            unset($child);
            usort($group['children'], fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);

        return $groups;
    }
}
