<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Integrations\QuickBooks\QuickBooksConnector;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class QuickBooksConnectionController extends Controller
{
    public function connect(Request $request, Tenant $tenant, QuickBooksConnector $connector): RedirectResponse
    {
        $this->authorizeTenantMember($request, $tenant);

        $state = Str::random(48);
        Cache::store((string) config('services.quickbooks.oauth_state_cache_store', config('cache.default')))
            ->put($this->stateKey($state), [
                'tenant_id' => (int) $tenant->id,
                'user_id' => (int) $request->user()->id,
            ], now()->addMinutes(15));

        return redirect()->away($connector->buildAuthorizationUrl($tenant, ['state' => $state]));
    }

    public function callback(Request $request, QuickBooksConnector $connector): RedirectResponse
    {
        $state = trim((string) $request->query('state'));
        $cache = Cache::store((string) config('services.quickbooks.oauth_state_cache_store', config('cache.default')));
        $payload = $state !== '' ? $cache->pull($this->stateKey($state)) : null;
        abort_unless(is_array($payload), 403, 'QuickBooks authorization expired. Start the connection again.');

        $tenant = Tenant::query()->findOrFail((int) ($payload['tenant_id'] ?? 0));
        $this->authorizeTenantMember($request, $tenant);

        $connection = $connector->handleCallback($tenant, $request);
        $connection->forceFill(['connected_by_user_id' => (int) $request->user()->id])->save();

        return redirect()
            ->route('field-service.index', ['tenant' => $tenant->slug])
            ->with('status', 'QuickBooks connected. Run the guided import when you are ready.');
    }

    protected function authorizeTenantMember(Request $request, Tenant $tenant): void
    {
        $user = $request->user();
        abort_unless($user && $user->tenants()->whereKey((int) $tenant->id)->exists(), 403);
    }

    protected function stateKey(string $state): string
    {
        return 'quickbooks_oauth_state:'.$state;
    }
}
