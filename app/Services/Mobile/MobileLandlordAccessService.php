<?php

namespace App\Services\Mobile;

use App\Models\User;

class MobileLandlordAccessService
{
    public function allows(?User $user): bool
    {
        if (! $user instanceof User || $user->is_active === false) {
            return false;
        }

        $role = strtolower(trim((string) $user->role));
        $roles = collect((array) config('tenancy.landlord.operator_roles', ['admin']))
            ->map(fn ($value): string => strtolower(trim((string) $value)))->filter()->unique()->values()->all();
        if (! in_array($role, $roles !== [] ? $roles : ['admin'], true)) {
            return false;
        }

        $emails = collect((array) config('tenancy.landlord.operator_emails', []))
            ->map(fn ($value): string => strtolower(trim((string) $value)))->filter()->unique()->values()->all();
        if ($emails !== []) {
            return in_array(strtolower(trim((string) $user->email)), $emails, true);
        }

        return $role === 'platform_admin' || ! $user->tenants()->exists();
    }

    public function authorize(?User $user): User
    {
        abort_unless($this->allows($user), 403);

        return $user;
    }
}
