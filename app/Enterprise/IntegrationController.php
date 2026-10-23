<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class IntegrationController
{
    public function status(Request $request, IntelligenceStatus $status)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($status->forUser($request->attributes->get('current_tenant'), $request->user(), $input['area_id']))
            ->header('Cache-Control', 'private, no-store');
    }
}
