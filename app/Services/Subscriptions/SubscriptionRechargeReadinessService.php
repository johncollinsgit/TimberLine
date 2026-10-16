<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeResource;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Local candidate preparation only. A matching shadow is never execution authority. */
class SubscriptionRechargeReadinessService
{
    public const SETTINGS_GAPS = [
        'notifications' => 'Notification templates, enabled events and recipient rules',
        'dunning' => 'Failed-payment retries, dunning and cancellation rules',
        'customer_portal' => 'Customer portal permissions, pause and cancellation options',
        'workflows' => 'Workflows, retention tools and third-party automations',
        'shipping_tax' => 'Shipping, tax and delivery configuration',
        'payment_vault' => 'Payment gateway ownership and supported vault migration',
    ];

    public const COVERAGE_GAPS = [
        'plans_discounts' => 'Plans, discounts and enrollment rules',
        'queued_activity' => 'Queued charges, prepaid deliveries and one-time items',
        'extensions' => 'Bundles, credits and metafields when present',
        'history' => 'Historical charges outside the provider API window',
    ];

    public function __construct(private SubscriptionAccessService $access) {}

    /** Page-safe offline totals. Unknown source inventory is never reported as zero. */
    public function summary(int $tenantId, User $actor, int $connectionId): array
    {
        $this->authorize($tenantId, $actor);
        $connection = $this->connection($tenantId, $connectionId);
        $generation = $this->generation($connection);
        $counts = $states = [];
        if ($generation) {
            $rows = $this->resources($connection, $generation);
            $counts = (clone $rows)->selectRaw('resource_type, COUNT(*) AS total')->groupBy('resource_type')->pluck('total', 'resource_type')->map(fn ($n) => (int) $n)->all();
            $states = (clone $rows)->selectRaw('mapping_state, COUNT(*) AS total')->groupBy('mapping_state')->pluck('total', 'mapping_state')->map(fn ($n) => (int) $n)->all();
        }

        return [
            'connection_id' => $connectionId, 'generation_id' => $generation,
            'source_verified' => $connection->status === 'verified' && $connection->verified_at !== null,
            'last_complete_at' => $connection->last_complete_sync_at?->toISOString(),
            'source_counts' => $generation ? $counts : null, 'mapping_counts' => $generation ? $states : null,
            'unreviewed_count' => $generation ? ($states['unreviewed'] ?? 0) : null,
            'customer_count' => $generation ? ($counts['customer'] ?? 0) : null,
            'subscription_count' => $generation ? ($counts['subscription'] ?? 0) : null,
            'stage' => ! $generation ? 'source_capture_required' : (($states['unreviewed'] ?? 0) > 0 ? 'mapping_review_required' : 'source_review_prepared'),
            'settings_gaps' => self::SETTINGS_GAPS, 'coverage_gaps' => self::COVERAGE_GAPS,
            'live_ready' => false, 'one_to_one_verified' => false,
            'billing_enabled' => false, 'servicing_enabled' => false, 'messages_enabled' => false,
            'cutover_enabled' => false, 'purchasing_enabled' => false,
            'limitations' => ['Source field matching does not establish functional parity or payment-vault ownership.',
                'Manual settings evidence and full historical coverage still require review.',
                'Existing CRM, Shopify, Website and operational subscription records are unchanged.'],
        ];
    }

    /** An explicit bounded local batch; repeat requests converge without provider calls. */
    public function prepareBatch(int $tenantId, User $actor, int $connectionId, string $expectedGeneration): array
    {
        $this->authorize($tenantId, $actor);
        abort_unless(Str::isUuid($expectedGeneration), 422, 'A complete source capture is required.');

        return DB::transaction(function () use ($tenantId, $actor, $connectionId, $expectedGeneration) {
            $connection = $this->connection($tenantId, $connectionId, true);
            abort_unless($this->generation($connection) === $expectedGeneration && $connection->status === 'verified', 409, 'The accepted source capture changed. Review the current capture.');
            $rows = $this->resources($connection, $expectedGeneration)->where('mapping_state', 'unreviewed')->orderBy('id')->limit(100)->lockForUpdate()->get();
            foreach ($rows as $row) {
                $mapping = $this->candidate($connection, $row);
                $mapping['review_actor_id'] = (int) $actor->id;
                $mapping['prepared_at'] = now()->toISOString();
                SubscriptionEvidence::assertContent($mapping);
                $row->forceFill(['mapping' => $mapping, 'mapping_state' => $mapping['state']])->save();
            }
            $this->authorize($tenantId, $actor);

            return ['prepared_count' => $rows->count(), 'generation_id' => $expectedGeneration,
                'remaining_count' => $this->resources($connection, $expectedGeneration)->where('mapping_state', 'unreviewed')->count(),
                'live_ready' => false, 'cutover_enabled' => false];
        }, 3);
    }

    /** Candidates preserve source values verbatim; provider IDs never become adopted vault/Shopify IDs. */
    private function candidate(SubscriptionRechargeConnection $connection, SubscriptionRechargeResource $row): array
    {
        $source = $row->snapshot ?? [];
        $coverage = $source['_coverage'] ?? [];
        unset($source['_coverage']);
        $issues = [];
        $type = $row->resource_type;
        $identity = is_scalar($source['id'] ?? null) ? (string) $source['id'] : null;
        if (! $identity || $identity !== $row->provider_id) {
            $issues[] = 'source_identity_mismatch';
        }
        if ($row->completeness !== 'complete' || $row->stale_reason) {
            $issues[] = 'incomplete_or_stale_source';
        }
        if (! in_array($type, ['store', 'customer', 'address', 'subscription', 'payment_method'], true)) {
            $issues[] = 'unsupported_resource_mapping';
        }
        if (! empty($coverage['omitted_fields'])) {
            $issues[] = 'excluded_source_fields_require_review';
        }

        $links = [];
        if (in_array($type, ['address', 'subscription', 'payment_method'], true)) {
            $customer = is_scalar($source['customer_id'] ?? null) ? (string) $source['customer_id'] : null;
            $linked = $customer ? $this->related($connection, $row, 'customer', $customer) : null;
            if (! $linked || $row->customer_provider_id !== $customer) {
                $issues[] = 'customer_link_unverified';
            }
            $links['customer_source_id'] = $customer;
        }
        if ($type === 'address' && isset($source['payment_method_id'])) {
            $method = $this->related($connection, $row, 'payment_method', (string) $source['payment_method_id']);
            if (! $method || ($method->snapshot['customer_id'] ?? null) !== ($source['customer_id'] ?? null)) {
                $issues[] = 'payment_method_link_unverified';
            }
            $links['payment_method_source_id'] = (string) $source['payment_method_id'];
        }
        if ($type === 'subscription') {
            $address = isset($source['address_id']) ? $this->related($connection, $row, 'address', (string) $source['address_id']) : null;
            if (! $address || ($address->snapshot['customer_id'] ?? null) !== ($source['customer_id'] ?? null)) {
                $issues[] = 'address_link_unverified';
            }
            $links['address_source_id'] = isset($source['address_id']) ? (string) $source['address_id'] : null;
            if (! is_string($source['price'] ?? null) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $source['price'])) {
                $issues[] = 'exact_decimal_price_required';
            }
            if (! is_int($source['quantity'] ?? null) || $source['quantity'] < 1) {
                $issues[] = 'quantity_unverified';
            }
            foreach (['order_interval_frequency', 'charge_interval_frequency'] as $field) {
                if (! is_int($source[$field] ?? null) || $source[$field] < 1) {
                    $issues[] = 'cadence_unverified';
                }
            }
            if (! in_array($source['order_interval_unit'] ?? null, ['day', 'week', 'month', 'year'], true)) {
                $issues[] = 'cadence_unverified';
            }
            if (! in_array($source['status'] ?? null, ['active', 'cancelled', 'expired'], true)) {
                $issues[] = 'status_unverified';
            }
            if (! isset($source['presentment_currency']) || ! is_string($source['presentment_currency']) || ! preg_match('/^[A-Z]{3}$/D', $source['presentment_currency'])) {
                $issues[] = 'currency_unverified';
            }
            $next = $source['next_charge_scheduled_at'] ?? null;
            if ($next !== null && (! is_string($next) || ! $this->validSourceDate($next))) {
                $issues[] = 'next_charge_date_unverified';
            }
            if (($source['status'] ?? null) === 'active' && $next === null) {
                $issues[] = 'active_schedule_unverified';
            }
            if ($source['is_prepaid'] ?? false) {
                $issues[] = 'prepaid_delivery_review_required';
            }
        }
        if ($type === 'payment_method') {
            $issues[] = 'provider_vault_authority_required';
        }

        $issues = array_values(array_unique($issues));
        $state = $issues ? 'unverified' : 'mapped';
        $copy = $source; // Not a live customer/config record; retained only in this encrypted candidate envelope.

        return ['version' => 1, 'generation_id' => $row->generation_id, 'source_provider' => 'recharge',
            'source_store_id' => $connection->provider_store_id, 'source_resource_type' => $type, 'source_id' => $row->provider_id,
            'source_observed_at' => $row->observed_at?->toISOString(), 'source_digest' => $row->canonical_digest,
            'candidate' => $copy, 'field_count' => count($source),
            'field_values_preserved' => SubscriptionEvidence::canonical($copy) === SubscriptionEvidence::canonical($source),
            'source_links' => $links, 'coverage' => $coverage, 'issues' => $issues, 'state' => $state,
            'date_policy' => 'Preserve source dates and offsets; bare dates remain store-local dates.',
            'target' => 'isolated_subscription_draft', 'live_record_created' => false, 'executable' => false];
    }

    private function validSourceDate(string $value): bool
    {
        if (! preg_match('/^(\d{4})-(\d{2})-(\d{2})(?:T([0-2][0-9]):([0-5][0-9]):([0-5][0-9])(?:\.\d{1,6})?(?:Z|[+-][0-2][0-9]:[0-5][0-9])?)?$/D', $value, $m)) {
            return false;
        }

        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) && (! isset($m[4]) || (int) $m[4] <= 23);
    }

    private function related(SubscriptionRechargeConnection $connection, SubscriptionRechargeResource $row, string $type, string $id): ?SubscriptionRechargeResource
    {
        return $this->resources($connection, $row->generation_id)->where('resource_type', $type)->where('provider_id', $id)->first();
    }

    private function resources(SubscriptionRechargeConnection $connection, string $generation): \Illuminate\Database\Eloquent\Builder
    {
        return SubscriptionRechargeResource::query()->forTenantId((int) $connection->tenant_id)->where('connection_id', $connection->id)->where('generation_id', $generation);
    }

    private function generation(SubscriptionRechargeConnection $connection): ?string
    {
        $value = data_get($connection->sync_state, 'last_complete.generation_id');

        return is_string($value) && Str::isUuid($value) ? $value : null;
    }

    private function connection(int $tenantId, int $id, bool $lock = false): SubscriptionRechargeConnection
    {
        $q = SubscriptionRechargeConnection::query()->forTenantId($tenantId)->whereKey($id);

        return ($lock ? $q->lockForUpdate() : $q)->firstOrFail();
    }

    private function authorize(int $tenantId, User $actor): void
    {
        $this->access->assertManager($tenantId, User::query()->find($actor->id));
    }
}
