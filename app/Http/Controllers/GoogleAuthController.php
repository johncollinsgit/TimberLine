<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\Auth\GoogleOAuthFailureClassifier;
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
        if (! $this->ensureGoogleLoginReady('redirect')) {
            return $this->redirectWithGoogleUnavailable();
        }

        try {
            return Socialite::driver('google')
                ->scopes(['openid', 'profile', 'email'])
                ->redirect();
        } catch (Throwable $e) {
            [$errorCode, $errorMessage] = $this->extractGoogleOAuthErrorDetails($e);
            $failureClass = GoogleOAuthFailureClassifier::classify($errorCode, $errorMessage, $e);

            $this->logGoogleOAuthFailure(
                failureClass: $failureClass,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                phase: 'redirect',
                attempt: 'provider_redirect',
                exception: $e,
            );

            report($e);

            return $this->redirectWithGoogleUnavailable();
        }
    }

    public function callback(): RedirectResponse
    {
        if (! $this->ensureGoogleLoginReady('callback')) {
            return $this->redirectWithGoogleUnavailable();
        }

        if (request()->filled('error')) {
            $errorCode = (string) request()->query('error', '');
            $errorMessage = (string) request()->query('error_description', '');
            $failureClass = GoogleOAuthFailureClassifier::classify($errorCode, $errorMessage);

            $this->logGoogleOAuthFailure(
                failureClass: $failureClass,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                phase: 'callback',
                attempt: 'provider_error_query',
                exception: null,
            );

            return $this->redirectWithGoogleError();
        }

        try {
            $googleUser = Socialite::driver('google')->user();
        } catch (InvalidStateException $stateException) {
            $this->logGoogleOAuthFailure(
                failureClass: GoogleOAuthFailureClassifier::STATE_ERROR,
                errorCode: 'invalid_state',
                errorMessage: $stateException->getMessage(),
                phase: 'callback',
                attempt: 'stateful',
                exception: $stateException,
            );
            report($stateException);

            try {
                $googleUser = Socialite::driver('google')
                    ->stateless()
                    ->user();
            } catch (Throwable $fallback) {
                [$errorCode, $errorMessage] = $this->extractGoogleOAuthErrorDetails($fallback);
                $failureClass = GoogleOAuthFailureClassifier::classify($errorCode, $errorMessage, $fallback);

                $this->logGoogleOAuthFailure(
                    failureClass: $failureClass,
                    errorCode: $errorCode,
                    errorMessage: $errorMessage,
                    phase: 'callback',
                    attempt: 'stateless_fallback',
                    exception: $fallback,
                );
                report($fallback);

                return $this->redirectWithGoogleError();
            }
        } catch (Throwable $e) {
            [$errorCode, $errorMessage] = $this->extractGoogleOAuthErrorDetails($e);
            $failureClass = GoogleOAuthFailureClassifier::classify($errorCode, $errorMessage, $e);

            $this->logGoogleOAuthFailure(
                failureClass: $failureClass,
                errorCode: $errorCode,
                errorMessage: $errorMessage,
                phase: 'callback',
                attempt: 'stateful',
                exception: $e,
            );
            report($e);

            return $this->redirectWithGoogleError();
        }

        Log::info('auth.google.oauth.callback_success', [
            'category' => 'auth.google.oauth',
            'event' => 'callback_success',
            'phase' => 'callback',
            'state_present' => request()->filled('state'),
            'code_present' => request()->filled('code'),
            'email_present' => filled($googleUser->getEmail()),
            'google_id_present' => filled($googleUser->getId()),
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

    protected function redirectWithGoogleUnavailable(): RedirectResponse
    {
        return redirect()->route('login')
            ->withErrors(['email' => 'Google sign-in is temporarily unavailable. Please use email and password.']);
    }

    protected function googleLoginEnabled(): bool
    {
        return (bool) config('services.google.enabled')
            && filled(config('services.google.client_id'))
            && filled(config('services.google.client_secret'))
            && filled(config('services.google.redirect'));
    }

    protected function ensureGoogleLoginReady(string $phase): bool
    {
        if ($this->googleLoginEnabled()) {
            return true;
        }

        Log::warning('auth.google.oauth.preflight_failed', [
            'category' => 'auth.google.oauth',
            'event' => 'preflight_failed',
            'phase' => $phase,
            'google_login_enabled' => (bool) config('services.google.enabled'),
            'client_id_present' => filled(config('services.google.client_id')),
            'client_secret_present' => filled(config('services.google.client_secret')),
            'redirect_present' => filled(config('services.google.redirect')),
            'state_present' => request()->filled('state'),
            'code_present' => request()->filled('code'),
        ]);

        return false;
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function extractGoogleOAuthErrorDetails(?Throwable $exception = null): array
    {
        $errorCode = (string) request()->query('error', '');
        $errorMessage = (string) request()->query('error_description', '');

        if ($exception) {
            $exceptionMessage = (string) $exception->getMessage();

            if ($errorCode === '' && preg_match('/"error"\s*:\s*"([^"]+)"/i', $exceptionMessage, $matches)) {
                $errorCode = (string) ($matches[1] ?? '');
            }

            if ($errorMessage === '' && preg_match('/"error_description"\s*:\s*"([^"]+)"/i', $exceptionMessage, $matches)) {
                $errorMessage = (string) ($matches[1] ?? '');
            }

            if ($errorMessage === '') {
                $errorMessage = $exceptionMessage;
            }
        }

        return [$this->sanitizeForLogs($errorCode), $this->sanitizeForLogs($errorMessage)];
    }

    protected function logGoogleOAuthFailure(
        string $failureClass,
        string $errorCode,
        string $errorMessage,
        string $phase,
        string $attempt,
        ?Throwable $exception,
    ): void {
        Log::warning('auth.google.oauth.callback_failure', [
            'category' => 'auth.google.oauth',
            'event' => 'callback_failure',
            'phase' => $phase,
            'attempt' => $attempt,
            'failure_class' => $failureClass,
            'state_present' => request()->filled('state'),
            'code_present' => request()->filled('code'),
            'oauth_error_code' => $errorCode !== '' ? $errorCode : null,
            'oauth_error_message' => $errorMessage !== '' ? $errorMessage : null,
            'exception_class' => $exception ? get_class($exception) : null,
        ]);
    }

    protected function sanitizeForLogs(string $value): string
    {
        $sanitized = $value;
        $sensitiveValues = array_filter([
            (string) config('services.google.client_id', ''),
            (string) config('services.google.client_secret', ''),
            (string) config('services.google_gbp.client_id', ''),
            (string) config('services.google_gbp.client_secret', ''),
        ], static fn (string $candidate): bool => $candidate !== '');

        foreach (array_unique($sensitiveValues) as $sensitiveValue) {
            $sanitized = str_replace($sensitiveValue, '[REDACTED]', $sanitized);
        }

        return $sanitized;
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
