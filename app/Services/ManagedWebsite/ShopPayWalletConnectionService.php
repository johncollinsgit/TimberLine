<?php

namespace App\Services\ManagedWebsite;

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteShopPayConnection;
use App\Services\Billing\ShopPayWalletPreflight;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Setup is shared by every retailer; collection remains merchant-specific. */
class ShopPayWalletConnectionService
{
    public function setupVisibleFor(Tenant $tenant, ?User $user): bool
    {
        if (! app(\App\Services\Billing\TenantPaymentSetupAccess::class)->allows($tenant, $user)) {
            return false;
        }
        $profile = app(\App\Services\Tenancy\TenantExperienceProfileService::class)->forTenant($tenant->id, $user, $tenant);

        return data_get($profile, 'company_context.category') === 'retail'
            || in_array($profile['channel_type'] ?? '', ['shopify', 'hybrid'], true)
            || app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant->id, 'managed_website');
    }

    public function connection(Tenant $tenant): ?WebsiteShopPayConnection
    {
        return WebsiteShopPayConnection::query()->forTenant($tenant)->first();
    }

    public function origin(TenantSite $site): string
    {
        $url = app(ManagedWebsiteDomainService::class)->publicUrl($site);

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)).'://'.strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    public function blockers(Tenant $tenant, ?TenantSite $site): array
    {
        $connection = $this->connection($tenant);
        $blockers = [];
        if (! $site || ! app(WebsiteCommerceService::class)->checkoutReadiness($tenant)['ready']) {
            $blockers[] = 'Finish the native website, payment account and tax setup.';
        }
        if (! config('managed_website.shop_pay_wallet_enabled') || ! in_array((int) $tenant->id, config('managed_website.shop_pay_wallet_tenant_ids', []), true)) {
            $blockers[] = 'Shop Pay collection is awaiting launch verification for this business.';
        }
        if (! $connection || $connection->connection_status !== 'connected' || ! $connection->last_verified_at?->gt(now()->subDays(7))) {
            $blockers[] = 'Verify this business’s dedicated Wallet store.';
        }
        if ($site && (! $connection || $connection->verified_origin !== $this->origin($site))) {
            $blockers[] = 'Verify the current website origin in the Shop channel.';
        }
        if ($connection && (($connection->environment === 'live') !== app(WebsiteStripeService::class)->live())) {
            $blockers[] = 'Shop Pay and tax account environments must match.';
        }
        if ($connection && ! data_get($connection->setup, 'dedicated_store_confirmed')) {
            $blockers[] = 'Confirm a separate Wallet store with Shopify Payments and the Shop channel.';
        }
        // An owner checkbox or API identity probe is not live payment acceptance.
        if ($connection?->environment === 'live' && ! data_get($connection->setup, 'live_acceptance_verified_at')) {
            $blockers[] = 'A signed test, payment, refund and settlement acceptance review is required before live collection.';
        }

        return $blockers;
    }

    public function availableFor(Tenant $tenant, TenantSite $site): bool
    {
        return $this->connection($tenant)?->enabled === true && $this->blockers($tenant, $site) === [];
    }

    public function save(Tenant $tenant, User $actor, array $data): WebsiteShopPayConnection
    {
        return DB::transaction(function () use ($tenant, $actor, $data) {
            Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $connection = $this->connection($tenant) ?? new WebsiteShopPayConnection(['tenant_id' => $tenant->id]);
            if ($connection->exists && ((string) $connection->shop_id !== (string) $data['shop_id'] || $connection->shop_domain !== strtolower($data['shop_domain']))) {
                throw ValidationException::withMessages(['shop_id' => 'This connection belongs to its original merchant. Contact support before changing stores; existing purchases retain their original account.']);
            }
            foreach (['storefront_access_token', 'admin_access_token', 'webhook_secret'] as $key) {
                if (filled($data[$key] ?? null)) {
                    $connection->{$key} = $data[$key];
                }
            }
            if (! $connection->storefront_access_token || ! $connection->admin_access_token || ! $connection->webhook_secret) {
                throw ValidationException::withMessages(['credentials' => 'Both API credentials and the app webhook signing secret are required.']);
            }
            $connection->fill(array_intersect_key($data, array_flip(['shop_id', 'shop_domain', 'client_id', 'environment'])));
            $connection->forceFill(['api_version' => ShopPayWalletPreflight::API_VERSION, 'enabled' => false,
                'connection_status' => 'unverified', 'last_verified_at' => null, 'verified_origin' => null, 'last_error' => null,
                'setup' => ['dedicated_store_confirmed' => (bool) ($data['dedicated_store_confirmed'] ?? false)]])->save();
            $this->audit($tenant, $actor, $connection, 'saved');

            return $connection;
        });
    }

    public function test(Tenant $tenant, TenantSite $site, User $actor): WebsiteShopPayConnection
    {
        $connection = $this->connection($tenant);
        abort_unless($connection, 422, 'Save this business’s connection first.');
        $revision = hash('sha256', $connection->getRawOriginal('admin_access_token').$connection->getRawOriginal('storefront_access_token').$connection->updated_at);
        try {
            (new ShopPayWalletPreflight(['shop_domain' => $connection->shop_domain, 'shop_id' => (string) $connection->shop_id,
                'admin_access_token' => $connection->admin_access_token, 'storefront_access_token' => $connection->storefront_access_token]))->probeSession();
            $error = null;
        } catch (\RuntimeException $exception) {
            $error = $exception->getMessage();
        }

        return DB::transaction(function () use ($tenant, $site, $actor, $connection, $revision, $error) {
            $locked = WebsiteShopPayConnection::query()->forTenant($tenant)->lockForUpdate()->findOrFail($connection->id);
            abort_unless(hash_equals($revision, hash('sha256', $locked->getRawOriginal('admin_access_token').$locked->getRawOriginal('storefront_access_token').$locked->updated_at)), 409, 'Connection changed. Verify it again.');
            $locked->forceFill(['enabled' => false, 'connection_status' => $error ? 'failed' : 'connected',
                'last_error' => $error, 'last_verified_at' => $error ? null : now(), 'verified_origin' => $error ? null : $this->origin($site)])->save();
            $this->audit($tenant, $actor, $locked, 'verified');

            return $locked;
        });
    }

    public function setEnabled(Tenant $tenant, TenantSite $site, User $actor, bool $enabled): void
    {
        DB::transaction(function () use ($tenant, $site, $actor, $enabled) {
            $connection = WebsiteShopPayConnection::query()->forTenant($tenant)->lockForUpdate()->firstOrFail();
            if ($enabled && ($blockers = $this->blockers($tenant, $site)) !== []) {
                throw ValidationException::withMessages(['shop_pay' => implode(' ', $blockers)]);
            }
            $connection->forceFill(['enabled' => $enabled])->save();
            $this->audit($tenant, $actor, $connection, $enabled ? 'enabled' : 'disabled');
        });
    }

    private function audit(Tenant $tenant, User $actor, WebsiteShopPayConnection $connection, string $action): void
    {
        app(LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, 'website.shop_pay.'.$action,
            targetType: 'website_shop_pay_connection', targetId: $connection->id,
            afterState: ['shop_domain' => $connection->shop_domain, 'environment' => $connection->environment, 'enabled' => $connection->enabled],
            context: ['billing_impact' => 'none']);
    }
}
