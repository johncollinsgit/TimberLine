<?php

namespace App\Support\Auth;

use App\Models\User;

class HomeRedirect
{
    public static function pathFor(?User $user): string
    {
        if (!$user) {
            return route('login', absolute: false);
        }

        $requestedVia = strtolower(trim((string) ($user->requested_via ?? '')));
        if ($requestedVia !== '' && str_starts_with($requestedVia, 'customer_')) {
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
}
