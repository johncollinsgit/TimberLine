<?php

namespace App\Services\Wholesale\Gmail;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Private encrypted operations; the reserved namespace is never a Teams work area. */
class WholesaleGmailJournal
{
    public const AREA = 'e083635e-84fb-4c33-9aba-0858ca637a8a';

    public function rows(int $tenant, string $kind)
    {
        return TeamOperationRecord::query()->where(['tenant_id' => $tenant, 'area_id' => self::AREA, 'kind' => 'wg_'.$kind]);
    }

    public function locked(int $tenant, callable $callback): mixed
    {
        return DB::transaction(function () use ($tenant, $callback) {
            Tenant::query()->whereKey($tenant)->lockForUpdate()->firstOrFail();

            return $callback();
        });
    }

    public function create(int $tenant, string $kind, string $key, array $data, string $status): TeamOperationRecord
    {
        return $this->rows($tenant, $kind)->create([
            'id' => (string) Str::uuid(), 'tenant_id' => $tenant, 'area_id' => self::AREA,
            'kind' => 'wg_'.$kind, 'source_key' => $key, 'title' => 'Private wholesale Gmail operation',
            'status' => $status, 'version' => 1, 'visibility' => 'private', 'data' => $data,
        ]);
    }

    public function operator(int $tenant, int $actor, string $shop): User
    {
        $user = User::query()->find($actor);
        $roles = array_map('strtolower', (array) config('tenancy.landlord.operator_roles', ['admin']));
        $store = ShopifyStores::findByShopDomain($shop);
        abort_unless($user && $user->is_active && in_array(strtolower((string) $user->role), $roles, true)
            && $user->tenants()->whereKey($tenant)->exists()
            && app(TenantModuleAccessResolver::class)->canAccess($tenant, 'wholesale_operations')
            && ($store['key'] ?? '') === 'wholesale'
            && (int) ($store['tenant_id'] ?? 0) === $tenant, 403, 'An active wholesale operator in this store is required.');

        return $user;
    }

    public function audit(int $tenant, int $actor, string $action, string $id, array $result = []): void
    {
        app(LandlordOperatorActionAuditService::class)->record($tenant, $actor, 'wholesale.gmail.'.$action,
            targetType: 'wholesale_gmail', targetId: $id, result: $result);
    }
}
