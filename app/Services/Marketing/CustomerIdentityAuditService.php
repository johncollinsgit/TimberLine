<?php

namespace App\Services\Marketing;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerIdentityAuditService
{
    private const MERGE_FIELDS = [
        'first_name', 'last_name', 'email', 'phone',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'notes', 'tags',
    ];

    public function __construct(private readonly MarketingProfileMergeReferenceRegistry $registry) {}

    /** @return array<string,mixed> */
    public function audit(int $tenantId, string $tenantSlug, string $storeKey, ?string $query = null): array
    {
        $filter = strtolower(trim((string) $query));
        $profiles = DB::table('marketing_profiles as profiles')
            ->where('profiles.tenant_id', $tenantId)
            ->whereNull('profiles.merged_at')
            ->get([
                'profiles.id',
                'profiles.first_name',
                'profiles.last_name',
                'profiles.normalized_first_name',
                'profiles.normalized_last_name',
                'profiles.email',
                'profiles.normalized_email',
                'profiles.phone',
                'profiles.normalized_phone',
            ]);

        if ($filter !== '') {
            $profiles = $profiles->filter(function (object $profile) use ($filter): bool {
                $haystack = strtolower(trim(implode(' ', [
                    $profile->first_name ?? '',
                    $profile->last_name ?? '',
                    $profile->email ?? '',
                    $profile->phone ?? '',
                ])));

                return str_contains($haystack, $filter);
            })->values();
        }

        $clusters = collect($this->connectedDuplicateClusters($profiles))
            ->map(function (array $cluster) use ($tenantId, $storeKey): array {
                $members = $this->loadFullProfiles($cluster['profile_ids']);
                $facts = $members->map(fn (object $profile): array => $this->profileFacts($profile, $tenantId, $storeKey));
                $recommendation = $this->recommendation($facts);

                return [
                    'identities' => $cluster['identities'],
                    'profile_ids' => $cluster['profile_ids'],
                    'status' => $recommendation['status'],
                    'recommended_survivor_profile_id' => $recommendation['survivor_profile_id'],
                    'shopify_merge_required' => $recommendation['shopify_merge_required'],
                    'review_reasons' => $recommendation['review_reasons'],
                    'field_sources' => $recommendation['field_sources'],
                    'donor_only_data' => $recommendation['donor_only_data'],
                    'profiles' => $facts->all(),
                ];
            })
            ->sortBy(fn (array $cluster): int => (int) ($cluster['recommended_survivor_profile_id'] ?? $cluster['profile_ids'][0] ?? 0))
            ->values();

        return [
            'mode' => 'preview',
            'tenant_id' => $tenantId,
            'tenant_slug' => $tenantSlug,
            'store_key' => $storeKey,
            'clusters' => $clusters->count(),
            'deterministic_clusters' => $clusters->where('status', 'deterministic')->count(),
            'needs_review_clusters' => $clusters->where('status', 'needs_review')->count(),
            'shopify_merge_clusters' => $clusters->where('shopify_merge_required', true)->count(),
            'birthday_data_clusters' => $clusters->filter(fn (array $cluster): bool => collect($cluster['profiles'])->contains(fn (array $profile): bool => $profile['birthdays'] !== []))->count(),
            'results' => $clusters->all(),
        ];
    }

    /** @return array<int,array{identities:array<int,string>,profile_ids:array<int,int>}> */
    private function connectedDuplicateClusters(Collection $profiles): array
    {
        $identityMembers = [];
        foreach ($profiles as $profile) {
            $email = strtolower(trim((string) ($profile->normalized_email ?? $profile->email ?? '')));
            $phone = preg_replace('/\D+/', '', (string) ($profile->normalized_phone ?? $profile->phone ?? ''));
            $firstName = strtolower(trim((string) ($profile->normalized_first_name ?? $profile->first_name ?? '')));
            $lastName = strtolower(trim((string) ($profile->normalized_last_name ?? $profile->last_name ?? '')));
            if ($email !== '') {
                $identityMembers['email:'.$email][] = (int) $profile->id;
            }
            if ($phone !== '') {
                $identityMembers['phone:'.$phone][] = (int) $profile->id;
            }
            if ($firstName !== '' && $lastName !== '') {
                $identityMembers['name:'.$firstName.' '.$lastName][] = (int) $profile->id;
            }
        }

        $profileIds = $profiles->pluck('id')->map('intval')->values();
        if ($profileIds->isNotEmpty() && Schema::hasTable('customer_external_profiles')) {
            $profileIds->chunk(5000)->each(function (Collection $chunk) use (&$identityMembers): void {
                DB::table('customer_external_profiles')
                    ->whereIn('marketing_profile_id', $chunk->all())
                    ->where('provider', 'shopify')
                    ->get(['marketing_profile_id', 'external_customer_id', 'external_customer_gid'])
                    ->each(function (object $row) use (&$identityMembers): void {
                        $identity = $this->normalizedShopifyId($row->external_customer_gid ?: $row->external_customer_id);
                        if ($identity !== '') {
                            $identityMembers['shopify:'.$identity][] = (int) $row->marketing_profile_id;
                        }
                    });
            });
        }
        if ($profileIds->isNotEmpty() && Schema::hasTable('marketing_profile_links')) {
            $profileIds->chunk(5000)->each(function (Collection $chunk) use (&$identityMembers): void {
                DB::table('marketing_profile_links')
                    ->whereIn('marketing_profile_id', $chunk->all())
                    ->whereIn('source_type', ['shopify_customer', 'growave_customer'])
                    ->get(['marketing_profile_id', 'source_type', 'source_id'])
                    ->each(function (object $row) use (&$identityMembers): void {
                        $identity = $this->normalizedShopifyId($row->source_id);
                        if ($identity !== '') {
                            $identityMembers['source:'.$row->source_type.':'.$identity][] = (int) $row->marketing_profile_id;
                        }
                    });
            });
        }

        $groups = collect($identityMembers)
            ->map(fn (array $ids): array => array_values(array_unique($ids)))
            ->filter(fn (array $ids): bool => count($ids) > 1);
        $components = [];

        foreach ($groups as $identity => $ids) {
            $matches = [];
            foreach ($components as $index => $component) {
                if (array_intersect($component['profile_ids'], $ids) !== []) {
                    $matches[] = $index;
                }
            }

            $profileIds = $ids;
            $identities = [(string) $identity];
            foreach (array_reverse($matches) as $index) {
                $profileIds = [...$profileIds, ...$components[$index]['profile_ids']];
                $identities = [...$identities, ...$components[$index]['identities']];
                array_splice($components, $index, 1);
            }
            sort($profileIds);
            sort($identities);
            $components[] = [
                'profile_ids' => array_values(array_unique($profileIds)),
                'identities' => array_values(array_unique($identities)),
            ];
        }

        return $components;
    }

    /** @param array<int,int> $profileIds */
    private function loadFullProfiles(array $profileIds): Collection
    {
        return collect($profileIds)
            ->map('intval')
            ->filter()
            ->chunk(1000)
            ->flatMap(fn (Collection $chunk): Collection => DB::table('marketing_profiles')
                ->whereIn('id', $chunk->all())
                ->whereNull('merged_at')
                ->get())
            ->values();
    }

    /** @return array<string,mixed> */
    private function profileFacts(object $profile, int $tenantId, string $storeKey): array
    {
        $profileId = (int) $profile->id;
        $transactions = DB::table('candle_cash_transactions')->where('marketing_profile_id', $profileId);
        $balance = (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $profileId)->value('balance');
        $ledgerNet = (float) (clone $transactions)->sum('candle_cash_delta');
        $transactionCount = (clone $transactions)->count();
        $externalProfiles = Schema::hasTable('customer_external_profiles')
            ? DB::table('customer_external_profiles')->where('marketing_profile_id', $profileId)->get()->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'provider' => (string) $row->provider,
                'integration' => (string) $row->integration,
                'store_key' => $row->store_key,
                'external_customer_id' => (string) $row->external_customer_id,
                'external_customer_gid' => $row->external_customer_gid,
                'email' => $row->email,
                'phone' => $row->phone,
                'points_balance' => $row->points_balance,
                'vip_tier' => $row->vip_tier,
            ])->all()
            : [];
        $links = Schema::hasTable('marketing_profile_links')
            ? DB::table('marketing_profile_links')->where('marketing_profile_id', $profileId)->get()->map(fn (object $row): array => [
                'id' => (int) $row->id,
                'tenant_id' => $row->tenant_id,
                'source_type' => (string) $row->source_type,
                'source_id' => (string) $row->source_id,
                'confidence' => $row->confidence ?? null,
            ])->all()
            : [];
        $shopifyIds = collect($externalProfiles)
            ->filter(fn (array $row): bool => $row['provider'] === 'shopify' && ($row['store_key'] === null || $row['store_key'] === $storeKey))
            ->map(fn (array $row): string => $this->normalizedShopifyId($row['external_customer_gid'] ?: $row['external_customer_id']))
            ->merge(collect($links)
                ->whereIn('source_type', ['shopify_customer', 'growave_customer'])
                ->pluck('source_id')
                ->map(fn ($value): string => $this->normalizedShopifyId($value)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return [
            'id' => $profileId,
            'tenant_id' => (int) ($profile->tenant_id ?? 0),
            'name' => trim((string) (($profile->first_name ?? '').' '.($profile->last_name ?? ''))),
            'fields' => collect(self::MERGE_FIELDS)->mapWithKeys(fn (string $field): array => [$field => $profile->{$field} ?? null])->all(),
            'balance' => $balance,
            'ledger_net' => $ledgerNet,
            'transaction_count' => $transactionCount,
            'balance_matches_ledger' => abs($balance - $ledgerNet) < 0.005,
            'balance_without_ledger' => abs($balance) >= 0.005 && $transactionCount === 0,
            'shopify_ids' => $shopifyIds,
            'external_profiles' => $externalProfiles,
            'source_links' => $links,
            'birthdays' => $this->birthdayRows($profileId),
            'owned_record_counts' => $this->ownedRecordCounts($profileId, $tenantId),
            'open_merge_operations' => $this->openMergeOperations($profileId),
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function birthdayRows(int $profileId): array
    {
        if (! Schema::hasTable('customer_birthday_profiles')) {
            return [];
        }

        $columns = array_values(array_intersect(Schema::getColumnListing('customer_birthday_profiles'), [
            'id', 'marketing_profile_id', 'birth_month', 'birth_day', 'birth_year', 'birthday_full_date',
            'source', 'signup_source', 'capture_date', 'email_subscribed', 'sms_subscribed', 'unsubscribed',
            'source_file', 'reward_last_issued_at', 'reward_last_issued_year', 'metadata', 'updated_at',
        ]));

        return DB::table('customer_birthday_profiles')
            ->where('marketing_profile_id', $profileId)
            ->get($columns)
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return array<string,int> */
    private function ownedRecordCounts(int $profileId, int $tenantId): array
    {
        $counts = [];
        $references = array_merge(
            $this->registry->directReferences(),
            collect($this->registry->conflictReferences())->map(fn (array $policy): array => [$policy['column']])->all(),
        );

        foreach ($references as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $query = DB::table($table)->where($column, $profileId);
                if (Schema::hasColumn($table, 'tenant_id')) {
                    $query->where(fn ($nested) => $nested->where('tenant_id', $tenantId)->orWhereNull('tenant_id'));
                }
                $count = $query->count();
                if ($count > 0) {
                    $counts[$table.'.'.$column] = $count;
                }
            }
        }

        return $counts;
    }

    /** @return array<int,array<string,mixed>> */
    private function openMergeOperations(int $profileId): array
    {
        if (! Schema::hasTable('customer_merge_members') || ! Schema::hasTable('customer_merge_operations')) {
            return [];
        }

        return DB::table('customer_merge_members as members')
            ->join('customer_merge_operations as operations', 'operations.id', '=', 'members.customer_merge_operation_id')
            ->where('members.marketing_profile_id', $profileId)
            ->whereNotIn('operations.status', ['completed', 'cancelled'])
            ->get(['operations.id', 'operations.status', 'operations.shopify_job_id', 'operations.updated_at'])
            ->map(fn (object $row): array => (array) $row)
            ->all();
    }

    /** @return array<string,mixed> */
    private function recommendation(Collection $profiles): array
    {
        $reviewReasons = [];
        $ledgerOwners = $profiles->filter(fn (array $profile): bool => (int) $profile['transaction_count'] > 0);
        $balanceOnly = $profiles->filter(fn (array $profile): bool => (bool) $profile['balance_without_ledger']);
        $mismatches = $profiles->reject(fn (array $profile): bool => (bool) $profile['balance_matches_ledger']);

        if ($balanceOnly->isNotEmpty()) {
            $reviewReasons[] = 'balance_without_supporting_ledger';
        }
        if ($mismatches->isNotEmpty()) {
            $reviewReasons[] = 'balance_does_not_match_ledger';
        }
        if ($ledgerOwners->count() > 1) {
            $reviewReasons[] = 'multiple_profiles_own_candle_cash_ledger_entries';
        }

        $survivorId = $ledgerOwners->count() === 1 ? (int) $ledgerOwners->first()['id'] : null;
        if ($survivorId === null && $ledgerOwners->isEmpty() && $balanceOnly->count() === 1) {
            $survivorId = (int) $balanceOnly->first()['id'];
        }
        if ($survivorId === null && $ledgerOwners->isEmpty() && $balanceOnly->isEmpty()) {
            $shopifyOwners = $profiles->filter(fn (array $profile): bool => $profile['shopify_ids'] !== []);
            if ($shopifyOwners->count() === 1) {
                $survivorId = (int) $shopifyOwners->first()['id'];
            } else {
                $reviewReasons[] = 'no_single_candle_cash_or_shopify_canonical_profile';
            }
        }

        $birthdaySignatures = $profiles->flatMap(fn (array $profile): array => collect($profile['birthdays'])->map(function (array $birthday): string {
            return implode('-', array_map(fn ($value): string => (string) ($value ?? ''), [
                $birthday['birth_year'] ?? null,
                $birthday['birth_month'] ?? null,
                $birthday['birth_day'] ?? null,
                $birthday['birthday_full_date'] ?? null,
            ]));
        })->filter(fn (string $value): bool => trim($value, '-') !== '')->all())->unique();
        if ($birthdaySignatures->count() > 1) {
            $reviewReasons[] = 'conflicting_birthday_values';
        }

        $conflictingFields = [];
        foreach (self::MERGE_FIELDS as $field) {
            $values = $profiles->pluck('fields.'.$field)
                ->map(fn ($value): string => $this->normalizedFieldValue($field, $value))
                ->filter()
                ->unique();
            if ($values->count() > 1) {
                $conflictingFields[] = $field;
            }
        }
        if ($conflictingFields !== []) {
            $reviewReasons[] = 'conflicting_profile_fields:'.implode(',', $conflictingFields);
        }

        $fieldSources = [];
        $donorOnlyData = [];
        if ($survivorId !== null) {
            $survivor = $profiles->firstWhere('id', $survivorId);
            foreach (self::MERGE_FIELDS as $field) {
                $source = trim((string) data_get($survivor, 'fields.'.$field)) !== ''
                    ? $survivor
                    : $profiles->first(fn (array $profile): bool => trim((string) data_get($profile, 'fields.'.$field)) !== '');
                if ($source) {
                    $fieldSources[$field] = (int) $source['id'];
                    if ((int) $source['id'] !== $survivorId) {
                        $donorOnlyData[] = 'field:'.$field.':profile:'.$source['id'];
                    }
                }
            }
            foreach ($profiles->where('id', '!=', $survivorId) as $donor) {
                if ($donor['birthdays'] !== []) {
                    $donorOnlyData[] = 'birthday:profile:'.$donor['id'];
                }
                foreach ($donor['owned_record_counts'] as $reference => $count) {
                    $donorOnlyData[] = $reference.':profile:'.$donor['id'].':count:'.$count;
                }
            }
        }

        $shopifyIds = $profiles->pluck('shopify_ids')->flatten()->filter()->unique();

        return [
            'status' => $survivorId !== null && $reviewReasons === [] ? 'deterministic' : 'needs_review',
            'survivor_profile_id' => $survivorId,
            'shopify_merge_required' => $shopifyIds->count() > 1,
            'review_reasons' => array_values(array_unique($reviewReasons)),
            'field_sources' => $fieldSources,
            'donor_only_data' => array_values(array_unique($donorOnlyData)),
        ];
    }

    private function normalizedFieldValue(string $field, mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if ($field === 'phone') {
            return preg_replace('/\D+/', '', $normalized) ?: '';
        }
        if ($field === 'tags') {
            $decoded = json_decode($normalized, true);
            if (is_array($decoded)) {
                sort($decoded);

                return json_encode($decoded) ?: '';
            }
        }

        return preg_replace('/\s+/', ' ', $normalized) ?: '';
    }

    private function normalizedShopifyId(mixed $value): string
    {
        $normalized = strtolower(trim((string) $value));
        if (preg_match('/(\d+)$/', $normalized, $matches) === 1) {
            return $matches[1];
        }

        return $normalized;
    }
}
