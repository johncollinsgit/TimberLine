<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class VoiceJobsController
{
    public function __invoke(Request $request, VoiceJobs $jobs)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'question' => ['required', 'string', 'max:1500'], 'focus' => ['nullable', 'uuid']]);

        return response()->json($jobs->lookup($request->attributes->get('current_tenant'), $request->user(), $input['area_id'],
            $input['question'], $input['focus'] ?? null))->header('Cache-Control', 'private, no-store');
    }
}
