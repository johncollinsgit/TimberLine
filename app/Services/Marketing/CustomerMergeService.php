<?php

namespace App\Services\Marketing;

use App\Models\CustomerMergeOperation;
use App\Models\MarketingProfile;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class CustomerMergeService
{
    private const SELECTABLE_FIELDS = [
        'first_name', 'last_name', 'email', 'phone',
        'address_line_1', 'address_line_2', 'city', 'state', 'postal_code', 'country', 'notes',
        'tags',
    ];

    public function __construct(private readonly MarketingProfileMergeReferenceRegistry $registry) {}

    /**
     * @param  array<int,int>  $profileIds
     * @param  array<string,int>  $fieldSources
     * @param  array<string,mixed>  $context
     */
    public function createOperation(
        int $tenantId,
        array $profileIds,
        int $survivorProfileId,
        string $storeKey,
        string $idempotencyKey,
        array $fieldSources = [],
        array $context = []
    ): CustomerMergeOperation {
        $profiles = $this->profilesForMerge($tenantId, $profileIds, $survivorProfileId);
        $shopifyIds = $this->shopifyIdsForProfiles($profiles, $storeKey);
        $this->assertFieldSources($profiles, $fieldSources);

        return DB::transaction(function () use ($tenantId, $profiles, $survivorProfileId, $storeKey, $idempotencyKey, $fieldSources, $context, $shopifyIds): CustomerMergeOperation {
            $operation = CustomerMergeOperation::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'idempotency_key' => $idempotencyKey],
                [
                    'store_key' => $storeKey,
                    'status' => 'draft',
                    'source' => (string) ($context['source'] ?? 'everbranch_wizard'),
                    'survivor_profile_id' => $survivorProfileId,
                    'initiated_by' => $context['initiated_by'] ?? null,
                    'shopify_admin_user_id' => $context['shopify_admin_user_id'] ?? null,
                    'field_choices' => ['sources' => $fieldSources],
                    'reward_resolution' => $this->rewardPreview($profiles),
                    'before_state' => $this->snapshot($profiles, $storeKey),
                ]
            );

            if (! $operation->wasRecentlyCreated) {
                $operation->load('members');
                $existingIds = $operation->members->pluck('marketing_profile_id')->map('intval')->sort()->values();
                $requestedIds = $profiles->pluck('id')->map('intval')->sort()->values();
                if ((int) $operation->survivor_profile_id !== $survivorProfileId
                    || (string) $operation->store_key !== $storeKey
                    || $existingIds->all() !== $requestedIds->all()) {
                    throw new CustomerMergeException('This idempotency key is already attached to a different merge request.', 'idempotency_conflict');
                }

                return $operation;
            }

            foreach ($profiles as $profile) {
                $operation->members()->create([
                    'marketing_profile_id' => $profile->id,
                    'shopify_customer_gid' => $shopifyIds[(int) $profile->id] ?? null,
                    'role' => (int) $profile->id === $survivorProfileId ? 'survivor' : 'donor',
                    'snapshot' => $this->profileSnapshot($profile),
                ]);
            }

            return $operation->load('members');
        });
    }

    /**
     * Apply the Everbranch side of a completed Shopify merge or an internal-only merge.
     * This method is idempotent and safe to replay from a webhook.
     */
    public function apply(CustomerMergeOperation $operation, ?string $keptShopifyGid = null, ?string $deletedShopifyGid = null): CustomerMergeOperation
    {
        return DB::transaction(function () use ($operation, $keptShopifyGid, $deletedShopifyGid): CustomerMergeOperation {
            $locked = CustomerMergeOperation::query()->lockForUpdate()->with('members')->findOrFail($operation->id);
            if ($locked->status === 'completed') {
                return $locked;
            }

            $profiles = MarketingProfile::query()
                ->whereIn('id', $locked->members->pluck('marketing_profile_id'))
                ->lockForUpdate()
                ->get();
            $survivor = $profiles->firstWhere('id', (int) $locked->survivor_profile_id);
            if (! $survivor || (int) $survivor->tenant_id !== (int) $locked->tenant_id) {
                throw new CustomerMergeException('The selected surviving customer is outside this tenant.', 'tenant_scope_mismatch');
            }

            $donors = $profiles->where('id', '!=', $survivor->id)->values();
            $locked->forceFill([
                'status' => 'processing',
                'started_at' => $locked->started_at ?: now(),
                'shopify_kept_customer_gid' => $keptShopifyGid ?: $locked->shopify_kept_customer_gid,
                'shopify_deleted_customer_gid' => $deletedShopifyGid ?: $locked->shopify_deleted_customer_gid,
            ])->save();

            $this->applySelectedFields($survivor, $profiles, (array) data_get($locked->field_choices, 'sources', []));
            $conflicts = $this->moveConflictReferences($donors, $survivor, (int) $locked->tenant_id);
            $this->moveDirectReferences($donors, $survivor, (int) $locked->tenant_id);
            $conflicts = array_merge($conflicts, $this->mergeCandleCash($locked, $donors, $survivor, (array) $locked->reward_resolution));
            $this->reassignShopifyOrders($locked, $keptShopifyGid, $deletedShopifyGid);

            foreach ($donors as $donor) {
                $donor->forceFill([
                    'merged_into_profile_id' => $survivor->id,
                    'merge_operation_id' => $locked->id,
                    'merged_at' => now(),
                ])->save();
                $locked->members()->where('marketing_profile_id', $donor->id)->update(['outcome' => 'archived']);
            }
            $locked->members()->where('marketing_profile_id', $survivor->id)->update(['outcome' => 'survived']);

            $locked->forceFill([
                'status' => 'completed',
                'approved_by' => $locked->approved_by ?: $locked->initiated_by,
                'after_state' => array_merge(
                    $this->snapshot(new EloquentCollection([$survivor->fresh()]), (string) $locked->store_key),
                    ['conflict_resolutions' => $conflicts]
                ),
                'completed_at' => now(),
                'errors' => null,
            ])->save();

            return $locked->fresh('members');
        });
    }

    /** @param array<int,int> $profileIds */
    private function profilesForMerge(int $tenantId, array $profileIds, int $survivorProfileId): EloquentCollection
    {
        $ids = collect($profileIds)->map('intval')->filter()->unique()->values();
        if ($ids->count() < 2 || ! $ids->contains($survivorProfileId)) {
            throw new CustomerMergeException('Select at least two customers and a survivor.', 'invalid_selection');
        }

        $profiles = MarketingProfile::query()->whereIn('id', $ids)->whereNull('merged_at')->get();
        if ($profiles->count() !== $ids->count()) {
            throw new CustomerMergeException('One or more selected customers no longer exist or were already merged.', 'stale_selection');
        }

        $survivor = $profiles->firstWhere('id', $survivorProfileId);
        if (! $survivor || (int) $survivor->tenant_id !== $tenantId) {
            throw new CustomerMergeException('The surviving customer must belong to the current tenant.', 'tenant_scope_mismatch');
        }

        foreach ($profiles as $profile) {
            if ((int) $profile->tenant_id === $tenantId) {
                continue;
            }
            if ($profile->tenant_id !== null || ! $this->legacyProfileMatches($profile, $profiles, $tenantId)) {
                throw new CustomerMergeException('A selected customer is not safely linked to this tenant.', 'tenant_scope_mismatch');
            }
        }

        return $profiles;
    }

    private function legacyProfileMatches(MarketingProfile $legacy, EloquentCollection $profiles, int $tenantId): bool
    {
        $tenantProfiles = $profiles->filter(fn (MarketingProfile $profile): bool => (int) $profile->tenant_id === $tenantId);
        if ($legacy->normalized_email && $tenantProfiles->contains('normalized_email', $legacy->normalized_email)) {
            return true;
        }
        if ($legacy->normalized_phone && $tenantProfiles->contains('normalized_phone', $legacy->normalized_phone)) {
            return true;
        }

        $legacySources = DB::table('marketing_profile_links')
            ->where('marketing_profile_id', $legacy->id)
            ->whereIn('source_type', ['shopify_customer', 'growave_customer'])
            ->get(['source_type', 'source_id']);

        return $legacySources->contains(fn ($link): bool => DB::table('marketing_profile_links')
            ->whereIn('marketing_profile_id', $tenantProfiles->pluck('id'))
            ->where('source_type', $link->source_type)
            ->where('source_id', $link->source_id)
            ->exists());
    }

    private function moveDirectReferences(EloquentCollection $donors, MarketingProfile $survivor, int $tenantId): void
    {
        foreach ($this->registry->directReferences() as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (! Schema::hasColumn($table, $column)) {
                    continue;
                }
                $updates = [$column => $survivor->id];
                if (Schema::hasColumn($table, 'tenant_id')) {
                    $updates['tenant_id'] = $tenantId;
                }
                DB::table($table)->whereIn($column, $donors->pluck('id'))->update($updates);
            }
        }
    }

    private function moveConflictReferences(EloquentCollection $donors, MarketingProfile $survivor, int $tenantId): array
    {
        $resolutions = [];
        foreach ($this->registry->conflictReferences() as $table => $policy) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $policy['column'])) {
                continue;
            }
            foreach ($donors as $donor) {
                $rows = DB::table($table)->where($policy['column'], $donor->id)->orderByDesc('id')->get();
                foreach ($rows as $row) {
                    $existing = DB::table($table)->where($policy['column'], $survivor->id);
                    foreach ($policy['keys'] as $key) {
                        $existing->where($key, $row->{$key});
                    }
                    $existingRow = $existing->first();
                    if ($existingRow) {
                        if ($this->completenessScore($row, $policy['column']) > $this->completenessScore($existingRow, $policy['column'])) {
                            DB::table($table)->where('id', $existingRow->id)->delete();
                            $resolutions[] = ['table' => $table, 'row_id' => $existingRow->id, 'action' => 'replaced_with_more_complete_donor', 'donor_profile_id' => $donor->id];
                        } else {
                            DB::table($table)->where('id', $row->id)->delete();
                            $resolutions[] = ['table' => $table, 'row_id' => $row->id, 'action' => 'deduplicated', 'donor_profile_id' => $donor->id];

                            continue;
                        }
                    }
                    $updates = [$policy['column'] => $survivor->id];
                    if (Schema::hasColumn($table, 'tenant_id')) {
                        $updates['tenant_id'] = $tenantId;
                    }
                    DB::table($table)->where('id', $row->id)->update($updates);
                }
            }
        }

        return $resolutions;
    }

    private function completenessScore(object $row, string $profileColumn): int
    {
        return collect((array) $row)->except(['id', $profileColumn, 'tenant_id', 'created_at', 'updated_at'])
            ->filter(fn ($value): bool => $value !== null && trim((string) $value) !== '' && $value !== '[]' && $value !== '{}')
            ->count();
    }

    private function mergeCandleCash(CustomerMergeOperation $operation, EloquentCollection $donors, MarketingProfile $survivor, array $resolution): array
    {
        $resolutions = [];
        $ambiguousChoices = (array) ($resolution['ambiguous_balances'] ?? []);
        $survivorBalance = (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $survivor->id)->value('balance');
        $survivorLedgerCount = DB::table('candle_cash_transactions')->where('marketing_profile_id', $survivor->id)->count();
        if ($survivorBalance !== 0.0 && $survivorLedgerCount === 0) {
            $choice = (string) ($ambiguousChoices[(string) $survivor->id] ?? $ambiguousChoices[$survivor->id] ?? '');
            if (! in_array($choice, ['include_as_opening', 'discard_duplicate'], true)) {
                throw new CustomerMergeException('Choose how to handle a balance without supporting ledger entries.', 'ambiguous_opening_balance');
            }
            if ($choice === 'include_as_opening') {
                DB::table('candle_cash_transactions')->insert([
                    'marketing_profile_id' => $survivor->id, 'type' => 'earn', 'points' => $survivorBalance,
                    'candle_cash_delta' => $survivorBalance, 'source' => 'customer_merge_opening_balance',
                    'source_id' => $operation->id.':'.$survivor->id,
                    'description' => 'Explicitly retained opening balance during customer merge',
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $resolutions[] = ['table' => 'candle_cash_balances', 'row_id' => $survivor->id, 'action' => $choice, 'donor_profile_id' => null, 'amount' => $survivorBalance];
        }
        foreach ($donors as $donor) {
            $donorBalance = (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $donor->id)->value('balance');
            $donorTransactions = DB::table('candle_cash_transactions')->where('marketing_profile_id', $donor->id)->orderBy('id')->get();
            if ($donorBalance !== 0.0 && $donorTransactions->isEmpty()) {
                $choice = (string) ($ambiguousChoices[(string) $donor->id] ?? $ambiguousChoices[$donor->id] ?? '');
                if (! in_array($choice, ['include_as_opening', 'discard_duplicate'], true)) {
                    throw new CustomerMergeException('Choose how to handle a balance without supporting ledger entries.', 'ambiguous_opening_balance');
                }
                if ($choice === 'include_as_opening') {
                    DB::table('candle_cash_transactions')->insert([
                        'marketing_profile_id' => $survivor->id,
                        'type' => 'earn',
                        'points' => $donorBalance,
                        'candle_cash_delta' => $donorBalance,
                        'source' => 'customer_merge_opening_balance',
                        'source_id' => $operation->id.':'.$donor->id,
                        'description' => 'Explicitly retained opening balance from merged profile #'.$donor->id,
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $resolutions[] = ['table' => 'candle_cash_balances', 'row_id' => $donor->id, 'action' => $choice, 'donor_profile_id' => $donor->id, 'amount' => $donorBalance];
            }

            foreach ($donorTransactions as $transaction) {
                $source = trim((string) ($transaction->source ?? ''));
                $sourceId = trim((string) ($transaction->source_id ?? ''));
                $duplicate = $source !== '' && $sourceId !== '' && DB::table('candle_cash_transactions')
                    ->where('marketing_profile_id', $survivor->id)
                    ->where('source', $source)
                    ->where('source_id', $sourceId)
                    ->where('type', $transaction->type)
                    ->where('candle_cash_delta', $transaction->candle_cash_delta)
                    ->exists();
                if ($duplicate) {
                    DB::table('candle_cash_transactions')->where('id', $transaction->id)->delete();
                    $resolutions[] = ['table' => 'candle_cash_transactions', 'row_id' => $transaction->id, 'action' => 'deduplicated_source', 'donor_profile_id' => $donor->id];
                } else {
                    DB::table('candle_cash_transactions')->where('id', $transaction->id)->update(['marketing_profile_id' => $survivor->id]);
                }
            }
            DB::table('candle_cash_balances')->where('marketing_profile_id', $donor->id)->delete();
        }

        $ledgerNet = (float) DB::table('candle_cash_transactions')
            ->where('marketing_profile_id', $survivor->id)
            ->sum('candle_cash_delta');
        DB::table('candle_cash_balances')->updateOrInsert(
            ['marketing_profile_id' => $survivor->id],
            ['balance' => $ledgerNet, 'updated_at' => now()]
        );

        return $resolutions;
    }

    private function applySelectedFields(MarketingProfile $survivor, EloquentCollection $profiles, array $sources): void
    {
        $updates = [];
        foreach (self::SELECTABLE_FIELDS as $field) {
            $sourceId = (int) ($sources[$field] ?? $survivor->id);
            $source = $profiles->firstWhere('id', $sourceId);
            if ($source) {
                $updates[$field] = $source->getAttribute($field);
            }
        }
        $updates['source_channels'] = $profiles->pluck('source_channels')->flatten()->filter()->unique()->values()->all();
        $survivor->forceFill($updates)->save();
    }

    private function assertFieldSources(EloquentCollection $profiles, array $sources): void
    {
        $ids = $profiles->pluck('id')->map('intval');
        foreach ($sources as $field => $profileId) {
            if (! in_array($field, self::SELECTABLE_FIELDS, true) || ! $ids->contains((int) $profileId)) {
                throw new CustomerMergeException('A selected field value is not part of this merge.', 'invalid_field_choice');
            }
        }
    }

    /** @return array<int,string> */
    private function shopifyIdsForProfiles(EloquentCollection $profiles, string $storeKey): array
    {
        $result = [];
        $rows = DB::table('customer_external_profiles')
            ->whereIn('marketing_profile_id', $profiles->pluck('id'))
            ->where('provider', 'shopify')->where('integration', 'shopify_customer')
            ->where('store_key', $storeKey)->get();
        foreach ($rows as $row) {
            $result[(int) $row->marketing_profile_id] = (string) ($row->external_customer_gid ?: 'gid://shopify/Customer/'.$row->external_customer_id);
        }

        return $result;
    }

    private function rewardPreview(EloquentCollection $profiles): array
    {
        return [
            'policy' => 'unique_ledger_combine',
            'profiles' => $profiles->mapWithKeys(function (MarketingProfile $profile): array {
                $transactions = DB::table('candle_cash_transactions')->where('marketing_profile_id', $profile->id);

                return [(string) $profile->id => [
                    'balance' => (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $profile->id)->value('balance'),
                    'transaction_count' => (clone $transactions)->count(),
                    'ledger_net' => (float) (clone $transactions)->sum('candle_cash_delta'),
                ]];
            })->all(),
            'ambiguous_balances' => [],
        ];
    }

    private function snapshot(EloquentCollection $profiles, string $storeKey): array
    {
        return [
            'profiles' => $profiles->map(fn (MarketingProfile $profile): array => $this->profileSnapshot($profile))->all(),
            'store_key' => $storeKey,
            'captured_at' => now()->toIso8601String(),
        ];
    }

    private function profileSnapshot(MarketingProfile $profile): array
    {
        return [
            'id' => (int) $profile->id,
            'tenant_id' => $profile->tenant_id,
            'name' => trim((string) ($profile->first_name.' '.$profile->last_name)),
            'email' => $profile->email,
            'phone' => $profile->phone,
            'balance' => (float) DB::table('candle_cash_balances')->where('marketing_profile_id', $profile->id)->value('balance'),
            'transaction_count' => DB::table('candle_cash_transactions')->where('marketing_profile_id', $profile->id)->count(),
            'owned_record_counts' => $this->ownedRecordCounts($profile),
        ];
    }

    private function ownedRecordCounts(MarketingProfile $profile): array
    {
        $counts = [];
        foreach (array_merge($this->registry->directReferences(), collect($this->registry->conflictReferences())->map(fn (array $policy): array => [$policy['column']])->all()) as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($columns as $column) {
                if (Schema::hasColumn($table, $column)) {
                    $counts[$table.'.'.$column] = DB::table($table)->where($column, $profile->id)->count();
                }
            }
        }

        return $counts;
    }

    private function reassignShopifyOrders(CustomerMergeOperation $operation, ?string $keptGid, ?string $deletedGid): void
    {
        $keptId = $this->numericTail($keptGid ?: $operation->shopify_kept_customer_gid);
        $deletedIds = collect([
            $deletedGid ?: $operation->shopify_deleted_customer_gid,
            ...((array) data_get($operation->shopify_preview, 'completed_deleted_gids', [])),
        ])->map(fn ($gid): ?string => $this->numericTail($gid))->filter()->unique()->values();
        if ($keptId && $deletedIds->isNotEmpty()) {
            DB::table('orders')->where('tenant_id', $operation->tenant_id)
                ->whereIn('shopify_customer_id', $deletedIds)
                ->update(['shopify_customer_id' => $keptId]);
        }
    }

    private function numericTail(?string $value): ?string
    {
        preg_match('/(\d+)$/', trim((string) $value), $matches);

        return $matches[1] ?? null;
    }
}
