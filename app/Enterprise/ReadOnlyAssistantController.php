<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class ReadOnlyAssistantController
{
    public function __invoke(Request $request, ReadOnlyAssistant $assistant)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'intent' => ['required', 'in:overview,building,costs'],
            'building_id' => ['nullable', 'required_if:intent,building', 'prohibited_unless:intent,building', 'uuid'],
            'actor' => ['prohibited'], 'role' => ['prohibited'], 'role_preview' => ['prohibited'], 'tools' => ['prohibited'],
            'amount_minor' => ['prohibited'], 'source_url' => ['prohibited'], 'question' => ['prohibited']]);

        return response()->json($assistant->answer($request->attributes->get('current_tenant'), $request->user(),
            $input['area_id'], $input['intent'], $input['building_id'] ?? null))->header('Cache-Control', 'private, no-store');
    }
}
