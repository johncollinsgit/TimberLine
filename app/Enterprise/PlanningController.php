<?php

namespace App\Enterprise;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PlanningController
{
    public function index(Request $request, Planning $planning)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($planning->listing($request->attributes->get('current_tenant'), $request->user(), $input['area_id']))->header('Cache-Control', 'private, no-store');
    }

    public function save(Request $request, Planning $planning)
    {
        $kind = $request->input('kind');
        $input = $request->validate(['kind' => ['required', Rule::in(array_keys(Planning::KINDS))], 'id' => ['required', 'uuid'],
            'request_id' => ['required', 'uuid'], 'area_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'title' => ['required', 'string', 'max:100'], 'status' => ['required', Rule::in(match ($kind) {
                'contractor' => ['active', 'inactive'], 'schedule' => ['planned', 'cancelled'], default => ['recorded']
            })],
            'project_id' => ['required_unless:kind,contractor', 'uuid'], 'contractor_id' => ['required_if:kind,schedule', 'uuid'],
            'starts_on' => ['required_if:kind,schedule', 'date_format:Y-m-d'], 'ends_on' => ['required_if:kind,schedule', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'amount_minor' => ['required_if:kind,cost', 'integer', 'min:0', 'max:1000000000'], 'currency' => ['required_if:kind,cost', 'regex:/^[A-Z]{3}$/D'],
            'basis' => ['required_if:kind,cost', 'in:estimate,recorded_cost']]);
        $input['expected_version'] = (int) $input['expected_version'];
        if (isset($input['amount_minor'])) {
            $input['amount_minor'] = (int) $input['amount_minor'];
        }

        return response()->json($planning->execute($request->attributes->get('current_tenant'), $request->user(), $input))->header('Cache-Control', 'private, no-store');
    }
}
