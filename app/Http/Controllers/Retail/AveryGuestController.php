<?php

namespace App\Http\Controllers\Retail;

use App\Http\Controllers\Controller;
use App\Services\Retail\AveryGuestAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AveryGuestController extends Controller
{
    public function show(Request $request)
    {
        abort_unless(AveryGuestAccess::enabled($request), 404);
        if (AveryGuestAccess::granted($request)) {
            return redirect('/retail/showroom/concepts/avery-knifeworks');
        }

        return response()->view('retail.avery-access')->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    public function unlock(Request $request)
    {
        abort_unless(AveryGuestAccess::enabled($request), 404);
        $input = $request->validate(['code' => 'required|string|max:32']);
        if (! Hash::check($input['code'], config('retail_showroom.avery_pin_hash'))) {
            return back()->withErrors(['code' => 'That access code did not match.']);
        }
        $request->session()->regenerate();
        $request->session()->put('avery_guest', ['expires' => time() + 43200, 'version' => hash('sha256', config('retail_showroom.avery_pin_hash'))]);

        return redirect('/retail/showroom/concepts/avery-knifeworks');
    }

    public function presenter(Request $request)
    {
        abort_unless(AveryGuestAccess::enabled($request), 404);
        $request->session()->forget('avery_guest');

        return redirect(auth()->check() ? '/retail/showroom' : '/login');
    }
}
