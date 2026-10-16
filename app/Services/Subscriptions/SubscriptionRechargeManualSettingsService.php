<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionRechargeConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Merchant-observed settings and exact isolated drafts; never runtime configuration. */
class SubscriptionRechargeManualSettingsService
{
    public const MAX_ENVELOPE_BYTES = 1048576;

    public const MAX_DOCUMENT_BYTES = 65536;

    public const MAX_CATEGORY_VERSIONS = 20;

    public const SOURCE_PATHS = [
        'notifications' => ['/merchant/settings/notifications'],
        'dunning' => ['/merchant/retain/failed-payment-recovery'],
        'customer_portal' => ['/merchant/storefront/customer-portal', '/merchant/retain/cancellation-prevention'],
        'workflows' => ['/merchant/automate'],
        'shipping_tax' => ['/merchant/settings/taxes', '/merchant/settings/shipping'],
        'payment_vault' => ['/merchant/settings/payment'],
        'order_processing' => ['/merchant/settings/order-processing'],
        'legal' => ['/merchant/settings/legal'],
    ];

    private const INPUT_KEYS = ['schema_version', 'expected_identity', 'expected_merchant_origin', 'category', 'source_url', 'observed_at', 'expected_previous_version_id', 'fields', 'required_fields'];

    private const MERCHANT_CONTACT_FIELDS = ['sender_email', 'reply_to_email', 'support_email', 'merchant_email'];

    public function __construct(private SubscriptionAccessService $access) {}

    /** No provider read gate is required for this explicit local evidence-only write. */
    public function record(int $tenantId, User $actor, int $connectionId, string $requestUuid, array $document): array
    {
        $this->authorize($tenantId, $actor);
        abort_unless(Str::isUuid($requestUuid), 422, 'A request UUID is required.');
        $requestUuid = strtolower($requestUuid);
        $document = $this->validate($document);

        return DB::transaction(function () use ($tenantId, $actor, $connectionId, $requestUuid, $document) {
            $connection = $this->connection($tenantId, $connectionId, true);
            $this->authorize($tenantId, $actor);
            abort_unless($connection->status === 'verified' && $connection->verified_at !== null, 409, 'A verified source connection is required.');
            abort_unless($document['expected_identity'] === $this->identity($connection), 409, 'The reviewed source identity does not match this connection.');
            $evidence = $connection->evidence ?? [];
            $manual = $evidence['manual_settings'] ?? ['schema_version' => 1, 'merchant_origin' => $document['expected_merchant_origin'],
                'origin_attribution' => 'merchant_observed_not_api_verified', 'store_identity' => $this->identity($connection), 'versions' => []];
            $this->assertEnvelope($manual, $connection);
            abort_unless($manual['merchant_origin'] === $document['expected_merchant_origin'], 409, 'The original reviewed merchant origin must be retained.');
            foreach ($manual['versions'] as $version) {
                if ($version['request_uuid'] === $requestUuid) {
                    // Compare the retained plaintext canonical request, so APP_KEY rotation
                    // does not turn a valid replay into a conflicting new observation.
                    $original = array_intersect_key($version['source'], array_flip(self::INPUT_KEYS));
                    abort_unless($version['actor_user_id'] === (int) $actor->id && SubscriptionEvidence::canonical($original) === SubscriptionEvidence::canonical($document), 409, 'The request UUID conflicts with its original settings observation.');

                    return $this->receipt($version, true);
                }
            }
            $history = array_values(array_filter($manual['versions'], fn ($v) => $v['source']['category'] === $document['category']));
            $previous = $history ? $history[array_key_last($history)]['version_id'] : null;
            abort_unless($document['expected_previous_version_id'] === $previous, 409, 'Settings evidence changed. Review the latest version before appending.');
            abort_if(count($history) >= self::MAX_CATEGORY_VERSIONS, 409, 'Settings history reached its retention bound; preserve it before expanding storage.');
            $missing = array_values(array_diff($document['required_fields'], array_keys($document['fields'])));
            $nulls = array_keys(array_filter($document['fields'], fn ($v) => $v === null));
            $versionId = (string) Str::uuid();
            $digest = SubscriptionEvidence::digest(SubscriptionEvidence::canonical($document));
            $version = ['version_id' => $versionId, 'request_uuid' => $requestUuid, 'actor_user_id' => (int) $actor->id, 'recorded_at' => now()->toISOString(),
                'source' => $document + ['capture_method' => 'reviewed_manual_transcription', 'domain_api_verified' => false],
                'source_digest' => $digest, 'digest_key_id' => SubscriptionEvidence::keyId(),
                'draft' => ['source_version_id' => $versionId, 'source_digest' => $digest, 'fields' => $document['fields'],
                    'field_values_preserved' => true, 'missing_fields' => $missing, 'null_fields' => $nulls,
                    'state' => $missing || $nulls ? 'unverified' : 'captured_fields_prepared', 'coverage_verified' => false,
                    'target' => 'isolated_subscription_settings_draft', 'runtime_wired' => false, 'executable' => false],
                'one_to_one_verified' => false, 'live_ready' => false];
            $manual['versions'][] = $version; // Never edit, replace or evict a retained version.
            $evidence['manual_settings'] = $manual;
            SubscriptionEvidence::assertContent($evidence, self::MAX_ENVELOPE_BYTES);
            $connection->forceFill(['evidence' => $evidence])->save();
            $this->authorize($tenantId, $actor);

            return $this->receipt($version, false);
        }, 3);
    }

    /** Safe offline counts and provenance only; no source values or drafts are serialized. */
    public function summary(int $tenantId, User $actor, int $connectionId): array
    {
        $this->authorize($tenantId, $actor);
        $connection = $this->connection($tenantId, $connectionId);
        $manual = $connection->evidence['manual_settings'] ?? null;
        $categories = [];
        foreach (array_keys(self::SOURCE_PATHS) as $category) {
            $versions = array_values(array_filter($manual['versions'] ?? [], fn ($v) => $v['source']['category'] === $category));
            $latest = $versions ? $versions[array_key_last($versions)] : null;
            $categories[$category] = ['version_count' => count($versions), 'latest_version_id' => $latest['version_id'] ?? null,
                'state' => $latest['draft']['state'] ?? 'source_observation_required', 'field_count' => $latest ? count($latest['source']['fields']) : null,
                'observed_at' => $latest['source']['observed_at'] ?? null, 'missing_fields' => $latest['draft']['missing_fields'] ?? null,
                'null_fields' => $latest['draft']['null_fields'] ?? null, 'field_values_preserved' => $latest['draft']['field_values_preserved'] ?? false,
                'coverage_verified' => false, 'runtime_wired' => false];
        }

        return ['connection_id' => $connectionId, 'merchant_origin_attribution' => $manual['origin_attribution'] ?? null,
            'categories' => $categories, 'one_to_one_verified' => false, 'live_ready' => false,
            'billing_enabled' => false, 'servicing_enabled' => false, 'messages_enabled' => false, 'cutover_enabled' => false];
    }

    /** Private tenant-manager review only. The caller must escape every displayed value. */
    public function latestForReview(int $tenantId, User $actor, int $connectionId, string $category): ?array
    {
        $this->authorize($tenantId, $actor);
        abort_unless(isset(self::SOURCE_PATHS[$category]), 404);
        $connection = $this->connection($tenantId, $connectionId);
        $versions = array_values(array_filter($connection->evidence['manual_settings']['versions'] ?? [], fn ($v) => $v['source']['category'] === $category));
        if (! $versions) {
            return null;
        }
        $version = $versions[array_key_last($versions)];

        return ['category' => $category, 'version_id' => $version['version_id'], 'actor_user_id' => $version['actor_user_id'],
            'recorded_at' => $version['recorded_at'], 'source_url' => $version['source']['source_url'], 'observed_at' => $version['source']['observed_at'],
            'source_identity' => $version['source']['expected_identity'], 'source_digest' => $version['source_digest'],
            'source_fields' => $version['source']['fields'], 'draft_fields' => $version['draft']['fields'],
            'required_fields' => $version['source']['required_fields'], 'missing_fields' => $version['draft']['missing_fields'], 'null_fields' => $version['draft']['null_fields'],
            'field_values_preserved' => SubscriptionEvidence::canonical($version['source']['fields']) === SubscriptionEvidence::canonical($version['draft']['fields']),
            'state' => $version['draft']['state'], 'origin_attribution' => 'merchant_observed_not_api_verified',
            'coverage_verified' => false, 'runtime_wired' => false, 'executable' => false, 'one_to_one_verified' => false, 'live_ready' => false];
    }

    private function receipt(array $version, bool $replayed): array
    {
        return ['status' => 'completed', 'version_id' => $version['version_id'], 'category' => $version['source']['category'],
            'replayed' => $replayed, 'state' => $version['draft']['state'], 'field_count' => count($version['source']['fields']),
            'missing_fields' => $version['draft']['missing_fields'], 'null_fields' => $version['draft']['null_fields'],
            'field_values_preserved' => true, 'one_to_one_verified' => false, 'live_ready' => false,
            'billing_enabled' => false, 'servicing_enabled' => false, 'messages_enabled' => false, 'cutover_enabled' => false];
    }

    private function validate(array $document): array
    {
        SubscriptionEvidence::assertContent($document, self::MAX_DOCUMENT_BYTES);
        abort_unless(! array_diff(array_keys($document), self::INPUT_KEYS) && ! array_diff(self::INPUT_KEYS, array_keys($document)) && ($document['schema_version'] ?? null) === 1, 422, 'A versioned complete settings observation envelope is required.');
        $identity = $document['expected_identity'];
        abort_unless(is_array($identity) && count($identity) === 3 && ! array_diff(array_keys($identity), ['provider_store_id', 'platform', 'identifier']), 422, 'Explicit source store identity is required.');
        foreach (['provider_store_id', 'platform', 'identifier'] as $field) {
            abort_unless(is_string($identity[$field] ?? null) && strlen($identity[$field]) > 0 && strlen($identity[$field]) <= 190, 422, 'Explicit source store identity is required.');
        }
        // Use one stable key order so direct identity comparison cannot depend on JSON order.
        $document['expected_identity'] = ['provider_store_id' => $identity['provider_store_id'], 'platform' => $identity['platform'], 'identifier' => $identity['identifier']];
        $origin = $document['expected_merchant_origin'];
        abort_unless(is_string($origin) && preg_match('~^https://[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.admin\.rechargeapps\.com$~D', $origin), 422, 'An explicitly reviewed Recharge merchant origin is required.');
        $category = $document['category'];
        abort_unless(is_string($category) && isset(self::SOURCE_PATHS[$category]) && is_string($document['source_url'])
            && in_array($document['source_url'], array_map(fn ($path) => $origin.$path, self::SOURCE_PATHS[$category]), true), 422, 'A known settings page on the reviewed merchant origin is required.');
        $observed = $document['observed_at'];
        abort_unless(is_string($observed) && preg_match('/^(\d{4})-(\d{2})-(\d{2})T([01][0-9]|2[0-3]):([0-5][0-9]):([0-5][0-9])(?:\.\d{1,6})?(?:Z|[+-](?:0[0-9]|1[0-4]):[0-5][0-9])$/D', $observed, $parts)
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]), 422, 'An explicit ISO source observation time with offset is required.');
        abort_unless($document['expected_previous_version_id'] === null || (is_string($document['expected_previous_version_id']) && Str::isUuid($document['expected_previous_version_id'])), 422, 'An explicit previous settings version is required.');
        $fields = $document['fields'];
        abort_unless(is_array($fields) && ! array_is_list($fields) && count($fields) <= 50, 422, 'A bounded named settings field map is required.');
        $nodes = 0;
        $this->values($fields, 0, $nodes);
        $required = $document['required_fields'];
        abort_unless(is_array($required) && array_is_list($required) && count($required) <= 100, 422, 'Required field names must be an explicit bounded list.');
        foreach ($required as $name) {
            $this->fieldName($name);
        }
        abort_unless(count($required) === count(array_unique($required)), 422, 'Required field names must be unique.');

        return $document;
    }

    private function values(array $value, int $depth, int &$nodes): void
    {
        abort_if($depth > 4 || count($value) > 100, 422, 'Settings arrays exceed their shape bound.');
        $list = array_is_list($value);
        foreach ($value as $key => $child) {
            abort_if(++$nodes > 1000, 422, 'Settings fields exceed their shape bound.');
            if (! $list) {
                $this->fieldName($key);
            }
            if (! $list && in_array($key, self::MERCHANT_CONTACT_FIELDS, true)) {
                abort_unless($child === null || (is_string($child) && strlen($child) <= 254 && filter_var($child, FILTER_VALIDATE_EMAIL) !== false), 422, 'Merchant contact fields require one valid business email address.');

                continue;
            }
            if (is_array($child)) {
                $this->values($child, $depth + 1, $nodes);
            } else {
                abort_unless(is_bool($child) || is_int($child) || is_string($child) || $child === null, 422, 'Settings values must be typed primitives or bounded arrays.');
                abort_if(is_int($child) && ($child < -2147483648 || $child > 2147483647), 422, 'A settings integer exceeds its safe bound.');
                if (is_string($child)) {
                    abort_unless(strlen($child) <= 4096 && preg_match('//u', $child) && ! preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', $child), 422, 'A settings string exceeds its safe shape.');
                    abort_if(preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $child)
                        || preg_match('/(?:\d[ -]?){13,19}/', $child)
                        || preg_match('/(?:Bearer\s+|sk_(?:live|test)_|-----BEGIN|(?:secret|token|password|api[_ -]?key)\s*[:=])/i', $child), 422, 'Secrets, personal contacts and payment data are not settings evidence.');
                }
            }
        }
    }

    private function fieldName(mixed $name): void
    {
        abort_unless(is_string($name) && preg_match('/^[a-z][a-z0-9_]{0,79}$/D', $name), 422, 'Settings field names must be bounded safe names.');
        $flat = str_replace('_', '', $name);
        abort_if(preg_match('/secret|token|password|credential|apikey|privatekey|gatewaykey|card|cvv|cvc|authorization|cookie|portalhash/', $flat)
            || in_array($flat, ['firstname', 'lastname', 'customername', 'customeremail', 'customerphone', 'customeraddress', 'billingaddress', 'shippingaddress', 'email', 'phone', 'address1', 'address2', 'zip', 'postcode', 'socialsecuritynumber'], true), 422, 'Private credentials, customer identity and payment fields are not settings evidence.');
    }

    private function assertEnvelope(array $manual, SubscriptionRechargeConnection $connection): void
    {
        abort_unless(($manual['schema_version'] ?? null) === 1 && ($manual['store_identity'] ?? null) === $this->identity($connection)
            && is_array($manual['versions'] ?? null) && array_is_list($manual['versions']), 409, 'The retained manual settings envelope requires review.');
    }

    private function identity(SubscriptionRechargeConnection $connection): array
    {
        return ['provider_store_id' => $connection->provider_store_id, 'platform' => $connection->platform, 'identifier' => $connection->identifier];
    }

    private function authorize(int $tenantId, User $actor): void
    {
        $this->access->assertManager($tenantId, User::query()->find($actor->id));
    }

    private function connection(int $tenantId, int $id, bool $lock = false): SubscriptionRechargeConnection
    {
        $query = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->whereKey($id);

        return ($lock ? $query->lockForUpdate() : $query)->firstOrFail();
    }
}
