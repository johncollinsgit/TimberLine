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

// Shopify webhook subscription drift audit (non-destructive; repair is manual).
Schedule::command('shopify:webhooks:verify')
    ->dailyAt('01:35')
    ->withoutOverlapping(60)
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
