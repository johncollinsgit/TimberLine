<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Mobile;

use App\Models\TenantSite;
use App\Models\User;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Fresh authorization is also called by PaymentCore after durable claim waits. */
final class MobilePaymentAccess implements PaymentAccess
{
    private const ROLES = ['owner', 'tenant_owner', 'admin', 'manager', 'member'];

    public function __construct(private Request $request, private MobileRegisterDirectory $registers) {}

    public function actor(string $operation): array
    {
        $user = $this->request->user();
        $tenantId = (int) $this->request->attributes->get('current_tenant_id');
        $write = in_array($operation, ['quote', 'collect', 'recover', 'refund'], true);
        $token = $user?->currentAccessToken();
        $currentToken = $token instanceof PersonalAccessToken ? PersonalAccessToken::query()->whereKey($token->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first() : null;
        if (! $user instanceof User || $tenantId < 1 || ! $currentToken || $currentToken->tokenable_id !== $user->id
            || $currentToken->tokenable_type !== $user->getMorphClass()
            || ($currentToken->expires_at && $currentToken->expires_at->isPast())
            || (config('sanctum.expiration') && $currentToken->created_at->lte(now()->subMinutes((int) config('sanctum.expiration'))))
            || ! in_array($write ? 'mobile:write' : 'mobile:read', $currentToken->abilities, true)
            || ! in_array($write ? 'payments:write' : 'payments:read', $currentToken->abilities, true)) {
            throw new MobilePaymentException('payment_access_denied', 403);
        }
        $actor = User::query()->whereKey($user->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first();
        $membership = DB::table('tenant_user')->where('tenant_id', $tenantId)->where('user_id', $user->id)
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->first();
        if (! $actor || ! $actor->is_active || ! $actor->hasVerifiedEmail() || ! $membership
            || ! $membership->membership_active || ! in_array($membership->role, self::ROLES, true)
            || ($operation === 'refund' && $membership->role === 'member')) {
            throw new MobilePaymentException('payment_access_denied', 403);
        }
        if (config('evergrove_payments.read_enabled') !== true) {
            throw new MobilePaymentException('payments_unavailable', 503);
        }

        return ['tenant_id' => $tenantId, 'actor_id' => (int) $actor->id];
    }

    public function register(string $registerId, string $operation): array
    {
        $actor = $this->actor($operation);
        $entry = $this->registers->get($actor['tenant_id'], $registerId);
        if (! in_array($actor['actor_id'], (array) ($entry['user_ids'] ?? []), true)) {
            throw new MobilePaymentException('register_unavailable', 404);
        }
        if (! TenantSite::query()->forTenantId($actor['tenant_id'])->whereKey($entry['site_id'])
            ->when(DB::transactionLevel() > 0, fn ($query) => $query->sharedLock())->exists()) {
            throw new MobilePaymentException('register_unavailable', 404);
        }
        if (in_array($operation, ['catalog', 'quote', 'collect'], true)
            && ! app(TenantModuleAccessResolver::class)->canAccess($actor['tenant_id'], 'managed_website')) {
            throw new MobilePaymentException('catalog_access_denied', 403);
        }
        if (in_array($operation, ['recover', 'refund'], true)
            && config('evergrove_payments.servicing_enabled') !== true) {
            throw new MobilePaymentException('servicing_unavailable', 503);
        }

        return [...$actor, 'site_id' => $entry['site_id'], 'definition' => $entry];
    }

    public function assertAllowed(MerchantBinding $binding, string $operation): void
    {
        $scope = $this->register($binding->registerId, $operation);
        if ($scope['tenant_id'] !== $binding->tenantId) {
            throw new MobilePaymentException('operation_unavailable', 404);
        }
        if ($operation === 'collect') {
            // Only explicit local/testing composition may rehearse collection.
            // Inventory support cannot open production or register a real provider.
            if (! $this->fixtureMode() || config('evergrove_payments.collection_enabled') !== true
                || config('evergrove_payments.fixture_collection_enabled') !== true
                || $binding->environment !== 'test' || ! str_starts_with($binding->provider, 'fixture_')) {
                throw new MobilePaymentException('stock_commit_unavailable', 503);
            }
            if (! hash_equals($this->registers->binding($binding->tenantId, $binding->registerId)->fingerprint(), $binding->fingerprint())) {
                throw new MobilePaymentException('register_binding_changed', 409);
            }
        }
    }

    public function fixtureMode(): bool
    {
        return app()->environment(['local', 'testing']) && config('evergrove_payments.fixture_mode') === true;
    }
}
