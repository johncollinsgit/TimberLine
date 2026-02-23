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

        return ($user->role ?? null) === 'pouring'
            ? route('pouring.index', absolute: false)
            : route('dashboard', absolute: false);
    }
}
