<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Services\Integrations\Instagram\InstagramConnector;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class InstagramConnectionController extends Controller
{
    public function index(Request $request): View
    {
        $tenants = $request->user()
            ->tenants()
            ->wherePivotIn('role', ['admin', 'owner', 'tenant_owner'])
            ->orderBy('name')
            ->get();
        $connectedTenantIds = IntegrationConnection::query()
            ->where('provider', 'instagram')
            ->where('status', IntegrationConnection::STATUS_CONNECTED)
            ->whereIn('tenant_id', $tenants->modelKeys())
            ->pluck('tenant_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        return view('integrations.instagram.index', [
            'tenants' => $tenants,
            'connectedTenantIds' => $connectedTenantIds,
            'isConfigured' => (bool) config('services.instagram.enabled')
                && filled(config('services.instagram.client_id'))
                && filled(config('services.instagram.client_secret')),
        ]);
    }

    public function connect(
        Request $request,
        Tenant $tenant,
        InstagramConnector $connector,
        TenantModuleAccessResolver $moduleAccessResolver
    ): RedirectResponse {
        $this->authorizeTenantAdmin($request, $tenant);
        abort_unless($moduleAccessResolver->canAccess((int) $tenant->id, 'integrations'), 403, 'The Integrations Branch is not enabled for this workspace.');

        $state = Str::random(48);
        Cache::store((string) config('services.instagram.oauth_state_cache_store', config('cache.default')))
            ->put($this->stateKey($state), [
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $request->user()->id,
            ], now()->addMinutes(15));

        return redirect()->away($connector->buildAuthorizationUrl($tenant, ['state' => $state]));
    }

    public function callback(
        Request $request,
        InstagramConnector $connector,
        TenantModuleAccessResolver $moduleAccessResolver
    ): RedirectResponse {
        $state = trim((string) $request->query('state'));
        $cache = Cache::store((string) config('services.instagram.oauth_state_cache_store', config('cache.default')));
        $payload = $state !== '' ? $cache->pull($this->stateKey($state)) : null;
        abort_unless(is_array($payload), 403, 'Instagram authorization expired. Start the connection again.');

        $tenant = Tenant::query()->findOrFail((int) ($payload['tenant_id'] ?? 0));
        $this->authorizeTenantAdmin($request, $tenant);
        abort_unless($moduleAccessResolver->canAccess((int) $tenant->id, 'integrations'), 403, 'The Integrations Branch is not enabled for this workspace.');

        $connection = $connector->handleCallback($tenant, $request);
        $connection->forceFill(['connected_by_user_id' => (int) $request->user()->id])->save();

        return redirect()
            ->route('integrations.instagram.index')
            ->with('status', 'Instagram is connected to '.$tenant->name.'. Incoming DMs will appear in the response inbox once the Meta webhook is configured.');
    }

    protected function authorizeTenantAdmin(Request $request, Tenant $tenant): void
    {
        $user = $request->user();
        $membership = $user?->tenants()->whereKey((int) $tenant->id)->first();
        $role = strtolower(trim((string) ($membership?->pivot->role ?? '')));

        abort_unless($user && in_array($role, ['admin', 'owner', 'tenant_owner'], true), 403);
    }

    protected function stateKey(string $state): string
    {
        return 'instagram_oauth_state:'.$state;
    }
}
