<?php

namespace App\Services\Onboarding;

use App\Models\LandlordOperatorAction;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Billing\SelfServicePurchaseEligibility;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A private, server-clock trial. It is never purchase or provider evidence. */
class SelfServiceTrialService
{
    public const POLICY = 'private-14d-v1';

    public const PRIVATE_MODULES = ['work_core', 'customers', 'field_service', 'documents', 'managed_website', 'uploads', 'onboarding', 'settings'];

    public function __construct(private readonly SelfServicePurchaseEligibility $purchases, private readonly LandlordOperatorActionAuditService $audit) {}

    /** Called only during the original verified, fresh-workspace transaction. */
    public function startForFreshWorkspace(Tenant $tenant, User $owner): array
    {
        if (! config('self_service.trial_enabled', true)) {
            return $this->status($tenant);
        }

        return DB::transaction(function () use ($tenant, $owner): array {
            Tenant::query()->whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $profile = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $metadata = (array) $profile->metadata;
            if (array_key_exists('self_service_trial', $metadata)) {
                return $this->status($tenant); // Never replace, repair or extend an existing clock.
            }
            abort_if(LandlordOperatorAction::withoutGlobalScopes()->where('tenant_id', $tenant->id)
                ->where('action_type', 'self_service.trial_started')->exists(), 403, 'An existing trial clock cannot be replaced.');
            abort_unless($owner->is_active && $owner->hasVerifiedEmail()
                && ($metadata['self_service_registration'] ?? false) === true
                && (int) ($metadata['self_service_owner_id'] ?? 0) === (int) $owner->id
                && $profile->source === 'self_serve_first_login'
                && $tenant->created_at && $tenant->created_at->greaterThanOrEqualTo(now()->subMinute())
                && $owner->tenants()->count() === 1
                && $owner->tenants()->whereKey($tenant->id)->wherePivot('role', 'owner')->wherePivot('membership_active', true)->exists(), 403);
            abort_unless(LandlordOperatorAction::withoutGlobalScopes()->where('tenant_id', $tenant->id)->where('actor_user_id', $owner->id)
                ->where('action_type', 'self_service.workspace_created')->where('target_id', (string) $profile->id)->where('status', 'success')->exists(), 403);
            $started = now()->toImmutable()->utc();
            $clock = [
                'policy' => self::POLICY, 'tenant_id' => (int) $tenant->id, 'profile_id' => (int) $profile->id,
                'owner_user_id' => (int) $owner->id, 'started_at' => $started->toIso8601String(),
                'ends_at' => $started->addDays(14)->toIso8601String(),
                'limits' => (array) config('module_catalog.offers.standard.trial.limits', []),
                'module_keys' => self::PRIVATE_MODULES,
            ];
            $clock['clock_sha256'] = $this->clockHash($clock);
            $metadata['self_service_trial'] = [...$clock, 'converted_at' => null];
            $profile->forceFill(['metadata' => $metadata])->save();
            $this->audit->record($tenant->id, $owner->id, 'self_service.trial_started', targetType: 'tenant_access_profile', targetId: $profile->id,
                afterState: ['trial_clock_sha256' => $clock['clock_sha256'], 'trial' => $clock]);

            return $this->status($tenant);
        });
    }

    /** Read only. No resolver calls, grants, clock renewal or expiry job. */
    public function status(Tenant|int $tenant): array
    {
        $id = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;
        $out = ['registered_trial' => false, 'state' => 'none', 'started_at' => null, 'ends_at' => null,
            'remaining_days' => 0, 'can_edit_private' => false, 'can_view_private' => false, 'upgrade_required' => false, 'limits' => []];
        $profilesAvailable = Schema::hasTable('tenant_access_profiles');
        $auditsAvailable = Schema::hasTable('landlord_operator_actions');
        if (! $profilesAvailable && ! $auditsAvailable) {
            return $out;
        }
        $profile = $profilesAvailable ? TenantAccessProfile::query()->where('tenant_id', $id)->first() : null;
        $metadata = (array) $profile?->metadata;
        $hasClock = array_key_exists('self_service_trial', $metadata);
        $partialAuthority = ! $profilesAvailable || ! $auditsAvailable;
        $hasAudit = $auditsAvailable && LandlordOperatorAction::withoutGlobalScopes()->where('tenant_id', $id)->where('action_type', 'self_service.trial_started')->exists();
        if (! $hasClock && ! $hasAudit && ! ($partialAuthority && ($metadata['self_service_registration'] ?? false) === true)) {
            return $out;
        }
        $out = [...$out, 'registered_trial' => true, 'state' => 'invalid', 'upgrade_required' => true];
        if ($partialAuthority) {
            return $out; // A remaining marker cannot grant usage without its authority.
        }
        if ($this->purchases->paidForTenant($id)) {
            return [...$out, 'state' => 'paid', 'can_edit_private' => true, 'can_view_private' => true, 'upgrade_required' => false];
        }
        if (! $hasClock) {
            return $out;
        }
        try {
            $clock = $metadata['self_service_trial'];
            if (! is_array($clock) || ($metadata['self_service_registration'] ?? false) !== true
                || ($clock['policy'] ?? '') !== self::POLICY || ($clock['tenant_id'] ?? null) !== $id
                || ($clock['profile_id'] ?? null) !== (int) $profile->id
                || ! is_int($clock['owner_user_id'] ?? null) || $clock['owner_user_id'] !== ($metadata['self_service_owner_id'] ?? null)
                || ($clock['module_keys'] ?? []) !== self::PRIVATE_MODULES || ! is_array($clock['limits'] ?? null)
                || ! hash_equals($this->clockHash($clock), (string) ($clock['clock_sha256'] ?? ''))) {
                return $out;
            }
            $started = CarbonImmutable::parse($clock['started_at'])->utc();
            $ends = CarbonImmutable::parse($clock['ends_at'])->utc();
            if ($started->greaterThan(now()->addSeconds(5)) || ! $started->addDays(14)->equalTo($ends)
                || ! LandlordOperatorAction::withoutGlobalScopes()->where('tenant_id', $id)->where('actor_user_id', $clock['owner_user_id'])
                    ->where('action_type', 'self_service.trial_started')->where('target_type', 'tenant_access_profile')->where('target_id', (string) $profile->id)
                    ->where('status', 'success')->whereNull('before_state')->where('after_state->trial_clock_sha256', $clock['clock_sha256'])->exists()) {
                return $out;
            }
            $converted = ! empty($clock['converted_at']) || $this->purchases->hasVerifiedPurchase($id);
            $active = ! $converted && $ends->isFuture();

            return [...$out, 'state' => $converted ? 'converted' : ($active ? 'active' : 'expired'),
                'started_at' => $started->toIso8601String(), 'ends_at' => $ends->toIso8601String(),
                'remaining_days' => $active ? (int) ceil(now()->diffInSeconds($ends) / 86400) : 0,
                'can_edit_private' => $active, 'can_view_private' => true, 'upgrade_required' => ! $active, 'limits' => $clock['limits']];
        } catch (\Throwable) {
            return $out;
        }
    }

    public function activeForTenant(Tenant|int $tenant): bool
    {
        return $this->status($tenant)['state'] === 'active';
    }

    public function retainedForTenant(Tenant|int $tenant): bool
    {
        return $this->status($tenant)['can_view_private'];
    }

    public function unpaidTrialForTenant(Tenant|int $tenant): bool
    {
        $status = $this->status($tenant);

        return $status['registered_trial'] && $status['state'] !== 'paid';
    }

    /** Retained even after refund/cancellation; a converted clock never reopens. */
    public function convertForVerifiedPurchase(Tenant|int $tenant): void
    {
        $id = $tenant instanceof Tenant ? (int) $tenant->id : $tenant;
        if (! $this->purchases->paidForTenant($id)) {
            return;
        }
        $profile = TenantAccessProfile::query()->where('tenant_id', $id)->lockForUpdate()->first();
        $metadata = (array) $profile?->metadata;
        $clock = $metadata['self_service_trial'] ?? null;
        if (! is_array($clock) || ! empty($clock['converted_at'])) {
            return;
        }
        $clock['converted_at'] = now()->toImmutable()->utc()->toIso8601String();
        $metadata['self_service_trial'] = $clock;
        $profile->forceFill(['metadata' => $metadata])->save();
        $this->audit->record($id, (int) ($clock['owner_user_id'] ?? 0), 'self_service.trial_converted', targetType: 'tenant_access_profile', targetId: $profile->id,
            afterState: ['trial_clock_sha256' => $clock['clock_sha256'] ?? null, 'converted_at' => $clock['converted_at']]);
    }

    public function assertPaidUsageAllowed(Tenant|int $tenant): void
    {
        abort_if($this->unpaidTrialForTenant($tenant), 423, 'Activate your plan before using paid services.');
    }

    public function assertMobileAccessAllowed(User $user): void
    {
        foreach ($this->candidateTenantsForUser($user) as $tenant) {
            $this->assertPaidUsageAllowed($tenant);
        }
    }

    public function candidateTenantsForUser(User $user): \Illuminate\Database\Eloquent\Collection
    {
        $profilesAvailable = Schema::hasTable('tenant_access_profiles');
        $auditsAvailable = Schema::hasTable('landlord_operator_actions');
        if (! $profilesAvailable && ! $auditsAvailable) {
            return new \Illuminate\Database\Eloquent\Collection;
        }

        return $user->tenants()->wherePivot('membership_active', true)->where(function ($query) use ($profilesAvailable, $auditsAvailable): void {
            if ($profilesAvailable) {
                $query->whereHas('accessProfile', function ($profile) use ($auditsAvailable): void {
                    $profile->where(function ($marker) use ($auditsAvailable): void {
                        $marker->whereJsonContainsKey('metadata->self_service_trial');
                        if (! $auditsAvailable) {
                            $marker->orWhere('metadata->self_service_registration', true);
                        }
                    });
                });
            }
            if ($auditsAvailable) {
                $query->orWhereExists(fn ($audit) => $audit->selectRaw('1')->from('landlord_operator_actions')
                    ->whereColumn('landlord_operator_actions.tenant_id', 'tenants.id')->where('action_type', 'self_service.trial_started'));
            }
        })->get();
    }

    private function clockHash(array $clock): string
    {
        unset($clock['clock_sha256'], $clock['converted_at']);
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (! is_array($value)) {
                return $value;
            }
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $canonical($item);
            }

            return $value;
        };

        return hash('sha256', json_encode($canonical($clock), JSON_THROW_ON_ERROR));
    }
}
