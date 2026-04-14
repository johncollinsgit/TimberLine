<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use App\Http\Responses\FortifyLoginResponse;
use App\Http\Responses\FortifyPasswordResetResponse;
use App\Http\Responses\FortifyRegisterResponse;
use App\Http\Responses\FortifyTwoFactorLoginResponse;
use App\Models\User;
use App\Support\Tenancy\TenantHostBuilder;
use App\Support\Auth\PasswordResetUrlFactory;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(\Laravel\Fortify\Contracts\LoginResponse::class, FortifyLoginResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\PasswordResetResponse::class, FortifyPasswordResetResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\RegisterResponse::class, FortifyRegisterResponse::class);
        $this->app->singleton(\Laravel\Fortify\Contracts\TwoFactorLoginResponse::class, FortifyTwoFactorLoginResponse::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configurePasswordResetLinks();
        $this->configureEmailVerificationLinks();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);

        Fortify::authenticateUsing(function (Request $request): ?User {
            $email = Str::lower((string) $request->input('email'));
            $user = User::query()->where('email', $email)->first();

            if (!$user || !Hash::check((string) $request->input('password'), (string) $user->password)) {
                return null;
            }

            if ($user->getAttribute('is_active') === false) {
                $pending = $user->getAttribute('approved_at') === null
                    && in_array((string) ($user->getAttribute('requested_via') ?? ''), ['registration', 'google'], true);

                throw ValidationException::withMessages([
                    Fortify::username() => $pending
                        ? __('Your account request is pending approval.')
                        : __('This account is disabled. Contact an administrator.'),
                ]);
            }

            return $user;
        });
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn () => view('pages::auth.login'));
        Fortify::verifyEmailView(fn () => view('pages::auth.verify-email'));
        Fortify::twoFactorChallengeView(fn () => view('pages::auth.two-factor-challenge'));
        Fortify::confirmPasswordView(fn () => view('pages::auth.confirm-password'));
        Fortify::registerView(fn () => view('pages::auth.register'));
        Fortify::resetPasswordView(fn () => view('pages::auth.reset-password'));
        Fortify::requestPasswordResetLinkView(fn () => view('pages::auth.forgot-password'));
    }

    /**
     * Ensure reset links use the active auth host (or flagship host fallback) instead of a stale APP_URL host.
     */
    private function configurePasswordResetLinks(): void
    {
        ResetPassword::createUrlUsing(function (object $notifiable, #[\SensitiveParameter] string $token): string {
            $email = method_exists($notifiable, 'getEmailForPasswordReset')
                ? (string) $notifiable->getEmailForPasswordReset()
                : (string) ($notifiable->email ?? '');

            return app(PasswordResetUrlFactory::class)->make($token, $email);
        });
    }

    /**
     * Ensure email verification links emit the canonical landlord host by default.
     */
    private function configureEmailVerificationLinks(): void
    {
        VerifyEmail::createUrlUsing(function (object $notifiable): string {
            $expiresAt = now()->addMinutes((int) config('auth.verification.expire', 60));
            $parameters = [
                'id' => $notifiable->getKey(),
                'hash' => sha1($notifiable->getEmailForVerification()),
            ];

            $hostBuilder = app(TenantHostBuilder::class);
            $host = $hostBuilder->canonicalLandlordHost();
            $scheme = $hostBuilder->canonicalScheme();

            if (! is_string($host) || $host === '') {
                return URL::temporarySignedRoute('verification.verify', $expiresAt, $parameters);
            }

            URL::forceRootUrl($scheme.'://'.$host);
            URL::forceScheme($scheme);

            try {
                return URL::temporarySignedRoute('verification.verify', $expiresAt, $parameters);
            } finally {
                URL::forceRootUrl(null);
                URL::forceScheme(null);
            }
        });
    }

    /**
     * Configure rate limiting.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by($request->session()->get('login.id'));
        });

        RateLimiter::for('login', function (Request $request) {
            $throttleKey = Str::transliterate(Str::lower($request->input(Fortify::username())).'|'.$request->ip());

            return Limit::perMinute(5)->by($throttleKey);
        });
    }
}
