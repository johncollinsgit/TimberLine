<?php

namespace App\Enterprise\Conversation;

use Illuminate\Http\Request;

class Controller
{
    public function status(Request $request, Readiness $readiness)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        abort_unless(config('enterprise.conversation.ui_enabled'), 404);
        $access = app(\App\Enterprise\WorkspaceAccess::class);
        $tenant = $request->attributes->get('current_tenant');
        $access->area($tenant, $access->actor($tenant, $request->user()), $input['area_id']);

        return response()->json($readiness->inspect($request->attributes->get('current_tenant'), $request->user(), $input['area_id']))
            ->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, string $id, Assistant $assistant)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return response()->json($assistant->show($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $id))
            ->header('Cache-Control', 'private, no-store');
    }

    public function turn(Request $request, Assistant $assistant)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'intent' => ['required', 'in:overview,building,costs'],
            'building_id' => ['nullable', 'required_if:intent,building', 'prohibited_unless:intent,building', 'uuid'],
            'assistant_id' => ['nullable', 'uuid'], 'session_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'], 'question' => ['required', 'string', 'max:1500'],
            'history' => ['prohibited'], 'actor' => ['prohibited'], 'role' => ['prohibited'], 'role_preview' => ['prohibited'],
            'tenant_id' => ['prohibited'], 'user_id' => ['prohibited'], 'silo_id' => ['prohibited'], 'model' => ['prohibited'],
            'api_key' => ['prohibited'], 'project_id' => ['prohibited'], 'input_ceiling_microusd_per_million' => ['prohibited'],
            'tools' => ['prohibited'], 'source_url' => ['prohibited'], 'amount_minor' => ['prohibited'], 'confirmed' => ['prohibited']]);

        return response()->json($assistant->turn($request->attributes->get('current_tenant'), $request->user(), $input['area_id'],
            $input['intent'], $input['building_id'] ?? null, $input['question'], $input['session_id'], $input['request_id'], $input['assistant_id'] ?? null))->header('Cache-Control', 'private, no-store');
    }

    public function end(Request $request, string $id, Assistant $assistant)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'confirm_end' => ['required', 'accepted']]);

        return response()->json($assistant->end($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $id))
            ->header('Cache-Control', 'private, no-store');
    }
}
