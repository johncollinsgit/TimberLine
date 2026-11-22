<?php

namespace App\Services\ManagedWebsite;

use App\Models\IntegrationConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class StudioYouTubeAccount
{
    private const SCOPE = 'https://www.googleapis.com/auth/youtube.upload https://www.googleapis.com/auth/youtube.readonly';

    public function ready(): bool
    {
        return filled(config('studio_distribution.google_id')) && filled(config('studio_distribution.google_secret'))
            && str_starts_with((string) config('studio_distribution.callback'), 'https://');
    }

    public function authorize(string $state, string $verifier): string
    {
        abort_unless($this->ready(), 503, 'YouTube OAuth is awaiting setup.');

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => config('studio_distribution.google_id'),
            'redirect_uri' => config('studio_distribution.callback'), 'response_type' => 'code', 'scope' => self::SCOPE,
            'access_type' => 'offline', 'prompt' => 'consent select_account', 'state' => $state, 'code_challenge_method' => 'S256',
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=')], '', '&', PHP_QUERY_RFC3986);
    }

    private function token(array $grant): array
    {
        $response = Http::withoutRedirecting()->asForm()->timeout(10)->post('https://oauth2.googleapis.com/token', [
            ...$grant, 'client_id' => config('studio_distribution.google_id'), 'client_secret' => config('studio_distribution.google_secret')]);
        abort_unless($response->successful() && $response->json('access_token') && (int) $response->json('expires_in') > 0, 422, 'Reconnect the YouTube creator account.');

        return $response->json();
    }

    public function connect(int $tenant, User $actor, string $code, string $verifier): void
    {
        $token = $this->token(['code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => config('studio_distribution.callback'), 'grant_type' => 'authorization_code']);
        abort_unless(! empty($token['refresh_token']) && array_diff(explode(' ', self::SCOPE), explode(' ', $token['scope'] ?? '')) === [], 422, 'Approve both channel access and video uploads.');
        $identity = Http::withoutRedirecting()->timeout(10)->withToken($token['access_token'])->get('https://www.googleapis.com/youtube/v3/channels', ['part' => 'snippet', 'mine' => 'true']);
        $channel = $identity->json('items.0');
        abort_unless($identity->successful() && ! empty($channel['id']) && ! empty($channel['snippet']['title']), 422, 'Google did not return a creator channel.');
        IntegrationConnection::updateOrCreate(['tenant_id' => $tenant, 'provider' => 'youtube_studio', 'external_account_id' => $channel['id']], [
            'access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'], 'expires_at' => now()->addSeconds($token['expires_in']),
            'status' => 'connected', 'connected_by_user_id' => $actor->id, 'connected_at' => now(), 'external_account_label' => $channel['snippet']['title'],
        ]);
    }

    public function access(IntegrationConnection $connection): string
    {
        return DB::transaction(function () use ($connection) {
            $current = IntegrationConnection::forTenantId($connection->tenant_id)->where('provider', 'youtube_studio')->where('status', 'connected')->lockForUpdate()->findOrFail($connection->id);
            if ($current->needsRefresh()) {
                $token = $this->token(['grant_type' => 'refresh_token', 'refresh_token' => $current->refresh_token]);
                $current->update(['access_token' => $token['access_token'], 'refresh_token' => $token['refresh_token'] ?? $current->refresh_token,
                    'expires_at' => now()->addSeconds($token['expires_in'])]);
            }

            return $current->access_token;
        });
    }
}
