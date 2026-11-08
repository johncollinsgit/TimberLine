<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingProfileLink;
use App\Services\Pos\PosException;
use App\Services\Pos\PosVerifiedCustomerAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** All account mapping writers and money admission share a durable, non-expiring mutex. */
final class CustomerAccountFence
{
    public function lock(int $tenant): void
    {
        if ($tenant < 1 || DB::transactionLevel() < 1) {
            throw new PosException('customer_account_fence_unavailable', 503);
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            // Acquire the mutex before any consistent SELECT. A schema probe
            // creates a REPEATABLE READ view that can predate the lock wait and
            // hide committed consent changes or another channel's delivery.
            try {
                DB::statement('INSERT INTO customer_account_fences (tenant_id) VALUES (?) ON DUPLICATE KEY UPDATE tenant_id = VALUES(tenant_id)', [$tenant]);
            } catch (\Illuminate\Database\QueryException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === 1146) {
                    throw new PosException('customer_account_fence_unavailable', 503);
                }
                throw $error;
            }
        } else {
            if (! Schema::hasTable('customer_account_fences')) {
                throw new PosException('customer_account_fence_unavailable', 503);
            }
            DB::table('customer_account_fences')->insertOrIgnore(['tenant_id' => $tenant]);
        }
        DB::table('customer_account_fences')->where('tenant_id', $tenant)->lockForUpdate()->firstOrFail();
    }

    public function forTenant(int $tenant, callable $work): mixed
    {
        return DB::transaction(function () use ($tenant, $work) {
            $this->lock($tenant);

            return $work();
        }, 3);
    }

    /** Validate only affected subjects; unrelated customer imports do not scan all wallets. */
    public function mutate(array $models, callable $write, ?callable $after = null): mixed
    {
        // During an older-schema upgrade no account can be admitted; new issuance requires this table.
        if (! Schema::hasTable('customer_account_fences')) {
            return $write();
        }
        $tenants = array_values(array_unique(array_filter(array_map(fn ($model) => (int) $model->tenant_id, $models))));
        sort($tenants);
        $keys = $this->keys($models);

        return DB::transaction(function () use ($models, $write, $after, $tenants, $keys) {
            foreach ($tenants as $tenant) {
                $this->lock($tenant);
            }
            $result = $write();
            foreach (array_unique([...$keys, ...$this->keys($after ? $after() : $models)]) as $key) {
                [$tenant, $store, $digits] = explode('|', $key);
                $this->assertSubject((int) $tenant, $store, $digits);
            }

            return $result;
        }, 3);
    }

    private function keys(array $models): array
    {
        $result = [];
        foreach ($models as $model) {
            if ((int) $model->tenant_id < 1) {
                continue;
            }
            $store = '';
            $ids = [];
            if ($model instanceof MarketingProfileLink && in_array($model->source_type, ['shopify_customer', PosVerifiedCustomerAccount::SOURCE], true)) {
                $meta = $model->source_meta ?? [];
                $store = (string) ($meta['shopify_store_key'] ?? '');
                $id = (string) $model->source_id;
                if (preg_match('~^([a-z0-9_-]+):(gid://shopify/Customer/[0-9]+|[0-9]+)$~D', $id, $match)) {
                    $store = $store ?: ($match[1] === 'shopify' ? '' : $match[1]);
                    $id = $match[2];
                }
                $ids = [$id, $meta['shopify_customer_gid'] ?? '', $meta['shopify_customer_id'] ?? ''];
            } elseif ($model instanceof CustomerExternalProfile && $model->provider === 'shopify' && $model->integration === 'shopify_customer') {
                $store = (string) $model->store_key;
                $ids = [$model->external_customer_id, $model->external_customer_gid];
            }
            foreach ($ids as $id) {
                if (is_string($id) && preg_match('~^(?:gid://shopify/Customer/)?([1-9][0-9]{0,19})$~D', $id, $match)) {
                    $result[] = $model->tenant_id.'|'.$store.'|'.$match[1];
                }
            }
        }

        return array_values(array_unique($result));
    }

    private function assertSubject(int $tenant, string $store, string $digits): void
    {
        $claims = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', PosVerifiedCustomerAccount::SOURCE)
            ->when($store !== '', fn ($query) => $query->where('source_id', $store.':'.$digits), fn ($query) => $query->where('source_id', 'like', '%:'.$digits))
            ->lockForUpdate()->get();
        foreach ($claims as $claim) {
            $profile = app(CanonicalMarketingProfileResolver::class)->lockedById($tenant, (int) $claim->marketing_profile_id);
            $meta = $claim->source_meta ?? [];
            $claimStore = $meta['shopify_store_key'] ?? null;
            $gid = 'gid://shopify/Customer/'.$digits;
            $accounts = app(PosVerifiedCustomerAccount::class);
            // Invalid/revoked attestations cannot authorize money; they do not become authority by import.
            if (! $profile || (int) $profile->id !== (int) $claim->marketing_profile_id || $profile->merged_at || $profile->archived_at || ! is_string($claimStore)
                || ($meta['verified_profile_id'] ?? null) !== (int) $profile->id || ($meta['shopify_customer_gid'] ?? null) !== $gid
                || ! is_string($meta['signature'] ?? null)
                || ! hash_equals($accounts->signature($tenant, (int) $profile->id, $claimStore, $gid), $meta['signature'])) {
                continue;
            }
            if (! $accounts->mappingsConsistent($profile, $claimStore, $gid, false)) {
                throw new PosException('customer_account_mapping_conflict', 409);
            }
        }
    }
}
