<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class FortifyRegisterResponse implements RegisterResponseContract
{
    public function toResponse($request)
    {
        Auth::guard(config('fortify.guard'))->logout();

        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->wantsJson()) {
            return new JsonResponse([
                'message' => 'Account request submitted and pending approval.',
            ], 202);
        }

        return redirect()->route('login')->with('status', 'Account request submitted. An administrator must approve your access before you can sign in.');
    }
}
