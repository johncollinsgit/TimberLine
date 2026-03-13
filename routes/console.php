<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Square -> canonical marketing profile sync cadence.
Schedule::command('marketing:sync-square-customers', [
    '--limit' => 30000,
])
    ->dailyAt('01:10')
    ->withoutOverlapping(240)
    ->runInBackground();

Schedule::command('marketing:sync-square-orders', [
    '--limit' => 2000,
    '--since' => '3 days ago',
])
    ->everyThirtyMinutes()
    ->withoutOverlapping(120)
    ->runInBackground();

Schedule::command('marketing:sync-square-payments', [
    '--limit' => 2000,
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
