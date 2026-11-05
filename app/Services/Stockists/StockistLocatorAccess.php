<?php

namespace App\Services\Stockists;

use App\Models\ShopifyStore;
use App\Models\User;
use App\Services\Shopify\ShopifyAdminIdentityResolver;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;

class StockistLocatorAccess
{
    public function store(array $context): ?ShopifyStore
    {
        if (! ($context['ok'] ?? false)) {
            return null;
        }
        $shop = strtolower(trim((string) ($context['shop_domain'] ?? data_get($context, 'store.shop', ''))));
        $store = ShopifyStore::query()->where('shop_domain', $shop)->first();
        if (! $store || ! $store->tenant_id || ! in_array($store->store_role, ['retail', 'mixed'], true)
            || ! app(TenantModuleAccessResolver::class)->canAccess((int) $store->tenant_id, 'shopify')) {
            return null;
        }

        return $store;
    }

    /** Both API reads and writes require a fresh Shopify session and tenant membership. */
    public function operator(Request $request): array
    {
        $context = app(ShopifyEmbeddedAppContext::class)->resolveAuthenticatedApiContext($request);
        $store = $this->store($context);
        abort_unless($store, 403, 'Open Store locator from the connected retail Shopify store.');
        $email = $context['shopify_admin_email'] ?? null;
        if (! $email) {
            $token = $request->bearerToken() ?: $request->header('X-Shopify-Session-Token') ?: $request->input('shopify_session_token', '');
            $email = app(ShopifyAdminIdentityResolver::class)->emailForVerifiedSession($context, (string) $token);
        }
        $actor = $request->user();
        if (! $actor instanceof User) {
            $actor = $email ? User::query()->whereRaw('LOWER(email) = ?', [strtolower($email)])->first() : null;
        }
        $membership = $actor?->tenants()->whereKey($store->tenant_id)->first()?->pivot;
        abort_unless($actor instanceof User && $actor->is_active && $membership?->membership_active
            && in_array(strtolower((string) $membership->role), ['owner', 'tenant_owner', 'admin'], true),
            403, 'Store locator management requires an active owner or admin membership.');

        return [$store, $actor];
    }
}
