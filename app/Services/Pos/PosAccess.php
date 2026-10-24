<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\User;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Laravel\Sanctum\PersonalAccessToken;

final class PosAccess implements PaymentAccess
{
    public function __construct(private PosStore $store, private PosReadiness $readiness) {}

    public function isWeb(): bool
    {
        return request()->attributes->get('pos_web_session') === true
            && request()->routeIs('pos.web.*', 'enterprise.pos.web.*');
    }

    public function actor(bool $write = false): array
    {
        if (request()->routeIs('pos.web.terminal.*', 'enterprise.pos.web.terminal.*')) {
            return app(PosPilotAccess::class)->context();
        }
        $user = request()->user();
        $tenant = (int) request()->attributes->get('current_tenant_id');
        if (! $user instanceof User || $tenant < 1 || config('pos_live.enabled') !== true) {
            throw new PosException('pos_unavailable', 404);
        }
        $actor = User::query()->whereKey($user->id)->first();
        $member = $this->store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $user->id)->first();
        if (! $actor?->is_active || ! $actor->hasVerifiedEmail() || ! $member?->membership_active
            || ! in_array($member->role, ['owner', 'tenant_owner', 'admin', 'manager', 'member'], true)) {
            throw new PosException('access_denied', 403);
        }
        $token = $user->currentAccessToken();
        // Enterprise administrative routes use their own CSRF-protected session.
        $enterpriseSession = config('enterprise.runtime') === true && ! request()->routeIs('enterprise.pos.api.*');
        if (! $enterpriseSession && ! $this->isWeb()) {
            $current = $token instanceof PersonalAccessToken ? PersonalAccessToken::query()->find($token->id) : null;
            $abilities = $current?->abilities ?? [];
            if (! $current || $current->tokenable_id !== $user->id || $current->tokenable_type !== $user->getMorphClass()
                || ! $current->expires_at || $current->expires_at->isPast()
                || (config('sanctum.expiration') && $current->created_at->lte(now()->subMinutes((int) config('sanctum.expiration'))))
                || ! in_array($write ? 'mobile:write' : 'mobile:read', $abilities, true)
                || ! in_array($write ? 'payments:write' : 'payments:read', $abilities, true)) {
                throw new PosException('access_denied', 403);
            }
        }

        return ['tenant_id' => $tenant, 'actor_id' => (int) $user->id, 'role' => $member->role, 'build' => request()->header('X-Pos-Build', ''), 'device_id' => request()->header('X-Pos-Device', '')];
    }

    public function register(string $id, string $operation = 'read'): array
    {
        if (request()->routeIs('pos.web.terminal.*', 'enterprise.pos.web.terminal.*')) {
            return app(PosPilotAccess::class)->register($id, $operation);
        }
        $actor = $this->actor(in_array($operation, ['quote', 'collect', 'recover', 'refund', 'admin'], true));
        $register = $this->store->rows('registers', $actor['tenant_id'])->where('id', $id)->first();
        $device = request()->header('X-Pos-Device', '');
        $assignment = $this->store->rows('assignments', $actor['tenant_id'])->where('register_id', $id)
            ->where('user_id', $actor['actor_id'])->where('device_id', $device)->where('active', true)->first();
        if (! $register || ! $assignment || ! in_array($assignment->role, ['cashier', 'manager', 'admin'], true)) {
            throw new PosException('register_unavailable', 404);
        }
        $terminalWeb = $this->isWeb() && request()->routeIs('pos.web.terminal.*', 'enterprise.pos.web.terminal.*') && ($register->provider ?? 'square_native') === 'stripe_terminal';
        if ($this->isWeb() && ! in_array($operation, ['read', 'catalog'], true) && ! $terminalWeb) {
            throw new PosException('native_payment_app_required', 403);
        }
        if (($register->provider ?? 'square_native') === 'stripe_terminal' && ! $this->isWeb()) {
            throw new PosException('staff_web_register_required', 403);
        }
        if ($operation === 'refund' && ($assignment->role === 'cashier' || $actor['role'] === 'member')) {
            throw new PosException('manager_required', 403);
        }
        if (in_array($operation, ['quote', 'collect', 'catalog'], true)
            && ! app(TenantModuleAccessResolver::class)->canAccess($actor['tenant_id'], 'pos')) {
            throw new PosException('pos_entitlement_required', 403);
        }
        if (in_array($operation, ['recover', 'refund'], true)
            && (config('pos_live.servicing_enabled') !== true || ! $register->servicing_enabled)) {
            throw new PosException('servicing_disabled', 503);
        }
        $connection = $this->store->rows('connections', $actor['tenant_id'])->where('id', $register->connection_id)->first();
        if (! $connection) {
            throw new PosException('merchant_unavailable', 503);
        }

        if (($connection->provider ?? 'square_native') !== ($register->provider ?? 'square_native')) {
            throw new PosException('provider_binding_mismatch');
        }

        return [...$actor, 'register' => $register, 'connection' => $connection,
            'can_refund' => $assignment->role !== 'cashier' && $actor['role'] !== 'member'];
    }

    public function binding(array $scope): MerchantBinding
    {
        $connection = $scope['connection'];

        return new MerchantBinding($scope['tenant_id'], $scope['register']->id, $scope['register']->provider ?? 'square_native',
            $connection->merchant_id, $connection->provider_location_id, $connection->environment === 'production' ? 'live' : 'test', $this->store->runtime());
    }

    public function assertAllowed(MerchantBinding $binding, string $operation): void
    {
        $scope = $this->register($binding->registerId, $operation);
        if (! hash_equals($binding->fingerprint(), $this->binding($scope)->fingerprint())) {
            throw new PosException('merchant_binding_changed');
        }
        if ($operation === 'collect' && $scope['connection']->environment === 'production') {
            $settings = $this->store->decrypt($scope['register']->configuration);
            $accepted = ($scope['register']->provider ?? 'square_native') === 'stripe_terminal'
                ? $this->store->rows('readers', $scope['tenant_id'])->where('register_id', $scope['register']->id)->firstOrFail() : $scope['connection'];
            $acceptedBuild = $accepted->acceptance_build ?: ($settings['acceptance']['build'] ?? null);
            if (! is_string($acceptedBuild) || ! hash_equals($acceptedBuild, request()->header('X-Pos-Build', ''))) {
                throw new PosException('accepted_build_required', 403);
            }
            if (! $accepted->acceptance_at && ($scope['register']->provider ?? '') === 'stripe_terminal' && (($settings['acceptance']['operator_id'] ?? null) !== ($scope['operator_id'] ?? null))) {
                throw new PosException('acceptance_operator_required', 403);
            }
            if (! $accepted->acceptance_at && (($settings['acceptance']['actor_id'] ?? null) !== $scope['actor_id']
                || ($settings['acceptance']['device_id'] ?? null) !== request()->header('X-Pos-Device'))) {
                throw new PosException('acceptance_device_required', 403);
            }
        }
        if ($operation === 'collect' && $this->readiness->blockers($scope['register'], $scope['connection']) !== []) {
            throw new PosException('collection_not_ready', 503);
        }
    }
}
