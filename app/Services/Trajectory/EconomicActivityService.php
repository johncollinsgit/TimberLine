<?php

namespace App\Services\Trajectory;

use Illuminate\Support\Collection;

/** Economic costs are a projection of evidence, never replacement bank transactions. */
class EconomicActivityService
{
    public const TAX_FLOW = 'sales_tax_remittance';

    public function unresolved(Collection $entries): Collection
    {
        return $entries->filter(fn ($e) => ($e['flow'] ?? null) === self::TAX_FLOW
            && (! ($e['tax_purpose']['valid'] ?? false) || ($e['tax_purpose']['components'] ?? null) === null));
    }

    /** Original IDs and gross bank cents remain available on every derived component. */
    public function expenses(Collection $entries, bool $medical = true): Collection
    {
        return $entries->flatMap(function ($entry) use ($medical): array {
            if (($entry['flow'] ?? null) !== self::TAX_FLOW) {
                return in_array($entry['flow'] ?? null, $medical ? HistoryService::SPENDING : ['expense', 'refund'], true)
                    && (($entry['flow'] ?? null) !== 'owner_wages' || $entry['amount_cents'] < 0) ? [$entry] : [];
            }
            $purpose = $entry['tax_purpose'] ?? [];
            if (! ($purpose['valid'] ?? false) || ($purpose['components'] ?? null) === null) {
                return [];
            }
            $sign = $entry['amount_cents'] < 0 ? -1 : 1;
            $rows = [];
            foreach (['interest_cents' => 'interest', 'penalty_cents' => 'tax_penalties', 'other_fees_cents' => 'tax_fees'] as $field => $category) {
                $amount = $purpose['components'][$field];
                if ($amount === 0) {
                    continue;
                }
                $rows[] = [...$entry, 'flow' => $sign < 0 ? 'expense' : 'refund', 'category' => $category,
                    'amount_cents' => $sign * $amount, 'bank_amount_cents' => $entry['amount_cents'],
                    'economic_component' => $field, 'economic_source_flow' => self::TAX_FLOW, 'amount_basis' => 'tax_economic_component'];
            }

            return $rows;
        })->values();
    }

    public function operating(Collection $entries): Collection
    {
        return $this->expenses($entries, false)->where('category', '!=', 'interest');
    }

    public function total(Collection $entries, bool $operating = false): ?int
    {
        if ($this->unresolved($entries)->isNotEmpty()) {
            return null;
        }

        return -(int) ($operating ? $this->operating($entries) : $this->expenses($entries))->sum('amount_cents');
    }

    public function evidence(Collection $entries, ?string $category = null, ?array $categories = null): array
    {
        return $entries->map(function ($entry) use ($category, $categories): array {
            if (($entry['flow'] ?? null) !== self::TAX_FLOW) {
                return $entry;
            }
            if ($category === 'sales_tax_principal') {
                $known = ($entry['tax_purpose']['valid'] ?? false) && ($entry['tax_purpose']['components'] ?? null) !== null;

                return [...$entry, 'bank_amount_cents' => $entry['amount_cents'],
                    'amount_cents' => $known ? ($entry['amount_cents'] < 0 ? -1 : 1) * $entry['tax_purpose']['components']['tax_principal_cents'] : null,
                    'amount_basis' => 'sales_tax_principal', 'economic_category' => $category];
            }
            $rows = $this->expenses(collect([$entry]));
            if ($category !== null || $categories !== null) {
                $rows = $rows->whereIn('category', $categories ?? [$category]);
            }

            return [...$entry, 'bank_amount_cents' => $entry['amount_cents'],
                'amount_cents' => $this->unresolved(collect([$entry]))->isEmpty() ? (int) $rows->sum('amount_cents') : null,
                'economic_category' => $category, 'economic_categories' => $categories, 'amount_basis' => 'tax_economic_cost',
                'economic_components' => $rows->map(fn ($row) => ['category' => $row['category'], 'amount_cents' => $row['amount_cents']])->values()->all()];
        })->all();
    }

    public function coverage(Collection $entries): array
    {
        $unresolved = $this->unresolved($entries);

        return ['complete' => $unresolved->isEmpty(), 'unresolved_ids' => $unresolved->pluck('id')->unique()->values()->all(),
            'unresolved_gross_cents' => (int) $unresolved->sum(fn ($e) => abs($e['amount_cents'])),
            'known_cost_cents' => -(int) $this->expenses($entries)->sum('amount_cents'),
            'note' => 'Sales-tax principal settles a liability, not an operating expense. Known interest, penalties and fees are costs; unknown splits make economic totals unavailable. Future tax cash needs an explicit plan, not a zero allowance.'];
    }
}
