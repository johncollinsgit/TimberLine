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
        $this->app->bind(\App\Enterprise\VoiceCompletionProvider::class, \App\Enterprise\ResponsesVoiceProvider::class);
        $this->app->bind(\App\Enterprise\VoiceCostReservation::class, \App\Enterprise\IntelligenceVoiceReservation::class);
        $this->app->bind(\App\Enterprise\MailboxGateway::class, \App\Enterprise\EnterpriseMailboxGateway::class);
    }

    public function boot(): void
    {
        \App\Proposals\RateLimits::register();
        $this->commands([\App\Enterprise\Provisioning\ProvisionClientCommand::class, \App\Enterprise\VoicePruneCommand::class]);
        DB::prohibitDestructiveCommands($this->app->isProduction());
        Gate::policy(TeamProject::class, TeamProjectPolicy::class);
        Gate::policy(TeamTask::class, TeamTaskPolicy::class);
        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            $silo = app(\App\Enterprise\Provisioning\ClientSilo::class);
            if ($silo->enabled() && in_array($event->command, ['db:wipe', 'db:seed', 'key:generate', 'migrate:fresh', 'migrate:refresh', 'migrate:reset', 'migrate:rollback'], true)) {
                throw new \RuntimeException('Destructive or identity-generating commands are unavailable in a client runtime.');
            }
            if ((str_starts_with((string) $event->command, 'migrate') || in_array($event->command, ['db:wipe', 'db:seed', 'schema:dump'], true))
                && ! EnsureEnterpriseRuntime::configured()) {
                throw new \RuntimeException('Enterprise schema commands require isolated runtime configuration.');
            }
            if ($silo->enabled() && (str_starts_with((string) $event->command, 'migrate') || $event->command === 'schema:dump')) {
                $silo->binding();
                if (\Illuminate\Support\Facades\Schema::hasTable('enterprise_client_silos') && $silo->row()) {
                    $silo->assertReady();
                }
            }
        });
        // Laravel ordinarily suppresses console-event rerouting during tests.
        // The isolation fence must apply equally to programmatic Artisan calls.
        $this->app->make(Kernel::class)->rerouteSymfonyCommandEvents();
    }
}
