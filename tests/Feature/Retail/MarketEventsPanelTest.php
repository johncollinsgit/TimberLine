<?php

use App\Livewire\Retail\Markets\UpcomingEventsPanel;
use App\Livewire\Retail\Markets\CandidateMatchList;
use App\Livewire\Retail\Plan as RetailPlanComponent;
use App\Models\Event;
use App\Models\User;
use App\Services\EventMatchingService;
use App\Services\MarketEventSyncCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Collection;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withoutVite();
});

test('markets panel renders upcoming db events without outbound http calls', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    Event::query()->create([
        'name' => 'Nashville Spring Pop-Up',
        'display_name' => 'Nashville Spring Pop-Up 2026',
        'starts_at' => now()->addDays(5)->toDateString(),
        'ends_at' => now()->addDays(5)->toDateString(),
        'source' => 'asana_calendar',
        'source_ref' => 'market-panel-http-1',
        'status' => 'needs_mapping',
    ]);

    Event::query()->create([
        'name' => 'Franklin Night Market',
        'display_name' => 'Franklin Night Market 2026',
        'starts_at' => now()->addDays(9)->toDateString(),
        'ends_at' => now()->addDays(9)->toDateString(),
        'source' => 'asana_calendar',
        'source_ref' => 'market-panel-http-2',
        'status' => 'needs_mapping',
    ]);

    Http::fake();

    Livewire::test(RetailPlanComponent::class, ['queue' => 'markets'])
        ->assertSet('queue', 'markets')
        ->call('loadMarketEventsPanel')
        ->assertSet('marketEventsPanelLoaded', true);

    Livewire::test(UpcomingEventsPanel::class, [
        'planId' => 0,
        'stateTab' => 'needs_mapping',
        'lookaheadDays' => 30,
    ])
        ->assertSeeText('Nashville Spring Pop-Up 2026')
        ->assertSeeText('Franklin Night Market 2026');

    Http::assertNothingSent();
});

test('initial markets plan render does not invoke sync coordinator or matching service', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    Event::query()->create([
        'name' => 'No Heavy Render Event',
        'display_name' => 'No Heavy Render Event 2026',
        'starts_at' => now()->addDays(3)->toDateString(),
        'ends_at' => now()->addDays(3)->toDateString(),
        'source' => 'asana_calendar',
        'source_ref' => 'market-panel-no-heavy-1',
        'status' => 'needs_mapping',
    ]);

    $coordinator = \Mockery::mock(MarketEventSyncCoordinator::class);
    $coordinator->shouldNotReceive('queueStatus');
    $coordinator->shouldNotReceive('canQueue');
    $coordinator->shouldNotReceive('markQueued');
    $coordinator->shouldNotReceive('runSync');
    $coordinator->shouldNotReceive('matchingCacheVersion');
    $coordinator->shouldNotReceive('bumpMatchingCacheVersion');
    $this->app->instance(MarketEventSyncCoordinator::class, $coordinator);

    $matching = \Mockery::mock(EventMatchingService::class);
    $matching->shouldNotReceive('candidatesForUpcoming');
    $matching->shouldNotReceive('bestMatch');
    $matching->shouldNotReceive('similarity');
    $matching->shouldNotReceive('normalizeTitle');
    $this->app->instance(EventMatchingService::class, $matching);

    Livewire::test(RetailPlanComponent::class, ['queue' => 'markets'])
        ->assertSet('queue', 'markets');
});

test('candidate matching runs only after explicit action', function () {
    $upcoming = Event::query()->create([
        'name' => 'Explicit Match Trigger Event',
        'display_name' => 'Explicit Match Trigger Event 2026',
        'starts_at' => now()->addDays(6)->toDateString(),
        'ends_at' => now()->addDays(6)->toDateString(),
        'city' => 'Nashville',
        'state' => 'TN',
        'source' => 'asana_calendar',
        'source_ref' => 'explicit-match-upcoming',
        'status' => 'needs_mapping',
    ]);

    $candidate = Event::query()->create([
        'name' => 'Explicit Match Candidate Event',
        'display_name' => 'Explicit Match Candidate Event 2025',
        'starts_at' => now()->subYear()->addDays(6)->toDateString(),
        'ends_at' => now()->subYear()->addDays(6)->toDateString(),
        'city' => 'Nashville',
        'state' => 'TN',
        'status' => 'planned',
    ]);

    $coordinator = \Mockery::mock(MarketEventSyncCoordinator::class);
    $coordinator->shouldReceive('matchingCacheVersion')->once()->andReturn(1);
    $this->app->instance(MarketEventSyncCoordinator::class, $coordinator);

    $matching = \Mockery::mock(EventMatchingService::class);
    $matching->shouldReceive('candidatesForUpcoming')
        ->once()
        ->andReturn(Collection::make([
            [
                'candidate_event_id' => $candidate->id,
                'event' => $candidate->fresh(),
                'match_score' => 0.92,
                'title_score' => 0.95,
                'date_score' => 0.88,
                'location_score' => 1.0,
                'days_diff' => 2,
            ],
        ]));
    $this->app->instance(EventMatchingService::class, $matching);

    Livewire::test(CandidateMatchList::class, [
        'upcomingEventId' => $upcoming->id,
        'matchWindowDays' => 30,
    ])
        ->assertSeeText('Run the local match scan to rank historical events for this upcoming date.')
        ->call('handleRunCandidateMatch', $upcoming->id, 30)
        ->assertSeeText('Explicit Match Candidate Event 2025');
});
