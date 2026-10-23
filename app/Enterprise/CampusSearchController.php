<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class CampusSearchController
{
    public function __invoke(Request $request, CampusSearch $search)
    {
        $input = $request->validate(['q' => ['required', 'string', 'max:150'], 'area_id' => ['nullable', 'uuid']]);

        return response()->json($search->search($request->attributes->get('current_tenant'), $request->user(), $input['q'], $input['area_id'] ?? null))->header('Cache-Control', 'private, no-store');
    }
}
