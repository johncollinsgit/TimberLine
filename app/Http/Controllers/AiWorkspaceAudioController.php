<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\AiBranch\AiWorkspaceAudio;
use Illuminate\Http\Request;

class AiWorkspaceAudioController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 404);

        return $tenant;
    }

    public function settings(Request $request, AiWorkspaceAudio $audio)
    {
        $data = $request->validate(['enabled' => 'required|boolean', 'rates_digest' => 'required|string|size:64', 'confirmed' => 'required|accepted']);
        $audio->settings($this->tenant($request), $request->user(), $data['enabled'], $data['rates_digest']);

        return response()->json(['ok' => true])->header('Cache-Control', 'no-store, private');
    }

    public function transcribe(Request $request, AiWorkspaceAudio $audio)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'audio' => 'required|string|max:1280060']);

        return response()->json($audio->transcribe($this->tenant($request), $request->user(), $data['request_id'], $data['audio']))->header('Cache-Control', 'no-store, private');
    }

    public function speech(Request $request, AiWorkspaceAudio $audio)
    {
        $data = $request->validate(['request_id' => 'required|uuid', 'conversation_id' => 'required|integer|min:1', 'message_uuid' => 'required|uuid']);

        return response()->json($audio->speech($this->tenant($request), $request->user(), $data['conversation_id'], $data['message_uuid'], $data['request_id']))->header('Cache-Control', 'no-store, private');
    }
}
