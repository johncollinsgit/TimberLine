<?php

namespace App\Services\Team\Studio;

use Illuminate\Support\Facades\Validator;

/** Tenant-owned estimating categories. No rates or currencies cross tenant boundaries. */
class EventCostTypes
{
    public function definitions(array $types): array
    {
        return Validator::make(['types' => $types], [
            'types' => 'array|max:100', 'types.*' => 'array:id,name,category,unit,rate_minor,rate_bps,required',
            'types.*.id' => 'required|regex:/^[a-z][a-z0-9_]{0,31}$/D|distinct',
            'types.*.name' => 'required|string|max:150', 'types.*.category' => 'required|regex:/^[a-z][a-z0-9_]{0,31}$/D',
            'types.*.unit' => 'required|in:flat,person,day,person_day,mile,hour,unit,percent_net_sales',
            'types.*.rate_minor' => 'nullable|integer|min:0|max:100000000', 'types.*.rate_bps' => 'nullable|integer|min:0|max:10000',
            'types.*.required' => 'required|boolean',
        ])->validate()['types'];
    }

    public function line(array $expense, array $types, ?int $net, array &$issues): array
    {
        $type = collect($types)->firstWhere('id', $expense['cost_type_id']);
        abort_unless($type, 422, 'This cost type is not in the selected tenant policy version.');
        $status = $expense['cost_status'] ?? 'unknown';
        $rate = $expense['rate_override_minor'] ?? $type['rate_minor'] ?? null;
        $bps = $expense['rate_override_bps'] ?? $type['rate_bps'] ?? null;
        $overridden = isset($expense['rate_override_minor']) || isset($expense['rate_override_bps']);
        abort_if($status === 'estimated' && $overridden && mb_strlen(trim($expense['override_reason'] ?? '')) < 12, 422, 'Explain the event-specific rate override.');
        $quantity = $type['unit'] === 'flat' ? 1000 : ($expense['quantity_milli'] ?? null);
        $amount = match ($status) {
            'not_applicable' => 0,
            'reported_actual' => $expense['amount_minor'] ?? null,
            'estimated' => $type['unit'] === 'percent_net_sales'
                ? ($net !== null && $bps !== null ? intdiv($net * $bps + 5000, 10000) : null)
                : ($rate !== null && $quantity !== null ? intdiv($rate * $quantity + 500, 1000) : null),
            default => null,
        };
        $model = $status === 'estimated' && $type['unit'] === 'percent_net_sales' && $bps !== null
            ? ['kind' => 'percent', 'rate_bps' => $bps, 'minimum_minor' => 0]
            : ['kind' => 'constant'];
        if ($amount === null) {
            $issues[] = $type['name'].': confirm applicability, quantity and the company rate or reported actual amount.';
        }

        return ['category' => $type['category'], 'label' => $expense['label'], 'cost_type_id' => $type['id'], 'cost_status' => $status,
            'amount_minor' => $amount, 'model' => $model,
            'calculation' => ['unit' => $type['unit'], 'quantity_milli' => $quantity, 'rate_minor' => $rate, 'rate_bps' => $bps,
                'rate_overridden' => $overridden, 'override_reason' => $expense['override_reason'] ?? null, 'evidence' => $expense['evidence'] ?? null],
            'basis' => $status === 'reported_actual' ? 'Owner-reported cost; not payroll or payment evidence.' : 'Estimate from the frozen company cost template.'];
    }

    public function starter(): array
    {
        $result = [];
        foreach ([['hotel', 'Hotel / lodging', 'lodging', 'unit'], ['meals', 'Meals', 'meals', 'person_day'], ['gifts', 'Gifts / giveaways', 'gifts', 'unit'], ['parking', 'Parking', 'parking', 'flat'], ['shipping', 'Shipping / freight', 'shipping', 'flat'], ['cogs', 'Estimated cost of goods sold', 'cogs', 'percent_net_sales'], ['processing', 'Estimated processing fees', 'processing', 'percent_net_sales']] as [$id, $name, $category, $unit]) {
            $result[] = compact('id', 'name', 'category', 'unit') + ['rate_minor' => null, 'rate_bps' => null, 'required' => false];
        }

        return $result;
    }
}
