<?php

namespace App\Services\Retail;

use App\Models\User;
use Illuminate\Http\Request;

class AveryGuestAccess
{
    public static function enabled(Request $request): bool
    {
        return app()->environment('staging') && config('retail_showroom.avery_pin_enabled')
            && config('retail_showroom.enabled') && config('retail_showroom.avery_pin_hash')
            && hash_equals((string) config('retail_showroom.presenter_host'), $request->getHost());
    }

    public static function granted(Request $request): bool
    {
        return self::enabled($request) && $request->session()->get('avery_guest.expires', 0) > time()
            && hash_equals(hash('sha256', (string) config('retail_showroom.avery_pin_hash')), (string) $request->session()->get('avery_guest.version', ''));
    }

    public static function actor(?User $user): bool
    {
        return $user && request()->attributes->get('avery_guest') === true
            && self::granted(request()) && $user->email === config('retail_showroom.avery_guest_email');
    }
}
