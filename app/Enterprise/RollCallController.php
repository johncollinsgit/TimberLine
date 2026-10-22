<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class RollCallController
{
    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, RollCall $rollCall)
    {
        return $this->json($rollCall->build($request->attributes->get('current_tenant'), $request->user(), $this->scope($request)));
    }

    public function meetings(Request $request, Meetings $meetings)
    {
        return $this->json($meetings->listing($request->attributes->get('current_tenant'), $request->user(), $this->scope($request)));
    }

    private function scope(Request $request): array
    {
        return $request->validate(['scope' => ['sometimes', 'string', 'in:campus,workspace,project,team'],
            'type' => ['sometimes', 'string', 'in:campus,workspace,project,team'], 'area_id' => ['nullable', 'uuid'],
            'project_id' => ['nullable', 'uuid'], 'team_user_id' => ['nullable', 'integer', 'min:1']]);
    }

    public function show(Request $request, string $id, Meetings $meetings)
    {
        return $this->json($meetings->show($request->attributes->get('current_tenant'), $request->user(), $id));
    }

    public function save(Request $request, Meetings $meetings)
    {
        return $this->json($meetings->save($request->attributes->get('current_tenant'), $request->user(), $request->all()));
    }

    public function preview(Request $request, string $id, Meetings $meetings)
    {
        return $this->json($meetings->preview($request->attributes->get('current_tenant'), $request->user(), $id));
    }

    public function confirm(Request $request, string $id, Meetings $meetings)
    {
        return $this->json($meetings->confirm($request->attributes->get('current_tenant'), $request->user(), $id, $request->all()));
    }

    public function pdf(Request $request, string $id, Meetings $meetings, MeetingPdf $pdf)
    {
        $document = $meetings->document($request->attributes->get('current_tenant'), $request->user(), $id);

        return response($pdf->render($document))->header('Content-Type', 'application/pdf')->header('Content-Disposition', 'attachment; filename="everbranch-meeting-roll-call.pdf"')
            ->header('Cache-Control', 'private, no-store')->header('X-Content-Type-Options', 'nosniff');
    }
}
