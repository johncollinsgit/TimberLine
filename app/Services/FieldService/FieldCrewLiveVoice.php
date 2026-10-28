<?php

namespace App\Services\FieldService;

use App\Models\AiBranchAccount;
use App\Models\Tenant;
use App\Models\TenantAiUsageEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** GPT-Live session setup and conservative usage reservation for Field workspace voice. */
class FieldCrewLiveVoice
{
    private const MAX_SECONDS = 600;
    private const MICROS_PER_MINUTE = 50000;
    private const MAX_RESERVATION_MICROS = 500000;

    public function start(Tenant $tenant, User $user, string $sdp): array
    {
        app(FieldCrewAssistant::class)->authorize($tenant, $user);
        $apiKey = trim((string) config('ai_branch.api_key'));
        abort_unless($apiKey !== '', 503, 'Workspace AI provider setup needs review.');
        $reservationId = (string) Str::uuid();

        $event = DB::transaction(function () use ($tenant, $user, $reservationId): TenantAiUsageEvent {
            AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $authority = app(FieldCrewAssistant::class)->authorize($tenant, $user);
            app(FieldCrewAssistant::class)->assertMonthlyBudget($tenant, self::MAX_RESERVATION_MICROS);

            return TenantAiUsageEvent::create([
                'tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $reservationId,
                'feature' => 'field_crew_assistant', 'context' => 'live_voice', 'model' => 'gpt-live-1', 'status' => 'reserved',
                'duration_seconds' => self::MAX_SECONDS, 'provider_cost_micros' => self::MAX_RESERVATION_MICROS,
                'buyer_charge_micros' => 0, 'occurred_at' => now('UTC'),
                'metadata' => ['max_seconds' => self::MAX_SECONDS, 'monthly_cap_cents' => $authority['cap_cents'], 'voice_session' => true],
            ]);
        }, 3);

        $instructions = 'You are the signed-in company’s live Everbranch field work assistant. Speak naturally, warmly, and briefly, like a helpful coworker on a call. You can hear the user interrupt and correct you; acknowledge corrections and keep context across turns. Greet the user and ask how you can help. For questions about company work, current clocks, hours, jobs, tasks, materials, or to open a job or app page, delegate to the workspace application so it can check current records and permissions. Never guess workspace facts. If the user asks to open something, delegate and let the app open only destinations it confirms. Customer records and contact details are admin-only. Do not claim that you changed records, clocked anyone in or out, sent a message, or ordered anything. The workspace application can answer questions and navigate, but this voice session has no write controls. For general conversation, answer briefly without searching workspace data.';

        try {
            $response = Http::withToken($apiKey)->withHeaders(['OpenAI-Safety-Identifier' => hash('sha256', 'everbranch-field-user:'.$user->id)])
                ->acceptJson()->timeout(35)->post('https://api.openai.com/v1/live/sessions', [
                'session' => [
                    'model' => 'gpt-live-1', 'instructions' => $instructions, 'delegation' => ['type' => 'client'],
                    'audio' => ['output' => ['voice' => 'marin']], 'store' => false,
                ],
                'transport' => ['type' => 'webrtc', 'sdp' => $sdp],
            ]);
        } catch (\Throwable $error) {
            // The upstream may have created a session even if this server lost its response.
            $event->forceFill(['status' => 'uncertain'])->save();
            throw new HttpException(503, 'The live voice connection could not be confirmed. Its reserved usage is held; try again later.', $error);
        }

        if (! $response->successful()) {
            $event->forceFill(['status' => 'failed', 'provider_cost_micros' => 0, 'duration_seconds' => 0])->save();

            return abort(503, 'OpenAI live voice is not available for this workspace right now.');
        }

        $sessionId = $response->json('session.id');
        $answer = $response->json('transport.sdp');
        if (! is_string($sessionId) || $sessionId === '' || ! is_string($answer) || $answer === '') {
            $event->forceFill(['status' => 'uncertain'])->save();
            abort(503, 'The live voice connection could not be confirmed. Its reserved usage is held; try again later.');
        }

        $event->forceFill(['provider_request_id' => $sessionId,
            'metadata' => [...(array) $event->metadata, 'live_session_id' => $sessionId]])->save();

        return ['reservation_id' => $reservationId, 'session_id' => $sessionId, 'sdp' => $answer, 'max_seconds' => self::MAX_SECONDS];
    }

    public function close(Tenant $tenant, User $user, string $reservationId, float $seconds): void
    {
        DB::transaction(function () use ($tenant, $user, $reservationId, $seconds): void {
            AiBranchAccount::where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $event = TenantAiUsageEvent::where('tenant_id', $tenant->id)->where('user_id', $user->id)
                ->where('client_uuid', $reservationId)->lockForUpdate()->firstOrFail();
            abort_unless($event->feature === 'field_crew_assistant' && data_get($event->metadata, 'voice_session') === true, 404);
            $reported = (int) ceil(max(15, $seconds));
            if ($event->status === 'settled') {
                abort_unless((int) data_get($event->metadata, 'reported_seconds') === $reported, 409);

                return;
            }
            abort_unless($event->status === 'reserved' && (int) ($event->provider_cost_micros ?? 0) === self::MAX_RESERVATION_MICROS, 409);

            $cost = (int) ceil($reported * self::MICROS_PER_MINUTE / 60);
            $event->forceFill(['status' => 'settled', 'duration_seconds' => min(self::MAX_SECONDS, $reported),
                'provider_cost_micros' => min(self::MAX_RESERVATION_MICROS, $cost),
                'metadata' => [...(array) $event->metadata, 'reported_seconds' => $reported]])->save();
        }, 3);
    }
}
