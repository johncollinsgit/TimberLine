<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Square -> canonical marketing profile sync cadence.
Schedule::command('marketing:sync-square-customers')
    ->dailyAt('01:10')
    ->withoutOverlapping(240)
    ->runInBackground();

Schedule::command('marketing:sync-square-orders', [
    '--since' => '3 days ago',
])
    ->everyThirtyMinutes()
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:sync-square-payments', [
    '--since' => '3 days ago',
])
    ->everyThirtyMinutes()
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:sync-profiles', [
    '--source' => 'square',
    '--chunk' => 1000,
])
    ->hourlyAt(20)
    ->withoutOverlapping(180)
    ->runInBackground();

// Drain default queued profile/order jobs even if a daemon worker is not running.
Schedule::command('queue:work database --queue=default --stop-when-empty --tries=1 --sleep=1 --timeout=120')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->runInBackground();

// Shopify webhook subscription drift audit (non-destructive; repair is manual).
Schedule::command('shopify:webhooks:verify')
    ->dailyAt('01:35')
    ->withoutOverlapping(60)
    ->runInBackground();

// Keep Shopify order snapshots fresh so message-attributed sales stay current.
Schedule::command('shopify:import-orders', [
    '--days' => 14,
    '--status' => 'any',
    '--limit' => 250,
])
    ->everyThirtyMinutes()
    ->withoutOverlapping(120)
    ->runInBackground();

// Reconcile click->order attributions after order imports and webhook drift.
Schedule::command('marketing:sync-message-order-attributions', [
    '--days' => 14,
])
    ->everyThirtyMinutes()
    ->withoutOverlapping(120)
    ->runInBackground();

// Bound integration-health event volume by pruning old resolved records.
Schedule::command('integration-health:prune')
    ->dailyAt('02:20')
    ->withoutOverlapping(30)
    ->runInBackground();

Schedule::command('marketing:process-tenant-rewards-reminders', [
    '--limit' => 200,
])
    ->hourlyAt(10)
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:send-tenant-rewards-finance-reports')
    ->dailyAt('06:10')
    ->withoutOverlapping(120)
    ->runInBackground();
