<?php

namespace App\Support\Auth;

use App\Models\Tenant;
use App\Models\User;

class HomeRedirect
{
    public static function pathFor(?User $user, ?Tenant $tenant = null): string
    {
        if (!$user) {
            return route('login', absolute: false);
        }

        if (self::isPlatformOperator($user)) {
            return route('landlord.dashboard', absolute: false);
        }

        if ($tenant instanceof Tenant && self::tenantSetupIsIncomplete($tenant)) {
            return route('app.start', absolute: false);
        }

        if ($tenant instanceof Tenant && self::isCustomerPortalUser($user)) {
            if ($tenant->clientProjects()->exists()) {
                return route('client.projects.index', absolute: false);
            }

            if (self::isProductionCustomerUser($user)) {
                return route('app.start', absolute: false);
            }
        }

        $requestedVia = strtolower(trim((string) ($user->requested_via ?? '')));
        if (! $tenant instanceof Tenant && self::isProductionCustomerUser($user)) {
            return route('app.start', absolute: false);
        }

        $role = (string) ($user->role ?? '');

        if ($role === 'pouring') {
            return route('pouring.index', absolute: false);
        }

        if ($role === 'marketing_manager') {
            return route('marketing.overview', absolute: false);
        }

        return route('dashboard', absolute: false);
    }

    public static function isPlatformOperator(User $user): bool
    {
        $role = strtolower(trim((string) ($user->role ?? '')));

        return $role === 'platform_admin';
    }

    protected static function tenantSetupIsIncomplete(Tenant $tenant): bool
    {
        $tenant->loadMissing('setupStatus');

        $setupStatus = $tenant->setupStatus;
        if (! $setupStatus) {
            return false;
        }

        return (string) ($setupStatus->landlord_review_status ?? '') !== 'reviewed';
    }

    protected static function isCustomerPortalUser(User $user): bool
    {
        $requestedVia = strtolower(trim((string) ($user->requested_via ?? '')));

        return $requestedVia !== '' && str_starts_with($requestedVia, 'customer_');
    }

    protected static function isProductionCustomerUser(User $user): bool
    {
        $requestedVia = strtolower(trim((string) ($user->requested_via ?? '')));

        return $requestedVia === 'customer_production';
    }
}
