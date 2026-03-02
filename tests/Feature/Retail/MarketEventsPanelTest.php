<?php

use App\Livewire\Retail\Markets\EventMatchWizard;
use App\Livewire\Retail\Markets\UpcomingEventsPanel;
use App\Livewire\Retail\Markets\CandidateMatchList;
use App\Livewire\Retail\Markets\DraftEventEditor;
use App\Livewire\Retail\Markets\MarketsPlanner;
use App\Livewire\Retail\Plan as RetailPlanComponent;
use App\Models\EventBoxPlan;
use App\Models\EventInstance;
use App\Models\RetailPlan;
use App\Models\Event;
use App\Models\RetailPlanItem;
use App\Models\Scent;
use App\Models\Size;
use App\Models\User;
use App\Services\MarketDurationTemplateService;
use App\Services\MarketEventSyncCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
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

    EventInstance::query()->create([
        'title' => 'Explicit Match Candidate Event, TN',
        'starts_at' => now()->subYear()->addDays(6)->toDateString(),
        'ends_at' => now()->subYear()->addDays(8)->toDateString(),
        'state' => 'TN',
        'status' => 'completed',
    ]);

    $coordinator = \Mockery::mock(MarketEventSyncCoordinator::class);
    $coordinator->shouldReceive('matchingCacheVersion')->once()->andReturn(1);
    $this->app->instance(MarketEventSyncCoordinator::class, $coordinator);

    Livewire::test(CandidateMatchList::class, [
        'upcomingEventId' => $upcoming->id,
        'matchWindowDays' => 30,
    ])
        ->assertSeeText('Run the local match scan to rank historical box-plan templates within 30 days of this upcoming date.')
        ->call('handleRunCandidateMatch', $upcoming->id, 30)
        ->assertSeeText('Explicit Match Candidate Event, TN');
});

test('candidate matching falls back when sql token prefilter would otherwise hide a valid historical template', function () {
    $upcoming = Event::query()->create([
        'name' => '03.21.26 Frosty Farmer',
        'display_name' => '03.21.26 Frosty Farmer',
        'starts_at' => '2026-03-21',
        'ends_at' => '2026-03-21',
        'state' => 'SC',
        'status' => 'needs_mapping',
    ]);

    EventInstance::query()->create([
        'title' => 'Frosty Weekend, SC',
        'starts_at' => '2025-03-15',
        'ends_at' => '2025-03-16',
        'state' => 'SC',
        'status' => 'completed',
    ]);

    $coordinator = \Mockery::mock(MarketEventSyncCoordinator::class);
    $coordinator->shouldReceive('matchingCacheVersion')->once()->andReturn(1);
    $this->app->instance(MarketEventSyncCoordinator::class, $coordinator);

    Livewire::test(CandidateMatchList::class, [
        'upcomingEventId' => $upcoming->id,
        'matchWindowDays' => 45,
    ])
        ->call('handleRunCandidateMatch', $upcoming->id, 45)
        ->assertSeeText('Frosty Weekend, SC');
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

    $candidate = EventInstance::query()->create([
        'title' => 'TR Winter Pop Up, SC',
        'starts_at' => '2025-02-08',
        'ends_at' => '2025-02-08',
        'state' => 'SC',
        'status' => 'completed',
    ]);

    foreach ([
        ['scent_raw' => (string) $scents[0]->display_name, 'box_count_sent' => 1, 'is_split_box' => false],
        ['scent_raw' => (string) $scents[1]->display_name, 'box_count_sent' => 1, 'is_split_box' => false],
        ['scent_raw' => (string) $scents[2]->display_name, 'box_count_sent' => 0.5, 'is_split_box' => false],
    ] as $row) {
        EventBoxPlan::query()->create(array_merge($row, [
            'event_instance_id' => $candidate->id,
        ]));
    }

    $component = Livewire::test(MarketsPlanner::class, ['planId' => $plan->id])
        ->call('handleMarketsMappingConfirmed', $upcoming->id, $candidate->id);

    $component
        ->assertDispatched('marketsDraftUpdated')
        ->assertDispatched('marketsPrefillStatusChanged');

    $items = RetailPlanItem::query()
        ->where('retail_plan_id', $plan->id)
        ->where('upcoming_event_id', $upcoming->id)
        ->where('source', 'event_prefill')
        ->get();

    expect($items)->toHaveCount(3);
    expect((int) $items->sum('quantity'))->toBe(5);
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

    $candidate = EventInstance::query()->create([
        'title' => 'TR Winter Pop Up, SC',
        'starts_at' => '2025-02-08',
        'ends_at' => '2025-02-08',
        'state' => 'SC',
        'status' => 'completed',
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

test('selecting a historical match still copies templates when event_mappings is unavailable', function () {
    $plan = RetailPlan::query()->create([
        'name' => 'Event Instance Templates Ignore Mappings Table',
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

    $candidate = EventInstance::query()->create([
        'title' => 'TR Winter Pop Up, SC',
        'starts_at' => '2025-02-08',
        'ends_at' => '2025-02-08',
        'state' => 'SC',
        'status' => 'completed',
    ]);

    EventBoxPlan::query()->create([
        'event_instance_id' => $candidate->id,
        'scent_raw' => 'TR Winter Pop Up',
        'box_count_sent' => 1,
        'is_split_box' => false,
    ]);

    if (Schema::hasTable('event_mappings')) {
        Schema::drop('event_mappings');
    }

    $component = Livewire::test(MarketsPlanner::class, ['planId' => $plan->id])
        ->call('handleMarketsMappingConfirmed', $upcoming->id, $candidate->id);

    $component
        ->assertDispatched('marketsPrefillStatusChanged')
        ->assertDispatched('marketsDraftUpdated');

    expect(Schema::hasTable('event_mappings'))->toBeFalse();
    expect(RetailPlanItem::query()->count())->toBe(1);
});

test('duration starter template creates a draft from fixed 1 to 3 day averages', function () {
    Cache::flush();

    $plan = RetailPlan::query()->create([
        'name' => 'Duration Starter Template Test',
        'status' => 'draft',
        'queue_type' => 'markets',
    ]);

    $upcoming = Event::query()->create([
        'name' => '04.11.26 Test Weekend Market',
        'display_name' => '04.11.26 Test Weekend Market',
        'starts_at' => '2026-04-11',
        'ends_at' => '2026-04-12',
        'status' => 'needs_mapping',
    ]);

    $starterPoolScents = [
        'Blue Ridge',
        'Moss Trail',
        'Cedar Smoke',
        'Room Refresh',
        'Golden Hour',
        'Pine Hollow',
        'Sunwashed Linen',
        'Blackberry Ember',
        'Salt Air',
        'Foggy Harbor',
        'Juniper Trail',
        'Sweet Tea',
        'White Oak',
        'Saddle Leather',
        'Garden Mint',
    ];

    foreach ($starterPoolScents as $scentName) {
        Scent::query()->firstOrCreate(['name' => $scentName], [
            'display_name' => $scentName,
            'is_active' => true,
        ]);
    }

    $historical = EventInstance::query()->create([
        'title' => 'Test Weekend Market, SC',
        'starts_at' => '2025-04-12',
        'ends_at' => '2025-04-13',
        'state' => 'SC',
        'status' => 'completed',
    ]);

    foreach ($starterPoolScents as $index => $scentName) {
        EventBoxPlan::query()->create([
            'event_instance_id' => $historical->id,
            'scent_raw' => $scentName,
            'box_count_sent' => ($index % 4) + 1,
        ]);
    }

    EventBoxPlan::query()->create([
        'event_instance_id' => $historical->id,
        'scent_raw' => 'Top Shelf',
        'box_count_sent' => 5,
    ]);

    $template = app(MarketDurationTemplateService::class)->templateForDays(2);

    expect($template['lines'] ?? [])->toHaveCount(15);
    expect(collect($template['lines'] ?? [])->pluck('scent_raw')->contains(
        fn ($value): bool => strtolower(trim((string) $value)) === 'top shelf'
    ))->toBeFalse();

    Livewire::test(UpcomingEventsPanel::class, [
        'planId' => $plan->id,
        'selectedEventId' => $upcoming->id,
        'stateTab' => 'needs_mapping',
        'lookaheadDays' => 30,
    ])
        ->assertSeeText('Quick Start Templates')
        ->assertSeeText('Use 2-Day Starter');

    Livewire::test(MarketsPlanner::class, ['planId' => $plan->id])
        ->call('handleMarketsDurationTemplateRequested', $upcoming->id, 2)
        ->assertDispatched('marketsDraftCreated')
        ->assertDispatched('marketsPrefillStatusChanged');

    $items = RetailPlanItem::query()
        ->where('retail_plan_id', $plan->id)
        ->where('upcoming_event_id', $upcoming->id)
        ->where('source', 'market_duration_template')
        ->get();

    expect($items->isNotEmpty())->toBeTrue();
    expect($items->pluck('source')->unique()->all())->toBe(['market_duration_template']);
    expect((string) $upcoming->fresh()->status)->toBe('drafted');

    Livewire::test(UpcomingEventsPanel::class, [
        'planId' => $plan->id,
        'selectedEventId' => $upcoming->id,
        'stateTab' => 'drafted',
        'lookaheadDays' => 30,
    ])
        ->call('handleMarketsDraftCreated', $upcoming->id, 2)
        ->assertSeeText('Draft created from 2-day starter template.')
        ->assertSeeText('Continue');

    Livewire::test(EventMatchWizard::class, [
        'planId' => $plan->id,
        'upcomingEventId' => $upcoming->id,
    ])
        ->call('handleDraftCreated', $upcoming->id, 2, $items->count())
        ->call('handleOpenDraftRequested', $upcoming->id)
        ->assertSet('step', 3)
        ->assertSet('draftSummary.line_count', $items->count());

    Livewire::test(DraftEventEditor::class, [
        'planId' => $plan->id,
        'selectedEventId' => $upcoming->id,
    ])
        ->assertSeeText('Blue Ridge');
});

test('draft event editor saves inline edits for draft boxes', function () {
    $plan = RetailPlan::query()->create([
        'name' => 'Draft Editor Inline Edit Test',
        'status' => 'draft',
        'queue_type' => 'markets',
    ]);

    $event = Event::query()->create([
        'name' => '03.14.26 TR Winter Pop Up',
        'display_name' => '03.14.26 TR Winter Pop Up',
        'starts_at' => '2026-03-14',
        'ends_at' => '2026-03-14',
        'status' => 'drafted',
    ]);

    $originalScent = Scent::query()->create([
        'name' => 'Blue Ridge',
        'display_name' => 'Blue Ridge',
        'is_active' => true,
    ]);

    $updatedScent = Scent::query()->create([
        'name' => 'Moss Trail',
        'display_name' => 'Moss Trail',
        'is_active' => true,
    ]);

    $size = Size::query()->firstOrCreate(
        ['code' => '16oz-cotton'],
        [
            'label' => '16 oz Cotton',
            'is_active' => true,
            'sort_order' => 1,
        ]
    );

    $item = RetailPlanItem::query()->create([
        'retail_plan_id' => $plan->id,
        'scent_id' => $originalScent->id,
        'size_id' => null,
        'quantity' => 2,
        'source' => 'event_prefill',
        'status' => 'draft',
        'upcoming_event_id' => $event->id,
        'box_tier' => 'standard',
        'notes' => null,
    ]);

    Livewire::test(DraftEventEditor::class, [
        'planId' => $plan->id,
        'selectedEventId' => $event->id,
    ])
        ->set("draftRows.{$item->id}.quantity", 5)
        ->set("draftRows.{$item->id}.size_id", $size->id)
        ->set("draftRows.{$item->id}.box_tier", 'top_shelf')
        ->set("draftRows.{$item->id}.scent_id", $updatedScent->id)
        ->set("draftRows.{$item->id}.top_shelf.preset", 'split_6_6')
        ->set("draftRows.{$item->id}.top_shelf.size_mode", '8oz')
        ->set("draftRows.{$item->id}.top_shelf.slots.0", $updatedScent->id)
        ->set("draftRows.{$item->id}.top_shelf.slots.1", $originalScent->id)
        ->call('saveItem', $item->id)
        ->assertSeeText('Saved.');

    $item->refresh();
    $topShelfConfiguration = RetailPlanItem::decodeTopShelfConfiguration($item->notes, $item->scent_id);

    expect((int) $item->quantity)->toBe(5);
    expect((int) $item->scent_id)->toBe($updatedScent->id);
    expect((int) $item->size_id)->toBe($size->id);
    expect((string) $item->box_tier)->toBe('top_shelf');
    expect((string) ($topShelfConfiguration['preset'] ?? ''))->toBe('split_6_6');
    expect((string) ($topShelfConfiguration['size_mode'] ?? ''))->toBe('8oz');
    expect((array) ($topShelfConfiguration['slots'] ?? []))->toBe([$updatedScent->id, $originalScent->id]);

    Livewire::test(DraftEventEditor::class, [
        'planId' => $plan->id,
        'selectedEventId' => $event->id,
    ])
        ->assertSet("draftRows.{$item->id}.quantity", 5)
        ->assertSet("draftRows.{$item->id}.scent_id", $updatedScent->id)
        ->assertSet("draftRows.{$item->id}.size_id", $size->id)
        ->assertSet("draftRows.{$item->id}.box_tier", 'top_shelf')
        ->assertSet("draftRows.{$item->id}.top_shelf.preset", 'split_6_6')
        ->assertSet("draftRows.{$item->id}.top_shelf.size_mode", '8oz');
});

test('draft event editor reloads db rows when mounted state is empty', function () {
    $plan = RetailPlan::query()->create([
        'name' => 'Draft Editor Reload Test',
        'status' => 'draft',
        'queue_type' => 'markets',
    ]);

    $event = Event::query()->create([
        'name' => '03.27.26 - 03.29.26 Flowertown Festival, SC',
        'display_name' => '03.27.26 - 03.29.26 Flowertown Festival, SC',
        'starts_at' => '2026-03-27',
        'ends_at' => '2026-03-29',
        'status' => 'drafted',
    ]);

    $scent = Scent::query()->firstOrCreate(
        ['name' => 'Garden Mint'],
        [
            'display_name' => 'Garden Mint',
            'is_active' => true,
        ]
    );

    $item = RetailPlanItem::query()->create([
        'retail_plan_id' => $plan->id,
        'scent_id' => $scent->id,
        'size_id' => null,
        'quantity' => 2,
        'source' => 'event_prefill',
        'status' => 'draft',
        'upcoming_event_id' => $event->id,
        'box_tier' => 'standard',
        'notes' => null,
    ]);

    Livewire::test(DraftEventEditor::class, [
        'planId' => $plan->id,
        'selectedEventId' => $event->id,
    ])
        ->assertSeeText('Garden Mint')
        ->set('draftRows', [])
        ->assertSeeText('Garden Mint')
        ->assertSet("draftRows.{$item->id}.quantity", 2);
});
