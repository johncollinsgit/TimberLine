<?php

namespace App\Enterprise;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ResourcesController
{
    public function catalog(Request $request, LocationCatalog $catalog, WorkspaceAccess $access)
    {
        abort_unless(config('enterprise.resources_enabled'), 404);
        $access->actor($request->attributes->get('current_tenant'), $request->user());
        $input = $request->validate(['q' => ['nullable', 'string', 'max:150']]);

        return response()->json($catalog->all($input['q'] ?? ''))->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, Resources $resources)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'q' => ['nullable', 'string', 'max:150']]);

        return response()->json($resources->listing($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $input['q'] ?? ''))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $id, Resources $resources)
    {
        return response()->json($resources->show($request->attributes->get('current_tenant'), $request->user(), $id))->header('Cache-Control', 'private, no-store');
    }

    public function history(Request $request, string $id, Resources $resources)
    {
        return response()->json($resources->history($request->attributes->get('current_tenant'), $request->user(), $id))->header('Cache-Control', 'private, no-store');
    }

    public function command(Request $request, Resources $resources)
    {
        $input = $request->validate([
            'action' => ['required', 'in:location.save,asset.save,asset.checkout,asset.return,reservation.save,reservation.cancel,conflict.resolve,fixture.seed,fixture.withdraw'],
            'id' => ['required', 'uuid'], 'area_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'title' => ['required_if:action,location.save,asset.save,reservation.save', 'string', 'max:150'],
            'level' => ['required_if:action,location.save', Rule::in(Resources::LEVELS)], 'parent_id' => ['nullable', 'uuid'],
            'catalog_map_number' => ['nullable', 'string', 'regex:/^(?:[1-9]|[1-5][0-9]|6[0-8])$/D'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location_id' => ['required_if:action,asset.save,asset.checkout,asset.return', 'uuid'], 'condition' => ['nullable', 'string', 'max:500'],
            'holder_id' => ['required_if:action,asset.checkout', 'integer', 'min:1'],
            'expected_return_at' => ['required_if:action,asset.checkout', 'date_format:Y-m-d\TH:i:s\Z'],
            'resource_id' => ['required_if:action,reservation.save', 'uuid'],
            'starts_at' => ['required_if:action,reservation.save', 'date_format:Y-m-d\TH:i:s\Z'],
            'ends_at' => ['required_if:action,reservation.save', 'date_format:Y-m-d\TH:i:s\Z', 'after:starts_at'],
            'purpose' => ['nullable', 'string', 'max:1000'], 'category' => ['sometimes', 'in:event,class,tour,maintenance,closure,other'],
            'note' => ['required_if:action,reservation.cancel,conflict.resolve', 'string', 'max:1000'],
        ]);
        $input['expected_version'] = (int) $input['expected_version'];

        return response()->json($resources->execute($request->attributes->get('current_tenant'), $request->user(), $input))->header('Cache-Control', 'private, no-store');
    }
}
