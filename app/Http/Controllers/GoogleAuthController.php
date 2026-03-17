<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Auth\HomeRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class GoogleAuthController extends Controller
{
    public function redirect(): RedirectResponse
    {
        abort_unless($this->googleLoginEnabled(), 404);

        return Socialite::driver('google')
            ->scopes(['openid', 'profile', 'email'])
            ->redirect();
    }

    public function callback(): RedirectResponse
    {
        abort_unless($this->googleLoginEnabled(), 404);

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException $stateException) {
            Log::warning('Google callback invalid state', [
                'exception' => get_class($stateException),
                'message' => $stateException->getMessage(),
                'state' => request('state'),
                'session_id' => session()->getId(),
            ]);
            report($stateException);

            try {
                $googleUser = Socialite::driver('google')
                    ->stateless()
                    ->user();
            } catch (Throwable $fallback) {
                Log::warning('Google callback stateless retry failed', [
                    'exception' => get_class($fallback),
                    'message' => $fallback->getMessage(),
                    'state' => request('state'),
                    'session_id' => session()->getId(),
                ]);
                report($fallback);

                return $this->redirectWithGoogleError();
            }
        } catch (Throwable $e) {
            Log::warning('Google callback failed', [
                'exception' => get_class($e),
                'message' => $e->getMessage(),
                'state' => request('state'),
                'session_id' => session()->getId(),
            ]);
            report($e);

            return $this->redirectWithGoogleError();
        }

        Log::debug('Google callback payload', [
            'email' => $googleUser->getEmail(),
            'name' => $googleUser->getName(),
            'avatar' => $googleUser->getAvatar(),
            'email_verified' => $googleUser->getEmail(),
            'state' => request('state'),
            'session_id' => session()->getId(),
        ]);

        $googleId = (string) ($googleUser->getId() ?? '');
        $email = Str::lower(trim((string) ($googleUser->getEmail() ?? '')));
        $name = trim((string) ($googleUser->getName() ?? $googleUser->getNickname() ?? ''));
        $avatar = $googleUser->getAvatar();

        if ($googleId === '' || $email === '') {
            return redirect()->route('login')
                ->withErrors(['email' => 'Google did not return a usable email address.']);
        }

        if (!$this->emailAllowedByDomain($email)) {
            return redirect()->route('login')
                ->withErrors(['email' => 'That Google account is not allowed for this workspace.']);
        }

        $user = User::query()->where('google_id', $googleId)->first();

        if (!$user) {
            $user = User::query()->where('email', $email)->first();
        }

        if (!$user) {
            if (!config('services.google.auto_provision', true)) {
                return redirect()->route('login')
                    ->withErrors(['email' => 'No Backstage account exists for that Google email. Ask an administrator to create one.']);
            }

            $user = User::query()->create([
                'name' => $name !== '' ? $name : Str::before($email, '@'),
                'email' => $email,
                'password' => Str::password(32),
                'role' => 'pouring',
                'is_active' => false,
                'requested_via' => 'google',
                'approval_requested_at' => now(),
            ]);
        }

        if ($user->getAttribute('is_active') === false) {
            if ($user->getAttribute('approved_at') === null) {
                return redirect()->route('login')
                    ->with('status', 'Google account request received. An administrator must approve your access before you can sign in.');
            }

            return redirect()->route('login')
                ->withErrors(['email' => 'This account is disabled. Contact an administrator.']);
        }

        $user->forceFill([
            'google_id' => $googleId,
            'google_avatar' => is_string($avatar) ? $avatar : null,
            'name' => $user->name ?: ($name !== '' ? $name : $user->name),
            'requested_via' => $user->requested_via ?: 'google',
        ])->save();

        Auth::login($user, remember: true);

        request()->session()->regenerate();

        return redirect()->intended(HomeRedirect::pathFor($user));
    }

    protected function redirectWithGoogleError(): RedirectResponse
    {
        return redirect()->route('login')
            ->withErrors(['email' => 'Google sign-in failed. Please try again.']);
    }

    protected function googleLoginEnabled(): bool
    {
        return (bool) config('services.google.enabled')
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    protected function emailAllowedByDomain(string $email): bool
    {
        $allowed = config('services.google.allowed_domains', []);

        if (!is_array($allowed) || $allowed === []) {
            return true;
        }

        $domain = Str::lower((string) Str::after($email, '@'));

        return in_array($domain, $allowed, true);
    }
}
