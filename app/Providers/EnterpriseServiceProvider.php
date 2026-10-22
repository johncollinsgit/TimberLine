<?php

namespace App\Providers;

use App\Http\Middleware\EnsureEnterpriseRuntime;
use App\Models\TeamProject;
use App\Models\TeamTask;
use App\Policies\TeamProjectPolicy;
use App\Policies\TeamTaskPolicy;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Explicit shared components only: no retail observers, providers, schedules or grants. */
final class EnterpriseServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class);
        $this->app['config']->set('enterprise.runtime', true);
        $this->app->bind(\App\Enterprise\AskModel::class, \App\Enterprise\IntelligenceAskModel::class);
        $this->app->bind(\App\Enterprise\MailboxGateway::class, \App\Enterprise\EnterpriseMailboxGateway::class);
    }

    public function boot(): void
    {
        DB::prohibitDestructiveCommands($this->app->isProduction());
        Gate::policy(TeamProject::class, TeamProjectPolicy::class);
        Gate::policy(TeamTask::class, TeamTaskPolicy::class);
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if ((str_starts_with((string) $event->command, 'migrate') || in_array($event->command, ['db:wipe', 'db:seed', 'schema:dump'], true))
                && ! EnsureEnterpriseRuntime::configured()) {
                throw new \RuntimeException('Enterprise schema commands require isolated runtime configuration.');
            }
        });
        // Laravel ordinarily suppresses console-event rerouting during tests.
        // The isolation fence must apply equally to programmatic Artisan calls.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    }
}
