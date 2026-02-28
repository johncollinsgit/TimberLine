<?php

use App\Livewire\Retail\Markets\EventMatchWizard;
use App\Livewire\Retail\Markets\UpcomingEventsPanel;
use App\Livewire\Retail\Markets\CandidateMatchList;
use App\Livewire\Retail\Markets\MarketsPlanner;
use App\Livewire\Retail\Plan as RetailPlanComponent;
use App\Models\RetailPlan;
use App\Models\Event;
use App\Models\MarketPlan;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Models\User;
use App\Services\EventMatchingService;
use App\Services\MarketEventSyncCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

test('markets planner mounts as a thin wrapper around the markets wizard', function () {
    $plan = RetailPlan::query()->create([
        'name' => 'Markets Planner Wrapper Test',
        'status' => 'draft',
        'queue_type' => 'markets',
    ]);

    Livewire::test(MarketsPlanner::class, ['planId' => $plan->id])
        ->assertSet('planId', $plan->id)
        ->assertSeeText('One Event At A Time');
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
    $coordinator->shouldReceive('queueStatus')->andReturn([
        'status' => 'idle',
        'weeks' => 4,
        'queued_at' => null,
        'started_at' => null,
        'finished_at' => null,
        'last_sync_at' => null,
        'last_sync_status' => null,
        'last_http_status' => null,
        'last_error' => null,
        'last_result' => [],
    ])->zeroOrMoreTimes();
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
        ->assertSeeText('Run the local match scan to rank historical events within 30 days of this upcoming date.')
        ->call('handleRunCandidateMatch', $upcoming->id, 30)
        ->assertSeeText('Explicit Match Candidate Event 2025');
});

test('event picker supports future past all scopes and local search without http', function () {
    Event::query()->create([
        'name' => 'Future Nashville Popup',
        'display_name' => 'Future Nashville Popup 2026',
        'starts_at' => now()->addDays(12)->toDateString(),
        'ends_at' => now()->addDays(12)->toDateString(),
        'city' => 'Nashville',
        'state' => 'TN',
        'source' => 'asana_calendar',
        'source_ref' => 'scope-future-1',
        'status' => 'needs_mapping',
    ]);

    Event::query()->create([
        'name' => 'Past Franklin Market',
        'display_name' => 'Past Franklin Market 2025',
        'starts_at' => now()->subDays(20)->toDateString(),
        'ends_at' => now()->subDays(20)->toDateString(),
        'city' => 'Franklin',
        'state' => 'TN',
        'source' => 'asana_calendar',
        'source_ref' => 'scope-past-1',
        'status' => 'needs_mapping',
    ]);

    Http::fake();

    $component = Livewire::test(UpcomingEventsPanel::class, [
        'planId' => 0,
        'stateTab' => 'needs_mapping',
        'lookaheadDays' => 30,
    ]);

    $component
        ->assertSeeText('Future Nashville Popup 2026')
        ->assertDontSeeText('Past Franklin Market 2025')
        ->call('setDateMode', 'past')
        ->assertSeeText('Past Franklin Market 2025')
        ->assertDontSeeText('Future Nashville Popup 2026')
        ->call('setDateMode', 'all')
        ->assertSeeText('Future Nashville Popup 2026')
        ->assertSeeText('Past Franklin Market 2025')
        ->set('searchTerm', 'Franklin')
        ->assertSeeText('Past Franklin Market 2025')
        ->assertDontSeeText('Future Nashville Popup 2026');

    Http::assertNothingSent();
});

test('selecting a historical match copies market plan rows into draft boxes', function () {
    $user = User::factory()->create([
        'role' => 'admin',
        'email_verified_at' => now(),
    ]);
    $this->actingAs($user);

    $plan = RetailPlan::query()->create([
        'name' => 'Historical Match Copy Test',
        'status' => 'draft',
        'queue_type' => 'markets',
    ]);

    $scents = collect([
        Scent::query()->create(['name' => 'Blue Ridge', 'display_name' => 'Blue Ridge', 'is_active' => true]),
        Scent::query()->create(['name' => 'Moss Trail', 'display_name' => 'Moss Trail', 'is_active' => true]),
        Scent::query()->create(['name' => 'Cedar Smoke', 'display_name' => 'Cedar Smoke', 'is_active' => true]),
    ]);

    $upcoming = Event::query()->create([
        'name' => '03.14.26 TR Winter Pop Up',
        'display_name' => '03.14.26 TR Winter Pop Up',
        'starts_at' => '2026-03-14',
        'ends_at' => '2026-03-14',
        'status' => 'needs_mapping',
    ]);

    $candidate = Event::query()->create([
        'name' => 'TR Winter Pop Up 02.08.25',
        'display_name' => 'TR Winter Pop Up 02.08.25',
        'starts_at' => '2025-02-08',
        'ends_at' => '2025-02-08',
        'status' => 'mapped',
    ]);

    $normalizedTitle = app(EventMatchingService::class)
        ->normalizeTitle((string) ($candidate->display_name ?: $candidate->name));

    foreach ([
        ['box_type' => 'half', 'scent' => (string) $scents[0]->display_name, 'box_count' => 1],
        ['box_type' => 'full', 'scent' => (string) $scents[1]->display_name, 'box_count' => 1],
        ['box_type' => 'half', 'scent' => (string) $scents[2]->display_name, 'box_count' => 1],
    ] as $row) {
        MarketPlan::query()->create([
            'event_title' => (string) ($candidate->display_name ?: $candidate->name),
            'event_date' => '2025-02-08',
            'normalized_title' => $normalizedTitle,
            'box_type' => $row['box_type'],
            'scent' => $row['scent'],
            'box_count' => $row['box_count'],
            'status' => 'published',
        ]);
    }

    $component = Livewire::test(MarketsPlanner::class, ['planId' => $plan->id])
        ->call('handleMarketsMappingConfirmed', $upcoming->id, $candidate->id);

    $component
        ->assertDispatched('marketsDraftUpdated')
        ->assertDispatched('marketsPrefillStatusChanged');

    $this->assertDatabaseHas('event_mappings', [
        'upcoming_event_id' => $upcoming->id,
        'past_event_id' => $candidate->id,
    ]);

    $items = RetailPlanItem::query()
        ->where('retail_plan_id', $plan->id)
        ->where('upcoming_event_id', $upcoming->id)
        ->where('source', 'event_prefill')
        ->get();

    expect($items)->toHaveCount(3);
    expect((int) $items->sum('quantity'))->toBe(4);
    expect((string) $upcoming->fresh()->status)->toBe('drafted');
});

test('wizard shows no-history guidance when the selected historical event has no boxes', function () {
    $plan = RetailPlan::query()->create([
        'name' => 'No History Guidance Test',
        'status' => 'draft',
        'queue_type' => 'markets',
    ]);

    $upcoming = Event::query()->create([
        'name' => '03.14.26 TR Winter Pop Up',
        'display_name' => '03.14.26 TR Winter Pop Up',
        'starts_at' => '2026-03-14',
        'ends_at' => '2026-03-14',
        'status' => 'needs_mapping',
    ]);

    $candidate = Event::query()->create([
        'name' => 'TR Winter Pop Up 02.08.25',
        'display_name' => 'TR Winter Pop Up 02.08.25',
        'starts_at' => '2025-02-08',
        'ends_at' => '2025-02-08',
        'status' => 'mapped',
    ]);

    Livewire::test(EventMatchWizard::class, [
        'planId' => $plan->id,
        'upcomingEventId' => $upcoming->id,
        'selectedCandidateEventId' => $candidate->id,
    ])
        ->set('step', 3)
        ->call(
            'handlePrefillStatusChanged',
            $upcoming->id,
            $candidate->id,
            'no_history_rows',
            'Historical event has no boxes to copy. Start building boxes by adding a scent.',
            0
        )
        ->assertSeeText('Historical event has no boxes to copy. Start building boxes by adding a scent.');
});

test('selecting a historical match shows migration guidance when mappings table is missing', function () {
    $plan = RetailPlan::query()->create([
        'name' => 'Missing Mappings Table Test',
        'status' => 'draft',
        'queue_type' => 'markets',
    ]);

    $upcoming = Event::query()->create([
        'name' => '03.14.26 TR Winter Pop Up',
        'display_name' => '03.14.26 TR Winter Pop Up',
        'starts_at' => '2026-03-14',
        'ends_at' => '2026-03-14',
        'status' => 'needs_mapping',
    ]);

    $candidate = Event::query()->create([
        'name' => 'TR Winter Pop Up 02.08.25',
        'display_name' => 'TR Winter Pop Up 02.08.25',
        'starts_at' => '2025-02-08',
        'ends_at' => '2025-02-08',
        'status' => 'mapped',
    ]);

    Schema::drop('event_mappings');

    $component = Livewire::test(MarketsPlanner::class, ['planId' => $plan->id])
        ->call('handleMarketsMappingConfirmed', $upcoming->id, $candidate->id);

    $component->assertDispatched('marketsPrefillStatusChanged');

    expect(Schema::hasTable('event_mappings'))->toBeFalse();
    expect(RetailPlanItem::query()->count())->toBe(0);

    Livewire::test(EventMatchWizard::class, [
        'planId' => $plan->id,
        'upcomingEventId' => $upcoming->id,
        'selectedCandidateEventId' => $candidate->id,
    ])
        ->set('step', 3)
        ->call(
            'handlePrefillStatusChanged',
            $upcoming->id,
            $candidate->id,
            'missing_mappings',
            'Mappings table missing. Run migrations: php artisan migrate',
            0
        )
        ->assertSeeText('Mappings table missing. Run migrations: php artisan migrate');
});
