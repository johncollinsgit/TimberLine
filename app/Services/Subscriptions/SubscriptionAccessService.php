<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\User;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;

/** The common boundary for HTTP, customer, webhook and future worker callers. */
class SubscriptionAccessService
{
    public const API_VERSION = '2026-10';

    public function entitled(int $tenantId): bool
    {
        return $tenantId > 0 && (bool) app(TenantModuleAccessResolver::class)->module($tenantId, 'subscriptions')['has_access'];
    }

    public function assertEntitled(int $tenantId): void
    {
        abort_unless($this->entitled($tenantId), 403, 'The Subscriptions add-on is not enabled for this workspace.');
    }

    public function canManage(int $tenantId, ?User $actor): bool
    {
        if (! $actor || ! $actor->is_active || ! $this->entitled($tenantId)) {
            return false;
        }
        $membership = $actor->tenants()->whereKey($tenantId)->first();

        return $membership && (bool) $membership->pivot->membership_active
            && in_array(strtolower((string) $membership->pivot->role), ['owner', 'tenant_owner', 'admin', 'manager'], true);
    }

    public function assertManager(int $tenantId, ?User $actor): void
    {
        abort_unless($this->canManage($tenantId, $actor), 403, 'An active workspace owner, admin or manager is required.');
    }

    /** Only a persisted, tenant-owned store is acceptable; configuration fallback is not ownership. */
    public function storeIdentity(int $tenantId, array $store): array
    {
        $domain = strtolower(trim((string) ($store['shop'] ?? '')));
        $record = ShopifyStore::query()->where('tenant_id', $tenantId)
            ->where('store_key', (string) ($store['key'] ?? ''))->where('shop_domain', $domain)->first();
        abort_unless($record && preg_match('/^[a-z0-9-]+\.myshopify\.com$/D', $domain)
            && trim((string) ($store['client_id'] ?? '')) !== '', 403, 'Verified workspace store ownership is required.');
        $canonicalStore = ShopifyStores::find((string) $record->store_key, true);
        abort_unless($canonicalStore && hash_equals((string) ($canonicalStore['client_id'] ?? ''), (string) $store['client_id']), 403, 'The canonical subscription app identity is required.');

        return [
            'version' => 1, 'tenant_id' => $tenantId, 'shopify_store_id' => (int) $record->id,
            'store_key' => (string) $record->store_key, 'shop_domain' => $domain,
            'app_client_id' => (string) $store['client_id'], 'api_version' => self::API_VERSION,
        ];
    }

    /** Local setup only. Never rebind an existing lane to another store, installation or app. */
    public function bindStore(int $tenantId, array $store, User $actor): array
    {
        $this->assertManager($tenantId, $actor);
        $identity = $this->storeIdentity($tenantId, $store);

        return DB::transaction(function () use ($tenantId, $identity, $actor): array {
            DB::table('subscription_module_settings')->insertOrIgnore([
                'tenant_id' => $tenantId, 'module_key' => 'subscriptions', 'status' => 'setup',
                'shopify_store_key' => $identity['store_key'], 'billing_scheduler_enabled' => false,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->where('module_key', 'subscriptions')->lockForUpdate()->first();
            abort_if(trim((string) $row->shopify_store_key) !== '' && (string) $row->shopify_store_key !== $identity['store_key'], 409, 'Existing subscription store routing requires review before binding another store.');
            $metadata = $this->json($row->metadata);
            $existing = $metadata['provider_binding'] ?? null;
            abort_if($existing !== null && SubscriptionEvidence::canonical($existing) !== SubscriptionEvidence::canonical($identity), 409, 'The subscription store binding has changed. Review the original installation.');
            if ($existing === null) {
                $metadata['provider_binding'] = $identity;
                DB::table('subscription_module_settings')->where('id', $row->id)->update([
                    'metadata' => json_encode($metadata, JSON_THROW_ON_ERROR), 'updated_at' => now(),
                ]);
                DB::table('subscription_lifecycle_events')->insert([
                    'tenant_id' => $tenantId, 'actor_user_id' => $actor->id, 'event_type' => 'provider_binding_recorded',
                    'source' => 'everbranch_setup', 'status' => 'local_setup',
                    'metadata' => json_encode(['provider_binding' => $identity], JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            return $identity;
        });
    }

    public function binding(int $tenantId): ?array
    {
        $metadata = DB::table('subscription_module_settings')->where('tenant_id', $tenantId)->where('module_key', 'subscriptions')->value('metadata');
        $binding = $this->json($metadata)['provider_binding'] ?? null;

        return is_array($binding) ? $binding : null;
    }

    public function assertBinding(int $tenantId, ?array $store = null): array
    {
        $this->assertEntitled($tenantId);
        $binding = $this->binding($tenantId);
        abort_unless($binding, 409, 'Subscription store setup is required before recording a request.');
        $store ??= ShopifyStores::find((string) ($binding['store_key'] ?? ''), true);
        abort_unless($store && SubscriptionEvidence::canonical($this->storeIdentity($tenantId, $store)) === SubscriptionEvidence::canonical($binding), 409, 'The original subscription store or app is unavailable.');

        return $binding;
    }

    public function readiness(int $tenantId): array
    {
        $binding = $this->binding($tenantId);
        $entitled = $this->entitled($tenantId);
        $valid = false;
        if ($binding && $entitled) {
            try {
                $this->assertBinding($tenantId);
                $valid = true;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                // Surface only setup state; never provider credentials or exception detail.
            }
        }

        return [
            'entitled' => $entitled, 'store_bound' => $valid,
            'api_version' => self::API_VERSION, 'mode' => 'local_requests_only',
            'provider_execution_enabled' => false, 'billing_scheduler_enabled' => false,
            'contract_ownership_verified' => false,
            'message' => 'Requests are saved for review. Shopify changes, payment emails and charges are not sent. Existing Shopify orders and shipping labels keep their original details.',
            'blockers' => array_values(array_filter([
                ! $entitled ? 'subscription_addon_required' : null,
                ! $valid ? 'store_binding_required' : null,
                'provider_adapter_not_enabled', 'contract_ownership_not_verified', 'merchant_acceptance_required',
            ])),
        ];
    }

    /** Hard closed until a reviewed executor and merchant acceptance exist. Future jobs must call this. */
    public function assertCanExecute(int $tenantId): never
    {
        $this->assertBinding($tenantId);
        abort(409, 'Subscription provider execution is disabled. No charge or Shopify change was attempted.');
    }

    public static function isDemo(object $contract): bool
    {
        $metadata = json_decode((string) ($contract->metadata ?? ''), true) ?: [];

        return (bool) ($metadata['app_review_demo'] ?? false)
            || ! str_starts_with((string) ($contract->shopify_subscription_contract_gid ?? ''), 'gid://shopify/SubscriptionContract/');
    }

    private function json(mixed $value): array
    {
        return is_string($value) ? (json_decode($value, true) ?: []) : (is_array($value) ? $value : []);
    }
}
