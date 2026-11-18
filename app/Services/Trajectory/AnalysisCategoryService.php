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
        $direct = $childMerchants = [];
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
                $childMerchants[$key][$leaf] ??= [];
                $this->addMerchant($childMerchants[$key][$leaf], $entry, $leaf);
            } else {
                $direct[$key] ??= [];
                $this->addMerchant($direct[$key], $entry, $leaf);
            }
        }
        foreach ($groups as $key => &$group) {
            $group['ids'] = array_values(array_unique($group['ids']));
            $group['children'] = array_values($group['children']);
            foreach ($group['children'] as &$child) {
                $child['ids'] = array_values(array_unique($child['ids']));
                $child['group_kind'] = 'subcategory';
                $child['category_key'] = $child['key'];
                $child['children'] = $this->merchantValues($childMerchants[$key][$child['key']] ?? []);
            }
            unset($child);
            // Direct parent purchases must remain visible after a custom child
            // is added; otherwise that child's subtotal hides the rest of the pie.
            $group['children'] = [...$group['children'], ...$this->merchantValues($direct[$key] ?? [])];
            usort($group['children'], fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);

        return $groups;
    }

    private function addMerchant(array &$groups, array $entry, string $category): void
    {
        // Ledger identity is already scoped to this viewer's current permissions.
        $merchant = $this->merchantLabel((string) ($entry['merchant'] ?? ''));
        $key = 'merchant:'.$category.':'.substr(hash('sha256', ClassificationService::merchant($merchant)), 0, 16);
        $groups[$key] ??= ['key' => $key, 'label' => $merchant, 'group_kind' => 'merchant', 'category_key' => $category,
            'amount_cents' => 0, 'ids' => [], 'entry_keys' => [], 'children' => []];
        $groups[$key]['amount_cents'] -= (int) $entry['amount_cents'];
        $groups[$key]['ids'][] = $entry['id'];
        $groups[$key]['entry_keys'][] = $entry['entry_key'];
    }

    private function merchantValues(array $groups): array
    {
        foreach ($groups as &$group) {
            $group['ids'] = array_values(array_unique($group['ids']));
        }
        unset($group);
        $groups = array_values($groups);
        usort($groups, fn ($a, $b) => $b['amount_cents'] <=> $a['amount_cents']);

        return $groups;
    }

    private function merchantLabel(string $merchant): string
    {
        $normalized = ClassificationService::merchant($merchant);
        $normalized = str_replace(['’', "'"], '', $normalized);
        foreach (['costco' => 'Costco', 'sams club' => 'Sam’s Club', 'samsclub' => 'Sam’s Club',
            'amazon' => 'Amazon', 'amzn' => 'Amazon', 'walmart' => 'Walmart', 'wal mart' => 'Walmart', 'wal-mart' => 'Walmart'] as $needle => $label) {
            if (preg_match('/\b'.preg_quote($needle, '/').'\b/u', $normalized)) {
                return $label;
            }
        }
        if ($normalized === '' || $normalized === 'shared allocation') {
            return $normalized === '' ? 'Merchant not recorded' : 'Shared cost';
        }

        return trim(preg_replace('/\s+/', ' ', $merchant));
    }
}
