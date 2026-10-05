<?php

namespace App\Services\HighLevel;

use App\Models\HighLevel\EmbeddedSession;
use App\Models\HighLevel\OAuthState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class OAuthStateService
{
    public function issue(string $provider, array $payload = [], ?EmbeddedSession $session = null): string
    {
        $state = Str::random(64);
        OAuthState::create(['state_hash' => hash('sha256', $state), 'provider' => $provider, 'payload' => $payload,
            'session_id' => $session?->id, 'expires_at' => now()->addMinutes(10)]);

        return $state;
    }

    public function consume(string $provider, string $state): OAuthState
    {
        return DB::transaction(function () use ($provider, $state): OAuthState {
            $record = OAuthState::where('provider', $provider)->where('state_hash', hash('sha256', $state))->lockForUpdate()->first();
            abort_unless($record && ! $record->consumed_at && $record->expires_at->isFuture(), 403, 'Authorization expired or was already used. Start again.');
            $record->update(['consumed_at' => now()]);

            return $record;
        });
    }
}
