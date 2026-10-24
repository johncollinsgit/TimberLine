<?php

namespace App\Enterprise\Conversation;

use Illuminate\Http\Request;

class MemoryController
{
    public function index(Request $request, PrivateMemory $memory)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($memory->listing($request->attributes->get('current_tenant'), $request->user(), $input['area_id']))->header('Cache-Control', 'private, no-store');
    }

    public function preview(Request $request, PrivateMemory $memory)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'source_ref' => ['required', 'string', 'max:50'],
            'field' => ['required', 'in:status,priority,due_on,amount_minor,currency,basis']]);

        return response()->json($memory->preview($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $input['source_ref'], $input['field']))->header('Cache-Control', 'private, no-store');
    }

    private function input(Request $request, bool $save): array
    {
        $rules = ['area_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'expected_version' => ['required', 'integer', 'min:0'],
            'confirmed' => ['required', 'accepted']];
        foreach (['model', 'api_key', 'project_id', 'tenant_id', 'user_id', 'silo_id', 'role', 'role_preview', 'tools', 'history', 'body', 'expires_at', 'source_url', 'observed_value'] as $key) {
            $rules[$key] = ['prohibited'];
        }
        if ($save) {
            $rules += ['id' => ['required', 'uuid'], 'type' => ['required', 'in:preference,source_observation'], 'retention_days' => ['required', 'integer', 'in:1,7,30'],
                'key' => ['required_if:type,preference', 'prohibited_unless:type,preference', 'in:reply_length,date_style'],
                'value' => ['required_if:type,preference', 'prohibited_unless:type,preference', 'string', 'max:12'],
                'source_ref' => ['required_if:type,source_observation', 'prohibited_unless:type,source_observation', 'string', 'max:50'],
                'source_sha256' => ['required_if:type,source_observation', 'prohibited_unless:type,source_observation', 'regex:/^[a-f0-9]{64}$/D'],
                'field' => ['required_if:type,source_observation', 'prohibited_unless:type,source_observation', 'in:status,priority,due_on,amount_minor,currency,basis']];
        }
        $input = $request->validate($rules);
        $input['expected_version'] = (int) $input['expected_version'];
        if ($save) {
            $input['retention_days'] = (int) $input['retention_days'];
        }

        return $input;
    }

    public function save(Request $request, PrivateMemory $memory)
    {
        return response()->json($memory->save($request->attributes->get('current_tenant'), $request->user(), $this->input($request, true)))->header('Cache-Control', 'private, no-store');
    }

    public function delete(Request $request, string $id, PrivateMemory $memory)
    {
        return response()->json($memory->delete($request->attributes->get('current_tenant'), $request->user(), ['id' => $id] + $this->input($request, false)))->header('Cache-Control', 'private, no-store');
    }
}
