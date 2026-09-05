<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldVoiceTranscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class EverbranchMobileVoiceController extends Controller
{
    public function store(Request $request, FieldVoiceTranscriptionService $transcriptions): JsonResponse
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:15360', 'mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/x-wav,audio/webm,audio/ogg,video/mp4,application/octet-stream'],
            'context' => ['required', 'in:job_note,material_request'],
        ]);

        try {
            return response()->json($transcriptions->transcribe($validated['audio'], (string) $validated['context']));
        } catch (RuntimeException $exception) {
            $status = config('services.openai.api_key') ? 502 : 503;

            return response()->json(['message' => $exception->getMessage()], $status);
        }
    }
}
