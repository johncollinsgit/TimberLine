<?php

namespace App\Enterprise\Conversation;

use Illuminate\Http\Request;

class SavedAssistantController
{
    public function index(Request $request, SavedAssistants $assistants)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($assistants->listing($request->attributes->get('current_tenant'), $request->user(), $input['area_id']))->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $id, SavedAssistants $assistants)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($assistants->show($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $id))->header('Cache-Control', 'private, no-store');
    }

    private function input(Request $request, bool $save): array
    {
        $rules = ['area_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'confirmed' => ['required', 'accepted']];
        foreach (['model', 'api_key', 'project_id', 'organization_id', 'tenant_id', 'user_id', 'silo_id', 'role', 'role_preview', 'tools', 'history', 'memory'] as $key) {
            $rules[$key] = ['prohibited'];
        }
        if ($save) {
            $rules += ['id' => ['required', 'uuid'], 'name' => ['required', 'string', 'max:80'], 'purpose' => ['required', 'string', 'max:250'],
                'instructions' => ['required', 'string', 'max:500'], 'checks' => ['required', 'array', 'min:1', 'max:3'],
                'checks.*' => ['required', 'distinct', 'in:overview,building,costs']];
        }
        $input = $request->validate($rules);
        $input['expected_version'] = (int) $input['expected_version'];

        return $input;
    }

    public function save(Request $request, SavedAssistants $assistants)
    {
        return response()->json($assistants->save($request->attributes->get('current_tenant'), $request->user(), $this->input($request, true)))->header('Cache-Control', 'private, no-store');
    }

    public function delete(Request $request, string $id, SavedAssistants $assistants)
    {
        return response()->json($assistants->delete($request->attributes->get('current_tenant'), $request->user(), ['id' => $id] + $this->input($request, false)))->header('Cache-Control', 'private, no-store');
    }
}
