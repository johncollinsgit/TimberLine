<?php

namespace App\Services\HighLevel;

use App\Models\HighLevel\Authorization;
use App\Models\HighLevel\EmbeddedSession;
use App\Models\HighLevel\Installation;
use App\Models\HighLevel\OAuthState;
use App\Models\HighLevel\UserBinding;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class EmbeddedSessionService
{
    public function __construct(private readonly HighLevelApi $api) {}

    public function challenge(): string
    {
        $nonce = Str::random(64);
        OAuthState::create(['state_hash' => hash('sha256', $nonce), 'provider' => 'context', 'expires_at' => now()->addMinutes(2)]);

        return $nonce;
    }

    public function exchange(string $encrypted, string $challenge, string $parent): array
    {
        abort_unless(in_array($parent, config('highlevel.parent_origins', []), true), 403);
        $context = $this->decrypt($encrypted);
        $install = Installation::where('app_id', config('highlevel.app_id'))->where('location_id', $context['activeLocation'] ?? '')->firstOrFail();
        abort_unless($install->status === 'installed' && $install->tenant_id && $install->company_id === ($context['companyId'] ?? null), 403);
        abort_unless($parent === 'https://app.gohighlevel.com' || $parent === $install->parent_origin, 403, 'This CRM domain is not verified for the installation.');
        $userId = (string) ($context['userId'] ?? '');
        $user = $this->verify($install, $userId);

        return DB::transaction(function () use ($install, $userId, $user, $challenge, $parent, $encrypted): array {
            $nonce = OAuthState::where('state_hash', hash('sha256', $challenge))->where('provider', 'context')->lockForUpdate()->first();
            abort_unless($nonce && ! $nonce->consumed_at && $nonce->expires_at->isFuture(), 403, 'The embedded launch expired. Refresh Everbranch.');
            // Reject replayed context across launches; no credentials or context
            // are placed in cookies, localStorage, URLs or client error logs.
            abort_unless(Cache::add('hl:context:'.hash('sha256', $encrypted), true, now()->addDay()), 403, 'Request fresh CRM context and try again.');
            $nonce->update(['consumed_at' => now()]);
            $binding = UserBinding::where('installation_id', $install->id)->where('provider_user_id', $userId)->first();
            if (! $binding) {
                $actor = app(InstallationService::class)->shadowUser((string) ($user['name'] ?? 'HighLevel administrator'));
                $install->tenant->users()->attach($actor->id, ['role' => 'admin', 'membership_active' => true]);
                $binding = UserBinding::create(['installation_id' => $install->id, 'provider_user_id' => $userId,
                    'user_id' => $actor->id, 'role' => 'admin', 'verified_at' => now()]);
            } else {
                $binding->update(['role' => 'admin', 'verified_at' => now(), 'revoked_at' => null]);
            }
            $token = Str::random(80);
            $session = EmbeddedSession::create(['installation_id' => $install->id, 'binding_id' => $binding->id,
                'token_hash' => hash('sha256', $token), 'parent_origin' => $parent,
                'expires_at' => now()->addMinutes((int) config('highlevel.session_minutes', 15))]);

            return ['token' => $token, 'expires_at' => $session->expires_at->toIso8601String()];
        });
    }

    public function authenticate(string $token, string $parent): EmbeddedSession
    {
        abort_unless(strlen($token) === 80, 401);
        $session = EmbeddedSession::where('token_hash', hash('sha256', $token))->first();
        abort_unless($session && ! $session->revoked_at && $session->expires_at->isFuture()
            && $session->parent_origin === $parent && in_array($parent, config('highlevel.parent_origins', []), true), 401);
        $install = $session->installation;
        abort_unless($install->status === 'installed' && $install->tenant_id && ! $session->binding->revoked_at, 403);
        try {
            // Fresh provider authorization on every API request handles changed
            // administrator access immediately; provider outages fail closed.
            $this->verify($install, $session->binding->provider_user_id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if ($error->getStatusCode() === 403) {
                $session->binding->update(['revoked_at' => now()]);
                EmbeddedSession::where('binding_id', $session->binding_id)->update(['revoked_at' => now()]);
            }
            throw $error;
        }
        $session->binding->update(['verified_at' => now()]);

        return $session;
    }

    public function verify(Installation $install, string $userId): array
    {
        $agency = Authorization::where('app_id', $install->app_id)->where('company_id', $install->company_id)->where('status', 'authorized')->first();
        if ($agency) {
            return $this->api->verifiedAdmin($agency, $userId, $install->location_id);
        }

        return $this->api->verifiedAdmin($install, $userId, $install->location_id);
    }

    private function decrypt(string $encrypted): array
    {
        $secret = (string) config('highlevel.shared_secret');
        abort_if($secret === '' || strlen($encrypted) > 16384, 403);
        $decoded = base64_decode($encrypted, true);
        abort_unless(is_string($decoded) && strlen($decoded) >= 32 && substr($decoded, 0, 8) === 'Salted__', 403);
        // CryptoJS passphrase AES uses OpenSSL EVP_BytesToKey (MD5), AES-256-CBC.
        $salt = substr($decoded, 8, 8);
        $derived = '';
        $previous = '';
        while (strlen($derived) < 48) {
            $previous = md5($previous.$secret.$salt, true);
            $derived .= $previous;
        }
        $plain = openssl_decrypt(substr($decoded, 16), 'aes-256-cbc', substr($derived, 0, 32), OPENSSL_RAW_DATA, substr($derived, 32, 16));
        $data = is_string($plain) ? json_decode($plain, true) : null;
        abort_unless(is_array($data) && is_string($data['userId'] ?? null) && is_string($data['companyId'] ?? null)
            && is_string($data['activeLocation'] ?? null), 403);

        return $data;
    }
}
