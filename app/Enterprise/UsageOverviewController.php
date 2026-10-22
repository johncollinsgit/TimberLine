<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class UsageOverviewController
{
    public function __invoke(Request $request, UsageOverview $usage)
    {
        $input = $request->validate(['area_id' => ['sometimes', 'uuid']]);

        return response()->json($usage->build($request->attributes->get('current_tenant'), $request->user(), $input['area_id'] ?? null))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
