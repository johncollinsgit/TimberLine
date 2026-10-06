<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\TenantAiUsageService;
use App\Services\FieldService\FieldVoiceTranscriptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class EverbranchMobileVoiceController extends Controller
{
    public function store(Request $request, FieldVoiceTranscriptionService $transcriptions, TenantAiUsageService $usage): JsonResponse
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active !== false, 401);

        $validated = $request->validate([
            'audio' => ['required', 'file', 'max:15360', 'mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/x-wav,audio/webm,audio/ogg,video/mp4,application/octet-stream'],
            'context' => ['required', 'in:job_note,material_request,bud_question'],
            'duration_seconds' => ['required', 'integer', 'min:1', 'max:90'],
            'client_uuid' => ['required', 'uuid'],
        ]);

        $event = $usage->reserveVoice(
            $tenant, $user, (string) $validated['client_uuid'], (string) $validated['context'],
            (string) config('services.openai.field_voice_model', 'gpt-transcribe'), (int) $validated['duration_seconds']
        );
        try {
            $result = $transcriptions->transcribe($validated['audio'], (string) $validated['context'], (int) $validated['duration_seconds']);
            $usage->settle($event, (int) $result['duration_seconds'], $result['provider_request_id']);
            unset($result['provider_request_id']);
            $result['billing'] = ['scope' => 'tenant', 'status' => 'metered'];

            return response()->json($result);
        } catch (RuntimeException $exception) {
            $usage->refund($event, $exception->getMessage());
            $status = config('services.openai.api_key') ? 502 : 503;

            return response()->json(['message' => $exception->getMessage()], $status);
        }
    }
}
