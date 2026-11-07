<?php

namespace App\Services\Marketing;

use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Pos\PosException;
use App\Services\Pos\PosStore;
use App\Services\Pos\SquareClient;
use App\Services\Pos\SquareMerchantOwnership;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Core customer-source OAuth only. Never provisions a register or changes rewards. */
final class SquareCustomerOAuth
{
    public const KEY = 'square_config';

    public const SESSION = 'core_square_customer_oauth';

    public const SCOPES = ['MERCHANT_PROFILE_READ', 'CUSTOMERS_READ', 'ORDERS_READ', 'PAYMENTS_READ'];

    public function __construct(private PosStore $store, private SquareClient $square, private LandlordOperatorActionAuditService $audit) {}

    public function authorize(Tenant $tenant, User $actor, bool $locking = false): User
    {
        $current = User::whereKey($actor->id)->when($locking, fn ($q) => $q->lockForUpdate())->first();
        $membership = DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $actor->id)->when($locking, fn ($q) => $q->lockForUpdate())->first();
        abort_unless($current?->is_active && $current->hasVerifiedEmail() && $membership?->membership_active
            && in_array($membership->role, ['owner', 'tenant_owner', 'admin'], true)
            && app(CustomerProfileAccess::class)->allows($current, $tenant), 403);

        return $current;
    }

    public function status(Tenant $tenant, User $actor): array
    {
        $this->authorize($tenant, $actor);

        return ['configured' => $this->configured(), 'connection' => $this->publicState($this->setting($tenant->id)?->value ?? []),
            'requested_scopes' => self::SCOPES, 'scope_notice' => 'Read Square customers, orders, payments and available card metadata. Connecting does not enable checkout, rewards or messaging.'];
    }

    public function begin(Tenant $tenant, User $actor, Request $request): string
    {
        $this->authorize($tenant, $actor);
        abort_unless($this->configured(), 503, 'Square customer-source OAuth needs its registered HTTPS callback and existing application configuration.');
        $input = $request->validate(['expected_generation' => 'required|integer|min:0', 'request_id' => 'required|uuid']);
        $input['expected_generation'] = (int) $input['expected_generation'];
        $this->store->outsideTransaction();
        $original = $this->setting($tenant->id)?->value ?? [];
        $pinned = $this->originalBinding($tenant->id, $original);
        $state = Str::random(64);
        $pending = DB::transaction(function () use ($tenant, $actor, $request, $input, $state, $pinned): array {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $actor, true);
            $setting = $this->setting($tenant->id, true);
            $value = $setting?->value ?? [];
            abort_unless(($value['generation'] ?? 0) === $input['expected_generation'], 409, 'The Square connection changed. Refresh before connecting.');
            if (! empty($pinned['merchant_id'])) {
                abort_unless(empty($value['merchant_id']) || $value['merchant_id'] === $pinned['merchant_id'], 409, 'The original Square merchant changed.');
                $value['merchant_id'] = $pinned['merchant_id'];
                $value['environment'] = $pinned['environment'];
                $value['location_ids'] = $pinned['location_ids'];
            }
            $environment = $value['environment'] ?? config('customer_sources.square.environment');
            abort_unless(in_array($environment, ['production', 'sandbox'], true), 422, 'Choose a supported Square source environment.');
            $pending = ['id' => (string) Str::uuid(), 'request_id' => $input['request_id'], 'hash' => hash('sha256', $state),
                'session_hash' => hash('sha256', $request->session()->getId()), 'actor_id' => (int) $actor->id, 'tenant_id' => (int) $tenant->id,
                'expires' => now()->timestamp + 600, 'expected_generation' => $input['expected_generation'], 'environment' => $environment,
                'redirect_uri' => config('customer_sources.square.redirect_uri'), 'client_id' => config('pos_live.square_application_id'),
                'original_merchant_id' => $value['merchant_id'] ?? null, 'connection_id' => $value['connection_id'] ?? ((! empty($value['access_token']) || ! empty($value['credentials'])) ? 'tenant-config' : 'square-source:'.Str::uuid())];
            $value['pending_oauth'] = $pending;
            $this->save($tenant->id, $value, $setting);
            $this->record($tenant, $actor, 'square_customer_oauth_started', $pending['connection_id'], null, null, ['request_id' => $input['request_id']]);

            return $pending;
        }, 3);
        $request->session()->put(self::SESSION, $pending);

        return $this->origin($pending['environment']).'/oauth2/authorize?'.http_build_query(['client_id' => $pending['client_id'], 'scope' => implode(' ', self::SCOPES), 'state' => $state, 'session' => 'false', 'redirect_uri' => $pending['redirect_uri']]);
    }

    public function complete(User $actor, Request $request): array
    {
        $pending = $request->session()->pull(self::SESSION);
        $state = $request->query('state');
        abort_unless(is_array($pending) && is_string($state) && strlen($state) <= 256
            && hash_equals($pending['hash'] ?? '', hash('sha256', $state))
            && ($pending['actor_id'] ?? null) === (int) $actor->id && ($pending['expires'] ?? 0) > now()->timestamp
            && hash_equals($pending['session_hash'] ?? '', hash('sha256', $request->session()->getId())), 403);
        $tenant = Tenant::findOrFail($pending['tenant_id']);
        $this->authorize($tenant, $actor);
        abort_unless($this->configured() && $pending['client_id'] === config('pos_live.square_application_id')
            && $pending['redirect_uri'] === config('customer_sources.square.redirect_uri'), 409, 'OAuth application configuration changed. Start a fresh connection review.');
        DB::transaction(function () use ($tenant, $actor, $pending): void {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $actor, true);
            $setting = $this->setting($tenant->id, true);
            $value = $setting?->value ?? [];
            abort_unless(($value['generation'] ?? 0) === $pending['expected_generation'] && ($value['pending_oauth']['id'] ?? null) === $pending['id']
                && empty($value['pending_oauth']['claimed_at']), 409, 'This OAuth request is already used or superseded.');
            $value['pending_oauth']['claimed_at'] = now()->toIso8601String();
            $this->save($tenant->id, $value, $setting);
        }, 3);
        abort_if($request->filled('error'), 409, 'Square authorization was not completed. The existing connection is unchanged.');
        $code = $request->query('code');
        abort_unless(is_string($code) && $code !== '' && strlen($code) <= 1000, 422, 'Square did not return an authorization code.');
        $this->store->outsideTransaction();
        try {
            // No automatic retry: the consumed code belongs to this one request.
            $reply = Http::acceptJson()->connectTimeout(5)->timeout(20)->post($this->origin($pending['environment']).'/oauth2/token', [
                'client_id' => $pending['client_id'], 'client_secret' => config('pos_live.square_application_secret'), 'code' => $code,
                'grant_type' => 'authorization_code', 'redirect_uri' => $pending['redirect_uri']]);
            abort_unless($reply->successful(), 503, 'Square authorization is unconfirmed. Review the grant before starting another connection.');
            $token = $reply->json();
            abort_unless(is_array($token) && is_string($token['access_token'] ?? null) && strlen($token['access_token']) <= 10000 && $token['access_token'] !== ''
                && is_string($token['refresh_token'] ?? null) && $token['refresh_token'] !== '' && strlen($token['refresh_token']) <= 10000
                && $this->identifier($token['merchant_id'] ?? null) && is_string($token['expires_at'] ?? null)
                && strtotime($token['expires_at']) > now()->timestamp, 503, 'Square returned incomplete source authorization.');
            abort_unless(empty($pending['original_merchant_id']) || $pending['original_merchant_id'] === $token['merchant_id'], 409, 'Reconnect the original Square merchant. Existing history was not reassigned.');
            $credentials = $this->store->encrypt(array_intersect_key($token, array_flip(['access_token', 'refresh_token', 'expires_at'])));
            $temporary = (object) ['credentials' => $credentials, 'environment' => $pending['environment']];
            $grant = $this->square->post($temporary, '/oauth2/token/status', []);
            $scopes = $grant['scopes'] ?? [];
            abort_unless(is_array($scopes) && collect($scopes)->every(fn ($scope) => is_string($scope)) && ! array_diff(self::SCOPES, $scopes)
                && ($grant['merchant_id'] ?? null) === $token['merchant_id'] && ($grant['client_id'] ?? null) === $pending['client_id'], 409, 'Square did not verify the requested read permissions and original account.');
            $merchant = $this->square->get($temporary, '/v2/merchants/'.rawurlencode($token['merchant_id']))['merchant'] ?? [];
            abort_unless(($merchant['id'] ?? null) === $token['merchant_id'], 409, 'Square merchant verification failed.');
            $locations = $this->square->get($temporary, '/v2/locations')['locations'] ?? null;
            abort_unless(is_array($locations) && count($locations) <= 1000, 409, 'Square locations need review.');
            $locationIds = [];
            foreach ($locations as $location) {
                abort_unless($this->identifier($location['id'] ?? null) && ($location['merchant_id'] ?? null) === $token['merchant_id'], 409, 'Square returned a location outside the original merchant.');
                $locationIds[] = $location['id'];
            }
            $locationIds = array_values(array_unique($locationIds));
            sort($locationIds);
            abort_unless($locationIds, 409, 'Square returned no merchant locations for source history.');
        } catch (\Illuminate\Http\Client\ConnectionException) {
            abort(503, 'Square authorization is unconfirmed. Review the grant before starting another connection.');
        }
        $connection = DB::transaction(function () use ($tenant, $actor, $pending, $token, $credentials, $scopes, $locationIds): array {
            app(SquareMerchantOwnership::class)->claim($tenant->id, $token['merchant_id'], $pending['environment']);
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $actor, true);
            $setting = $this->setting($tenant->id, true);
            $value = $setting?->value ?? [];
            abort_unless(($value['generation'] ?? 0) === $pending['expected_generation'] && ($value['pending_oauth']['id'] ?? null) === $pending['id']
                && ! empty($value['pending_oauth']['claimed_at']), 409, 'The Square connection changed while authorizing. The original connection is retained.');
            abort_unless(empty($value['merchant_id']) || ($value['merchant_id'] === $token['merchant_id'] && $value['environment'] === $pending['environment']), 409, 'The original Square merchant or environment cannot change during reconnect.');
            $before = $this->publicState($value);
            $connected = ['credentials' => $credentials, 'connection_id' => $pending['connection_id'], 'generation' => $pending['expected_generation'] + 1,
                'merchant_id' => $token['merchant_id'], 'environment' => $pending['environment'], 'location_ids' => $locationIds,
                'granted_scopes' => array_values(array_unique($scopes)), 'expires_at' => $token['expires_at'], 'status' => 'active',
                'credential_flow' => 'core_source_authorization_code', 'oauth_client_id' => $pending['client_id'], 'credential_issued_at' => now()->toIso8601String(),
                'connected_by' => (int) $actor->id, 'connected_at' => now()->toIso8601String()];
            $this->save($tenant->id, $connected, $setting);
            $after = $this->publicState($connected);
            $this->record($tenant, $actor, 'square_customer_source_connected', $connected['connection_id'], $before, $after, ['request_id' => $pending['request_id']]);

            return $after;
        }, 3);

        return ['tenant' => $tenant, 'connection' => $connection];
    }

    /** Renews only grants created by this bridge, before source retrieval and outside caller transactions. */
    public function renewIfNeeded(int $tenantId, ?string $connectionId = null): void
    {
        $original = $this->setting($tenantId)?->value ?? [];
        if (! $this->renewable($original, $connectionId)) {
            return;
        }
        $this->store->outsideTransaction();
        $claim = DB::transaction(function () use ($tenantId, $connectionId): ?array {
            Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
            $setting = $this->setting($tenantId, true);
            $value = $setting?->value ?? [];
            if (! $this->renewable($value, $connectionId)) {
                return null;
            }
            abort_unless(empty($value['credential_refresh']), 409, 'Square credential renewal needs review before another attempt.');
            abort_unless(($value['oauth_client_id'] ?? null) === config('pos_live.square_application_id')
                && filled(config('pos_live.square_application_secret')), 409, 'Reconnect the original Square application before renewing its authorization.');
            $value['credential_refresh'] = ['id' => (string) Str::uuid(), 'generation' => $value['generation'],
                'connection_id' => $value['connection_id'], 'merchant_id' => $value['merchant_id'], 'environment' => $value['environment'],
                'client_id' => $value['oauth_client_id'], 'status' => 'claimed', 'claimed_at' => now()->toIso8601String()];
            $this->save($tenantId, $value, $setting);

            return $value;
        }, 3);
        if (! $claim) {
            return;
        }
        $candidate = null;
        try {
            $credentials = $this->store->decrypt($claim['credentials']);
            abort_unless(is_string($credentials['refresh_token'] ?? null) && $credentials['refresh_token'] !== '', 409);
            // The confidential code flow uses a multi-use refresh token. Still claim
            // once before transport: a lost response is not permission to replay it.
            $this->store->outsideTransaction();
            $reply = Http::acceptJson()->connectTimeout(5)->timeout(20)->post($this->origin($claim['environment']).'/oauth2/token', [
                'client_id' => $claim['oauth_client_id'], 'client_secret' => config('pos_live.square_application_secret'),
                'grant_type' => 'refresh_token', 'refresh_token' => $credentials['refresh_token'], 'scopes' => self::SCOPES]);
            abort_unless($reply->successful(), 503);
            $token = $reply->json();
            abort_unless(is_array($token) && is_string($token['access_token'] ?? null) && $token['access_token'] !== '' && strlen($token['access_token']) <= 10000
                && is_string($token['refresh_token'] ?? null) && $token['refresh_token'] !== '' && strlen($token['refresh_token']) <= 10000
                && is_string($token['expires_at'] ?? null) && strtotime($token['expires_at']) > now()->timestamp, 503);
            $candidate = $this->store->encrypt(array_intersect_key($token, array_flip(['access_token', 'refresh_token', 'expires_at'])));
            abort_unless(($token['merchant_id'] ?? null) === $claim['merchant_id'], 409);
            $grant = $this->square->post((object) ['credentials' => $candidate, 'environment' => $claim['environment']], '/oauth2/token/status', []);
            $scopes = $grant['scopes'] ?? [];
            abort_unless(is_array($scopes) && collect($scopes)->every(fn ($scope) => is_string($scope)) && ! array_diff(self::SCOPES, $scopes)
                && ($grant['merchant_id'] ?? null) === $claim['merchant_id'] && ($grant['client_id'] ?? null) === $claim['oauth_client_id'], 409);
            DB::transaction(function () use ($tenantId, $claim, $candidate, $token, $scopes): void {
                Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $setting = $this->setting($tenantId, true);
                $value = $setting?->value ?? [];
                abort_unless($this->sameRefresh($value, $claim) && $value['status'] === 'active'
                    && $claim['oauth_client_id'] === config('pos_live.square_application_id'), 409);
                $before = $this->publicState($value);
                unset($value['credential_refresh'], $value['pending_oauth']);
                $value = array_replace($value, ['credentials' => $candidate, 'generation' => $claim['generation'] + 1,
                    'expires_at' => $token['expires_at'], 'credential_issued_at' => now()->toIso8601String(), 'granted_scopes' => array_values(array_unique($scopes))]);
                $this->save($tenantId, $value, $setting);
                $this->audit->record($tenantId, null, 'square_customer_source_credentials_renewed', 'success', 'square_customer_source', $value['connection_id'],
                    ['refresh_id' => $claim['credential_refresh']['id']], [], $before, $this->publicState($value), ['source_only' => true]);
            }, 3);
        } catch (\Throwable) {
            // Keep both original and any received credentials encrypted for review.
            // A newer reconnect/disconnect is never overwritten by this late result.
            DB::transaction(function () use ($tenantId, $claim, $candidate): void {
                Tenant::whereKey($tenantId)->lockForUpdate()->firstOrFail();
                $setting = $this->setting($tenantId, true);
                $value = $setting?->value ?? [];
                if ($this->sameRefresh($value, $claim)) {
                    $value['status'] = 'reconnect_required';
                    $value['credential_refresh']['status'] = 'unknown';
                    if ($candidate) {
                        $value['credential_refresh']['candidate_credentials'] = $candidate;
                    }
                    $this->save($tenantId, $value, $setting);
                    $this->audit->record($tenantId, null, 'square_customer_source_credentials_renewed', 'unknown', 'square_customer_source', $value['connection_id'],
                        ['refresh_id' => $claim['credential_refresh']['id']], [], null, $this->publicState($value), ['source_only' => true, 'reconnect_required' => true]);
                }
            }, 3);
            abort(503, 'Square credential renewal is unconfirmed. Reconnect the original merchant before refreshing history.');
        }
    }

    private function renewable(array $value, ?string $connectionId): bool
    {
        if (($value['credential_flow'] ?? null) !== 'core_source_authorization_code'
            || ($value['status'] ?? null) !== 'active' || empty($value['credentials'])
            || ($connectionId && ! in_array($connectionId, ['tenant-config', $value['connection_id'] ?? null], true))) {
            return false;
        }

        return ! empty($value['credential_refresh']) || strtotime($value['expires_at'] ?? '') <= now()->timestamp + 300
            || strtotime($value['credential_issued_at'] ?? '') <= now()->subDays(7)->timestamp;
    }

    private function sameRefresh(array $value, array $claim): bool
    {
        return ($value['generation'] ?? null) === $claim['generation'] && ($value['credential_refresh']['id'] ?? null) === $claim['credential_refresh']['id']
            && ($value['connection_id'] ?? null) === $claim['connection_id'] && ($value['merchant_id'] ?? null) === $claim['merchant_id']
            && ($value['environment'] ?? null) === $claim['environment'] && ($value['oauth_client_id'] ?? null) === $claim['oauth_client_id'];
    }

    public function disconnect(Tenant $tenant, User $actor, array $input): array
    {
        $this->authorize($tenant, $actor);
        $input = validator($input, ['request_id' => 'required|uuid', 'expected_generation' => 'required|integer|min:0'])->validate();
        $input['expected_generation'] = (int) $input['expected_generation'];

        return DB::transaction(function () use ($tenant, $actor, $input): array {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $actor, true);
            $setting = $this->setting($tenant->id, true);
            $value = $setting?->value ?? [];
            abort_unless($setting && (! empty($value['merchant_id']) || ! empty($value['access_token']) || ! empty($value['credentials'])), 409, 'No original Square source connection is available.');
            if (($value['last_disconnect_request'] ?? null) === $input['request_id']) {
                return $this->publicState($value);
            }
            abort_unless(($value['generation'] ?? 0) === $input['expected_generation'], 409, 'The Square connection changed. Refresh before disconnecting.');
            $before = $this->publicState($value);
            unset($value['access_token'], $value['refresh_token'], $value['credentials'], $value['pending_oauth'], $value['credential_refresh']);
            $value = array_replace($value, ['generation' => $input['expected_generation'] + 1, 'status' => 'disconnected', 'last_disconnect_request' => $input['request_id'], 'disconnected_at' => now()->toIso8601String()]);
            $this->save($tenant->id, $value, $setting);
            $after = $this->publicState($value);
            $this->record($tenant, $actor, 'square_customer_source_disconnected', $value['connection_id'] ?? 'tenant-config', $before, $after, ['request_id' => $input['request_id']]);

            return $after;
        }, 3);
    }

    private function configured(): bool
    {
        $uri = config('customer_sources.square.redirect_uri');

        return is_string($uri) && parse_url($uri, PHP_URL_SCHEME) === 'https' && filled(config('pos_live.square_application_id')) && filled(config('pos_live.square_application_secret'));
    }

    private function originalBinding(int $tenant, array $original): array
    {
        if (! empty($original['merchant_id'])) {
            return array_replace($original, ['environment' => $original['environment'] ?? 'production', 'location_ids' => $original['location_ids'] ?? []]);
        }
        if (! empty($original['access_token']) || ! empty($original['credentials'])) {
            // A legacy token must prove its original merchant before replacement.
            $verified = app(SquareConnectionContext::class)->resolve($tenant, $original['connection_id'] ?? 'tenant-config');
            abort_unless($verified, 409, 'Review the original Square merchant before reconnecting.');

            return $verified;
        }
        $bindings = [];
        try {
            foreach (['merchants', 'connections'] as $table) {
                foreach ($this->store->rows($table, $tenant)->get() as $row) {
                    $key = $row->environment.':'.$row->merchant_id;
                    $bindings[$key] ??= ['merchant_id' => $row->merchant_id, 'environment' => $row->environment, 'location_ids' => []];
                    if (isset($row->provider_location_id)) {
                        $bindings[$key]['location_ids'][] = $row->provider_location_id;
                    }
                }
            }
        } catch (PosException $error) {
            if ($error->blocker !== 'runtime_unconfigured') {
                throw $error;
            }
        }
        abort_if(count($bindings) > 1, 409, 'Review the original Square merchant and environment before connecting another source account.');
        if ($bindings) {
            $binding = array_values($bindings)[0];
            $binding['location_ids'] = array_values(array_unique($binding['location_ids']));

            return $binding;
        }
        foreach (['square_customers', 'square_orders', 'square_payments'] as $table) {
            if ($this->store->db->getSchemaBuilder()->hasTable($table)) {
                abort_if($this->store->db->table($table)->where('tenant_id', $tenant)->exists(), 409, 'Existing Square history has no verified original merchant binding. Review that account before connecting.');
            }
        }

        return $original;
    }

    private function setting(int $tenant, bool $locking = false): ?TenantMarketingSetting
    {
        return TenantMarketingSetting::where('tenant_id', $tenant)->where('key', self::KEY)->when($locking, fn ($q) => $q->lockForUpdate())->first();
    }

    private function save(int $tenant, array $value, ?TenantMarketingSetting $setting): void
    {
        ($setting ?? new TenantMarketingSetting(['tenant_id' => $tenant, 'key' => self::KEY]))->fill(['value' => $value, 'description' => 'Original Square customer source connection; encrypted authorization, read-only adapter.'])->save();
    }

    private function publicState(array $value): ?array
    {
        if (empty($value['merchant_id']) && empty($value['access_token']) && empty($value['credentials']) && ($value['status'] ?? null) !== 'disconnected') {
            return null;
        }

        $public = array_replace(['connection_id' => 'tenant-config', 'generation' => 0, 'status' => 'legacy_review_required'], array_intersect_key($value,
            array_flip(['connection_id', 'generation', 'merchant_id', 'environment', 'location_ids', 'granted_scopes', 'status', 'connected_by', 'connected_at', 'disconnected_at', 'expires_at', 'credential_issued_at'])));
        if (data_get($value, 'credential_refresh.status') === 'claimed') {
            $public['status'] = 'renewal_unconfirmed';
        }

        return $public;
    }

    private function record(Tenant $tenant, User $actor, string $action, string $id, ?array $before, ?array $after, array $context): void
    {
        $this->audit->record($tenant->id, $actor->id, $action, 'success', 'square_customer_source', $id, $context, [], $before, $after,
            ['source_only' => true, 'checkout_enabled' => false, 'rewards_enabled' => false]);
    }

    private function origin(string $environment): string
    {
        return $environment === 'production' ? 'https://connect.squareup.com' : 'https://connect.squareupsandbox.com';
    }

    private function identifier(mixed $id): bool
    {
        return is_string($id) && preg_match('/^[A-Za-z0-9_-]{1,192}$/D', $id) === 1;
    }
}
