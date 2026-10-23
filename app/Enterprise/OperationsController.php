<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class OperationsController
{
    private function json(array $data)
    {
        return response()->json($data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, Operations $operations)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'project_id' => ['sometimes', 'uuid']]);

        return $this->json($operations->listing($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $input['project_id'] ?? null));
    }

    public function show(Request $request, string $id, Operations $operations)
    {
        return $this->json($operations->show($request->attributes->get('current_tenant'), $request->user(), $id));
    }

    public function quality(Request $request, string $id, Operations $operations)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return $this->json($operations->quality($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $id));
    }

    public function command(Request $request, Operations $operations)
    {
        $action = $request->input('action');
        $rules = ['action' => ['required', 'in:task.configure,task.check,task.interrupt,task.resume,task.reassign,task.reschedule,task.close,task.approve,handoff.propose,handoff.accept,handoff.decline,contractor.review'],
            'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0']];
        if (str_starts_with((string) $action, 'task.')) {
            $rules += ['task_id' => ['required', 'uuid'], 'expected_task_version' => ['required', 'integer', 'min:1']];
        }
        $userId = ['string', 'regex:/^[1-9][0-9]{0,18}$/D'];
        $rules += match ($action) {
            'task.configure' => ['owner_user_id' => ['required', ...$userId], 'ball_user_id' => ['required_unless:ball_team,true', 'nullable', ...$userId],
                'ball_team' => ['required', 'boolean'], 'next_action' => ['required', 'string', 'max:500'], 'due_on' => ['sometimes', 'nullable', 'date_format:Y-m-d'],
                'priority' => ['sometimes', 'in:low,normal,high,urgent'],
                'blockers' => ['sometimes', 'array', 'max:20'], 'blockers.*' => ['required', 'string', 'max:300'],
                'checklist' => ['required', 'array', 'min:1', 'max:50'], 'checklist.*' => ['array:id,label,weight'],
                'checklist.*.id' => ['required', 'string', 'regex:/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/D', 'distinct'],
                'checklist.*.label' => ['required', 'string', 'max:200'], 'checklist.*.weight' => ['sometimes', 'integer', 'min:1', 'max:100']],
            'task.check' => ['item_id' => ['required', 'string', 'max:40'], 'completed' => ['required', 'boolean']],
            'task.interrupt' => ['checkpoint' => ['required', 'string', 'max:2000'], 'reason' => ['required', 'string', 'max:1000'], 'emergency_task_id' => ['sometimes', 'uuid']],
            'task.resume', 'task.close' => ['reason' => ['required', 'string', 'max:1000']],
            'task.reassign' => ['ball_user_id' => ['required', ...$userId], 'reason' => ['required', 'string', 'max:1000']],
            'task.reschedule' => ['due_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:1000']],
            'task.approve' => ['note' => ['sometimes', 'nullable', 'string', 'max:1000']],
            'handoff.propose' => ['id' => ['required', 'uuid'], 'source_request_id' => ['required', 'uuid'], 'expected_request_version' => ['required', 'integer', 'min:1'],
                'target_area_id' => ['required', 'uuid'], 'target_project_id' => ['required', 'uuid'], 'assignee_id' => ['required', ...$userId],
                'title' => ['required', 'string', 'max:100'], 'next_action' => ['required', 'string', 'max:500'], 'confirmed' => ['required', 'accepted']],
            'handoff.accept', 'handoff.decline' => ['id' => ['required', 'uuid'], 'reason' => ['required', 'string', 'max:1000'], 'confirmed' => ['required', 'accepted']],
            'contractor.review' => ['task_id' => ['required', 'uuid'], 'contractor_id' => ['required', 'uuid'], 'workmanship' => ['required', 'integer', 'between:0,4'],
                'timeliness' => ['required', 'integer', 'between:0,4'], 'communication' => ['required', 'integer', 'between:0,4'], 'documentation' => ['required', 'integer', 'between:0,4'], 'note' => ['sometimes', 'nullable', 'string', 'max:2000']],
            default => [],
        };
        $input = $request->validate($rules);
        foreach (['expected_version', 'expected_task_version', 'expected_request_version', 'workmanship', 'timeliness', 'communication', 'documentation'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = (int) $input[$key];
            }
        }
        foreach (['ball_team', 'completed'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = (bool) $input[$key];
            }
        }
        if (isset($input['checklist'])) {
            $input['checklist'] = array_map(fn ($item) => array_replace($item, ['weight' => (int) ($item['weight'] ?? 1)]), $input['checklist']);
        }
        try {
            return $this->json($operations->execute($request->attributes->get('current_tenant'), $request->user(), $input));
        } catch (\DomainException $error) {
            return response()->json(['error' => $error->getMessage(), 'saved' => false], match ($error->getMessage()) {
                'conflict', 'idempotency_mismatch' => 409, 'access_denied' => 403, default => 422,
            })->header('Cache-Control', 'private, no-store');
        }
    }
}
