<?php

use App\Models\MarketEventSyncState;
use App\Services\MarketEventSyncCoordinator;
use App\Services\UpcomingMarketEventsService;

test('queued sync transitions through running and does not remain queued after success', function () {
    config()->set('features.market_events_sync_enabled', true);
    config()->set('features.market_events_sync_cooldown_minutes', 10);

    $service = \Mockery::mock(UpcomingMarketEventsService::class);
    $service->shouldReceive('syncUpcoming')
        ->once()
        ->with(4)
        ->andReturn([
            'fetched' => 3,
            'upserted' => 2,
            'events' => [
                ['id' => 101, 'title' => 'Event 101', 'date' => now()->toDateString()],
                ['id' => 102, 'title' => 'Event 102', 'date' => now()->toDateString()],
            ],
        ]);
    $this->app->instance(UpcomingMarketEventsService::class, $service);

    $coordinator = app(MarketEventSyncCoordinator::class);

    $queued = $coordinator->markQueued(4, 7);
    expect($queued['status'])->toBe('queued');
    expect($queued['queued_at'])->not->toBeNull();
    expect($queued['started_at'])->toBeNull();
    expect($queued['finished_at'])->toBeNull();

    $result = $coordinator->runSync(4, false, 'test');

    expect($result['ok'])->toBeTrue();
    expect((string) ($result['status'] ?? ''))->toBe('success');
    expect((string) ($result['state']['status'] ?? ''))->not->toBe('queued');
    expect((string) ($result['state']['last_sync_status'] ?? ''))->toBe('success');
    expect($result['state']['started_at'])->not->toBeNull();
    expect($result['state']['finished_at'])->not->toBeNull();

    $stored = MarketEventSyncState::query()->where('sync_key', MarketEventSyncCoordinator::SYNC_KEY)->firstOrFail();
    expect((string) $stored->status)->not->toBe('queued');
    expect((string) $stored->last_sync_status)->toBe('success');
});

