<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Shopify\ShopifyGraphqlClient;
use App\Services\Shopify\ShopifyStores;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShopifyCustomerProvisioningService
{
    protected const CUSTOMER_LOOKUP_QUERY = <<<'GRAPHQL'
query FindCustomersByEmail($query: String!) {
  customers(first: 3, query: $query) {
    edges {
      node {
        id
        legacyResourceId
        email
        firstName
        lastName
        phone
      }
    }
  }
}
GRAPHQL;

    protected const CUSTOMER_CREATE_MUTATION = <<<'GRAPHQL'
mutation CreateCustomer($input: CustomerInput!) {
  customerCreate(input: $input) {
    customer {
      id
      legacyResourceId
      email
      firstName
      lastName
      phone
    }
    userErrors {
      field
      message
    }
  }
}
GRAPHQL;

    public function __construct(
        protected MarketingIdentityNormalizer $normalizer
    ) {
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function provisionForProfile(MarketingProfile $profile, array $context = []): array
    {
        $storeKey = $this->normalizeStoreKey($context['store_key'] ?? null);
        $tenantId = $this->positiveInt($context['tenant_id'] ?? $profile->tenant_id);
        $rawEmail = $this->nullableString($profile->email) ?: $this->nullableString($profile->normalized_email);
        $normalizedEmail = $this->normalizer->normalizeEmail($rawEmail);

        if ($storeKey === null) {
            return $this->result('skipped_store_context_missing', $profile, $storeKey, $tenantId);
        }

        if ($tenantId === null) {
            return $this->result('skipped_tenant_context_missing', $profile, $storeKey, $tenantId);
        }

        if ($normalizedEmail === null) {
            return $this->result('skipped_email_missing', $profile, $storeKey, $tenantId);
        }

        $existingProfileLink = $this->existingShopifyLinkForProfile($profile, $storeKey, $tenantId);
        if ($existingProfileLink !== null) {
            $shopifyCustomerId = $this->parseShopifyCustomerIdFromSourceId((string) $existingProfileLink->source_id);

            return $this->result('linked_existing_profile_link', $profile, $storeKey, $tenantId, [
                'source_id' => (string) $existingProfileLink->source_id,
                'shopify_customer_id' => $shopifyCustomerId,
            ]);
        }

        $existingExternalForProfile = $this->existingShopifyExternalForProfile($profile, $storeKey, $tenantId);
        if ($existingExternalForProfile !== null && $this->nullableString($existingExternalForProfile->external_customer_id) !== null) {
            $shopifyCustomerId = (string) $existingExternalForProfile->external_customer_id;
            $sourceId = $this->shopifyCustomerSourceId($storeKey, $shopifyCustomerId);

            $conflict = $this->existingShopifyLinkForAnyProfile($sourceId, $tenantId);
            if ($conflict && (int) $conflict->marketing_profile_id !== (int) $profile->id) {
                return $this->result('skipped_link_conflict', $profile, $storeKey, $tenantId, [
                    'source_id' => $sourceId,
                    'conflict_profile_id' => (int) $conflict->marketing_profile_id,
                ]);
            }

            $this->upsertCanonicalShopifyMappings(
                profile: $profile,
                storeKey: $storeKey,
                tenantId: $tenantId,
                customerPayload: [
                    'id' => (string) ($existingExternalForProfile->external_customer_gid ?? ''),
                    'legacyResourceId' => (string) $shopifyCustomerId,
                    'email' => (string) ($existingExternalForProfile->email ?: $rawEmail ?: ''),
                    'firstName' => (string) ($existingExternalForProfile->first_name ?? ''),
                    'lastName' => (string) ($existingExternalForProfile->last_name ?? ''),
                    'phone' => (string) ($existingExternalForProfile->phone ?? ''),
                ],
                provenance: 'local_external_profile'
            );

            return $this->result('linked_existing_external_profile', $profile, $storeKey, $tenantId, [
                'source_id' => $sourceId,
                'shopify_customer_id' => $shopifyCustomerId,
            ]);
        }

        $existingExternalByEmail = $this->existingShopifyExternalByEmail($normalizedEmail, $storeKey, $tenantId);
        if ($existingExternalByEmail !== null && $this->nullableString($existingExternalByEmail->external_customer_id) !== null) {
            if (
                $existingExternalByEmail->marketing_profile_id !== null
                && (int) $existingExternalByEmail->marketing_profile_id !== (int) $profile->id
            ) {
                return $this->result('skipped_external_conflict', $profile, $storeKey, $tenantId, [
                    'external_profile_id' => (int) $existingExternalByEmail->id,
                    'conflict_profile_id' => (int) $existingExternalByEmail->marketing_profile_id,
                ]);
            }

            $shopifyCustomerId = (string) $existingExternalByEmail->external_customer_id;
            $sourceId = $this->shopifyCustomerSourceId($storeKey, $shopifyCustomerId);

            $conflict = $this->existingShopifyLinkForAnyProfile($sourceId, $tenantId);
            if ($conflict && (int) $conflict->marketing_profile_id !== (int) $profile->id) {
                return $this->result('skipped_link_conflict', $profile, $storeKey, $tenantId, [
                    'source_id' => $sourceId,
                    'conflict_profile_id' => (int) $conflict->marketing_profile_id,
                ]);
            }

            $this->upsertCanonicalShopifyMappings(
                profile: $profile,
                storeKey: $storeKey,
                tenantId: $tenantId,
                customerPayload: [
                    'id' => (string) ($existingExternalByEmail->external_customer_gid ?? ''),
                    'legacyResourceId' => (string) $shopifyCustomerId,
                    'email' => (string) ($existingExternalByEmail->email ?: $rawEmail ?: ''),
                    'firstName' => (string) ($existingExternalByEmail->first_name ?? ''),
                    'lastName' => (string) ($existingExternalByEmail->last_name ?? ''),
                    'phone' => (string) ($existingExternalByEmail->phone ?? ''),
                ],
                provenance: 'local_external_email_lookup'
            );

            return $this->result('linked_existing_external_profile', $profile, $storeKey, $tenantId, [
                'source_id' => $sourceId,
                'shopify_customer_id' => $shopifyCustomerId,
            ]);
        }

        $store = ShopifyStores::find($storeKey);
        if (! $store) {
            return $this->result('skipped_store_not_configured', $profile, $storeKey, $tenantId);
        }

        $shopDomain = $this->nullableString($store['shop'] ?? null);
        $token = $this->nullableString($store['token'] ?? null);
        $apiVersion = $this->nullableString($store['api_version'] ?? null) ?: '2026-01';
        if ($shopDomain === null || $token === null) {
            return $this->result('skipped_store_credentials_missing', $profile, $storeKey, $tenantId);
        }

        $client = new ShopifyGraphqlClient($shopDomain, $token, $apiVersion);

        $remoteMatches = $this->lookupRemoteCustomersByEmail($client, $normalizedEmail);
        if (count($remoteMatches) > 1) {
            return $this->result('skipped_remote_ambiguous', $profile, $storeKey, $tenantId, [
                'matched_count' => count($remoteMatches),
            ]);
        }

        if ($remoteMatches !== []) {
            $remoteCustomer = $remoteMatches[0];
            $sourceId = $this->sourceIdFromCustomer($storeKey, $remoteCustomer);
            if ($sourceId === null) {
                return $this->result('skipped_remote_missing_customer_id', $profile, $storeKey, $tenantId);
            }

            $conflict = $this->existingShopifyLinkForAnyProfile($sourceId, $tenantId);
            if ($conflict && (int) $conflict->marketing_profile_id !== (int) $profile->id) {
                return $this->result('skipped_link_conflict', $profile, $storeKey, $tenantId, [
                    'source_id' => $sourceId,
                    'conflict_profile_id' => (int) $conflict->marketing_profile_id,
                ]);
            }

            $this->upsertCanonicalShopifyMappings(
                profile: $profile,
                storeKey: $storeKey,
                tenantId: $tenantId,
                customerPayload: $remoteCustomer,
                provenance: 'remote_email_lookup'
            );

            return $this->result('linked_existing_remote_customer', $profile, $storeKey, $tenantId, [
                'source_id' => $sourceId,
                'shopify_customer_id' => $this->extractShopifyCustomerId($remoteCustomer),
            ]);
        }

        $createdByMutation = true;
        try {
            $createdCustomer = $this->createRemoteCustomer($client, $profile, $normalizedEmail);
        } catch (RuntimeException $e) {
            if (! $this->isRecoverableDuplicateCreateError($e->getMessage())) {
                throw $e;
            }

            $retryMatches = $this->lookupRemoteCustomersByEmail($client, $normalizedEmail);
            if (count($retryMatches) !== 1) {
                throw $e;
            }

            $createdCustomer = $retryMatches[0];
            $createdByMutation = false;
        }

        $sourceId = $this->sourceIdFromCustomer($storeKey, $createdCustomer);
        if ($sourceId === null) {
            throw new RuntimeException('Shopify provisioning succeeded but no usable customer ID was returned.');
        }

        $conflict = $this->existingShopifyLinkForAnyProfile($sourceId, $tenantId);
        if ($conflict && (int) $conflict->marketing_profile_id !== (int) $profile->id) {
            return $this->result('skipped_link_conflict', $profile, $storeKey, $tenantId, [
                'source_id' => $sourceId,
                'conflict_profile_id' => (int) $conflict->marketing_profile_id,
            ]);
        }

        $this->upsertCanonicalShopifyMappings(
            profile: $profile,
            storeKey: $storeKey,
            tenantId: $tenantId,
            customerPayload: $createdCustomer,
            provenance: 'remote_customer_create'
        );

        return $this->result($createdByMutation ? 'created_remote_customer' : 'linked_existing_remote_customer', $profile, $storeKey, $tenantId, [
            'source_id' => $sourceId,
            'shopify_customer_id' => $this->extractShopifyCustomerId($createdCustomer),
        ]);
    }

    protected function existingShopifyLinkForProfile(MarketingProfile $profile, string $storeKey, ?int $tenantId): ?MarketingProfileLink
    {
        return MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'like', $storeKey . ':%')
            ->orderByDesc('id')
            ->first();
    }

    protected function existingShopifyExternalForProfile(MarketingProfile $profile, string $storeKey, ?int $tenantId): ?CustomerExternalProfile
    {
        return CustomerExternalProfile::query()
            ->forTenantId($tenantId)
            ->where('marketing_profile_id', $profile->id)
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->where('store_key', $storeKey)
            ->orderByDesc('id')
            ->first();
    }

    protected function existingShopifyExternalByEmail(string $normalizedEmail, string $storeKey, ?int $tenantId): ?CustomerExternalProfile
    {
        return CustomerExternalProfile::query()
            ->forTenantId($tenantId)
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->where('store_key', $storeKey)
            ->where('normalized_email', $normalizedEmail)
            ->orderByDesc('id')
            ->first();
    }

    protected function existingShopifyLinkForAnyProfile(string $sourceId, ?int $tenantId): ?MarketingProfileLink
    {
        return MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', $sourceId)
            ->first();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function lookupRemoteCustomersByEmail(ShopifyGraphqlClient $client, string $normalizedEmail): array
    {
        $payload = $client->query(self::CUSTOMER_LOOKUP_QUERY, [
            'query' => 'email:' . $normalizedEmail,
        ]);

        $edges = $payload['customers']['edges'] ?? null;
        if (! is_array($edges)) {
            return [];
        }

        $matches = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }

            $node = $edge['node'] ?? null;
            if (! is_array($node)) {
                continue;
            }

            $email = $this->normalizer->normalizeEmail($this->nullableString($node['email'] ?? null));
            if ($email === $normalizedEmail) {
                $matches[] = $node;
            }
        }

        return $matches;
    }

    /**
     * @return array<string,mixed>
     */
    protected function createRemoteCustomer(ShopifyGraphqlClient $client, MarketingProfile $profile, string $normalizedEmail): array
    {
        $rawPhone = $this->nullableString($profile->phone);
        $input = array_filter([
            'email' => $this->nullableString($profile->email) ?: $normalizedEmail,
            'firstName' => $this->nullableString($profile->first_name),
            'lastName' => $this->nullableString($profile->last_name),
            // Only send valid E.164 phones; Shopify rejects many local formats.
            'phone' => $this->isE164($rawPhone) ? $rawPhone : null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        $payload = $client->query(self::CUSTOMER_CREATE_MUTATION, [
            'input' => $input,
        ]);

        $create = $payload['customerCreate'] ?? null;
        if (! is_array($create)) {
            throw new RuntimeException('Shopify customerCreate returned an invalid payload.');
        }

        $userErrors = $create['userErrors'] ?? null;
        if (is_array($userErrors) && $userErrors !== []) {
            $messages = [];
            foreach ($userErrors as $error) {
                if (! is_array($error)) {
                    continue;
                }

                $message = trim((string) ($error['message'] ?? ''));
                if ($message !== '') {
                    $messages[] = $message;
                }
            }

            $joined = implode(' | ', $messages);
            throw new RuntimeException('Shopify customerCreate failed: ' . ($joined !== '' ? $joined : 'unknown error'));
        }

        $customer = $create['customer'] ?? null;
        if (! is_array($customer)) {
            throw new RuntimeException('Shopify customerCreate did not return a customer object.');
        }

        return $customer;
    }

    /**
     * @param  array<string,mixed>  $customerPayload
     */
    protected function upsertCanonicalShopifyMappings(
        MarketingProfile $profile,
        string $storeKey,
        ?int $tenantId,
        array $customerPayload,
        string $provenance
    ): void {
        $shopifyCustomerId = $this->extractShopifyCustomerId($customerPayload);
        if ($shopifyCustomerId === null) {
            throw new RuntimeException('Unable to extract Shopify customer ID from customer payload.');
        }

        $sourceId = $this->shopifyCustomerSourceId($storeKey, $shopifyCustomerId);
        $existingLink = MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', $sourceId)
            ->first();

        if ($existingLink && (int) $existingLink->marketing_profile_id !== (int) $profile->id) {
            throw new RuntimeException("Shopify customer link '{$sourceId}' is already owned by another marketing profile.");
        }

        $email = $this->nullableString($customerPayload['email'] ?? null) ?: $this->nullableString($profile->email);
        $phone = $this->nullableString($customerPayload['phone'] ?? null) ?: $this->nullableString($profile->phone);

        DB::transaction(function () use ($profile, $tenantId, $sourceId, $storeKey, $shopifyCustomerId, $customerPayload, $provenance, $email, $phone): void {
            MarketingProfileLink::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'source_type' => 'shopify_customer',
                    'source_id' => $sourceId,
                ],
                [
                    'marketing_profile_id' => $profile->id,
                    'source_meta' => array_filter([
                        'source_system' => 'shopify',
                        'shopify_store_key' => $storeKey,
                        'shopify_customer_id' => $shopifyCustomerId,
                        'shopify_customer_gid' => $this->nullableString($customerPayload['id'] ?? null),
                        'provisioning_source' => $provenance,
                    ], static fn (mixed $value): bool => $value !== null && $value !== ''),
                    'match_method' => 'shopify_customer_provision',
                    'confidence' => 1.00,
                ]
            );

            CustomerExternalProfile::query()->updateOrCreate(
                [
                    'provider' => 'shopify',
                    'integration' => 'shopify_customer',
                    'store_key' => $storeKey,
                    'external_customer_id' => $shopifyCustomerId,
                ],
                [
                    'tenant_id' => $tenantId,
                    'marketing_profile_id' => $profile->id,
                    'external_customer_gid' => $this->nullableString($customerPayload['id'] ?? null),
                    'first_name' => $this->nullableString($customerPayload['firstName'] ?? null) ?: $this->nullableString($profile->first_name),
                    'last_name' => $this->nullableString($customerPayload['lastName'] ?? null) ?: $this->nullableString($profile->last_name),
                    'full_name' => $this->fullName(
                        $this->nullableString($customerPayload['firstName'] ?? null) ?: $this->nullableString($profile->first_name),
                        $this->nullableString($customerPayload['lastName'] ?? null) ?: $this->nullableString($profile->last_name)
                    ),
                    'email' => $email,
                    'normalized_email' => $this->normalizer->normalizeEmail($email),
                    'phone' => $phone,
                    'normalized_phone' => $this->normalizer->normalizePhone($phone),
                    'source_channels' => ['shopify'],
                    'synced_at' => now(),
                ]
            );
        });
    }

    /**
     * @param  array<string,mixed>  $customerPayload
     */
    protected function extractShopifyCustomerId(array $customerPayload): ?string
    {
        $legacyId = $this->nullableString($customerPayload['legacyResourceId'] ?? null);
        if ($legacyId !== null && preg_match('/^\d+$/', $legacyId) === 1) {
            return $legacyId;
        }

        $gid = $this->nullableString($customerPayload['id'] ?? null);
        if ($gid !== null && preg_match('/(\d+)$/', $gid, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $customerPayload
     */
    protected function sourceIdFromCustomer(string $storeKey, array $customerPayload): ?string
    {
        $customerId = $this->extractShopifyCustomerId($customerPayload);
        if ($customerId === null) {
            return null;
        }

        return $this->shopifyCustomerSourceId($storeKey, $customerId);
    }

    protected function shopifyCustomerSourceId(string $storeKey, string $customerId): string
    {
        return $storeKey . ':' . $customerId;
    }

    protected function parseShopifyCustomerIdFromSourceId(string $sourceId): ?string
    {
        if (preg_match('/^[^:]+:(\d+)$/', trim($sourceId), $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    protected function normalizeStoreKey(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $tenantId = (int) $value;

        return $tenantId > 0 ? $tenantId : null;
    }

    protected function fullName(?string $firstName, ?string $lastName): ?string
    {
        $fullName = trim((string) $firstName . ' ' . (string) $lastName);

        return $fullName !== '' ? $fullName : null;
    }

    protected function isE164(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        return preg_match('/^\+[1-9]\d{7,14}$/', $value) === 1;
    }

    protected function isRecoverableDuplicateCreateError(string $message): bool
    {
        $normalized = strtolower(trim($message));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'taken')
            || str_contains($normalized, 'already exists')
            || str_contains($normalized, 'already been taken');
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    protected function result(string $status, MarketingProfile $profile, ?string $storeKey, ?int $tenantId, array $extra = []): array
    {
        return array_replace([
            'status' => $status,
            'marketing_profile_id' => (int) $profile->id,
            'store_key' => $storeKey,
            'tenant_id' => $tenantId,
        ], $extra);
    }
}
