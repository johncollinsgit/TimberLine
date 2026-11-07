<?php

namespace App\Services\Team\Studio;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** Integer minor-unit estimates only. This service never issues payroll or payments. */
class EventCostCalculator
{
    public function __construct(private QualificationRules $qualifications) {}

    public function policy(array $input, bool $publishing = false): array
    {
        $policy = Validator::make($input, [
            'name' => 'required|string|max:100', 'currency' => 'required|regex:/^[A-Z]{3}$/D',
            'effective_on' => 'required|date_format:Y-m-d', 'expires_on' => 'nullable|date_format:Y-m-d|after_or_equal:effective_on',
            'per_diem_selection' => 'sometimes|in:highest,priority', 'required_categories' => 'present|array|max:20', 'required_categories.*' => 'string|max:32|distinct',
            'requires_confirmation' => 'present|array|max:20', 'requires_confirmation.*' => 'string|max:1000',
            'source' => 'nullable|string|max:1000', 'source_url' => 'nullable|url:https|max:2000', 'notes' => 'nullable|string|max:10000', 'rules' => 'present|array|max:100',
            'rules.*.id' => 'required|regex:/^[a-z0-9_-]{1,60}$/D|distinct', 'rules.*.name' => 'required|string|max:150',
            'rules.*.category' => 'required|in:base_pay,minimum_pay,per_diem,mileage', 'rules.*.priority' => 'required|integer|min:0|max:1000',
            'rules.*.when' => 'required|array', 'rules.*.effect' => 'required|array',
            'rules.*.effect.kind' => 'required|in:hourly,commission,per_day,mileage',
            'rules.*.effect.rate_minor' => 'sometimes|integer|min:0|max:10000000',
            'rules.*.effect.rate_bps' => 'sometimes|integer|min:0|max:10000',
            'rules.*.effect.setup_minutes' => 'sometimes|integer|min:0|max:1440',
            'rules.*.effect.long_event_days' => 'sometimes|integer|min:1|max:365',
            'rules.*.effect.long_setup_minutes' => 'sometimes|integer|min:0|max:1440',
        ])->validate();
        $policy['cost_types'] = app(EventCostTypes::class)->definitions($input['cost_types'] ?? []);
        $remaining = 1000;
        foreach ($policy['rules'] as $rule) {
            $this->qualifications->validate($rule['when'], 0, $remaining);
            $kind = $rule['effect']['kind'];
            abort_unless(in_array($kind, match ($rule['category']) {
                'base_pay' => ['hourly', 'commission'], 'minimum_pay' => ['hourly'], 'per_diem' => ['per_day'], 'mileage' => ['mileage'],
            }, true), 422, 'Rule effect does not match its category.');
            abort_unless(isset($rule['effect'][$kind === 'commission' ? 'rate_bps' : 'rate_minor']), 422, 'A rule rate is required.');
        }
        abort_if($publishing && count($policy['requires_confirmation']) > 0, 422, 'Resolve the policy review items before publication.');

        return $policy;
    }

    public function inputs(array $input): array
    {
        $data = Validator::make($input, [
            'starts_on' => 'required|date_format:Y-m-d', 'ends_on' => 'required|date_format:Y-m-d|after_or_equal:starts_on',
            'selling_days' => 'nullable|integer|min:1|max:366', 'expected_net_sales_minor' => 'nullable|integer|min:0|max:100000000000',
            'facts' => 'sometimes|array|max:30', 'crew' => 'present|array|max:100',
            'crew.*.id' => 'required|string|max:80|distinct', 'crew.*.name' => 'required|string|max:150',
            'crew.*.pay_type' => 'required|in:hourly,commission', 'crew.*.work_minutes' => 'nullable|integer|min:0|max:500000',
            'crew.*.attributes' => 'sometimes|array|max:30', 'crew.*.days' => 'present|array|max:366',
            'crew.*.days.*.date' => 'required|date_format:Y-m-d', 'crew.*.days.*.event_minutes' => 'nullable|integer|min:0|max:1440',
            'crew.*.days.*.setup' => 'sometimes|boolean', 'crew.*.days.*.travel' => 'sometimes|boolean',
            'crew.*.days.*.drive_minutes' => 'nullable|integer|min:0|max:1440',
            'fees' => 'present|array|max:100', 'fees.*.label' => 'required|string|max:150',
            'fees.*.total_minor' => 'nullable|integer|min:0|max:100000000000', 'fees.*.deposit_minor' => 'nullable|integer|min:0|max:100000000000',
            'fees.*.deposit_credited' => 'required|boolean',
            'hotels' => 'present|array|max:100', 'hotels.*.label' => 'required|string|max:150',
            'hotels.*.check_in' => 'required|date_format:Y-m-d', 'hotels.*.check_out' => 'required|date_format:Y-m-d',
            'hotels.*.rooms' => 'required|integer|min:1|max:100', 'hotels.*.nightly_total_minor' => 'nullable|integer|min:0|max:100000000',
            'hotels.*.booking_fee_minor' => 'sometimes|integer|min:0|max:100000000',
            'trips' => 'present|array|max:100', 'trips.*.id' => 'required|string|max:80|distinct',
            'trips.*.kind' => 'required|in:studio_event,hotel_event,other', 'trips.*.distance_millimiles' => 'nullable|integer|min:0|max:100000000',
            'trips.*.distance_basis' => 'sometimes|in:planned,reported_actual', 'trips.*.round_trip' => 'required|boolean', 'trips.*.vehicles' => 'required|integer|min:1|max:100',
            'expenses' => 'present|array|max:100', 'expenses.*.category' => 'required|regex:/^[a-z_][a-z0-9_]{0,31}$/D',
            'expenses.*.cost_type_id' => 'nullable|regex:/^[a-z][a-z0-9_]{0,31}$/D|distinct',
            'expenses.*.cost_status' => 'sometimes|in:unknown,not_applicable,estimated,reported_actual',
            'expenses.*.quantity_milli' => 'nullable|integer|min:0|max:1000000000',
            'expenses.*.rate_override_minor' => 'nullable|integer|min:0|max:100000000', 'expenses.*.rate_override_bps' => 'nullable|integer|min:0|max:10000',
            'expenses.*.override_reason' => 'nullable|string|max:3000', 'expenses.*.evidence' => 'nullable|string|max:3000',
            'expenses.*.label' => 'required|string|max:150', 'expenses.*.amount_minor' => 'nullable|integer|min:0|max:100000000000',
        ])->validate();
        abort_unless(CarbonImmutable::parse($data['starts_on'])->diffInDays(CarbonImmutable::parse($data['ends_on'])) <= 366, 422, 'An occurrence covers at most one year.');
        abort_if(array_sum(array_map(fn ($person) => count($person['days']), $data['crew'])) > 2000, 422, 'Split budgets exceeding 2000 crew-date entries.');
        foreach ($data['crew'] as $person) {
            $dates = array_column($person['days'], 'date');
            abort_unless(count($dates) === count(array_unique($dates)), 422, 'Use one day entry per person per date.');
        }
        foreach ($data['hotels'] as $hotel) {
            $nights = CarbonImmutable::parse($hotel['check_in'])->diffInDays(CarbonImmutable::parse($hotel['check_out']), false);
            abort_unless($nights > 0 && $nights <= 366, 422, 'Confirm between one and 366 hotel nights.');
        }

        return $data;
    }

    public function preview(array $input, array $policy): array
    {
        $input = $this->inputs($input);
        $policy = $this->policy($policy);
        $issues = $policy['requires_confirmation'];
        $policyCoversEvent = $input['starts_on'] >= $policy['effective_on'] && (! isset($policy['expires_on']) || $input['ends_on'] <= $policy['expires_on']);
        if (! $policyCoversEvent) {
            $issues[] = 'Policy does not cover every event date.';
        }
        $lines = [];
        $event = array_replace($input['facts'] ?? [], ['selling_days' => $input['selling_days'] ?? null, 'expected_net_sales_minor' => $input['expected_net_sales_minor'] ?? null]);
        foreach ($input['crew'] as $person) {
            $facts = ['event' => $event, 'staff' => array_replace($person['attributes'] ?? [], ['pay_type' => $person['pay_type']])];
            $base = $this->select($policy, 'base_pay', $facts, $issues, $person['name']);
            $floorUnknown = false;
            $floor = $this->select($policy, 'minimum_pay', $facts, $issues, $person['name'], false, $floorUnknown);
            $amount = $base && $policyCoversEvent ? $this->pay($base, $person, $input) : null;
            $minimum = $floor && $policyCoversEvent ? $this->pay($floor, $person, $input) : null;
            if ($floorUnknown || ($policyCoversEvent && $floor && $minimum === null)) {
                $issues[] = $person['name'].': minimum pay needs confirmed hours and setup qualification.';
                $amount = null;
            }
            if ($amount !== null && $minimum !== null) {
                $amount = max($amount, $minimum);
            }
            if ($amount === null) {
                $issues[] = $person['name'].': pay remains provisional; confirm sales, hours and applicable rules.';
            }
            $model = $base && $base['effect']['kind'] === 'commission' ? ['kind' => 'percent', 'rate_bps' => $base['effect']['rate_bps'], 'minimum_minor' => $minimum ?? 0] : ['kind' => 'constant'];
            $lines[] = ['model' => $model, 'category' => 'staffing', 'label' => $person['name'], 'amount_minor' => $amount, 'rules' => array_values(array_filter([$base['id'] ?? null, $floor['id'] ?? null])), 'inputs' => ['work_minutes' => $person['work_minutes'] ?? null, 'expected_net_sales_minor' => $input['expected_net_sales_minor'] ?? null]];
            foreach ($person['days'] as $day) {
                $covered = $day['date'] >= $policy['effective_on'] && (! isset($policy['expires_on']) || $day['date'] <= $policy['expires_on']);
                if (! $covered) {
                    $issues[] = $person['name'].': policy does not cover '.$day['date'];
                }
                $unknown = false;
                $matched = $this->matched($policy, 'per_diem', [...$facts, 'day' => $day + ['weekday' => CarbonImmutable::parse($day['date'])->isoWeekday()]], $issues, $person['name'].' '.$day['date'], $unknown);
                // One qualifying allowance per person per date: take the largest, never add them.
                $selection = $policy['per_diem_selection'] ?? 'highest';
                $allowance = $matched ? max(array_column(array_column($matched, 'effect'), 'rate_minor')) : 0;
                if ($selection === 'priority' && $matched) {
                    usort($matched, fn ($a, $b) => $b['priority'] <=> $a['priority']);
                    $allowance = $matched[0]['effect']['rate_minor'];
                    if (isset($matched[1]) && $matched[0]['priority'] === $matched[1]['priority']) {
                        $issues[] = $person['name'].' '.$day['date'].': conflicting per diem priorities.';
                        $allowance = null;
                    }
                }
                if ($unknown || ! $covered) {
                    $allowance = null;
                }
                $lines[] = ['category' => 'per_diem', 'label' => $person['name'].' '.$day['date'], 'amount_minor' => $allowance, 'rules' => array_column($matched, 'id'), 'basis' => 'One qualifying allowance per person per date; selection: '.$selection];
            }
        }
        foreach ($input['fees'] as $fee) {
            $deposit = $fee['deposit_minor'] ?? null;
            $total = $fee['total_minor'] ?? null;
            if ($total !== null && $deposit !== null && $deposit > $total) {
                $issues[] = $fee['label'].': deposit exceeds total fee.';
            }
            $lines[] = ['category' => 'event_fees', 'label' => $fee['label'], 'amount_minor' => $total, 'deposit_minor' => $deposit, 'deposit_credited' => $fee['deposit_credited'], 'remaining_minor' => $total !== null && $deposit !== null && $fee['deposit_credited'] && $deposit <= $total ? $total - $deposit : null];
        }
        foreach ($input['hotels'] as $hotel) {
            $nights = (int) CarbonImmutable::parse($hotel['check_in'])->diffInDays(CarbonImmutable::parse($hotel['check_out']));
            $lines[] = ['category' => 'lodging', 'label' => $hotel['label'], 'nights' => $nights, 'rooms' => $hotel['rooms'], 'amount_minor' => isset($hotel['nightly_total_minor']) ? $hotel['nightly_total_minor'] * $nights * $hotel['rooms'] + ($hotel['booking_fee_minor'] ?? 0) : null];
        }
        foreach ($input['trips'] as $trip) {
            $rule = $this->select($policy, 'mileage', ['event' => $event, 'trip' => $trip], $issues, $trip['id']);
            $lines[] = ['category' => 'mileage', 'label' => $trip['id'], 'inputs' => $trip, 'rules' => $rule ? [$rule['id']] : [], 'amount_minor' => $policyCoversEvent && $rule && isset($trip['distance_millimiles']) ? $this->rounded($trip['distance_millimiles'] * $rule['effect']['rate_minor'] * ($trip['round_trip'] ? 2 : 1) * $trip['vehicles'], 1000) : null];
        }
        foreach ($input['expenses'] as $expense) {
            $lines[] = isset($expense['cost_type_id']) ? app(EventCostTypes::class)->line($expense, $policy['cost_types'], $input['expected_net_sales_minor'] ?? null, $issues) : ['category' => $expense['category'], 'label' => $expense['label'], 'amount_minor' => $expense['amount_minor'] ?? null, 'model' => ['kind' => 'constant']];
        }
        foreach (['lodging', 'mileage', 'per_diem', 'staffing', 'event_fees'] as $category) {
            $categoryLines = array_filter($lines, fn ($line) => $line['category'] === $category && ($line['cost_status'] ?? '') !== 'not_applicable');
            if (collect($categoryLines)->contains(fn ($line) => isset($line['cost_type_id'])) && collect($categoryLines)->contains(fn ($line) => ! isset($line['cost_type_id']))) {
                $issues[] = 'Potential duplicate '.$category.' cost: choose the automatic calculation or the cost-template entry.';
            }
        }
        $usedTypes = array_column($input['expenses'], 'cost_type_id');
        foreach ($policy['cost_types'] as $type) {
            if ($type['required'] && ! in_array($type['id'], $usedTypes, true)) {
                $issues[] = 'Missing cost type: '.$type['name'].'. Mark it not applicable explicitly when appropriate.';
            }
        }
        foreach ($lines as $line) {
            if ($line['amount_minor'] === null) {
                $issues[] = $line['label'].': missing cost input.';
            }
        }
        foreach ($policy['required_categories'] as $category) {
            if (! in_array($category, array_column($lines, 'category'), true)) {
                $issues[] = 'Missing category: '.$category;
            }
        }
        $issues = array_values(array_unique($issues));
        $known = array_sum(array_column($lines, 'amount_minor'));

        return ['currency' => $policy['currency'], 'lines' => $lines, 'review_items' => $issues, 'known_cost_minor' => $known, 'total_cost_minor' => $issues ? null : $known, 'expected_profit_minor' => ! $issues && isset($input['expected_net_sales_minor']) ? $input['expected_net_sales_minor'] - $known : null, 'complete' => ! $issues, 'input_hash' => hash('sha256', json_encode($input)), 'policy_hash' => hash('sha256', json_encode($policy)), 'basis' => 'Planned budget only; not payroll, a quote, a payment or an actual expense.'];
    }

    private function matched(array $policy, string $category, array $facts, array &$issues, string $label, ?bool &$unknown = null): array
    {
        $unknown = false;
        $matches = [];
        foreach ($policy['rules'] as $rule) {
            if ($rule['category'] !== $category) {
                continue;
            }
            $match = $this->qualifications->matches($rule['when'], $facts);
            if ($match === null) {
                $unknown = true;
                $issues[] = $label.': missing qualification facts for '.$rule['name'];
            } elseif ($match) {
                $matches[] = $rule;
            }
        }

        return $matches;
    }

    private function select(array $policy, string $category, array $facts, array &$issues, string $label, bool $required = true, ?bool &$unknown = null): ?array
    {
        $matches = $this->matched($policy, $category, $facts, $issues, $label, $unknown);
        if ($unknown) {
            return null;
        }
        usort($matches, fn ($a, $b) => $b['priority'] <=> $a['priority']);
        if (! $matches) {
            if ($required) {
                $issues[] = $label.': no qualifying '.$category.' rule.';
            }

            return null;
        }
        if (isset($matches[1]) && $matches[0]['priority'] === $matches[1]['priority']) {
            $issues[] = $label.': conflicting '.$category.' rules have the same priority.';

            return null;
        }

        return $matches[0];
    }

    private function pay(array $rule, array $person, array $input): ?int
    {
        $effect = $rule['effect'];
        if ($effect['kind'] === 'commission') {
            return isset($input['expected_net_sales_minor']) ? $this->rounded($input['expected_net_sales_minor'] * $effect['rate_bps'], 10000) : null;
        }
        if (! isset($person['work_minutes'])) {
            return null;
        }
        $setup = ($input['selling_days'] ?? null) === null && isset($effect['long_event_days']) ? null : (($input['selling_days'] ?? 0) >= ($effect['long_event_days'] ?? PHP_INT_MAX) ? ($effect['long_setup_minutes'] ?? 0) : ($effect['setup_minutes'] ?? 0));

        return $setup === null ? null : $this->rounded(($person['work_minutes'] + $setup) * $effect['rate_minor'], 60);
    }

    private function rounded(int $numerator, int $denominator): int
    {
        return intdiv($numerator + intdiv($denominator, 2), $denominator);
    }
}
