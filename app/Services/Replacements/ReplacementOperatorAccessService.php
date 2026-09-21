<?php

namespace App\Services\Replacements;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Http\Request;

class ReplacementOperatorAccessService
{
    /** @param array<string,mixed> $context */
    public function resolveActor(Request $request, array $context, int $tenantId): ?User
    {
        $tenant = Tenant::query()->find($tenantId);
        if (! $tenant) {
            return null;
        }

        $candidate = $request->user();
        if (! $candidate instanceof User) {
            $email = strtolower(trim((string) ($context['shopify_admin_email'] ?? '')));
            $candidate = $email !== '' ? User::query()->whereRaw('LOWER(email) = ?', [$email])->first() : null;
        }

        if (! $candidate instanceof User || ! (bool) $candidate->is_active) {
            return null;
        }

        $membership = $candidate->tenants()->whereKey($tenantId)->first()?->pivot;
        if (! $membership || ! (bool) $membership->membership_active) {
            return null;
        }

        $role = strtolower(trim((string) $membership->role));

        return in_array($role, ['owner', 'tenant_owner', 'admin'], true) || $candidate->isAdmin()
            ? $candidate
            : null;
    }

    /** @param array<string,mixed> $context */
    public function activationIdentityAllowed(Request $request, array $context, ?User $actor): bool
    {
        if ($actor instanceof User && $actor->isAdmin()) {
            return true;
        }

        $email = strtolower(trim((string) ($context['shopify_admin_email'] ?? $actor?->email ?? '')));
        $shopifyAdminId = trim((string) ($context['shopify_admin_user_id'] ?? ''));
        $allowedEmails = (array) config('replacement_readiness.allowed_activation_admin_emails', []);
        $allowedIds = (array) config('replacement_readiness.allowed_activation_shopify_admin_ids', []);

        return ($email !== '' && in_array($email, $allowedEmails, true))
            || ($shopifyAdminId !== '' && in_array($shopifyAdminId, $allowedIds, true));
    }
}
