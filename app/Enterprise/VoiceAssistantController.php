<?php

namespace App\Enterprise;

use Illuminate\Http\Request;

class VoiceAssistantController
{
    public function turn(Request $request, VoiceAssistant $assistant)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'session_id' => ['required', 'uuid'], 'request_id' => ['required', 'uuid'],
            'question' => ['required', 'string', 'max:1500'], 'focus' => ['nullable', 'uuid'], 'history' => ['prohibited'], 'confirmed' => ['prohibited']]);

        return $this->json($assistant->turn($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $input['question'],
            $input['session_id'], $input['request_id'], $input['focus'] ?? null));
    }

    public function cancel(Request $request, string $id, VoiceAssistant $assistant)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid']]);

        return $this->json($assistant->cancel($request->attributes->get('current_tenant'), $request->user(), $input['area_id'], $id));
    }

    public function preview(Request $request, VoiceAssistant $assistant)
    {
        $input = $request->validate(['area_id' => ['required', 'uuid'], 'session_id' => ['required', 'uuid'], 'turn_id' => ['required', 'uuid'],
            'preview_id' => ['required', 'uuid'], 'to' => ['required', 'string', 'max:254'], 'subject' => ['prohibited'], 'body' => ['prohibited'], 'confirmed' => ['prohibited']]);

        return $this->json($assistant->preview($request->attributes->get('current_tenant'), $request->user(), $input['area_id'],
            $input['session_id'], $input['turn_id'], $input['preview_id'], $input['to']));
    }

    private function json(array $result)
    {
        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
