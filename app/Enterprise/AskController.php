<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class AskController
{
    public function ask(Request $request, AskEverbranch $ask)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'question' => ['required', 'string', 'max:1500'], 'request_id' => ['sometimes', 'uuid'],
            'focus' => ['nullable', 'string', 'regex:/^(request|project|contractor|schedule|cost):[a-f0-9-]{36}$/D']]);

        return response()->json($ask->ask($request->attributes->get('current_tenant'), $request->user(), $input['area_id'],
            $input['question'], $input['focus'] ?? null))->header('Cache-Control', 'private, no-store');
    }
}
