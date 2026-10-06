<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\FleetTracking\FleetTrackingAccessService;
use App\Services\Integrations\Bouncie\BouncieConnector;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class BouncieConnectionController extends Controller
{
    public function __construct(private readonly LandlordOperatorActionAuditService $audit) {}

    public function connect(Request $request, BouncieConnector $connector, FleetTrackingAccessService $access): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeTenantAdmin($request, $tenant);
        abort_unless($access->enabledFor($tenant), 403, 'The Location Tracker Branch is not enabled for this workspace.');
        abort_if(blank(config('services.fleet_tracking.bouncie_client_id')) || blank(config('services.fleet_tracking.bouncie_client_secret')), 503, 'Bouncie is not configured yet.');

        $state = Str::random(48);
        $verifier = Str::random(96);
        $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
        $this->cache()->put($this->stateKey($state), [
            'tenant_id' => (int) $tenant->id,
            'user_id' => (int) $request->user()->id,
            'code_verifier' => $verifier,
        ], now()->addMinutes(15));

        return redirect()->away($connector->buildAuthorizationUrl($tenant, ['state' => $state, 'code_challenge' => $challenge]));
    }

    public function callback(Request $request, BouncieConnector $connector, FleetTrackingAccessService $access): RedirectResponse
    {
        $state = trim((string) $request->query('state'));
        $payload = $state !== '' ? $this->cache()->pull($this->stateKey($state)) : null;
        abort_unless(is_array($payload), 403, 'Bouncie authorization expired. Start the connection again.');
        abort_unless((int) ($payload['user_id'] ?? 0) === (int) $request->user()?->id, 403);

        $tenant = Tenant::query()->findOrFail((int) ($payload['tenant_id'] ?? 0));
        $this->authorizeTenantAdmin($request, $tenant);
        abort_unless($access->enabledFor($tenant), 403, 'The Location Tracker Branch is not enabled for this workspace.');
        $request->attributes->set('bouncie_code_verifier', (string) ($payload['code_verifier'] ?? ''));
        $connection = $connector->handleCallback($tenant, $request);
        $connection->forceFill(['connected_by_user_id' => (int) $request->user()->id])->save();
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'fleet_tracking.bouncie.connected', targetType: 'integration_connection', targetId: $connection->id, afterState: ['provider' => 'bouncie', 'status' => $connection->status, 'account_label' => $connection->external_account_label]);

        return redirect()->route('field-service.fleet-tracking.index')
            ->with('status', 'Bouncie connected. Select which company vehicle each tracker belongs to.');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $tenant = $this->tenant($request);
        $this->authorizeTenantAdmin($request, $tenant);
        $connection = IntegrationConnection::query()->forTenantId((int) $tenant->id)->where('provider', 'bouncie')->firstOrFail();
        $before = ['provider' => 'bouncie', 'status' => $connection->status, 'account_label' => $connection->external_account_label];
        $connection->forceFill([
            'status' => IntegrationConnection::STATUS_DISCONNECTED,
            'access_token' => null,
            'refresh_token' => null,
            'expires_at' => null,
            'external_account_secret' => null,
            'metadata' => array_merge((array) $connection->metadata, ['disconnected_at' => now()->toIso8601String()]),
        ])->save();
        $this->audit->record((int) $tenant->id, (int) $request->user()->id, 'fleet_tracking.bouncie.disconnected', targetType: 'integration_connection', targetId: $connection->id, beforeState: $before, afterState: ['provider' => 'bouncie', 'status' => $connection->status]);

        return back()->with('status', 'Bouncie disconnected. Previously saved company-vehicle locations remain subject to the retention policy.');
    }

    private function authorizeTenantAdmin(Request $request, Tenant $tenant): void
    {
        $membership = $request->user()?->tenants()->whereKey((int) $tenant->id)->first();
        $role = strtolower(trim((string) ($membership?->pivot->role ?? '')));
        abort_unless(in_array($role, ['admin', 'owner', 'tenant_owner'], true), 403);
    }

    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return $tenant;
    }

    private function cache(): \Illuminate\Contracts\Cache\Repository
    {
        return Cache::store((string) config('services.fleet_tracking.oauth_state_cache_store', config('cache.default')));
    }

    private function stateKey(string $state): string
    {
        return 'bouncie_oauth_state:'.$state;
    }
}
