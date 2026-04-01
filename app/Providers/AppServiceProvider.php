<?php

namespace App\Providers;

use App\Models\User;
use App\Models\MarketingReviewHistory;
use App\Observers\MarketingReviewHistoryObserver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
    }

    protected function configureDefaults(): void
    {
        MarketingReviewHistory::observe(MarketingReviewHistoryObserver::class);

        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null
        );

        $this->registerAuthorizationGates();
    }

    protected function registerAuthorizationGates(): void
    {
        Gate::define('manage-landlord-commercial', function (User $user): bool {
            if ($user->getAttribute('is_active') === false) {
                return false;
            }

            $role = strtolower(trim((string) ($user->role ?? '')));
            $allowedRoles = collect((array) config('tenancy.landlord.operator_roles', ['admin']))
                ->map(fn (mixed $candidate): string => strtolower(trim((string) $candidate)))
                ->filter()
                ->values()
                ->all();
            if ($allowedRoles === []) {
                $allowedRoles = ['admin'];
            }

            if (! in_array($role, $allowedRoles, true)) {
                return false;
            }

            $allowedEmails = collect((array) config('tenancy.landlord.operator_emails', []))
                ->map(fn (mixed $candidate): string => strtolower(trim((string) $candidate)))
                ->filter()
                ->values()
                ->all();

            if ($allowedEmails === []) {
                return true;
            }

            $email = strtolower(trim((string) ($user->email ?? '')));

            return $email !== '' && in_array($email, $allowedEmails, true);
        });
    }
}
