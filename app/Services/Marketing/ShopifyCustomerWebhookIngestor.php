<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Support\Marketing\MarketingIdentityNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ShopifyCustomerWebhookIngestor
{
    public function __construct(
        protected MarketingProfileSyncService $profileSyncService,
        protected MarketingIdentityNormalizer $normalizer,
        protected IntegrationHealthEventRecorder $healthEventRecorder
    ) {
    }

    /**
     * @param  array<string,mixed>  $storeContext
     * @param  array<string,mixed>  $customerPayload
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function ingest(array $storeContext, array $customerPayload, array $options = []): array
    {
        $storeKey = $this->normalizeStoreKey($options['store_key'] ?? ($storeContext['key'] ?? $storeContext['store_key'] ?? null));
        $tenantId = $this->positiveInt($options['tenant_id'] ?? ($storeContext['tenant_id'] ?? null));
        $topic = strtolower(trim((string) ($options['topic'] ?? 'customers/create')));

        if ($storeKey === null) {
            return $this->result('skipped_store_context_missing', [
                'topic' => $topic,
            ]);
        }

        if ($tenantId === null) {
            return $this->result('skipped_tenant_context_missing', [
                'store_key' => $storeKey,
                'topic' => $topic,
            ]);
        }

        $shopifyCustomerId = $this->extractShopifyCustomerId($customerPayload);
        if ($shopifyCustomerId === null) {
            return $this->result('skipped_shopify_customer_id_missing', [
                'store_key' => $storeKey,
                'tenant_id' => $tenantId,
                'topic' => $topic,
            ]);
        }

        $sourceId = $this->sourceId($storeKey, $shopifyCustomerId);
        $existingLink = $this->existingShopifyLink($tenantId, $sourceId);
        $existingExternal = $this->existingShopifyExternal($tenantId, $storeKey, $shopifyCustomerId);

        $identity = $this->identityPayloadFromWebhook(
            storeKey: $storeKey,
            tenantId: $tenantId,
            shopifyCustomerId: $shopifyCustomerId,
            payload: $customerPayload,
            topic: $topic
        );

        $syncResult = $this->profileSyncService->syncExternalIdentity($identity, [
            'tenant_id' => $tenantId,
            'allow_create' => true,
            'review_context' => [
                'source_label' => 'shopify_customer_webhook',
                'source_id' => $sourceId,
                'store_key' => $storeKey,
                'topic' => $topic,
                'tenant_id' => $tenantId,
            ],
        ]);

        $profileId = $this->positiveInt($syncResult['profile_id'] ?? null)
            ?? $this->positiveInt($existingLink?->marketing_profile_id)
            ?? $this->positiveInt($existingExternal?->marketing_profile_id);
        $profile = $profileId
            ? MarketingProfile::query()->forTenantId($tenantId)->find($profileId)
            : null;

        $linkStatus = 'not_linked';
        $externalStatus = 'unchanged';

        DB::transaction(function () use (
            $storeKey,
            $tenantId,
            $shopifyCustomerId,
            $customerPayload,
            $topic,
            $profile,
            &$linkStatus,
            &$externalStatus
        ): void {
            if ($profile) {
                $linkStatus = $this->upsertShopifyLink(
                    profile: $profile,
                    tenantId: $tenantId,
                    storeKey: $storeKey,
                    shopifyCustomerId: $shopifyCustomerId,
                    payload: $customerPayload,
                    topic: $topic
                );
            }

            $externalStatus = $this->upsertExternalSnapshot(
                profileId: $profile?->id,
                tenantId: $tenantId,
                storeKey: $storeKey,
                shopifyCustomerId: $shopifyCustomerId,
                payload: $customerPayload,
                topic: $topic
            );
        });

        $status = $profile ? 'linked' : (($syncResult['status'] ?? '') === 'review' ? 'review_required' : 'snapshot_only');

        if ($status === 'review_required') {
            Log::warning('shopify customer webhook requires identity review', [
                'store_key' => $storeKey,
                'tenant_id' => $tenantId,
                'topic' => $topic,
                'source_id' => $sourceId,
                'reason' => $syncResult['reason'] ?? null,
            ]);

            $this->healthEventRecorder->record([
                'provider' => 'shopify',
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'event_type' => 'identity_conflict_pending',
                'severity' => 'warning',
                'status' => 'open',
                'context' => [
                    'topic' => $topic,
                    'source_id' => $sourceId,
                    'reason' => $syncResult['reason'] ?? null,
                ],
                'dedupe_key' => sha1(json_encode([
                    'provider' => 'shopify',
                    'event_type' => 'identity_conflict_pending',
                    'store_key' => $storeKey,
                    'source_id' => $sourceId,
                ])),
            ]);
        }

        if ($status === 'linked') {
            $this->healthEventRecorder->resolve([
                'provider' => 'shopify',
                'tenant_id' => $tenantId,
                'store_key' => $storeKey,
                'event_type' => 'identity_conflict_pending',
                'dedupe_key' => sha1(json_encode([
                    'provider' => 'shopify',
                    'event_type' => 'identity_conflict_pending',
                    'store_key' => $storeKey,
                    'source_id' => $sourceId,
                ])),
            ]);
        }

        return $this->result($status, [
            'store_key' => $storeKey,
            'tenant_id' => $tenantId,
            'topic' => $topic,
            'shopify_customer_id' => $shopifyCustomerId,
            'source_id' => $sourceId,
            'marketing_profile_id' => $profile ? (int) $profile->id : null,
            'sync_status' => (string) ($syncResult['status'] ?? ''),
            'sync_reason' => (string) ($syncResult['reason'] ?? ''),
            'link_status' => $linkStatus,
            'external_status' => $externalStatus,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    protected function identityPayloadFromWebhook(
        string $storeKey,
        int $tenantId,
        string $shopifyCustomerId,
        array $payload,
        string $topic
    ): array {
        $customerGid = $this->nullableString($payload['admin_graphql_api_id'] ?? null);
        $sourceId = $this->sourceId($storeKey, $shopifyCustomerId);

        return [
            'tenant_id' => $tenantId,
            'first_name' => $this->nullableString($payload['first_name'] ?? null),
            'last_name' => $this->nullableString($payload['last_name'] ?? null),
            'raw_email' => $this->nullableString($payload['email'] ?? null),
            'raw_phone' => $this->nullableString($payload['phone'] ?? null),
            'source_channels' => $this->sourceChannels($storeKey),
            'source_links' => [[
                'source_type' => 'shopify_customer',
                'source_id' => $sourceId,
                'source_meta' => array_filter([
                    'source_system' => 'shopify',
                    'shopify_store_key' => $storeKey,
                    'shopify_customer_id' => $shopifyCustomerId,
                    'shopify_customer_gid' => $customerGid,
                    'webhook_topic' => $topic,
                    'customer_updated_at' => $this->nullableString($payload['updated_at'] ?? null),
                ], static fn (mixed $value): bool => $value !== null && $value !== ''),
            ]],
            'primary_source' => [
                'source_type' => 'shopify_customer',
                'source_id' => $sourceId,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return 'created'|'updated'|'conflict'
     */
    protected function upsertShopifyLink(
        MarketingProfile $profile,
        int $tenantId,
        string $storeKey,
        string $shopifyCustomerId,
        array $payload,
        string $topic
    ): string {
        $sourceId = $this->sourceId($storeKey, $shopifyCustomerId);
        $existing = MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', $sourceId)
            ->first();

        if ($existing && (int) $existing->marketing_profile_id !== (int) $profile->id) {
            return 'conflict';
        }

        $mergedMeta = $this->mergeSourceMeta(
            is_array($existing?->source_meta ?? null) ? $existing->source_meta : [],
            [
                'source_system' => 'shopify',
                'shopify_store_key' => $storeKey,
                'shopify_customer_id' => $shopifyCustomerId,
                'shopify_customer_gid' => $this->nullableString($payload['admin_graphql_api_id'] ?? null),
                'webhook_topic' => $topic,
                'last_webhook_at' => now()->toIso8601String(),
            ]
        );

        MarketingProfileLink::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'source_type' => 'shopify_customer',
                'source_id' => $sourceId,
            ],
            [
                'marketing_profile_id' => $profile->id,
                'source_meta' => $mergedMeta,
                'match_method' => 'shopify_customer_webhook',
                'confidence' => 1.00,
            ]
        );

        return $existing ? 'updated' : 'created';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return 'created'|'updated'
     */
    protected function upsertExternalSnapshot(
        ?int $profileId,
        int $tenantId,
        string $storeKey,
        string $shopifyCustomerId,
        array $payload,
        string $topic
    ): string {
        $lookup = [
            'provider' => 'shopify',
            'integration' => 'shopify_customer',
            'store_key' => $storeKey,
            'external_customer_id' => $shopifyCustomerId,
        ];

        $existing = CustomerExternalProfile::query()
            ->forTenantId($tenantId)
            ->where($lookup)
            ->first();

        $incomingEmail = $this->nullableString($payload['email'] ?? null);
        $incomingPhone = $this->nullableString($payload['phone'] ?? null);
        $incomingFirst = $this->nullableString($payload['first_name'] ?? null);
        $incomingLast = $this->nullableString($payload['last_name'] ?? null);

        $email = $incomingEmail ?? $this->nullableString($existing?->email);
        $phone = $incomingPhone ?? $this->nullableString($existing?->phone);
        $firstName = $incomingFirst ?? $this->nullableString($existing?->first_name);
        $lastName = $incomingLast ?? $this->nullableString($existing?->last_name);

        $updatedAt = $this->parseDate($payload['updated_at'] ?? null);
        $createdAt = $this->parseDate($payload['created_at'] ?? null);
        $lastOrderAt = $this->parseDate($payload['last_order_at'] ?? null);
        $existingLastActivity = $existing?->last_activity_at?->toImmutable();
        $lastActivity = $this->latestDate(
            $lastOrderAt,
            $this->latestDate($updatedAt, $this->latestDate($createdAt, $existingLastActivity))
        );

        $existingChannels = is_array($existing?->source_channels ?? null) ? $existing->source_channels : [];
        $channels = array_values(array_unique(array_filter(array_merge(
            $existingChannels,
            $this->sourceChannels($storeKey)
        ))));

        $rawMetafields = is_array($existing?->raw_metafields ?? null) ? $existing->raw_metafields : [];
        $rawMetafields['shopify_customer_webhook'] = array_filter([
            'topic' => $topic,
            'received_at' => now()->toIso8601String(),
            'customer_updated_at' => $this->nullableString($payload['updated_at'] ?? null),
            'customer_created_at' => $this->nullableString($payload['created_at'] ?? null),
            'accepts_marketing' => $this->parseAcceptsMarketing($payload),
            'email_marketing_state' => $this->nullableString(data_get($payload, 'email_marketing_consent.state')),
            'sms_marketing_state' => $this->nullableString(data_get($payload, 'sms_marketing_consent.state')),
            'verified_email' => is_bool($payload['verified_email'] ?? null) ? (bool) $payload['verified_email'] : null,
            'tags' => $this->nullableString($payload['tags'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');

        CustomerExternalProfile::query()->updateOrCreate(
            $lookup,
            [
                'tenant_id' => $tenantId,
                'marketing_profile_id' => $profileId ?: $this->positiveInt($existing?->marketing_profile_id),
                'external_customer_gid' => $this->nullableString($payload['admin_graphql_api_id'] ?? null) ?: $this->nullableString($existing?->external_customer_gid),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'full_name' => $this->fullName($firstName, $lastName) ?? $this->nullableString($existing?->full_name),
                'email' => $email,
                'normalized_email' => $this->normalizer->normalizeEmail($email),
                'phone' => $phone,
                'normalized_phone' => $this->normalizer->normalizePhone($phone),
                'accepts_marketing' => $this->parseAcceptsMarketing($payload) ?? (is_bool($existing?->accepts_marketing) ? (bool) $existing->accepts_marketing : null),
                'order_count' => is_numeric($payload['orders_count'] ?? null) ? max(0, (int) $payload['orders_count']) : $existing?->order_count,
                'last_order_at' => $lastOrderAt ?: $existing?->last_order_at,
                'last_activity_at' => $lastActivity,
                'source_channels' => $channels !== [] ? $channels : null,
                'raw_metafields' => $rawMetafields !== [] ? $rawMetafields : null,
                'synced_at' => now(),
            ]
        );

        return $existing ? 'updated' : 'created';
    }

    protected function existingShopifyLink(int $tenantId, string $sourceId): ?MarketingProfileLink
    {
        return MarketingProfileLink::query()
            ->forTenantId($tenantId)
            ->where('source_type', 'shopify_customer')
            ->where('source_id', $sourceId)
            ->first();
    }

    protected function existingShopifyExternal(int $tenantId, string $storeKey, string $shopifyCustomerId): ?CustomerExternalProfile
    {
        return CustomerExternalProfile::query()
            ->forTenantId($tenantId)
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->where('store_key', $storeKey)
            ->where('external_customer_id', $shopifyCustomerId)
            ->first();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function extractShopifyCustomerId(array $payload): ?string
    {
        $id = $payload['id'] ?? null;
        if (is_numeric($id) && (int) $id > 0) {
            return (string) ((int) $id);
        }

        $gid = $this->nullableString($payload['admin_graphql_api_id'] ?? null);
        if ($gid !== null && preg_match('/(\d+)$/', $gid, $matches) === 1) {
            return (string) $matches[1];
        }

        return null;
    }

    protected function parseAcceptsMarketing(array $payload): ?bool
    {
        if (is_bool($payload['accepts_marketing'] ?? null)) {
            return (bool) $payload['accepts_marketing'];
        }

        $emailState = strtolower((string) data_get($payload, 'email_marketing_consent.state', ''));
        if (in_array($emailState, ['subscribed', 'confirmed_opt_in'], true)) {
            return true;
        }
        if (in_array($emailState, ['unsubscribed', 'not_subscribed', 'invalid', 'pending'], true)) {
            return false;
        }

        return null;
    }

    protected function sourceId(string $storeKey, string $shopifyCustomerId): string
    {
        return $storeKey . ':' . $shopifyCustomerId;
    }

    /**
     * @return array<int,string>
     */
    protected function sourceChannels(string $storeKey): array
    {
        $channels = ['shopify'];
        if ($storeKey === 'wholesale') {
            $channels[] = 'wholesale';
        } else {
            $channels[] = 'online';
        }

        return $channels;
    }

    protected function normalizeStoreKey(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function fullName(?string $firstName, ?string $lastName): ?string
    {
        $fullName = trim((string) $firstName . ' ' . (string) $lastName);

        return $fullName !== '' ? $fullName : null;
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($string);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function latestDate(?CarbonImmutable $left, ?CarbonImmutable $right): ?CarbonImmutable
    {
        if ($left === null) {
            return $right;
        }
        if ($right === null) {
            return $left;
        }

        return $left->greaterThan($right) ? $left : $right;
    }

    /**
     * @param  array<string,mixed>  $existing
     * @param  array<string,mixed>  $incoming
     * @return array<string,mixed>
     */
    protected function mergeSourceMeta(array $existing, array $incoming): array
    {
        foreach ($incoming as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $existing[$key] = $value;
        }

        return $existing;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    protected function result(string $status, array $extra = []): array
    {
        return array_replace([
            'status' => $status,
        ], $extra);
    }
}
