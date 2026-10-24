<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/** A restricted register grant never authenticates the enrolling owner. */
final class PosPilotAccess
{
    public function __construct(private PosStore $store) {}

    public function tenant(): int
    {
        $tenant = (int) request()->attributes->get('current_tenant_id');
        if (! config('pos_live.enabled') || ! config('pos_live.pilot_enabled') || $tenant < 1
            || ! in_array($tenant, config('pos_live.tenant_ids', []), true)) {
            throw new PosException('pos_unavailable', 404);
        }

        return $tenant;
    }

    private function membership(int $tenant, int $id): array
    {
        $user = User::query()->find($id);
        $member = $this->store->db->table('tenant_user')->where('tenant_id', $tenant)->where('user_id', $id)->first();
        if (! $user?->is_active || ! $user->hasVerifiedEmail() || ! $member?->membership_active
            || ! in_array($member->role, ['owner', 'tenant_owner', 'admin', 'manager', 'member'], true)) {
            throw new PosException('access_denied', 403);
        }

        return [$user, $member];
    }

    public function owner(): array
    {
        $tenant = $this->tenant();
        if (! request()->user()) {
            throw new PosException('owner_signin_required', 401);
        }
        [$user, $member] = $this->membership($tenant, (int) request()->user()->id);
        if (! in_array($member->role, ['owner', 'tenant_owner', 'admin'], true)) {
            throw new PosException('owner_required', 403);
        }

        return ['tenant_id' => $tenant, 'actor_id' => (int) $user->id];
    }

    public function enroll(string $register, string $name, bool $shared): array
    {
        $owner = $this->owner();
        $tenant = $owner['tenant_id'];
        $row = $this->store->rows('registers', $tenant)->where('id', $register)->where('active', true)->firstOrFail();
        if (($row->provider ?? '') !== 'stripe_terminal') {
            throw new PosException('reader_register_required');
        }
        $key = request()->header('X-Pos-Device');

        return \Illuminate\Support\Facades\Cache::lock('pos-enroll-'.hash('sha256', $this->store->runtime().':'.$tenant.':'.$key), 30)->block(5, function () use ($owner, $tenant, $register, $name, $shared, $key): array {
            $device = $this->store->rows('devices', $tenant)->where('device_key', $key)->first();

            return \Illuminate\Support\Facades\Cache::lock('pos-start-'.hash('sha256', $this->store->runtime().':'.$tenant.':'.($device->register_id ?? $register)), 180)->block(5, function () use ($owner, $tenant, $register, $name, $shared, $key, $device): array {
                if ($device && $device->register_id !== $register) {
                    $sales = $this->store->rows('sales', $tenant)->where('device_record_id', $device->id);
                    $unresolved = (clone $sales)->where(function ($q) {
                        $q->whereNotIn('state', ['succeeded', 'failed', 'cancelled'])->orWhere('stock_state', 'held')->orWhere(fn ($p) => $p->where('state', 'succeeded')->where('stock_state', '!=', 'committed'))->orWhere(fn ($p) => $p->whereNotNull('source_reference')->where('stock_state', 'unprepared'));
                    })->exists();
                    $effects = $this->store->rows('outbox', $tenant)->whereIn('sale_id', (clone $sales)->select('id'))->where('state', '!=', 'completed')->exists();
                    $refunds = $this->store->rows('refund_details', $tenant)->whereIn('sale_id', (clone $sales)->select('id'))->whereNull('confirmed_at')->whereNotIn('state', ['failed', 'cancelled', 'not_dispatched'])->exists();
                    if ($unresolved || $effects || $refunds) {
                        throw new PosException('resolve_device_sales_before_reassignment');
                    }
                }
                $id = $device?->id ?? bin2hex(random_bytes(16));
                $this->store->rows('devices', $tenant)->updateOrInsert(['id' => $id], $this->store->values($tenant,
                    ['name' => $name, 'device_key' => $key, 'register_id' => $register, 'enrolled_by' => $owner['actor_id'], 'active' => true, 'created_at' => $device?->created_at ?? now()]));
                $this->store->rows('shifts', $tenant)->where('device_id', $id)->where('state', 'open')->update(['state' => 'closed', 'closed_at' => now()]);
                $this->store->rows('register_sessions', $tenant)->where('device_id', $id)->update(['active' => false]);
                request()->session()->forget(['pos_pilot_token', 'pos_pilot_individual']);
                Auth::guard('web')->logout();
                request()->session()->invalidate();
                request()->session()->regenerateToken();
                request()->setUserResolver(fn () => null);
                request()->session()->put(['pos_web_device' => $key, 'url.intended' => preg_replace('~/owner/enroll$~D', '', request()->url())]);
                if ($shared) {
                    $secret = bin2hex(random_bytes(32));
                    $this->store->rows('register_sessions', $tenant)->insert($this->store->values($tenant,
                        ['id' => bin2hex(random_bytes(16)), 'device_id' => $id, 'token_hash' => hash('sha256', $secret), 'expires_at' => now()->addHours(12), 'created_at' => now()]));
                    request()->session()->put(['pos_pilot_token' => $secret, 'pos_web_device' => $key]);
                }
                $this->store->event($tenant, str_repeat('0', 32), 'register_enrolled', ['device_id' => $id, 'owner_id' => $owner['actor_id'], 'shared' => $shared]);

                return ['device_id' => $id, 'shared' => $shared, 'csrf_token' => csrf_token()];
            });
        });
    }

    public function context(bool $lockedAllowed = false): array
    {
        $tenant = $this->tenant();
        $device = $this->store->rows('devices', $tenant)->where('device_key', request()->header('X-Pos-Device'))->where('active', true)->first();
        if (! $device) {
            throw new PosException('device_enrollment_required', 403);
        }
        [, $ownerMember] = $this->membership($tenant, (int) $device->enrolled_by);
        if (! in_array($ownerMember->role, ['owner', 'tenant_owner', 'admin'], true)) {
            throw new PosException('device_owner_revoked', 403);
        }
        $secret = request()->session()->get('pos_pilot_token');
        if (is_string($secret)) {
            $session = $this->store->rows('register_sessions', $tenant)->where('token_hash', hash('sha256', $secret))->where('device_id', $device->id)->where('active', true)->first();
            if (! $session || strtotime($session->expires_at) <= time()) {
                throw new PosException('register_session_expired', 401);
            }
            $operator = $session->operator_id ? $this->store->rows('operators', $tenant)->where('id', $session->operator_id)->where('active', true)->first() : null;
            if ($session->last_used_at && strtotime($session->last_used_at) <= time() - 300) {
                $this->store->rows('register_sessions', $tenant)->where('id', $session->id)->update(['operator_id' => null]);
                $operator = null;
            }
            $actorId = (int) $device->enrolled_by;
        } else {
            if (! request()->user()) {
                throw new PosException('staff_signin_required', 401);
            }
            [$user, $member] = $this->membership($tenant, (int) request()->user()->id);
            $operator = $this->store->rows('operators', $tenant)->where('user_id', $user->id)->where('active', true)->first();
            if (! $operator) {
                throw new PosException('staff_profile_required', 403);
            }
            if (($operator->role === 'admin' && ! in_array($member->role, ['owner', 'tenant_owner', 'admin'], true))
                || ($operator->role === 'manager' && $member->role === 'member')) {
                throw new PosException('staff_role_revoked', 403);
            }
            $sessionId = request()->session()->get('pos_pilot_individual');
            $session = $sessionId ? $this->store->rows('register_sessions', $tenant)->where('id', $sessionId)->where('device_id', $device->id)->where('operator_id', $operator->id)->where('active', true)->first() : null;
            if (! $session) {
                $sessionId = bin2hex(random_bytes(16));
                $this->store->rows('register_sessions', $tenant)->insert($this->store->values($tenant, ['id' => $sessionId, 'device_id' => $device->id,
                    'operator_id' => $operator->id, 'token_hash' => hash('sha256', random_bytes(32)), 'created_at' => now(), 'expires_at' => now()->addHours(12), 'last_used_at' => now()]));
                request()->session()->put('pos_pilot_individual', $sessionId);
                $session = $this->store->rows('register_sessions', $tenant)->where('id', $sessionId)->firstOrFail();
            }
            if (strtotime($session->expires_at) <= time()) {
                throw new PosException('register_session_expired', 401);
            }
            $actorId = (int) $user->id;
        }
        if ($operator?->user_id) {
            [, $linkedMembership] = $this->membership($tenant, (int) $operator->user_id);
            if (($operator->role === 'admin' && ! in_array($linkedMembership->role, ['owner', 'tenant_owner', 'admin'], true)) || ($operator->role === 'manager' && $linkedMembership->role === 'member')) {
                throw new PosException('staff_role_revoked', 403);
            }
        }
        if (! $operator && ! $lockedAllowed) {
            throw new PosException('register_locked', 423);
        }

        return ['tenant_id' => $tenant, 'actor_id' => $actorId, 'operator' => $operator, 'device' => $device, 'session' => $session,
            'operator_id' => $operator?->id, 'device_id' => $device->device_key, 'build' => config('pos_live.web_build') ?: 'web-prepare',
            'role' => $operator?->role === 'admin' ? (is_string($secret) ? 'manager' : 'admin') : ($operator?->role === 'manager' ? 'manager' : 'member'), 'shared' => is_string($secret)];
    }

    public function unlock(string $operatorId, string $pin): array
    {
        $ctx = $this->context(true);
        if (! $ctx['shared']) {
            throw new PosException('individual_signin_required');
        }
        $key = 'pos-pin-'.hash('sha256', $this->store->runtime().':'.$ctx['tenant_id'].':'.$ctx['device']->id.':'.$operatorId);
        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw new PosException('pin_temporarily_locked', 429);
        }
        $operator = $this->store->rows('operators', $ctx['tenant_id'])->where('id', $operatorId)->where('active', true)->first();
        if (! preg_match('/^\d{6}$/D', $pin) || ! $operator?->pin_hash || ! Hash::check($pin, $operator->pin_hash)
            || ! $this->hasRegisterAssignment($ctx['tenant_id'], $operatorId, $ctx['device']->register_id)) {
            RateLimiter::hit($key, 300);
            throw new PosException('invalid_staff_pin', 403);
        }
        RateLimiter::clear($key);
        $this->store->rows('register_sessions', $ctx['tenant_id'])->where('id', $ctx['session']->id)->update(['operator_id' => $operatorId, 'shift_id' => null, 'last_used_at' => now()]);
        $this->store->event($ctx['tenant_id'], str_repeat('0', 32), 'operator_unlocked', ['operator_id' => $operatorId, 'device_id' => $ctx['device']->id]);

        return ['unlocked' => true];
    }

    public function hasRegisterAssignment(int $tenant, string $operator, string $register): bool
    {
        $ids = $this->store->rows('event_registers', $tenant)->where('register_id', $register)->where('active', true)->pluck('event_id');

        return $this->store->rows('event_staff', $tenant)->whereIn('event_id', $ids)->where('operator_id', $operator)->where('active', true)->exists();
    }

    public function register(string $id, string $operation): array
    {
        $ctx = $this->context();
        $tenant = $ctx['tenant_id'];
        $register = $this->store->rows('registers', $tenant)->where('id', $id)->first();
        if (! $register || ($register->provider ?? null) !== 'stripe_terminal' || ! $this->hasRegisterAssignment($tenant, $ctx['operator_id'], $id)
            || ($ctx['device']->register_id !== $id && in_array($operation, ['quote', 'collect', 'catalog'], true) && $ctx['role'] === 'member')) {
            throw new PosException('register_unavailable', 404);
        }
        $connection = $this->store->rows('connections', $tenant)->where('id', $register->connection_id)->firstOrFail();
        if ($connection->provider !== 'stripe_terminal') {
            throw new PosException('provider_binding_mismatch');
        }
        if (in_array($operation, ['quote', 'collect', 'catalog'], true)
            && ! app(TenantModuleAccessResolver::class)->canAccess($tenant, 'pos')) {
            throw new PosException('pos_entitlement_required', 403);
        }
        if ($operation === 'refund' && $ctx['role'] === 'member') {
            throw new PosException('manager_required', 403);
        }
        if (in_array($operation, ['recover', 'refund'], true) && (! config('pos_live.servicing_enabled') || ! $register->servicing_enabled)) {
            throw new PosException('servicing_disabled', 503);
        }
        $event = null;
        $shift = null;
        if (in_array($operation, ['quote', 'collect'], true)) {
            if ($ctx['device']->register_id !== $id) {
                throw new PosException('device_register_mismatch', 403);
            }
            $shift = $this->store->rows('shifts', $tenant)->where('id', $ctx['session']->shift_id)->where('device_id', $ctx['device']->id)
                ->where('operator_id', $ctx['operator_id'])->where('register_id', $id)->where('state', 'open')->first();
            $event = $shift ? $this->store->rows('trade_events', $tenant)->where('id', $shift->event_id)->where('state', 'open')->first() : null;
            if (! $event || $event->location_id !== $register->location_id || strtotime($event->starts_at) > time() || strtotime($event->ends_at) <= time()
                || ! $this->store->rows('event_staff', $tenant)->where('event_id', $event->id)->where('operator_id', $ctx['operator_id'])->where('active', true)->exists()
                || ! $this->store->rows('event_registers', $tenant)->where('event_id', $event->id)->where('register_id', $id)->where('active', true)->exists()) {
                throw new PosException('active_event_shift_required');
            }
            $settings = $this->store->decrypt($event->configuration);
            if (($settings['register_tax_revisions'][$id] ?? null) !== PosPilotOperations::taxRevision($this->store->decrypt($register->configuration))) {
                throw new PosException('event_tax_revision_changed');
            }
            if (($settings['tax_reviewed'] ?? false) !== true || empty($settings['tax_valid_until']) || strtotime($settings['tax_valid_until']) <= time()) {
                throw new PosException('event_tax_unreviewed');
            }
        }

        return [...$ctx, 'register' => $register, 'connection' => $connection, 'can_refund' => $ctx['role'] !== 'member', 'trade_event' => $event, 'shift' => $shift];
    }
}
