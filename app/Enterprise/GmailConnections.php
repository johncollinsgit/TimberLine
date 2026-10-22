<?php

namespace App\Enterprise;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Signed-in user's send-only OAuth connection; no tenant grants, inbox reads or fallback sender. */
class GmailConnections
{
    public const CONNECTION = 'ent_gmail_connection';

    public const STATE = 'ent_gmail_state';

    public const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    public const USERINFO_URL = 'https://openidconnect.googleapis.com/v1/userinfo';

    public const REVOKE_URL = 'https://oauth2.googleapis.com/revoke';

    public const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/gmail.send'];

    public function __construct(private WorkspaceAccess $access, private RecordStore $store) {}

    public static function base64url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function settings(): ?array
    {
        $config = (array) config('enterprise.gmail', []);
        $redirect = $config['redirect_uri'] ?? null;
        $expected = MountUrls::url('/enterprise/gmail/callback');
        if ($expected === null || $redirect !== $expected || ! is_string($config['client_id'] ?? null) || ! preg_match('/^[a-zA-Z0-9._-]{1,255}$/D', $config['client_id'])
            || ! is_string($config['client_secret'] ?? null) || $config['client_secret'] === '' || strlen($config['client_secret']) > 4096) {
            return null;
        }

        return ['client_id' => $config['client_id'], 'client_secret' => $config['client_secret'], 'redirect_uri' => $redirect];
    }

    public function configured(): bool
    {
        return (bool) config('enterprise.gmail.connection_enabled') && $this->settings() !== null;
    }

    public function status(Tenant $tenant, User $user, string $areaId): array
    {
        $actor = $this->actor($tenant, $user, $areaId, false, false);
        $allowed = $this->configured() && in_array((string) $tenant->id, array_map('strval', (array) config('enterprise.gmail.tenant_allowlist', [])), true);
        $canConnect = $allowed && config('enterprise.writes_enabled') && WorkspaceAccess::permits($actor, $areaId, true);
        $items = $canConnect ? $this->mailboxes($tenant, $user, $areaId) : [];
        $ownedConnection = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::CONNECTION,
            'source_key' => hash('sha256', 'gmail:'.$user->id.':'.$areaId)])->first();
        $revocationPending = $ownedConnection && isset($ownedConnection->data['revocation_claim']);
        if ($revocationPending) {
            $canConnect = false;
            $items = [];
        }

        return ['configured' => (bool) $allowed, 'can_connect' => (bool) $canConnect, 'connected' => $items !== [],
            'connection_id' => $ownedConnection?->id, 'connection_status' => $ownedConnection?->status,
            'address' => $items[0]['address'] ?? null, 'send_enabled' => $canConnect && (bool) config('enterprise.gmail.send_enabled'),
            'scopes' => self::SCOPES, 'mailboxes' => array_map(fn ($item) => ['id' => $item['id'], 'address' => $item['address']], $items),
            'status' => $revocationPending ? 'Google revocation is pending or uncertain. The mailbox remains locally disconnected; reconcile revocation before reconnecting.'
                : (! $allowed ? 'Gmail send-only connection requires an approved Enterprise Google client and exact callback registration.'
                : (! $canConnect ? 'Mailbox connection changes are unavailable in your current role or while Enterprise writes are paused.'
                    : ($items ? 'Your verified send-only Gmail connection is available. Sending requires an exact draft and separate confirmation.'
                    : 'Connect your own Google mailbox matching your current verified Enterprise email. No mailbox is connected.'))),
            'live_delivery_verified' => false];
    }

    private function fingerprint(): string
    {
        return hash('sha256', json_encode([$this->settings(), config('app.env'), self::SCOPES, MountUrls::context()], JSON_THROW_ON_ERROR));
    }

    private function actor(Tenant $tenant, User $user, string $areaId, bool $configured = true, bool $write = true): array
    {
        $actor = $this->access->actor($tenant, $user, $write);
        $this->access->area($tenant, $actor, $areaId);
        abort_unless(WorkspaceAccess::permits($actor, $areaId, $write), 404);
        $fresh = $user->fresh();
        abort_unless($fresh && $fresh->is_active && $fresh->email_verified_at !== null && filter_var($fresh->email, FILTER_VALIDATE_EMAIL), 403);
        if ($configured) {
            abort_unless($this->configured() && in_array((string) $tenant->id, array_map('strval', (array) config('enterprise.gmail.tenant_allowlist', [])), true), 503, 'Gmail connection is not configured for this Enterprise tenant.');
        }

        return $actor;
    }

    private function sessionHash(Request $request, bool $create = false): string
    {
        abort_unless($request->hasSession() && $request->session()->getId() !== '', 403, 'The original signed-in browser session is required.');
        $nonce = $request->session()->get('enterprise.gmail_browser');
        if ($create && ! is_string($nonce)) {
            $nonce = self::base64url(random_bytes(32));
            $request->session()->put('enterprise.gmail_browser', $nonce);
        }
        abort_unless(is_string($nonce) && strlen($nonce) === 43, 403, 'The original signed-in browser session is required.');

        return hash('sha256', $request->session()->getId().'|'.$nonce);
    }

    public function connect(Tenant $tenant, User $user, string $areaId, Request $request): array
    {
        abort_unless(MountUrls::matches($request), 403, 'Use the canonical Enterprise mount.');
        $this->actor($tenant, $user, $areaId);
        abort_unless((string) $request->user()?->id === (string) $user->id, 403);
        $settings = $this->settings();
        $session = $this->sessionHash($request, true);
        $stateId = (string) Str::uuid();
        $state = $stateId.'.'.self::base64url(random_bytes(32));
        $verifier = self::base64url(random_bytes(64));
        $expires = now()->addMinutes(10)->toIso8601String();
        $this->store->locked($tenant, function () use ($tenant, $user, $areaId, $session, $stateId, $state, $verifier, $expires): void {
            $this->actor($tenant, $user, $areaId);
            $source = hash('sha256', 'gmail:'.$user->id.':'.$areaId);
            $connection = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::CONNECTION, 'source_key' => $source])->first();
            abort_if($connection && isset($connection->data['revocation_claim']), 409, 'Google revocation is pending or uncertain. Reconcile it before reconnecting.');
            $generation = (string) Str::uuid();
            if (! $connection) {
                $connection = new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $areaId,
                    'kind' => self::CONNECTION, 'source_key' => $source, 'visibility' => 'area', 'title' => 'Private Gmail connection', 'version' => 0]);
            }
            // Reconnect starts a new local authority epoch, invalidating previous previews and OAuth states.
            $connection->data = ['user_id' => (string) $user->id, 'email' => strtolower($user->fresh()->email),
                'generation' => $generation, 'config_hash' => $this->fingerprint()];
            $connection->status = 'pending_connection';
            $connection->version++;
            $connection->save();
            $this->store->audit($connection, $user->id, 'gmail.connection_started');
            $row = TeamOperationRecord::query()->create(['id' => $stateId, 'tenant_id' => $tenant->id, 'area_id' => $areaId,
                'kind' => self::STATE, 'source_key' => hash('sha256', $state), 'visibility' => 'area', 'title' => 'Private OAuth state', 'status' => 'pending', 'version' => 1,
                'data' => ['user_id' => (string) $user->id, 'email' => strtolower($user->fresh()->email), 'session_hash' => $session,
                    'config_hash' => $this->fingerprint(), 'state_hash' => hash('sha256', $state), 'verifier' => $verifier,
                    'expires_at' => $expires, 'connection_id' => $connection->id, 'generation' => $generation]]);
            $this->store->audit($row, $user->id, 'gmail.oauth_started');
        });
        $url = 'https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query(['client_id' => $settings['client_id'],
            'redirect_uri' => $settings['redirect_uri'], 'response_type' => 'code', 'scope' => implode(' ', self::SCOPES),
            'state' => $state, 'code_challenge' => self::base64url(hash('sha256', $verifier, true)), 'code_challenge_method' => 'S256',
            'access_type' => 'offline', 'prompt' => 'consent select_account', 'include_granted_scopes' => 'false', 'login_hint' => strtolower($user->fresh()->email)], '', '&', PHP_QUERY_RFC3986);

        return ['authorization_url' => $url, 'state_expires_at' => $expires];
    }

    public function callback(Request $request): array
    {
        abort_unless(MountUrls::matches($request), 403, 'Use the canonical Enterprise mount.');
        $input = $request->validate(['state' => ['required', 'string', 'regex:/^[a-f0-9-]{36}\.[A-Za-z0-9_-]{43}$/D'],
            'code' => ['nullable', 'string', 'max:4096'], 'error' => ['nullable', 'string', 'max:100']]);
        $stateId = explode('.', $input['state'], 2)[0];
        $row = TeamOperationRecord::query()->where('kind', self::STATE)->findOrFail($stateId);
        $tenant = Tenant::query()->findOrFail($row->tenant_id);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $data = $row->data;
        $this->actor($tenant, $user, $row->area_id);
        abort_unless($request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo() === $this->settings()['redirect_uri'], 403, 'Use the exact registered Enterprise callback.');
        abort_unless($data['user_id'] === (string) $user->id && hash_equals($data['state_hash'], hash('sha256', $input['state']))
            && hash_equals($data['session_hash'], $this->sessionHash($request)) && $data['email'] === strtolower($user->fresh()->email), 403, 'The OAuth browser or signed-in identity changed.');
        abort_unless(! $request->has('tenant') || $request->query('tenant') === $tenant->slug, 403);
        abort_unless(! $request->has('area_id') || $request->query('area_id') === $row->area_id, 403);
        abort_unless(hash_equals($data['config_hash'], $this->fingerprint()), 409, 'The Google client or callback configuration changed. Start again.');
        $row = $this->store->locked($tenant, function () use ($tenant, $user, $row, $input) {
            $this->actor($tenant, $user, $row->area_id);
            $state = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::STATE])->findOrFail($row->id);
            abort_unless($state->status === 'pending' && now()->lt(\Carbon\CarbonImmutable::parse($state->data['expires_at'])), 409, 'This OAuth state was used or expired. Start again.');
            $connection = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::CONNECTION])->findOrFail($state->data['connection_id']);
            abort_unless($connection->status === 'pending_connection' && $connection->data['generation'] === $state->data['generation'], 409, 'A newer connection attempt superseded this OAuth state.');
            $state->status = isset($input['error']) || ! filled($input['code'] ?? null) ? 'denied' : 'exchanging';
            $state->version++;
            $state->save();
            $this->store->audit($state, $user->id, 'gmail.oauth_'.$state->status);

            return $state;
        });
        $result = ['tenant_slug' => $tenant->slug, 'area_id' => $row->area_id, 'connection_id' => $data['connection_id']];
        if ($row->status === 'denied') {
            $this->closeState($tenant, $user, $row->id, 'denied');

            return $result + ['status' => 'cancelled'];
        }
        try {
            $this->actor($tenant, $user, $row->area_id);
            abort_unless(hash_equals($data['config_hash'], $this->fingerprint()), 409);
            $settings = $this->settings();
            $response = Http::asForm()->acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)
                ->post(self::TOKEN_URL, ['client_id' => $settings['client_id'], 'client_secret' => $settings['client_secret'],
                    'redirect_uri' => $settings['redirect_uri'], 'code' => $input['code'], 'code_verifier' => $data['verifier'], 'grant_type' => 'authorization_code'])->throw()->json();
            $token = $this->tokens($response, true);
            $identity = $this->identity($token['access_token']);
            abort_unless($identity['email'] === $data['email'], 403);
            $this->store->locked($tenant, function () use ($tenant, $user, $row, $data, $token, $identity): void {
                $this->actor($tenant, $user, $row->area_id);
                abort_unless(strtolower($user->fresh()->email) === $identity['email'] && hash_equals($data['config_hash'], $this->fingerprint()), 409);
                $connection = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::CONNECTION])->findOrFail($data['connection_id']);
                abort_unless($connection->status === 'pending_connection' && $connection->data['generation'] === $data['generation'], 409);
                $connection->data = array_replace($connection->data, $token, ['subject' => $identity['sub'], 'email' => $identity['email'], 'connected_at' => now()->toIso8601String()]);
                $connection->status = 'connected';
                $connection->version++;
                $connection->save();
                $this->store->audit($connection, $user->id, 'gmail.connected', ['scopes' => self::SCOPES]);
            });
            $this->closeState($tenant, $user, $row->id, 'completed');
        } catch (\Throwable) {
            $this->closeState($tenant, $user, $row->id, 'uncertain');
            abort(503, 'Gmail connection could not be verified. This one-use OAuth attempt is closed; start a fresh connection. No email was sent.');
        }

        return $result + ['status' => 'connected'];
    }

    private function closeState(Tenant $tenant, User $user, string $id, string $status): void
    {
        $this->store->locked($tenant, function () use ($tenant, $user, $id, $status): void {
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::STATE])->findOrFail($id);
            $data = $row->data;
            unset($data['verifier']);
            $row->data = $data;
            $row->status = $status;
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'gmail.oauth_'.$status);
        });
    }

    private function scopes(string $scope): void
    {
        $scopes = array_map(fn ($item) => $item === 'email' ? 'https://www.googleapis.com/auth/userinfo.email' : $item, preg_split('/\s+/', trim($scope)));
        $expected = ['openid', 'https://www.googleapis.com/auth/userinfo.email', 'https://www.googleapis.com/auth/gmail.send'];
        sort($scopes);
        sort($expected);
        abort_unless($scopes === $expected, 503, 'The granted Gmail scopes differ from the requested send-only scopes.');
    }

    private function tokenString(mixed $value): bool
    {
        return is_string($value) && strlen($value) >= 1 && strlen($value) <= 8192 && preg_match('/^[A-Za-z0-9._~+\/=\-]+$/D', $value);
    }

    private function tokens(mixed $response, bool $refreshRequired): array
    {
        abort_unless(is_array($response) && $this->tokenString($response['access_token'] ?? null) && ($response['token_type'] ?? '') === 'Bearer'
            && is_int($response['expires_in'] ?? null) && $response['expires_in'] >= 60 && $response['expires_in'] <= 86400, 503);
        if ($refreshRequired || isset($response['refresh_token'])) {
            abort_unless($this->tokenString($response['refresh_token'] ?? null), 503, 'Offline Gmail permission requires a refresh token.');
        }
        if ($refreshRequired || isset($response['scope'])) {
            abort_unless(is_string($response['scope'] ?? null), 503);
            $this->scopes($response['scope']);
        }

        return ['access_token' => $response['access_token'], 'expires_at' => now()->addSeconds($response['expires_in'])->toIso8601String(),
            ...(isset($response['refresh_token']) ? ['refresh_token' => $response['refresh_token']] : [])];
    }

    private function identity(string $accessToken): array
    {
        $identity = Http::withToken($accessToken)->acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(10)->get(self::USERINFO_URL)->throw()->json();
        abort_unless(is_array($identity) && is_string($identity['sub'] ?? null) && preg_match('/^[A-Za-z0-9_-]{1,255}$/D', $identity['sub'])
            && ($identity['email_verified'] ?? null) === true && is_string($identity['email'] ?? null) && filter_var($identity['email'], FILTER_VALIDATE_EMAIL), 503);

        return ['sub' => $identity['sub'], 'email' => strtolower($identity['email'])];
    }

    public function connection(Tenant $tenant, User $user, string $areaId, string $id): TeamOperationRecord
    {
        $this->actor($tenant, $user, $areaId);
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::CONNECTION])->findOrFail($id);
        $data = $row->data;
        abort_unless($row->status === 'connected' && $data['user_id'] === (string) $user->id && $data['email'] === strtolower($user->fresh()->email)
            && hash_equals($data['config_hash'], $this->fingerprint()) && isset($data['subject'], $data['refresh_token'], $data['access_token']), 404, 'Reconnect your own Gmail mailbox.');

        return $row;
    }

    public function mailboxes(Tenant $tenant, User $user, string $areaId): array
    {
        $this->actor($tenant, $user, $areaId, false);
        if (! $this->configured() || ! in_array((string) $tenant->id, array_map('strval', (array) config('enterprise.gmail.tenant_allowlist', [])), true)) {
            return [];
        }
        $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::CONNECTION,
            'source_key' => hash('sha256', 'gmail:'.$user->id.':'.$areaId)])->first();
        if (! $row) {
            return [];
        }
        try {
            $row = $this->connection($tenant, $user, $areaId, $row->id);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if ($error->getStatusCode() !== 404) {
                throw $error;
            }

            return [];
        }
        $data = $row->data;

        return [['id' => $row->id, 'tenant_id' => (string) $tenant->id, 'user_id' => (string) $user->id, 'area_id' => $areaId,
            'address' => $data['email'], 'verified' => true, 'permission' => 'send',
            'binding_version' => hash('sha256', json_encode([$row->id, $data['generation'], $data['subject'], $data['email'], $data['config_hash']], JSON_THROW_ON_ERROR))]];
    }

    public function accessToken(Tenant $tenant, User $user, string $areaId, string $id): string
    {
        $claim = $this->store->locked($tenant, function () use ($tenant, $user, $areaId, $id): array {
            $row = $this->connection($tenant, $user, $areaId, $id);
            $data = $row->data;
            if (now()->addMinute()->lt(\Carbon\CarbonImmutable::parse($data['expires_at']))) {
                return ['row' => $row, 'refresh' => false];
            }
            abort_if(isset($data['refresh_claim']), 409, 'Gmail refresh is already claimed. Do not retry; reconnect if it does not resolve.');
            $data['refresh_claim'] = (string) Str::uuid();
            $row->data = $data;
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'gmail.refresh_claimed');

            return ['row' => $row, 'refresh' => true];
        });
        $row = $claim['row'];
        if (! $claim['refresh']) {
            return $row->data['access_token'];
        }
        $data = $row->data;
        try {
            $this->connection($tenant, $user, $areaId, $id);
            $settings = $this->settings();
            $response = Http::asForm()->acceptJson()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)
                ->post(self::TOKEN_URL, ['client_id' => $settings['client_id'], 'client_secret' => $settings['client_secret'],
                    'refresh_token' => $data['refresh_token'], 'grant_type' => 'refresh_token'])->throw()->json();
            $tokens = $this->tokens($response, false);
            $identity = $this->identity($tokens['access_token']);
            abort_unless($identity['sub'] === $data['subject'] && $identity['email'] === $data['email'], 403);
            $this->store->locked($tenant, function () use ($tenant, $user, $areaId, $id, $data, $tokens): void {
                $row = $this->connection($tenant, $user, $areaId, $id);
                abort_unless($row->data['generation'] === $data['generation'] && ($row->data['refresh_claim'] ?? null) === $data['refresh_claim'], 409);
                $next = array_replace($row->data, $tokens);
                unset($next['refresh_claim']);
                $row->data = $next;
                $row->version++;
                $row->save();
                $this->store->audit($row, $user->id, 'gmail.refreshed');
            });
        } catch (\Throwable) {
            $this->invalidate($tenant, $user, $id, $data['generation'], 'refresh_unresolved');
            abort(503, 'Gmail refresh failed or is uncertain. Reconnect your mailbox; no automatic retry was made.');
        }

        return $this->connection($tenant, $user, $areaId, $id)->data['access_token'];
    }

    public function invalidate(Tenant $tenant, User $user, string $id, string $generation, string $reason): void
    {
        $this->store->locked($tenant, function () use ($tenant, $user, $id, $generation, $reason): void {
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::CONNECTION])->findOrFail($id);
            if ($row->data['user_id'] !== (string) $user->id || $row->data['generation'] !== $generation) {
                return;
            }
            $row->data = array_intersect_key($row->data, array_flip(['user_id', 'email', 'config_hash']));
            $row->data = $row->data + ['generation' => (string) Str::uuid()];
            $row->status = 'reconnect_required';
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'gmail.'.$reason);
        });
    }

    public function disconnect(Tenant $tenant, User $user, string $areaId, string $id): array
    {
        $this->actor($tenant, $user, $areaId, false, false);
        $claim = $this->store->locked($tenant, function () use ($tenant, $user, $areaId, $id): array {
            $this->actor($tenant, $user, $areaId, false, false);
            $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'area_id' => $areaId, 'kind' => self::CONNECTION])->findOrFail($id);
            abort_unless($row->data['user_id'] === (string) $user->id, 404);
            if (isset($row->data['revocation_claim'])) {
                return ['token' => null, 'pending' => true];
            }
            $token = $row->data['refresh_token'] ?? $row->data['access_token'] ?? null;
            $generation = (string) Str::uuid();
            $row->data = ['user_id' => (string) $user->id, 'email' => strtolower($user->fresh()->email), 'generation' => $generation,
                ...($token ? ['revocation_claim' => (string) Str::uuid()] : [])];
            $row->status = 'disconnected';
            $row->version++;
            $row->save();
            $this->store->audit($row, $user->id, 'gmail.disconnected');

            return ['token' => $token, 'pending' => false, 'generation' => $generation];
        });
        $outcome = $claim['pending'] ? 'uncertain' : ($claim['token'] ? $this->revoke($claim['token']) : 'not_requested');
        if (! $claim['pending'] && $outcome === 'accepted') {
            $this->store->locked($tenant, function () use ($tenant, $user, $id, $claim): void {
                $row = TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => self::CONNECTION])->findOrFail($id);
                if ($row->data['user_id'] === (string) $user->id && $row->data['generation'] === $claim['generation']) {
                    $data = $row->data;
                    unset($data['revocation_claim']);
                    $row->data = $data;
                    $row->version++;
                    $row->save();
                    $this->store->audit($row, $user->id, 'gmail.revocation_accepted');
                }
            });
        }

        return ['status' => 'disconnected', 'provider_revocation' => $outcome];
    }

    private function revoke(string $token): string
    {
        try {
            $response = Http::asForm()->withOptions(['allow_redirects' => false])->connectTimeout(3)->timeout(8)->post(self::REVOKE_URL, ['token' => $token]);

            return $response->status() === 200 ? 'accepted' : 'uncertain';
        } catch (\Throwable) {
            return 'uncertain';
        }
    }
}
