<?php

use App\Services\Team\Studio\EventCostCalculator;
use App\Services\Team\Studio\EventProfitability;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

function flexibleCostPolicy(array $types): array
{
    return ['name' => 'Fictional company costs', 'currency' => 'USD', 'effective_on' => '2026-01-01', 'required_categories' => [], 'requires_confirmation' => [], 'rules' => [], 'cost_types' => $types];
}
function flexibleCostType(string $id, string $unit, ?int $rate = null, ?int $bps = null, bool $required = false): array
{
    return ['id' => $id, 'name' => ucfirst($id), 'category' => $id, 'unit' => $unit, 'rate_minor' => $rate, 'rate_bps' => $bps, 'required' => $required];
}
function flexibleCostItem(string $id, string $status, array $extra = []): array
{
    return ['cost_type_id' => $id, 'category' => $id, 'label' => ucfirst($id), 'cost_status' => $status, 'amount_minor' => null] + $extra;
}

test('custom company types support meals gifts and explicitly absent lodging without guessing missing costs', function () {
    $policy = flexibleCostPolicy([flexibleCostType('meals', 'person_day', 3500), flexibleCostType('gifts', 'unit', 500), flexibleCostType('hotel', 'unit', 12000, null, true)]);
    $inputs = studioInputs(['expenses' => [flexibleCostItem('meals', 'estimated', ['quantity_milli' => 6000]), flexibleCostItem('gifts', 'estimated', ['quantity_milli' => 4000]), flexibleCostItem('hotel', 'not_applicable')]]);
    $result = app(EventCostCalculator::class)->preview($inputs, $policy);
    expect($result['total_cost_minor'])->toBe(23000)->and($result['lines'][2]['amount_minor'])->toBe(0);
    $inputs['expenses'][2]['cost_status'] = 'unknown';
    expect(app(EventCostCalculator::class)->preview($inputs, $policy)['total_cost_minor'])->toBeNull();
    array_pop($inputs['expenses']);
    expect(implode(' ', app(EventCostCalculator::class)->preview($inputs, $policy)['review_items']))->toContain('Missing cost type');
});

test('company-specific mileage and event overrides require their own versioned rates and reasons', function () {
    $inputs = studioInputs(['trips' => [['id' => 'eligible', 'kind' => 'studio_event', 'distance_millimiles' => 100000, 'distance_basis' => 'reported_actual', 'round_trip' => true, 'vehicles' => 1]]]);
    $a = studioPolicy();
    $b = $a;
    foreach ($b['rules'] as &$rule) {
        if ($rule['category'] === 'mileage') {
            $rule['effect']['rate_minor'] = 80;
        }
    }
    unset($rule);
    expect(app(EventCostCalculator::class)->preview($inputs, $a)['total_cost_minor'])->toBe(13400)
        ->and(app(EventCostCalculator::class)->preview($inputs, $b)['total_cost_minor'])->toBe(16000);
    $policy = flexibleCostPolicy([flexibleCostType('gifts', 'unit', 500)]);
    $input = studioInputs(['expenses' => [flexibleCostItem('gifts', 'estimated', ['quantity_milli' => 2000, 'rate_override_minor' => 700])]]);
    expect(fn () => app(EventCostCalculator::class)->preview($input, $policy))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $input['expenses'][0]['override_reason'] = 'Reviewed supplier price for this occurrence.';
    expect(app(EventCostCalculator::class)->preview($input, $policy)['total_cost_minor'])->toBe(1400);
});

test('estimated COGS and break-even use net sales and the saved cost model including penny rounding', function () {
    $policy = flexibleCostPolicy([flexibleCostType('cogs', 'percent_net_sales', null, 2000)]);
    $inputs = studioInputs(['expenses' => [['category' => 'event_fees', 'label' => 'Booth', 'amount_minor' => 5000], flexibleCostItem('cogs', 'estimated')]]);
    $report = app(EventProfitability::class)->calculate($inputs, $policy, 3000);
    expect($report['estimated_cost_minor'])->toBe(5600)->and($report['estimated_profit_minor'])->toBe(-2600)
        ->and($report['break_even_sales_minor'])->toBe(6251)->and($report['sales_remaining_to_break_even_minor'])->toBe(3251)
        ->and($report['break_even_progress_percent'])->toBe(47.99);
    expect(app(EventProfitability::class)->calculate($inputs, $policy, null)['estimated_profit_minor'])->toBeNull();
    foreach (range($report['break_even_sales_minor'], $report['break_even_sales_minor'] + 100) as $net) {
        expect(app(EventProfitability::class)->calculate($inputs, $policy, $net)['estimated_profit_minor'])->toBeGreaterThanOrEqual(0);
    }
});

test('unknown rates and unprofitable variable costs do not create fabricated break-even targets', function () {
    $inputs = studioInputs(['expenses' => [flexibleCostItem('cogs', 'estimated')]]);
    $unknown = app(EventProfitability::class)->calculate($inputs, flexibleCostPolicy([flexibleCostType('cogs', 'percent_net_sales')]), 10000);
    expect($unknown['break_even_sales_minor'])->toBeNull()->and($unknown['estimated_profit_minor'])->toBeNull();
    $excessive = app(EventProfitability::class)->calculate($inputs, flexibleCostPolicy([flexibleCostType('cogs', 'percent_net_sales', null, 10000)]), 10000);
    expect($excessive['break_even_sales_minor'])->toBeNull()->and($excessive['estimated_profit_minor'])->toBe(0);
});

test('reported amounts remain reported amounts when company template rates later change', function () {
    $inputs = studioInputs(['expenses' => [array_replace(flexibleCostItem('hotel', 'reported_actual'), ['amount_minor' => 30000, 'evidence' => 'Fictional lodging receipt'])]]);
    $a = flexibleCostPolicy([flexibleCostType('hotel', 'unit', 12000)]);
    $b = flexibleCostPolicy([flexibleCostType('hotel', 'unit', 15000)]);
    expect(app(EventCostCalculator::class)->preview($inputs, $a)['total_cost_minor'])->toBe(30000)
        ->and(app(EventCostCalculator::class)->preview($inputs, $b)['total_cost_minor'])->toBe(30000);
});
