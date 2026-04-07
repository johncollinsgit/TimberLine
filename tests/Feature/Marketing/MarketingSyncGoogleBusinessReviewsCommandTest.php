<?php

use App\Services\Marketing\GoogleBusinessProfileConnectionService;
use App\Services\Marketing\GoogleBusinessProfileReviewSyncService;
use Illuminate\Console\Scheduling\Schedule;

test('marketing sync google business reviews command skips cleanly when readiness is not live', function () {
    $connectionService = \Mockery::mock(GoogleBusinessProfileConnectionService::class);
    $connectionService->shouldReceive('reviewReadiness')
        ->once()
        ->andReturn([
            'enabled' => true,
            'ready' => false,
            'reason' => 'needs_first_sync',
            'message' => 'Run the first Google review sync before review matching goes live on the storefront.',
        ]);

    $syncService = \Mockery::mock(GoogleBusinessProfileReviewSyncService::class);
    $syncService->shouldNotReceive('sync');

    app()->instance(GoogleBusinessProfileConnectionService::class, $connectionService);
    app()->instance(GoogleBusinessProfileReviewSyncService::class, $syncService);

    $this->artisan('marketing:sync-google-business-reviews')
        ->expectsOutputToContain('status=skipped')
        ->expectsOutputToContain('reason=needs_first_sync')
        ->expectsOutputToContain('message=Run the first Google review sync before review matching goes live on the storefront.')
        ->assertExitCode(0);
});

test('marketing sync google business reviews command runs the sync when readiness is live', function () {
    $connectionService = \Mockery::mock(GoogleBusinessProfileConnectionService::class);
    $connectionService->shouldReceive('reviewReadiness')
        ->once()
        ->andReturn([
            'enabled' => true,
            'ready' => true,
            'reason' => 'live',
            'message' => 'Google review matching is live.',
        ]);

    $syncService = \Mockery::mock(GoogleBusinessProfileReviewSyncService::class);
    $syncService->shouldReceive('sync')
        ->once()
        ->andReturn([
            'counts' => [
                'fetched' => 4,
                'matched' => 2,
                'awarded' => 1,
                'unmatched' => 1,
                'duplicates' => 0,
            ],
        ]);

    app()->instance(GoogleBusinessProfileConnectionService::class, $connectionService);
    app()->instance(GoogleBusinessProfileReviewSyncService::class, $syncService);

    $this->artisan('marketing:sync-google-business-reviews')
        ->expectsOutputToContain('status=completed')
        ->expectsOutputToContain('fetched=4')
        ->expectsOutputToContain('matched=2')
        ->expectsOutputToContain('awarded=1')
        ->expectsOutputToContain('unmatched=1')
        ->expectsOutputToContain('duplicates=0')
        ->assertExitCode(0);
});

test('marketing sync google business reviews command is scheduled every fifteen minutes in the background', function () {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduled) => str_contains((string) $scheduled->command, 'marketing:sync-google-business-reviews'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('*/15 * * * *')
        ->and($event->runInBackground)->toBeTrue()
        ->and($event->withoutOverlapping)->toBeTrue();
});
