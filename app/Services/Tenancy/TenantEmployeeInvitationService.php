<?php

namespace App\Services\Tenancy;

use App\Models\FieldServiceReminderSetting;
use App\Models\Tenant;
use App\Models\TenantEmployeeInvitation;
use App\Models\User;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class TenantEmployeeInvitationService
{
    public function __construct(protected TwilioSmsService $sms) {}

    /** @return array{invitation:TenantEmployeeInvitation,invite_url:string} */
    public function create(Tenant $tenant, User $actor, ?string $phone, ?string $email, string $role): array
    {
        $plain = Str::random(64);
        $invitation = TenantEmployeeInvitation::query()->create([
            'tenant_id' => (int) $tenant->id, 'invited_by_user_id' => (int) $actor->id,
            'phone' => filled($phone) ? trim((string) $phone) : null, 'email' => filled($email) ? Str::lower(trim((string) $email)) : null,
            'role' => $role, 'token_hash' => hash('sha256', $plain), 'status' => 'pending', 'expires_at' => now()->addDays(7),
        ]);
        $url = $this->inviteUrl($plain);
        $this->deliver($tenant, $invitation, $url);

        return ['invitation' => $invitation->fresh(), 'invite_url' => $url];
    }

    /** @return array{invitation:TenantEmployeeInvitation,invite_url:string} */
    public function resend(Tenant $tenant, TenantEmployeeInvitation $invitation): array
    {
        $this->assertTenant($tenant, $invitation);
        abort_if(in_array($invitation->status, ['accepted', 'revoked'], true), 409, 'This invitation cannot be resent.');
        $plain = Str::random(64);
        $invitation->forceFill(['token_hash' => hash('sha256', $plain), 'status' => 'pending', 'expires_at' => now()->addDays(7), 'delivery_error' => null])->save();
        $url = $this->inviteUrl($plain);
        $this->deliver($tenant, $invitation, $url);

        return ['invitation' => $invitation->fresh(), 'invite_url' => $url];
    }

    public function revoke(Tenant $tenant, TenantEmployeeInvitation $invitation): void
    {
        $this->assertTenant($tenant, $invitation);
        abort_if($invitation->status === 'accepted', 409, 'An accepted invitation cannot be revoked. Deactivate the membership instead.');
        $invitation->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
    }

    public function accept(User $user, string $plainToken): Tenant
    {
        return DB::transaction(function () use ($user, $plainToken): Tenant {
            $invitation = TenantEmployeeInvitation::query()->withoutGlobalScopes()
                ->where('token_hash', hash('sha256', $plainToken))->lockForUpdate()->first();
            if (! $invitation || $invitation->status !== 'pending' || $invitation->expires_at->isPast()) {
                throw ValidationException::withMessages(['token' => 'This invitation is invalid, expired, or already used.']);
            }
            if ($invitation->email && Str::lower((string) $user->email) !== $invitation->email) {
                throw ValidationException::withMessages(['token' => 'Sign in with the email address that received this invitation.']);
            }
            $tenant = Tenant::query()->findOrFail((int) $invitation->tenant_id);
            $existing = $user->tenants()->whereKey((int) $tenant->id)->first();
            if ($existing) {
                $user->tenants()->updateExistingPivot((int) $tenant->id, ['membership_active' => true, 'updated_at' => now()]);
            } else {
                $user->tenants()->attach((int) $tenant->id, ['role' => $invitation->role, 'membership_active' => true, 'created_at' => now(), 'updated_at' => now()]);
            }
            $invitation->forceFill(['status' => 'accepted', 'accepted_by_user_id' => (int) $user->id, 'accepted_at' => now()])->save();

            return $tenant;
        });
    }

    protected function deliver(Tenant $tenant, TenantEmployeeInvitation $invitation, string $url): void
    {
        if (! $invitation->phone) {
            $invitation->forceFill(['delivery_status' => 'share_link_ready'])->save();

            return;
        }
        $readiness = FieldServiceReminderSetting::query()->forTenantId((int) $tenant->id)->first();
        if (! $readiness?->enabled || $readiness->provider_status !== 'verified') {
            $invitation->forceFill(['delivery_status' => 'blocked', 'delivery_error' => 'SMS is not verified for this workspace. Copy the invitation link instead.'])->save();

            return;
        }
        $result = $this->sms->sendSms($invitation->phone, Str::limit($tenant->name.' invited you to join its Everbranch team: '.$url, 500), [
            'tenant_id' => (int) $tenant->id, 'source_type' => 'tenant_employee_invitation', 'source_id' => (int) $invitation->id,
            'idempotency_key' => 'employee-invite:'.$invitation->id.':'.$invitation->updated_at?->timestamp,
        ]);
        $invitation->forceFill([
            'delivery_status' => ($result['success'] ?? false) ? 'sent' : 'failed',
            'provider_message_id' => $result['provider_message_id'] ?? null,
            'delivery_error' => ($result['success'] ?? false) ? null : ($result['error_message'] ?? 'SMS delivery failed. Copy the invitation link instead.'),
        ])->save();
    }

    protected function inviteUrl(string $plain): string
    {
        return rtrim((string) config('app.url'), '/').'/join-team?token='.urlencode($plain);
    }

    protected function assertTenant(Tenant $tenant, TenantEmployeeInvitation $invitation): void
    {
        abort_unless((int) $invitation->tenant_id === (int) $tenant->id, 404);
    }
}
