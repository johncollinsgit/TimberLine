<?php

namespace App\Enterprise;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class SessionController
{
    public function create()
    {
        return response()->view('enterprise.login')->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request)
    {
        $input = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        $candidate = \App\Models\User::query()->where('email', $input['email'])->first();
        // Never bypass an existing MFA requirement. Challenge support is a separate phase.
        if ($candidate && ($candidate->two_factor_secret !== null || $candidate->email_verified_at === null)) {
            throw ValidationException::withMessages(['email' => 'This account cannot sign in through this Enterprise entry point. Contact your administrator.']);
        }
        if (! Auth::attempt($input + ['is_active' => true], false)) {
            throw ValidationException::withMessages(['email' => 'These credentials could not be verified.']);
        }
        $request->session()->regenerate();

        // Tenant selection is explicit; never create a membership during login.
        return response()->view('enterprise.select', ['tenants' => $request->user()->tenants()->wherePivot('membership_active', true)->get(['tenants.name', 'tenants.slug'])])->header('Cache-Control', 'private, no-store');
    }

    public function destroy(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
