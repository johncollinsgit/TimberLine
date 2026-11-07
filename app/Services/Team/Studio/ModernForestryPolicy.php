<?php

namespace App\Services\Team\Studio;

/** A reviewable draft, never automatically published or applied to historical pay. */
class ModernForestryPolicy
{
    public function draft(): array
    {
        $rule = fn ($id, $name, $category, $when, $effect, $priority = 10) => compact('id', 'name', 'category', 'priority', 'when', 'effect');
        $is = fn ($field, $value) => ['field' => $field, 'op' => 'eq', 'value' => $value];

        return [
            'name' => 'Modern Forestry event compensation — owner review', 'currency' => 'USD', 'effective_on' => '2026-01-01',
            'per_diem_selection' => 'highest', 'required_categories' => ['staffing', 'per_diem', 'mileage', 'lodging', 'event_fees', 'cogs', 'processing'],
            'requires_confirmation' => ['Confirm whether the three-day setup threshold means selling, paid or schedule days; revise the qualifying rule if needed.', 'Confirm setup-hour precedence: Payment Criteria C5/C11 specify one setup hour, C6 specifies three for events of three or more days, and the inspected workbook payroll formulas use three for Macon and two in their ordinary-event branch. Macon 2025/2026 selects the larger hourly or commission base; it does not omit a minimum. Resolve the setup/minimum rule before publishing.', 'Confirm the proposed effective date; January 1, 2026 is a draft setting, not a date stated by the workbook.'],
            'source' => 'Event Team Member Compensation / Payment Criteria. Reviewed source criteria; not a new payroll authorization.',
            'source_url' => 'https://docs.google.com/spreadsheets/d/1V9FAzTg6FA7tzEnGyDQDQ-OYgHqbxiBot9PWlj7txDw/edit',
            'notes' => 'Review summary; retain the connected workbook criteria as the original evidence. Hourly: $12/hour plus one setup/breakdown hour, or three setup/breakdown hours for events of three or more days. Commission starts at 13% of net revenue excluding tax. Macon 2025 row 145 and 2026 row 85 select the larger hourly/commission base, with three setup hours in the hourly comparison; their unused ordinary-event formula branch adds two hours. Payment Criteria C5/C11 specify one setup hour and C6 specifies three for longer events: resolve setup/minimum precedence rather than assuming the workbook omits a floor. Historical recorded rates can differ from the starting rate. Per diem: $55 for an event day over eight hours, $35 Thursday setup, $35 for three or more driving hours; this draft selects one qualifying allowance per person/date. Mileage: studio/event and event/hotel at $0.67/mile. Parking reimbursement requires a receipt. The company supplies decorations. Confirm the threshold day definition, allowance selection and proposed effective date before publishing.',
            'cost_types' => app(EventCostTypes::class)->starter(), 'rules' => [
                $rule('hourly', 'Hourly $12 plus setup (3 hours for 3+ selling days)', 'base_pay', $is('staff.pay_type', 'hourly'), ['kind' => 'hourly', 'rate_minor' => 1200, 'setup_minutes' => 60, 'long_event_days' => 3, 'long_setup_minutes' => 180]),
                $rule('commission', '13% of net revenue excluding tax', 'base_pay', $is('staff.pay_type', 'commission'), ['kind' => 'commission', 'rate_bps' => 1300]),
                $rule('commission_floor_review', 'Written commission minimum — confirm setup-hour precedence', 'minimum_pay', $is('staff.pay_type', 'commission'), ['kind' => 'hourly', 'rate_minor' => 1200, 'setup_minutes' => 60]),
                $rule('long_day', 'Event exceeds 8 hours: $55', 'per_diem', ['field' => 'day.event_minutes', 'op' => 'gt', 'value' => 480], ['kind' => 'per_day', 'rate_minor' => 5500]),
                $rule('thursday_setup', 'Thursday setup: $35', 'per_diem', ['all' => [$is('day.setup', true), $is('day.weekday', 4)]], ['kind' => 'per_day', 'rate_minor' => 3500]),
                $rule('long_drive', 'Drive 3 or more hours: $35', 'per_diem', ['field' => 'day.drive_minutes', 'op' => 'gte', 'value' => 180], ['kind' => 'per_day', 'rate_minor' => 3500]),
                $rule('eligible_mileage', 'Studio/event and event/hotel: $0.67 per mile', 'mileage', ['field' => 'trip.kind', 'op' => 'in', 'value' => ['studio_event', 'hotel_event']], ['kind' => 'mileage', 'rate_minor' => 67]),
            ],
        ];
    }
}
