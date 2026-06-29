<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifyCustomerAddressSyncService
{
    public function __construct(
        protected ShopifyCustomerWebhookIngestor $ingestor
    ) {
    }

    public function hydrateProfileAddress(
        MarketingProfile $profile,
        ?string $shopifyCustomerId,
        ?int $tenantId = null
    ): MarketingProfile {
        $tenantId = $tenantId ?: (int) ($profile->tenant_id ?? 0);

        if ($tenantId <= 0 || (int) ($profile->tenant_id ?? 0) !== $tenantId) {
            return $profile;
        }

        if ($this->hasSavedAddress($profile)) {
            return $profile;
        }

        $normalizedCustomerId = $this->normalizeCustomerId($shopifyCustomerId);
        if ($normalizedCustomerId === null) {
            return $profile;
        }

        $store = ShopifyStores::find('retail');
        if (! is_array($store) || (int) ($store['tenant_id'] ?? 0) !== $tenantId) {
            return $profile;
        }

        $shopDomain = trim((string) ($store['shop'] ?? ''));
        $token = trim((string) ($store['token'] ?? ''));
        if ($shopDomain === '' || $token === '') {
            return $profile;
        }

        try {
            $client = new ShopifyClient(
                $shopDomain,
                $token,
                trim((string) ($store['api_version'] ?? '')) ?: config('services.shopify.api_version', '2026-01')
            );

            $payload = $client->get("customers/{$normalizedCustomerId}.json", [
                'fields' => implode(',', [
                    'id',
                    'admin_graphql_api_id',
                    'email',
                    'phone',
                    'first_name',
                    'last_name',
                    'orders_count',
                    'total_spent',
                    'created_at',
                    'updated_at',
                    'tags',
                    'verified_email',
                    'accepts_marketing',
                    'email_marketing_consent',
                    'sms_marketing_consent',
                    'default_address',
                ]),
            ]);
        } catch (Throwable $exception) {
            Log::warning('Modern Forestry mobile address hydration failed to fetch Shopify customer.', [
                'tenant_id' => $tenantId,
                'marketing_profile_id' => (int) $profile->id,
                'shopify_customer_id' => $normalizedCustomerId,
                'message' => $exception->getMessage(),
            ]);

            return $profile;
        }

        $customer = data_get($payload, 'customer');
        if (! is_array($customer)) {
            return $profile;
        }

        $result = $this->ingestor->ingest($store, $customer, [
            'tenant_id' => $tenantId,
            'topic' => 'customers/mobile_address_sync',
        ]);

        $resolvedProfileId = is_numeric($result['marketing_profile_id'] ?? null)
            ? (int) $result['marketing_profile_id']
            : (int) $profile->id;

        return MarketingProfile::query()
            ->forTenantId($tenantId)
            ->find($resolvedProfileId)
            ?? $profile->fresh()
            ?? $profile;
    }

    protected function normalizeCustomerId(?string $shopifyCustomerId): ?string
    {
        $normalized = trim((string) $shopifyCustomerId);
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/(\d+)(?!.*\d)/', $normalized, $matches) === 1) {
            return (string) $matches[1];
        }

        return ctype_digit($normalized) ? $normalized : null;
    }

    protected function hasSavedAddress(MarketingProfile $profile): bool
    {
        return array_filter([
            trim((string) ($profile->address_line_1 ?? '')),
            trim((string) ($profile->address_line_2 ?? '')),
            trim((string) ($profile->city ?? '')),
            trim((string) ($profile->state ?? '')),
            trim((string) ($profile->postal_code ?? '')),
            trim((string) ($profile->country ?? '')),
        ]) !== [];
    }
}
