<?php

namespace App\Services\Wholesale\Gmail;

use App\Models\TeamOperationRecord;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

class WholesaleGmailConnection
{
    public const SCOPES = ['openid', 'email', 'https://www.googleapis.com/auth/gmail.send', 'https://www.googleapis.com/auth/gmail.settings.basic'];

    public const TOKEN = 'https://oauth2.googleapis.com/token';

    public const IDENTITY = 'https://openidconnect.googleapis.com/v1/userinfo';

    public const ALIASES = 'https://gmail.googleapis.com/gmail/v1/users/me/settings/sendAs';

    public function __construct(private WholesaleGmailJournal $journal) {}

    public static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public function settings(int $tenant, string $shop): array
    {
        $c = config('wholesale_gmail');
        $binding = $c['tenants'][(string) $tenant] ?? [];
        $expected = rtrim((string) config('app.url'), '/').'/wholesale/gmail/callback';
        abort_unless(($c['connection_enabled'] ?? false) && str_starts_with($expected, 'https://')
            && ($c['redirect_uri'] ?? '') === $expected && filled($c['client_id'] ?? null) && filled($c['client_secret'] ?? null)
            && ($binding['shop'] ?? '') === $shop, 503, 'Gmail setup is required for this wholesale store.');
        foreach (['mailbox', 'from', 'reply_to'] as $field) {
            abort_unless(filter_var($binding[$field] ?? '', FILTER_VALIDATE_EMAIL), 503, 'The Gmail sender binding is incomplete.');
            $binding[$field] = strtolower($binding[$field]);
        }
        abort_unless(is_string($binding['name'] ?? null) && strlen($binding['name']) <= 100 && ! preg_match('/[\r\n]/', $binding['name']), 503);
        abort_unless(is_string($binding['mailing_address'] ?? null) && trim($binding['mailing_address']) !== '' && mb_strlen($binding['mailing_address']) <= 300, 503, 'The business mailing address is required.');
        abort_if(config('wholesale_gmail.test_enabled') && ! filter_var($binding['test_recipient'] ?? '', FILTER_VALIDATE_EMAIL), 503, 'Set an approved internal test recipient first.');
        $config = ['client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'redirect_uri' => $expected, 'binding' => $binding];
        $config['fingerprint'] = hash('sha256', json_encode($config, JSON_THROW_ON_ERROR));

        return $config;
    }

    public function connection(int $tenant, string $shop): ?TeamOperationRecord
    {
        return $this->journal->rows($tenant, 'connection')->where('source_key', hash('sha256', $shop))->first();
    }

    public function status(int $tenant, string $shop): array
    {
        $row = $this->connection($tenant, $shop);
        $canDisconnect = $row && $row->status !== 'disconnected';
        try {
            $c = $this->settings($tenant, $shop);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
            return ['configured' => false, 'connected' => false, 'can_disconnect' => $canDisconnect, 'can_review' => false, 'can_test' => false, 'can_send' => false, 'message' => 'Connect Gmail after your administrator completes sender setup.'];
        }
        $row = $this->connection($tenant, $shop);
        $connected = $row?->status === 'connected' && hash_equals($c['fingerprint'], $row->data['fingerprint'] ?? '');

        return ['configured' => true, 'connected' => $connected, 'can_disconnect' => $canDisconnect, 'can_review' => $connected && (config('wholesale_gmail.send_enabled') || config('wholesale_gmail.test_enabled')), 'can_test' => $connected && config('wholesale_gmail.test_enabled'), 'test_recipient' => config('wholesale_gmail.test_enabled') ? ($c['binding']['test_recipient'] ?? null) : null, 'can_send' => $connected && config('wholesale_gmail.send_enabled'),
            'from' => $c['binding']['from'], 'reply_to' => $c['binding']['reply_to'], 'name' => $c['binding']['name'],
            'message' => $connected ? (config('wholesale_gmail.send_enabled') ? 'Ready to send from your verified address.' : 'Gmail is connected. Sending awaits activation.') : 'Connect the approved Google account to send here.'];
    }

    public function begin(int $tenant, int $actor, string $shop): string
    {
        $this->journal->operator($tenant, $actor, $shop);
        $c = $this->settings($tenant, $shop);
        $launch = Str::random(64);
        $state = $this->journal->locked($tenant, function () use ($tenant, $actor, $shop, $c, $launch) {
            $connection = $this->connection($tenant, $shop) ?? $this->journal->create($tenant, 'connection', hash('sha256', $shop), [], 'disconnected');
            abort_if($connection->status === 'refreshing', 409, 'A mailbox update is still in progress.');
            $generation = (string) Str::uuid();
            $connection->update(['status' => 'connecting', 'data' => ['shop' => $shop, 'generation' => $generation, 'fingerprint' => $c['fingerprint']]]);
            $state = $this->journal->create($tenant, 'state', hash('sha256', Str::random(64)), [
                'actor' => $actor, 'shop' => $shop, 'generation' => $generation, 'fingerprint' => $c['fingerprint'],
                'launch_hash' => hash('sha256', $launch), 'verifier' => Str::random(64), 'cookie' => Str::random(64),
                'oauth_state' => Str::random(64), 'expires' => now()->addMinutes(10)->timestamp,
            ], 'created');
            $this->journal->audit($tenant, $actor, 'connect_started', $state->id);

            return $state;
        });

        return rtrim((string) config('app.url'), '/').'/wholesale/gmail/start/'.$state->id.'?launch='.rawurlencode($launch);
    }

    public function cookieName(string $state): string
    {
        return '__Host-wgmail-'.substr(hash('sha256', $state), 0, 16);
    }

    public function browserCookie(string $id, string $value, bool $expire = false): Cookie
    {
        // Bypass CookieJar's shared SESSION_DOMAIN. __Host- cookies must never have a Domain attribute.
        return Cookie::create($this->cookieName($id), $value, $expire ? 1 : now()->addMinutes(10), '/', null, true, true, false, Cookie::SAMESITE_LAX);
    }

    private function state(string $id): TeamOperationRecord
    {
        return TeamOperationRecord::query()->where(['area_id' => WholesaleGmailJournal::AREA, 'kind' => 'wg_state'])->findOrFail($id);
    }

    private function validateState(TeamOperationRecord $state): array
    {
        $d = $state->data;
        $this->journal->operator($state->tenant_id, $d['actor'], $d['shop']);
        $c = $this->settings($state->tenant_id, $d['shop']);
        $connection = $this->connection($state->tenant_id, $d['shop']);
        abort_unless(($d['expires'] ?? 0) > now()->timestamp && hash_equals($c['fingerprint'], $d['fingerprint'])
            && ($connection?->data['generation'] ?? null) === $d['generation'], 409, 'This connection attempt expired or was replaced. Start again from Backstage.');

        return $c;
    }

    public function launch(Request $request, string $id)
    {
        $state = $this->state($id);
        abort_unless($request->getSchemeAndHttpHost() === rtrim((string) config('app.url'), '/'), 403);
        $c = $this->validateState($state);
        $d = $this->journal->locked($state->tenant_id, function () use ($id, $request) {
            $state = $this->state($id);
            $this->validateState($state);
            $d = $state->data;
            abort_unless($state->status === 'created' && hash_equals($d['launch_hash'], hash('sha256', (string) $request->query('launch'))), 403);
            unset($d['launch_hash']);
            $state->update(['status' => 'authorizing', 'data' => $d]);

            return $d;
        });
        $query = ['client_id' => $c['client_id'], 'redirect_uri' => $c['redirect_uri'], 'response_type' => 'code',
            'scope' => implode(' ', self::SCOPES), 'access_type' => 'offline', 'prompt' => 'consent select_account',
            'include_granted_scopes' => 'false', 'login_hint' => $c['binding']['mailbox'], 'state' => $id.'.'.$d['oauth_state'],
            'code_challenge' => self::encode(hash('sha256', $d['verifier'], true)), 'code_challenge_method' => 'S256'];

        return redirect()->away('https://accounts.google.com/o/oauth2/v2/auth?'.http_build_query($query, '', '&', PHP_QUERY_RFC3986))
            ->withCookie($this->browserCookie($id, $d['cookie']));
    }

    private function identity(string $access, array $c): array
    {
        $identity = Http::withToken($access)->withOptions(['allow_redirects' => false])->timeout(15)->get(self::IDENTITY)->throw()->json();
        abort_unless(($identity['email_verified'] ?? false) === true && filled($identity['sub'] ?? null)
            && strtolower($identity['email'] ?? '') === $c['binding']['mailbox'], 403, 'Choose the approved Google account.');
        // Only read this endpoint. Never create/update aliases or other Gmail settings.
        $aliases = Http::withToken($access)->withOptions(['allow_redirects' => false])->timeout(15)->get(self::ALIASES)->throw()->json('sendAs');
        $alias = collect($aliases)->first(fn ($a) => strtolower($a['sendAsEmail'] ?? '') === $c['binding']['from']);
        abort_unless($alias && (($alias['verificationStatus'] ?? '') === 'accepted' || ($alias['isPrimary'] ?? false)), 403, 'The approved From address must be verified in Gmail.');

        return $identity;
    }

    private function tokens(array $value, array $c, ?string $refresh = null): array
    {
        $scopes = preg_split('/\s+/', trim((string) ($value['scope'] ?? '')));
        $scopes = array_values(array_unique(array_map(fn ($s) => $s === 'https://www.googleapis.com/auth/userinfo.email' ? 'email' : $s, $scopes)));
        $expected = self::SCOPES;
        sort($scopes);
        sort($expected);
        abort_unless($scopes === $expected && filled($value['access_token'] ?? null) && ($value['token_type'] ?? '') === 'Bearer'
            && is_numeric($value['expires_in'] ?? null) && $value['expires_in'] >= 60 && $value['expires_in'] <= 86400
            && filled($value['refresh_token'] ?? $refresh), 403, 'Google did not grant the required mailbox permission.');
        $identity = $this->identity($value['access_token'], $c);

        return ['access' => $value['access_token'], 'refresh' => $value['refresh_token'] ?? $refresh,
            'expires' => now()->addSeconds((int) $value['expires_in'])->timestamp, 'subject' => $identity['sub']];
    }

    public function callback(Request $request): string
    {
        [$id, $nonce] = array_pad(explode('.', (string) $request->query('state'), 2), 2, '');
        abort_unless(Str::isUuid($id) && $request->url() === config('wholesale_gmail.redirect_uri'), 403);
        $state = $this->state($id);
        $d = $this->journal->locked($state->tenant_id, function () use ($id, $nonce, $request) {
            $state = $this->state($id);
            $this->validateState($state);
            $d = $state->data;
            abort_unless($state->status === 'authorizing' && hash_equals($d['oauth_state'], $nonce)
                && hash_equals($d['cookie'], (string) $request->cookie($this->cookieName($id))), 403);
            $state->update(['status' => 'exchanging']);

            return $d;
        });
        try {
            abort_if($request->has('error') || ! $request->filled('code'), 403);
            $c = $this->validateState($state);
            $value = Http::asForm()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)->post(self::TOKEN, [
                'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'redirect_uri' => $c['redirect_uri'],
                'grant_type' => 'authorization_code', 'code' => $request->query('code'), 'code_verifier' => $d['verifier'],
            ])->throw()->json();
            $tokens = $this->tokens($value, $c);
            $this->journal->locked($state->tenant_id, function () use ($state, $d, $tokens) {
                $this->validateState($state);
                $connection = $this->connection($state->tenant_id, $d['shop']);
                $connection->update(['status' => 'connected', 'data' => [...$connection->data, ...$tokens]]);
                $this->journal->audit($state->tenant_id, $d['actor'], 'connected', $connection->id);
            });
        } catch (\Throwable) {
            $state->update(['status' => 'failed', 'data' => array_intersect_key($d, array_flip(['actor', 'shop', 'generation', 'fingerprint', 'expires']))]);
            abort(422, 'Gmail could not connect. Return to Backstage and start a new connection.');
        }
        $state->update(['status' => 'completed', 'data' => array_intersect_key($d, array_flip(['actor', 'shop', 'generation', 'fingerprint', 'expires']))]);

        return $id;
    }

    public function accessToken(int $tenant, int $actor, string $shop): array
    {
        $this->journal->operator($tenant, $actor, $shop);
        $c = $this->settings($tenant, $shop);
        $row = $this->journal->locked($tenant, function () use ($tenant, $shop, $c) {
            $row = $this->connection($tenant, $shop);
            abort_unless($row?->status === 'connected' && hash_equals($c['fingerprint'], $row->data['fingerprint'] ?? ''), 409, 'Reconnect Gmail before sending.');
            if ($row->data['expires'] <= now()->addSeconds(60)->timestamp) {
                $row->update(['status' => 'refreshing']);
            }

            return $row;
        });
        if ($row->status === 'refreshing') {
            try {
                $value = Http::asForm()->withOptions(['allow_redirects' => false])->connectTimeout(5)->timeout(20)->post(self::TOKEN, [
                    'client_id' => $c['client_id'], 'client_secret' => $c['client_secret'], 'grant_type' => 'refresh_token', 'refresh_token' => $row->data['refresh'],
                ])->throw()->json();
                $tokens = $this->tokens($value, $c, $row->data['refresh']);
                abort_unless($tokens['subject'] === $row->data['subject'], 403);
                $this->journal->locked($tenant, function () use ($row, $tokens, $actor, $shop, $c) {
                    $this->journal->operator($row->tenant_id, $actor, $shop);
                    abort_unless($this->settings($row->tenant_id, $shop)['fingerprint'] === $c['fingerprint'], 409);
                    $fresh = $row->fresh();
                    abort_unless($fresh->status === 'refreshing' && $fresh->data['generation'] === $row->data['generation'], 409);
                    $row->update(['status' => 'connected', 'data' => [...$row->data, ...$tokens]]);
                });
            } catch (\Throwable) {
                $this->invalidate($tenant, $shop, $row->data['generation']);
                abort(409, 'Gmail authorization needs reconnecting. No email was sent.');
            }
        }
        // Current identity and alias are rechecked before every message, including unexpired tokens.
        $identity = $this->identity($row->data['access'], $c);
        abort_unless($identity['sub'] === $row->data['subject'], 403);

        return ['token' => $row->data['access'], 'generation' => $row->data['generation'], 'config' => $c];
    }

    public function invalidate(int $tenant, string $shop, string $generation): void
    {
        $this->journal->locked($tenant, function () use ($tenant, $shop, $generation) {
            $row = $this->connection($tenant, $shop);
            if ($row && ($row->data['generation'] ?? null) === $generation) {
                $row->update(['status' => 'disconnected', 'data' => ['shop' => $shop, 'generation' => (string) Str::uuid()]]);
            }
        });
    }

    public function disconnect(int $tenant, int $actor, string $shop): void
    {
        $this->journal->operator($tenant, $actor, $shop);
        $this->journal->locked($tenant, function () use ($tenant, $actor, $shop) {
            if ($row = $this->connection($tenant, $shop)) {
                $row->update(['status' => 'disconnected', 'data' => ['shop' => $shop, 'generation' => (string) Str::uuid()]]);
                $this->journal->audit($tenant, $actor, 'disconnected', $row->id);
            }
        });
    }
}
