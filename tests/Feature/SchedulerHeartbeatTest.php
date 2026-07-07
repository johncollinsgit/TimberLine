<?php

use App\Models\IntegrationHealthEvent;
use App\Services\SchedulerHeartbeatService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

function openSchedulerAlerts(): int
{
    return IntegrationHealthEvent::query()
        ->where('event_type', SchedulerHeartbeatService::EVENT_TYPE)
        ->where('status', 'open')
        ->count();
}

it('records a heartbeat and treats it as fresh', function () {
    $service = app(SchedulerHeartbeatService::class);
    $service->pulse();

    expect($service->minutesSinceHeartbeat())->toBeLessThan(2);

    $service->evaluate(10);
    expect(openSchedulerAlerts())->toBe(0);
});

it('does nothing before any heartbeat has been recorded', function () {
    app(SchedulerHeartbeatService::class)->evaluate(10);

    expect(openSchedulerAlerts())->toBe(0);
});

it('raises a system error alert when the heartbeat is stale', function () {
    Cache::forever('scheduler:last_heartbeat', CarbonImmutable::now()->subMinutes(45)->toIso8601String());

    app(SchedulerHeartbeatService::class)->evaluate(10);

    $event = IntegrationHealthEvent::query()
        ->where('event_type', SchedulerHeartbeatService::EVENT_TYPE)
        ->where('status', 'open')
        ->first();

    expect($event)->not->toBeNull();
    expect($event->provider)->toBe('system');
    expect($event->severity)->toBe('error');
});

it('auto-resolves the alert once the heartbeat is fresh again', function () {
    $service = app(SchedulerHeartbeatService::class);
    Cache::forever('scheduler:last_heartbeat', CarbonImmutable::now()->subMinutes(45)->toIso8601String());

    $service->evaluate(10);
    expect(openSchedulerAlerts())->toBe(1);

    $service->pulse();
    $service->evaluate(10);

    expect(openSchedulerAlerts())->toBe(0);
    expect(IntegrationHealthEvent::where('event_type', SchedulerHeartbeatService::EVENT_TYPE)->where('status', 'resolved')->count())->toBe(1);
});

it('pulses via the scheduler:heartbeat command', function () {
    $this->artisan('scheduler:heartbeat')->assertSuccessful();

    expect(app(SchedulerHeartbeatService::class)->minutesSinceHeartbeat())->not->toBeNull();
});
