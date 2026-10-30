<?php

namespace App\Services\CampusFacilities;

use App\Services\SchedulerHeartbeatService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Throwable;

/** One bounded public demo call at a time. Own key, own ledger, no tenant authority. */
class LiveVoice
{
    public function configured(): bool
    {
        return config('campus_facilities.voice_enabled') && filled(config('campus_facilities.openai_api_key'));
    }

    public function start(DemoStore $store, array $input): array
    {
        abort_unless($this->configured(), 503, 'Live voice is not configured. You can type every detail.');
        $heartbeat = app(SchedulerHeartbeatService::class)->lastHeartbeatAt();
        abort_unless($heartbeat && abs(now()->diffInSeconds($heartbeat)) < 180, 503, 'Voice is temporarily unavailable while its session timer reconnects. Typed entry is available.');
        Validator::make($input, ['sessionId' => 'required|uuid', 'closeToken' => 'required|uuid', 'sdp' => 'required|string|max:20000', 'mode' => 'required|in:request,note,work,complete,ask', 'fields' => 'required|array'])->validate();
        // TrimStrings removes SDP's final CRLF; the WebRTC parser requires it.
        $input['sdp'] = rtrim($input['sdp'], "\r\n")."\r\n";
        abort_unless(str_starts_with($input['sdp'], "v=0\r\n") && str_contains($input['sdp'], 'm=audio '), 422, 'The voice offer is invalid.');
        $drafts = app(VoiceDraft::class);
        $fields = $drafts->validate($input['mode'], $input['fields']);
        $session = $drafts->session($input['mode'], $fields);
        $id = $input['sessionId'];
        $digest = hash('sha256', json_encode([$input['mode'], $fields, $input['sdp']], JSON_THROW_ON_ERROR));
        $result = $store->access(function (&$state) use ($id, $input, $digest) {
            $state['voice_sessions'] ??= [];
            if (isset($state['voice_sessions'][$id])) {
                $old = $state['voice_sessions'][$id];
                abort_unless(hash_equals($old['token_hash'], hash('sha256', $input['closeToken'])) && ($old['digest'] ?? null) === $digest, 409, 'This voice attempt belongs to another draft.');
                abort_unless($old['status'] === 'active' && $old['expires'] > time(), 409, 'This voice attempt ended or is awaiting reconciliation. Do not retry it.');

                return ['sdp' => $old['sdp'], 'expiresAt' => gmdate('c', $old['expires'])];
            }
            foreach ($state['voice_sessions'] as $call) {
                abort_unless(in_array($call['status'], ['closed', 'rejected', 'cancelled'], true), 429, 'Another demo voice session is active or awaiting cleanup. Please use typed entry for now.');
            }
            $day = now()->format('Y-m-d');
            abort_if(($state['voice_usage'][$day] ?? 0) >= 3, 429, 'The shared demo has used its three voice sessions today. Typed entry is still available.');
            $state['voice_usage'][$day] = ($state['voice_usage'][$day] ?? 0) + 1;
            $state['voice_sessions'] = array_filter($state['voice_sessions'], fn ($s) => $s['created'] > time() - 86400);
            $state['voice_sessions'][$id] = ['digest' => $digest, 'token_hash' => hash('sha256', $input['closeToken']), 'status' => 'creating', 'created' => time(), 'expires' => time() + 300, 'call_id' => null];

            return null;
        });
        if ($result) {
            return $result;
        }
        try {
            $response = Http::withToken(config('campus_facilities.openai_api_key'))->accept('application/sdp')->timeout(35)->connectTimeout(10)
                ->attach('sdp', $input['sdp'])->attach('session', json_encode($session, JSON_THROW_ON_ERROR))
                ->post('https://api.openai.com/v1/realtime/calls');
        } catch (Throwable) {
            $store->access(function (&$state) use ($id) {
                $state['voice_sessions'][$id]['status'] = 'unknown';
            });
            abort(503, 'The voice provider did not confirm this attempt. The session is held for review; your draft is safe.');
        }
        if (! $response->successful()) {

            $store->access(function (&$state) use ($id, $response) {
                $state['voice_sessions'][$id]['status'] = $response->status() >= 500 ? 'unknown' : 'rejected';
            });
            abort(503, 'Live voice could not start. Your draft is preserved; continue by typing.');
        }
        $path = parse_url($response->header('Location'), PHP_URL_PATH);
        $callId = is_string($path) && preg_match('~/v1/realtime/calls/([A-Za-z0-9_-]{5,200})$~D', $path, $matches) ? $matches[1] : null;
        $sdp = $response->body();
        $cancelled = $store->access(function (&$state) use ($id, $callId, $sdp) {
            $call = &$state['voice_sessions'][$id];
            $cancelled = ! empty($call['cancel_requested']) || $call['expires'] <= time();
            $call['call_id'] = $callId;
            $call['sdp'] = $sdp;
            $call['status'] = $callId && str_starts_with($sdp, 'v=0') ? 'active' : 'unknown';

            return $cancelled;
        });
        if ($cancelled || ! $callId || ! str_starts_with($sdp, 'v=0')) {
            if ($callId) {
                $this->hangup($store, $id, $callId);
            }
            abort(409, 'The voice attempt ended before connecting. Your draft is preserved.');
        }

        $expires = $store->access(fn (&$state) => $state['voice_sessions'][$id]['expires'], false);

        return ['sdp' => $sdp, 'expiresAt' => gmdate('c', $expires)];
    }

    public function close(DemoStore $store, array $input): array
    {
        Validator::make($input, ['sessionId' => 'required|uuid', 'closeToken' => 'required|uuid'])->validate();
        $callId = $store->access(function (&$state) use ($input) {
            $call = &$state['voice_sessions'][$input['sessionId']];
            abort_unless(is_array($call) && hash_equals($call['token_hash'], hash('sha256', $input['closeToken'])), 403, 'Unknown voice session.');
            $call['cancel_requested'] = true;

            return in_array($call['status'], ['closed', 'rejected', 'cancelled'], true) ? null : $call['call_id'];
        });
        if ($callId) {
            $this->hangup($store, $input['sessionId'], $callId);
        }

        return ['closed' => true];
    }

    public function expire(DemoStore $store): int
    {
        if (blank(config('campus_facilities.openai_api_key'))) {
            return 0;
        }
        $calls = $store->access(fn (&$state) => array_filter($state['voice_sessions'] ?? [], fn ($s) => $s['expires'] <= time() && ! empty($s['call_id']) && ! in_array($s['status'], ['closed', 'rejected', 'cancelled'], true)), false);
        foreach ($calls as $id => $call) {
            $this->hangup($store, $id, $call['call_id']);
        }

        return count($calls);
    }

    private function hangup(DemoStore $store, string $id, string $callId): void
    {
        try {
            $response = Http::withToken(config('campus_facilities.openai_api_key'))->timeout(10)->connectTimeout(5)->post('https://api.openai.com/v1/realtime/calls/'.$callId.'/hangup');
            // A missing call is already gone. Unknown responses retain the occupied slot.
            $closed = $response->successful() || $response->status() === 404;
        } catch (Throwable) {
            $closed = false;
        }
        $store->access(function (&$state) use ($id, $closed) {
            $state['voice_sessions'][$id]['status'] = $closed ? 'closed' : 'closing';
            if ($closed) {
                unset($state['voice_sessions'][$id]['sdp']);
            }
        });
    }
}
