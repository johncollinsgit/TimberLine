<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class BriefingController
{
    public function __construct(private Briefing $briefing, private BriefingPdf $renderer) {}

    public function show(Request $request)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($this->briefing->build($request->attributes->get('current_tenant'), $request->user(), $input['area_id']))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function refresh(Request $request)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid']]);

        return response()->json($this->briefing->refresh($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $input['request_id']))
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }

    public function pdf(Request $request)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);
        // Download always rebuilds current facts. It never spends model credits or reuses another user's answer.
        $briefing = $this->briefing->build($request->attributes->get('current_tenant'), $request->user(), $input['area_id']);

        return response($this->renderer->render($briefing))->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', 'attachment; filename="everbranch-campus-briefing.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
