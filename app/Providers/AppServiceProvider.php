<?php

namespace App\Providers;

use App\Models\MarketingReviewHistory;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\MarketingReviewHistoryObserver;
use App\Observers\TenantObserver;
use App\Services\Integrations\ConnectionManager;
use App\Services\Integrations\QuickBooks\QuickBooksConnector;
use App\Services\Onboarding\Rails\DirectOnboardingRailAdapter;
use App\Services\Onboarding\Rails\OnboardingRailAdapterRegistry;
use App\Services\Onboarding\Rails\ShopifyOnboardingRailAdapter;
use App\Support\Tenancy\TenantContext;
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
        $this->app->singleton(OnboardingRailAdapterRegistry::class, function ($app): OnboardingRailAdapterRegistry {
            return new OnboardingRailAdapterRegistry([
                $app->make(ShopifyOnboardingRailAdapter::class),
                $app->make(DirectOnboardingRailAdapter::class),
            ]);
        });

        // The single registry for per-tenant external-provider connections.
        // QuickBooks is read/import-only for guided Collins Electric launch work.
        $this->app->singleton(ConnectionManager::class, function ($app): ConnectionManager {
            return new ConnectionManager([
                $app->make(QuickBooksConnector::class),
            ]);
        });

        // Holds the tenant the current request/job acts for, read by the enforced
        // TenantScope. `scoped` so it is flushed between requests/jobs.
        $this->app->scoped(TenantContext::class);
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
        Tenant::observe(TenantObserver::class);

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

        Gate::define('view-tenant-module-store', function (User $user, ?int $tenantId = null): bool {
            if ($user->getAttribute('is_active') === false || ! $user->canAccessMarketing() || $tenantId === null) {
                return false;
            }

            return $user->tenants()->whereKey($tenantId)->exists();
        });

        Gate::define('mutate-tenant-module-store', function (User $user, ?int $tenantId = null): bool {
            if ($user->getAttribute('is_active') === false || ! $user->canAccessMarketing() || $tenantId === null) {
                return false;
            }

            return $user->tenants()->whereKey($tenantId)->exists();
        });

        Gate::define('use-global-search', function (User $user): bool {
            return $user->getAttribute('is_active') !== false;
        });
    }
}
