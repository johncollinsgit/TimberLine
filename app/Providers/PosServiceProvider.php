<?php

namespace App\Providers;

use App\Services\Pos\PosStore;
use Illuminate\Support\ServiceProvider;

final class PosServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/pos_live.php', 'pos_live');
        $this->app->bind(PosStore::class, fn ($app) => new PosStore($app['db']->connection(), $app['encrypter']));
    }

    public function boot(): void
    {
        $this->app->afterResolving(\Illuminate\Console\Scheduling\Schedule::class, function ($schedule): void {
            if (config('pos_live.enabled') === true && config('pos_live.servicing_enabled') === true) {
                $schedule->command('pos:reconcile --limit=100')->everyMinute()->withoutOverlapping(10);
            }
        });
        $this->commands([\App\Console\Commands\PosReadinessCommand::class, \App\Console\Commands\PosReconcileCommand::class,
            \App\Console\Commands\PosProvisionCommand::class, \App\Console\Commands\PosActivateCommand::class, \App\Console\Commands\PosAcceptCommand::class]);
    }
}
