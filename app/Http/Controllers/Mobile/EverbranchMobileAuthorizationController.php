<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAuthorizationCode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class EverbranchMobileAuthorizationController extends Controller
{
    private const INTENT_SESSION_KEY = 'everbranch.mobile.authorization_intent';

    public function __invoke(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id' => ['required', 'in:everbranch-mobile'],
            'redirect_uri' => ['required', 'in:everbranch://auth/callback'],
            'code_challenge' => ['required', 'string', 'size:43', 'regex:/^[A-Za-z0-9_-]+$/'],
            'code_challenge_method' => ['required', 'in:S256'],
            'state' => ['required', 'string', 'min:16', 'max:255', 'regex:/^[A-Za-z0-9._~-]+$/'],
            'device_name' => ['nullable', 'string', 'max:160'],
            'auth_method' => ['required', 'in:google,email'],
        ]);

        $canonicalUrl = route('mobile.everbranch.authorize', $validated, absolute: false);
        $fingerprint = hash('sha256', json_encode($validated, JSON_THROW_ON_ERROR));
        $intent = $request->session()->get(self::INTENT_SESSION_KEY);

        if (is_array($intent) && hash_equals((string) ($intent['fingerprint'] ?? ''), $fingerprint)) {
            if (now()->timestamp > (int) ($intent['expires_at'] ?? 0)) {
                $request->session()->forget(self::INTENT_SESSION_KEY);

                throw ValidationException::withMessages([
                    'state' => 'This mobile sign-in attempt expired. Return to Everbranch and try again.',
                ]);
            }
        } else {
            $request->session()->put(self::INTENT_SESSION_KEY, [
                'fingerprint' => $fingerprint,
                'state' => $validated['state'],
                'auth_method' => $validated['auth_method'],
                'expires_at' => now()->addMinutes(10)->timestamp,
            ]);
        }

        $user = $request->user();
        if (! $user instanceof User) {
            $request->session()->put('url.intended', $canonicalUrl);

            return $validated['auth_method'] === 'google'
                ? redirect()->route('auth.google.redirect')
                : redirect()->route('login');
        }

        abort_unless($user->is_active !== false, 403);

        if (! $user->hasVerifiedEmail()) {
            $request->session()->put('url.intended', $canonicalUrl);

            return redirect()->route('verification.notice');
        }

        $plainCode = Str::random(64);
        MobileAuthorizationCode::query()->create([
            'user_id' => (int) $user->id,
            'code_hash' => hash('sha256', $plainCode),
            'code_challenge' => $validated['code_challenge'],
            'redirect_uri' => $validated['redirect_uri'],
            'client_id' => $validated['client_id'],
            'device_name' => $validated['device_name'] ?? null,
            'state' => $validated['state'],
            'expires_at' => now()->addMinutes(5),
        ]);

        $request->session()->forget(self::INTENT_SESSION_KEY);

        $query = http_build_query(array_filter([
            'code' => $plainCode,
            'state' => $validated['state'],
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));

        return redirect()->away($validated['redirect_uri'].'?'.$query);
    }
}
