<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class WorkflowController
{
    private function json(array $result)
    {
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request, Workflows $workflows)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'project_id' => ['required', 'uuid']]);

        return $this->json($workflows->listing($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $input['project_id']));
    }

    public function show(Request $request, string $id, Workflows $workflows)
    {
        return $this->json($workflows->show($request->attributes->get('current_tenant'), $request->user(), $id));
    }

    public function save(Request $request, Workflows $workflows)
    {
        $input = $request->validate(['id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'area_id' => ['required', 'uuid'], 'project_id' => ['required', 'uuid'],
            'expected_version' => ['required', 'integer', 'min:0'], 'name' => ['required', 'string', 'max:100'], 'definition' => ['required', 'array']]);
        $input['expected_version'] = (int) $input['expected_version'];

        return $this->json($workflows->save($request->attributes->get('current_tenant'), $request->user(), $input));
    }

    public function preview(Request $request, Workflows $workflows)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'project_id' => ['required', 'uuid'], 'definition' => ['required', 'array'], 'values' => ['sometimes', 'array']]);

        return $this->json($workflows->preview($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $input['project_id'], $input['definition'], $input['values'] ?? []));
    }

    public function activate(Request $request, string $id, Workflows $workflows)
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'], 'confirmed' => ['required', 'boolean'], 'digest' => ['required', 'regex:/^[a-f0-9]{64}$/D']]);
        $input['expected_version'] = (int) $input['expected_version'];

        return $this->json($workflows->activate($request->attributes->get('current_tenant'), $request->user(), $id, $input));
    }

    public function pause(Request $request, string $id, Workflows $workflows)
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']]);
        $input['expected_version'] = (int) $input['expected_version'];

        return $this->json($workflows->activate($request->attributes->get('current_tenant'), $request->user(), $id, $input, true));
    }

    public function start(Request $request, string $id, Workflows $workflows)
    {
        $input = $request->validate(['id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1']]);
        $input['expected_version'] = (int) $input['expected_version'];

        return $this->json($workflows->start($request->attributes->get('current_tenant'), $request->user(), $id, $input));
    }

    public function advance(Request $request, string $id, string $run, Workflows $workflows)
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:1'], 'expected_task_version' => ['sometimes', 'integer', 'min:1'],
            'expected_operations_version' => ['sometimes', 'integer', 'min:1'], 'values' => ['sometimes', 'array'], 'decision' => ['sometimes', 'in:complete,approve,reject']]);
        $input['expected_version'] = (int) $input['expected_version'];
        foreach (['expected_task_version', 'expected_operations_version'] as $key) {
            if (isset($input[$key])) {
                $input[$key] = (int) $input[$key];
            }
        }

        return $this->json($workflows->advance($request->attributes->get('current_tenant'), $request->user(), $id, $run, $input));
    }

    public function propose(Request $request, Workflows $workflows, Intelligence $intelligence)
    {
        $input = $request->validate(['request_id' => ['required', 'uuid'], 'area_id' => ['required', 'uuid'], 'project_id' => ['required', 'uuid'], 'prompt' => ['required', 'string', 'max:3000']]);
        $tenant = $request->attributes->get('current_tenant');
        $scope = $workflows->scope($tenant, $request->user(), $input['area_id'], $input['project_id']);
        abort_unless($scope['can_design'], 403);
        $result = $intelligence->generate($tenant, $request->user(), $input['area_id'], 'workflow_draft', ['request' => $input['prompt'], 'members' => $scope['members']], $input['request_id']);
        abort_unless(array_keys($result['value']) === ['definition'] && is_array($result['value']['definition']), 503, 'The model did not return a supported workflow draft.');
        // Current access, member assignments and every binding are rechecked after the external response.
        $preview = $workflows->preview($tenant, $request->user(), $input['area_id'], $input['project_id'], $result['value']['definition']);
        abort_unless($workflows->scope($tenant, $request->user(), $input['area_id'], $input['project_id'])['can_design'], 403);

        return $this->json($preview + ['usage_id' => $result['usage_id'], 'replayed' => $result['replayed'], 'proposal' => true,
            'model_status' => 'AI proposed this validated draft. Review its screens, people and conditions before saving or activating.']);
    }
}
