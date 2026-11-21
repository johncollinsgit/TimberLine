<?php

use App\Jobs\Team\RefreshEventPerformance;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerEventValueService;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\Team\Studio\EventCommerceReport;
use App\Services\Team\Studio\EventPerformance;
use App\Services\Team\Studio\EventSalesHistory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Queue::fake();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'pos_live.runtime_id' => 'performance_fixture']);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->summaries = [];
    $history = Mockery::mock(EventSalesHistory::class);
    $history->shouldReceive('summarizeOccurrence')->andReturnUsing(fn ($tenant, $user, $event) => $this->summaries[$event->id]);
    app()->instance(EventSalesHistory::class, $history);
    $commerce = Mockery::mock(EventCommerceReport::class);
    $commerce->shouldReceive('commerceId')->andReturn(null)->byDefault();
    $commerce->shouldReceive('commerceIds')->andReturnUsing(fn ($events) => collect($events)->mapWithKeys(fn ($event) => [$event->id => $commerce->commerceId($event)])->all());
    app()->instance(EventCommerceReport::class, $commerce);
    $this->url = '/team/event-performance?tenant='.$this->tenant->slug.'&area='.$this->area->id.'&from=2025-11-01&to=2025-11-30';
});

function performanceEvent($test, string $title = 'Fictional fair', string $start = '2025-11-07', array $changes = []): TeamOperationRecord
{
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $test->tenant->id, 'area_id' => $test->area->id,
        'kind' => 'event', 'title' => $title, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'starts_on' => $start, 'ends_on' => Carbon\CarbonImmutable::parse($start)->addDays(2)->toDateString(), 'data' => []]);
    $test->summaries[$event->id] = array_replace(['event_id' => $event->id, 'title' => $title, 'starts_on' => $start,
        'ends_on' => $event->ends_on->toDateString(), 'timezone' => 'America/New_York', 'net_sales_minor' => 6000, 'known_net_sales_minor' => 6000,
        'reported_net_sales_minor' => 6100, 'selling_days' => 3, 'sale_count' => 6, 'complete' => true, 'source_coverage_complete' => true, 'partial' => false,
        'staff' => [['id' => 'former-person', 'name' => 'Kari Lara']],
        'daily' => array_map(fn ($n) => ['date' => Carbon\CarbonImmutable::parse($start)->addDays($n)->toDateString(), 'net_sales_minor' => ($n + 1) * 1000, 'sale_count' => $n + 1, 'complete' => true], [0, 1, 2])], $changes);

    return $event;
}

function performanceCustomerOriginal($test, TeamOperationRecord $event, MarketingProfile $profile, string $id, int $amount = 1070, int $refund = 0, int $commerce = 123): MarketingProfileLink
{
    app()->forgetInstance(EventCommerceReport::class);
    app()->forgetInstance(EventCustomerCohort::class);
    app()->forgetInstance(CustomerEventValueService::class);
    $stock = app(\App\Services\Inventory\StockLedger::class);
    if (! $stock->rows('event_links', $test->tenant->id)->where('event_id', $commerce)->exists()) {
        $stock->rows('event_links', $test->tenant->id)->insert(['tenant_id' => $test->tenant->id, 'event_id' => $commerce,
            'provider' => 'manual', 'external_id' => 'performance-customer-'.$commerce, 'reviewed_by' => $test->owner->id,
            'metadata' => json_encode(['name' => $event->title, 'timezone' => 'America/New_York',
                'start_on' => $event->starts_on->toDateString(), 'end_on' => $event->ends_on->toDateString()]), 'created_at' => now(), 'updated_at' => now()]);
    }
    $binding = ['environment' => 'production', 'merchant_id' => 'performance-merchant', 'location_ids' => ['performance-location'],
        'connection_id' => 'performance-reader', 'runtime_id' => 'performance_fixture', 'connection_generation' => 1, 'source_purpose' => 'customer_history'];
    SquareOrder::create(['tenant_id' => $test->tenant->id, 'square_order_id' => $id, 'location_id' => 'performance-location',
        'state' => 'COMPLETED', 'closed_at' => '2025-11-07T15:00:00Z', 'total_money_amount' => $amount, 'total_money_currency' => 'USD',
        'raw_payload' => ['id' => $id, 'state' => 'COMPLETED', 'location_id' => 'performance-location', '_source_binding' => $binding,
            'total_money' => ['amount' => $amount, 'currency' => 'USD'], 'total_tax_money' => ['amount' => 0, 'currency' => 'USD'],
            'total_tip_money' => ['amount' => 0, 'currency' => 'USD'], 'net_amounts' => ['total_money' => ['amount' => $amount - $refund, 'currency' => 'USD'],
                'tax_money' => ['amount' => 0, 'currency' => 'USD'], 'tip_money' => ['amount' => 0, 'currency' => 'USD']]]]);
    SquarePayment::create(['tenant_id' => $test->tenant->id, 'square_payment_id' => 'payment-'.$id, 'square_order_id' => $id,
        'location_id' => 'performance-location', 'status' => 'COMPLETED', 'amount_money' => $amount, 'currency' => 'USD',
        'source_type' => 'CARD', 'created_at_source' => '2025-11-07T15:00:00Z', 'raw_payload' => ['id' => 'payment-'.$id, 'order_id' => $id,
            'location_id' => 'performance-location', 'status' => 'COMPLETED', '_source_binding' => $binding,
            'amount_money' => ['amount' => $amount, 'currency' => 'USD'], 'total_money' => ['amount' => $amount, 'currency' => 'USD'],
            'refunded_money' => ['amount' => $refund, 'currency' => 'USD'], 'card_details' => ['entry_method' => 'CONTACTLESS']]]);
    $link = MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $profile->id,
        'source_type' => 'square_order', 'source_id' => $id, 'source_meta' => []]);
    $data = $event->data;
    $data['_teams_enrichment']['commerce_event'] = ['id' => $commerce, 'reviewed_by' => (string) $test->owner->id, 'reviewed_at' => now()->toIso8601String()];
    $data['_teams_enrichment']['square_orders'] = ['ids' => [...($data['_teams_enrichment']['square_orders']['ids'] ?? []), $id],
        'reviewed_by' => $test->owner->id, 'reviewed_at' => now()->toIso8601String(), 'source_binding' => $binding];
    $event->update(['data' => $data, 'version' => $event->version + 1]);

    return $link;
}

test('preparation queues an asynchronous projection without building history on the request', function () {
    performanceEvent($this);
    $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertJsonPath('status', 'preparing')->assertJsonPath('summary.net_sales_minor', null)->assertJsonCount(0, 'rows');
    Queue::assertPushed(RefreshEventPerformance::class, fn ($job) => $job->connection === 'event_performance' && $job->queue === 'event-performance');
    expect(config('queue.connections.event_performance.retry_after'))->toBeGreaterThan(600);
});

test('whole-event totals stay the same on every page and sensitive labels stay encrypted at rest', function () {
    foreach (range(1, 53) as $number) {
        performanceEvent($this, 'Fictional fair '.$number);
    }
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $page = $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertJsonPath('status', 'ready')->assertJsonCount(50, 'rows')->assertJsonPath('total_rows', 53)->json();
    $next = $this->getJson($this->url.'&page=2')->assertOk()->assertJsonCount(3, 'rows')->json();
    expect($page['summary'])->toBe($next['summary'])->and($page['summary']['net_sales_minor'])->toBe(318000)
        ->and($page['summary']['sale_count'])->toBe(318)->and($page['summary']['average_day_net_sales_minor'])->toBe(2000);
    expect(DB::table(EventPerformance::TABLE)->first()->payload)->not->toContain('Kari')->not->toContain('Fictional');
    expect(TeamOperationRecord::where('kind', 'performance_snapshot')->first()->getRawOriginal('data'))->not->toContain('generation');
});

test('weekends use actual local selling dates and adjustable boundaries rather than repeated full-event totals', function () {
    performanceEvent($this, 'Long fair', '2025-11-06'); // Thursday through Saturday.
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $response = $this->actingAs($this->owner)->getJson($this->url.'&group_by=weekend')->assertOk()->json();
    $friday = $response['rows'];
    expect($response['summary']['net_sales_minor'])->toBe(5000);
    expect($friday)->toHaveCount(1)->and($friday[0]['id'])->toBe('2025-11-07')->and($friday[0]['net_sales_minor'])->toBe(5000)->and($friday[0]['selling_days'])->toBe(2);
    $thursday = $this->getJson($this->url.'&group_by=weekend&weekend_start=4&weekend_days=4')->assertOk()->json('rows');
    expect($thursday[0]['net_sales_minor'])->toBe(6000)->and($thursday[0]['id'])->toBe('2025-11-06');
});

test('known weekend money remains partial until complete original source coverage is proven', function () {
    performanceEvent($this, changes: ['source_coverage_complete' => false, 'complete' => false, 'net_sales_minor' => null]);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $response = $this->actingAs($this->owner)->getJson($this->url.'&group_by=weekend')->assertOk()->json();
    expect($response['rows'][0]['complete'])->toBeFalse()->and($response['rows'][0]['net_sales_minor'])->toBeNull()
        ->and($response['rows'][0]['known_net_sales_minor'])->toBe(6000)->and($response['summary']['net_sales_minor'])->toBeNull();
});

test('unchanged scheduled checks retain the same generation without walking historical sources again', function () {
    performanceEvent($this);
    $reports = app(EventPerformance::class);
    $first = $reports->rebuild($this->tenant, $this->owner, $this->area);
    $count = DB::table(EventPerformance::TABLE)->count();
    $next = $reports->rebuild($this->tenant, $this->owner, $this->area);
    expect($next['version'])->toBe($first['version'])->and($next['unchanged'])->toBeTrue()->and(DB::table(EventPerformance::TABLE)->count())->toBe($count);
});

test('a source failure after the first flushed batch removes only its unpublished generation', function () {
    performanceEvent($this, 'Last good fair');
    $reports = app(EventPerformance::class);
    $good = $reports->rebuild($this->tenant, $this->owner, $this->area);
    $goodCount = DB::table(EventPerformance::TABLE)->count();
    foreach (range(1, 201) as $number) {
        performanceEvent($this, 'Next fair '.$number);
    }
    $calls = 0;
    $history = Mockery::mock(EventSalesHistory::class);
    $history->shouldReceive('summarizeOccurrence')->andReturnUsing(function ($tenant, $user, $event) use (&$calls, $good) {
        if (++$calls === 201) {
            expect(DB::table(EventPerformance::TABLE)->where('generation', '!=', $good['version'])->count())->toBeGreaterThanOrEqual(1000);
            throw new RuntimeException('Fictional interrupted source read.');
        }

        return $this->summaries[$event->id];
    });
    app()->instance(EventSalesHistory::class, $history);
    expect(fn () => $reports->rebuild($this->tenant, $this->owner, $this->area))->toThrow(RuntimeException::class, 'Fictional interrupted source read.');
    expect(DB::table(EventPerformance::TABLE)->count())->toBe($goodCount)
        ->and(DB::table(EventPerformance::TABLE)->distinct()->pluck('generation')->all())->toBe([$good['version']])
        ->and(TeamOperationRecord::where('kind', 'performance_snapshot')->first()->data['generation'])->toBe($good['version']);
});

test('staff comparisons retain former names and clearly separate event context from direct individual credit', function () {
    $event = performanceEvent($this);
    performanceEvent($this, 'Other fair', '2025-11-14', ['staff' => [['id' => 'another-person', 'name' => 'John Collins']]]);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $response = $this->actingAs($this->owner)->getJson($this->url.'&group_by=team_member&q=Kari')->assertOk()->json();
    expect($response['rows'])->toHaveCount(1)->and($response['rows'][0]['label'])->toBe('Kari Lara')
        ->and($response['rows'][0]['direct_net_sales_minor'])->toBeNull()->and($response['summary']['net_sales_minor'])->toBe(6000);
    $drill = $this->getJson($this->url.'&team_member_id=former-person')->assertOk()->json('rows');
    expect($drill)->toHaveCount(1)->and($drill[0]['event_ids'])->toBe([$event->id]);
});

test('direct staff sales use verified original operators without duplicating shared event revenue', function () {
    performanceEvent($this, changes: ['staff' => [['id' => 'user:123', 'name' => 'Recorded Person']],
        'operators' => [['user_id' => '123', 'name' => 'Recorded Person', 'sale_count' => 2, 'net_sales_minor' => 1700, 'known_net_sales_minor' => 1700, 'complete' => true]]]);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $response = $this->actingAs($this->owner)->getJson($this->url.'&group_by=team_member')->assertOk()->json();
    expect($response['rows'])->toHaveCount(1)->and($response['rows'][0]['net_sales_minor'])->toBe(6000)
        ->and($response['rows'][0]['direct_net_sales_minor'])->toBe(1700)->and($response['rows'][0]['direct_sale_count'])->toBe(2)
        ->and($response['summary']['net_sales_minor'])->toBe(6000);
    $tokens = json_decode(DB::table(EventPerformance::TABLE)->where('kind', 'team_member')->value('search_tokens'), true);
    expect($tokens)->not->toContain(hash('sha256', 'recorded'))
        ->toContain(hash_hmac('sha256', $this->tenant->id.'|recorded', config('app.key')));
});

test('reports never expose another tenant or change access through a saved generation', function () {
    performanceEvent($this);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $member = teamMember($this->tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $this->actingAs($member)->getJson($this->url)->assertForbidden();
    [$foreign, $foreignOwner] = teamWorkspace();
    $this->actingAs($foreignOwner)->getJson('/team/event-performance?tenant='.$foreign->slug.'&area='.$this->area->id)->assertNotFound();
    $this->tenant->users()->updateExistingPivot($this->owner->id, ['membership_active' => false]);
    $this->actingAs($this->owner)->getJson($this->url)->assertForbidden();
});

test('source changes retain a labelled stale generation and refresh atomically', function () {
    $event = performanceEvent($this);
    $first = app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $event->update(['version' => 2]);
    $stale = $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertJsonPath('status', 'stale')->json();
    expect($stale['version'])->toBe($first['version']);
    $this->summaries[$event->id]['net_sales_minor'] = 7000;
    $this->summaries[$event->id]['known_net_sales_minor'] = 7000;
    $second = app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    expect($second['version'])->not->toBe($first['version']);
    $this->getJson($this->url)->assertOk()->assertJsonPath('version', $second['version'])->assertJsonPath('summary.net_sales_minor', 7000);
    $event->update(['status' => 'withdrawn']);
    $this->getJson($this->url)->assertConflict();
});

test('customer monetary and count summaries cover the whole canonical cohort independently of the displayed page', function () {
    $event = performanceEvent($this);
    $profiles = collect(range(1, 251))->map(fn ($n) => MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Buyer '.$n]));
    foreach ($profiles as $profile) {
        performanceCustomerOriginal($this, $event, $profile, 'original-'.$profile->id);
    }
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $first = $this->actingAs($this->owner)->getJson($this->url.'&group_by=customer')->assertOk()->assertJsonCount(50, 'rows')->assertJsonPath('total_rows', 251)->json();
    $last = $this->getJson($this->url.'&group_by=customer&page=6')->assertOk()->assertJsonCount(1, 'rows')->json();
    expect($first['summary'])->toBe($last['summary'])->and($first['summary']['customer_count'])->toBe(251)
        ->and($first['summary']['customer_purchase_count'])->toBe(251)->and($first['summary']['paid_total_minor'])->toBe(268570)
        ->and($first['rows'][0]['paid_total_minor'])->toBe(1070)->and($first['rows'][0]['net_sales_minor'])->toBeNull();
    $profiles[0]->update(['archived_at' => now()->addSecond()]);
    $this->getJson($this->url.'&group_by=customer')->assertOk()->assertJsonPath('status', 'preparing')->assertJsonCount(0, 'rows')->assertJsonPath('summary.paid_total_minor', null);
});

test('highest customer spending sorts whole customer totals after refunds while net sales remain unknown', function () {
    $event = performanceEvent($this);
    $low = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Mostly refunded']);
    $high = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Highest spender']);
    $middle = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Two purchases']);
    $amounts = [$low->id => [[10000, 9000]], $high->id => [[7000, 0]], $middle->id => [[3000, 0], [2000, 0]]];
    foreach ([$low, $high, $middle] as $profile) {
        foreach ($amounts[$profile->id] as $index => [$amount, $refund]) {
            performanceCustomerOriginal($this, $event, $profile, 'sorting-'.$profile->id.'-'.$index, $amount, $refund);
        }
    }
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $rows = $this->actingAs($this->owner)->getJson($this->url.'&group_by=customer&sort=net_sales')->assertOk()->json('rows');
    expect(array_column($rows, 'id'))->toBe(array_map('strval', [$high->id, $middle->id, $low->id]))
        ->and(array_column($rows, 'paid_total_minor'))->toBe([7000, 5000, 1000])
        ->and(array_column($rows, 'net_sales_minor'))->toBe([null, null, null]);
});

test('concurrent identity imports withhold customer facts without blocking unchanged event sales', function () {
    performanceEvent($this);
    performanceEvent($this, 'Another event sharing the customer');
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Before review']);
    $commerce = Mockery::mock(EventCommerceReport::class);
    $commerce->shouldReceive('commerceId')->andReturn(123);
    $commerce->shouldReceive('commerceIds')->andReturnUsing(fn ($events) => collect($events)->mapWithKeys(fn ($event) => [$event->id => $commerce->commerceId($event)])->all());
    app()->instance(EventCommerceReport::class, $commerce);
    $cohort = Mockery::mock(EventCustomerCohort::class);
    $cohort->shouldReceive('read')->andReturn(['profile_ids' => [$profile->id], 'relations' => [], 'review_items' => []]);
    app()->instance(EventCustomerCohort::class, $cohort);
    $values = Mockery::mock(CustomerEventValueService::class);
    $values->shouldReceive('forProfile')->andReturnUsing(function ($readProfile) {
        $readProfile->update(['archived_at' => now()]);

        return ['purchase_journey' => [['purchase_id' => 'original:'.$readProfile->id, 'confirmed_purchase' => true,
            'purchase_event' => ['event' => ['id' => 123]], 'currency' => 'usd', 'amount_minor' => 1070,
            'refunded_minor' => 0, 'occurred_at' => '2025-11-07T15:00:00Z']]];
    });
    app()->instance(CustomerEventValueService::class, $values);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    expect(DB::table(EventPerformance::TABLE)->where('kind', 'customer')->count())->toBe(0);
    $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertJsonPath('summary.net_sales_minor', 12000)
        ->assertJsonPath('summary.customer_count', null)->assertDontSee('Before review');
    $this->getJson($this->url.'&group_by=customer')->assertOk()->assertJsonPath('status', 'preparing')->assertJsonCount(0, 'rows');
});

test('an unrelated customer import during original fact assembly preserves the private event projection', function () {
    $event = performanceEvent($this);
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Actual event buyer']);
    performanceCustomerOriginal($this, $event, $profile, 'unchanged-event-original');
    $value = app(CustomerEventValueService::class)->forProfile($profile, $this->tenant->id, true);
    expect($value['purchase_journey'])->toHaveCount(1);
    $calls = 0;
    $values = Mockery::mock(CustomerEventValueService::class);
    $values->shouldReceive('forProfile')->andReturnUsing(function ($readProfile) use ($profile, $value, &$calls) {
        expect($readProfile->id)->toBe($profile->id);
        $unrelated = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Concurrent unrelated import']);
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $unrelated->id,
            'source_type' => 'square_customer', 'source_id' => 'unrelated-during-original-read-'.($calls + 1), 'source_meta' => ['retained' => ['provider_import' => true]]]);
        $calls++;

        return $value;
    });
    app()->instance(CustomerEventValueService::class, $values);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $snapshot = TeamOperationRecord::where('kind', 'performance_snapshot')->firstOrFail();
    expect($calls)->toBeGreaterThanOrEqual(1)->and($snapshot->data['customer_access'])->toBeTrue()
        ->and($snapshot->data['customer_scope'])->toBeArray()
        ->and($snapshot->getRawOriginal('data'))->not->toContain('customer_scope')->not->toContain('Actual event buyer')->not->toContain('unchanged-event-original')
        ->and(DB::table(EventPerformance::TABLE)->where('kind', 'customer')->count())->toBe(1);
    $this->actingAs($this->owner)->getJson($this->url.'&group_by=customer')->assertOk()->assertJsonPath('status', 'ready')
        ->assertJsonPath('summary.net_sales_minor', 6000)->assertJsonPath('summary.customer_count', 1)
        ->assertJsonPath('summary.customer_purchase_count', 1)->assertJsonPath('summary.paid_total_minor', 1070)
        ->assertJsonPath('rows.0.label', 'Actual event buyer');
});

test('an untrusted cached customer journey cannot publish mismatched original facts', function (string $change) {
    $event = performanceEvent($this);
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'First original buyer']);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Second original buyer']);
    performanceCustomerOriginal($this, $event, $profile, 'first-authoritative-original');
    performanceCustomerOriginal($this, $event, $other, 'second-authoritative-original');
    $valuesService = app(CustomerEventValueService::class);
    $value = $valuesService->forProfile($change === 'customer' ? $other : $profile, $this->tenant->id, true);
    expect($value['purchase_journey'])->toHaveCount(1);
    if ($change === 'source') {
        $value['purchase_journey'][0]['purchase_id'] = 'square_order:synthetic-unowned-original';
        $value['purchase_journey'][0]['source_id'] = 'synthetic-unowned-original';
    } elseif ($change === 'money') {
        $value['purchase_journey'][0]['amount_minor'] += 1000;
    }
    $cohortValue = app(EventCustomerCohort::class)->read($this->tenant->id, 123, $event);
    expect($cohortValue['profile_ids'])->toContain($profile->id, $other->id);
    $cohortValue['profile_ids'] = [$profile->id];
    $cohort = Mockery::mock(EventCustomerCohort::class);
    $cohort->shouldReceive('read')->andReturn($cohortValue);
    app()->instance(EventCustomerCohort::class, $cohort);
    $values = Mockery::mock(CustomerEventValueService::class);
    $values->shouldReceive('forProfile')->once()->withArgs(fn ($readProfile) => $readProfile->id === $profile->id)->andReturn($value);
    app()->instance(CustomerEventValueService::class, $values);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $snapshot = TeamOperationRecord::where('kind', 'performance_snapshot')->firstOrFail();
    expect($snapshot->data['customer_access'])->toBeFalse()
        ->and(DB::table(EventPerformance::TABLE)->where('kind', 'customer')->count())->toBe(0)
        ->and(DB::table(EventPerformance::TABLE)->where('kind', 'event')->count())->toBe(1);
    $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertJsonPath('summary.net_sales_minor', 6000)
        ->assertJsonPath('summary.customer_count', null)->assertJsonPath('summary.paid_total_minor', null)
        ->assertDontSee('First original buyer')->assertDontSee('Second original buyer');
    $this->getJson($this->url.'&group_by=customer')->assertOk()->assertJsonPath('status', 'preparing')->assertJsonCount(0, 'rows');
})->with(['source', 'customer', 'money']);

test('a same-id intermediate emitted customer label cannot reuse a matching private frame', function () {
    $event = performanceEvent($this);
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Witnessed original name']);
    performanceCustomerOriginal($this, $event, $profile, 'label-authoritative-original');
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $intermediate = $profile->replicate();
    $intermediate->id = $profile->id;
    $intermediate->first_name = 'Intermediate emitted name';
    $resolver = Mockery::mock(CanonicalMarketingProfileResolver::class)->makePartial();
    $resolver->shouldReceive('byId')->andReturnUsing(fn ($tenant, $id) => (int) $id === $profile->id ? $intermediate : null);
    app()->instance(CanonicalMarketingProfileResolver::class, $resolver);
    expect($profile->fresh()->first_name)->toBe('Witnessed original name');
    $this->actingAs($this->owner)->getJson($this->url.'&group_by=customer')->assertConflict()
        ->assertDontSee('Witnessed original name')->assertDontSee('Intermediate emitted name');
});

test('an unavailable canonical cohort stays preparing rather than presenting an empty customer total', function () {
    performanceEvent($this);
    $commerce = Mockery::mock(EventCommerceReport::class);
    $commerce->shouldReceive('commerceId')->andReturn(123);
    $commerce->shouldReceive('commerceIds')->andReturnUsing(fn ($events) => collect($events)->mapWithKeys(fn ($event) => [$event->id => $commerce->commerceId($event)])->all());
    app()->instance(EventCommerceReport::class, $commerce);
    $cohort = Mockery::mock(EventCustomerCohort::class);
    $cohort->shouldReceive('read')->andReturn(['profile_ids' => [999999], 'relations' => [], 'review_items' => []]);
    app()->instance(EventCustomerCohort::class, $cohort);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $this->actingAs($this->owner)->getJson($this->url)->assertOk()->assertJsonPath('summary.net_sales_minor', 6000)->assertJsonPath('summary.customer_count', null);
    $this->getJson($this->url.'&group_by=customer')->assertOk()->assertJsonPath('status', 'preparing')->assertJsonCount(0, 'rows');
});

test('warm indexed filtering remains bounded with twice a large event workload', function () {
    foreach (range(1, 400) as $number) {
        performanceEvent($this, 'Fictional fair '.$number);
    }
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $times = [];
    foreach (range(1, 6) as $run) {
        $started = microtime(true);
        $this->actingAs($this->owner)->getJson($this->url.'&group_by=event&page=2')->assertOk()->assertJsonPath('summary.event_count', 400);
        $times[] = (microtime(true) - $started) * 1000;
    }
    sort($times);
    if (getenv('EVENT_PERFORMANCE_BENCHMARK') === '1') {
        fwrite(STDERR, json_encode(['event_count' => 400, 'fact_count' => DB::table(EventPerformance::TABLE)->count(), 'samples_ms' => array_map(fn ($value) => round($value, 2), $times), 'p95_ms' => round($times[5], 2)], JSON_THROW_ON_ERROR).PHP_EOL);
    }
    expect($times[5])->toBeLessThan(500);
});

test('same-second original customer-link reassignment invalidates private projection dimensions', function () {
    $first = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Original']);
    $second = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Replacement']);
    $link = MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $first->id, 'source_type' => 'square_order', 'source_id' => 'original-sale', 'source_meta' => []]);
    $reports = app(EventPerformance::class);
    $signature = $reports->customerSignature($this->tenant);
    DB::table('marketing_profile_links')->where('id', $link->id)->update(['marketing_profile_id' => $second->id]);
    expect($reports->customerSignature($this->tenant))->not->toBe($signature);
    $signature = $reports->customerSignature($this->tenant);
    DB::table('marketing_profile_links')->where('id', $link->id)->update(['source_meta' => json_encode(['event_attribution' => ['event_id' => 999]])]);
    expect($reports->customerSignature($this->tenant))->not->toBe($signature);
});

test('same-second original payment revisions mark financial snapshots stale', function () {
    $event = performanceEvent($this);
    $event->update(['data' => ['_teams_enrichment' => ['square_orders' => ['ids' => ['original-order']]]]]);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'original-order', 'raw_payload' => []]);
    $payment = SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'fictional-original', 'square_order_id' => 'original-order', 'amount_money' => 1070, 'currency' => 'USD', 'status' => 'COMPLETED', 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    $reports = app(EventPerformance::class);
    $signature = $reports->signature($this->tenant, $this->area, true);
    DB::table('square_payments')->where('id', $payment->id)->update(['raw_payload' => json_encode(['refunded_money' => ['amount' => 1070, 'currency' => 'USD']])]);
    expect($reports->signature($this->tenant, $this->area, true))->not->toBe($signature);
});

test('unrelated source backfills do not invalidate a reviewed report while its exact scope and receipt ancestry remain fresh', function () {
    performanceEvent($this);
    $parent = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_orders_sync', 'status' => 'partial', 'summary' => []]);
    $receipt = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_orders_sync', 'status' => 'completed', 'summary' => ['resumed_from_run_id' => $parent->id]]);
    TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'kind' => 'event_link', 'title' => 'Reviewed scope', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'data' => ['timezone' => 'America/New_York', 'square_scopes' => [['location_id' => 'reviewed-location', 'starts_on' => '2025-11-07', 'ends_on' => '2025-11-09', 'orders_run_id' => $receipt->id, 'payments_run_id' => $receipt->id]]]]);
    $reports = app(EventPerformance::class);
    $signature = $reports->signature($this->tenant, $this->area, true);
    $unrelated = SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'unrelated', 'location_id' => 'another-location', 'closed_at' => '2025-11-07 18:00:00', 'raw_payload' => []]);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'unrelated-payment', 'square_order_id' => 'unrelated', 'raw_payload' => []]);
    MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_payments_sync', 'status' => 'partial', 'summary' => ['processed' => 500]]);
    expect($reports->signature($this->tenant, $this->area, true))->toBe($signature);
    // A late offline order inside the reviewed allowance changes this report.
    $unrelated->update(['location_id' => 'reviewed-location', 'closed_at' => '2025-11-12 18:00:00']);
    expect($reports->signature($this->tenant, $this->area, true))->not->toBe($signature);
    $signature = $reports->signature($this->tenant, $this->area, true);
    DB::table('square_payments')->where('square_order_id', 'unrelated')->update(['raw_payload' => json_encode(['refunded_money' => ['amount' => 100, 'currency' => 'USD']])]);
    expect($reports->signature($this->tenant, $this->area, true))->not->toBe($signature);
    $signature = $reports->signature($this->tenant, $this->area, true);
    $parent->update(['summary' => ['errors' => 1]]);
    expect($reports->signature($this->tenant, $this->area, true))->not->toBe($signature);
});

test('warm reads reuse only financial content digests while source admission and identity checks stay fresh', function () {
    $reports = app(EventPerformance::class);
    $reports->signature($this->tenant, $this->area, true);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $reports->signature($this->tenant, $this->area);
    $reports->signature($this->tenant, $this->area);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    expect($queries->filter(fn ($query) => str_contains($query['query'], 'raw_payload')))->toBeEmpty()
        ->and($queries->filter(fn ($query) => str_contains($query['query'], 'team_provider_connections')))->toHaveCount(2);
});

function scopedPerformanceAccess(?Closure $afterRecord = null): \App\Services\Team\Operations\OperationsAccess
{
    return new class($afterRecord) extends \App\Services\Team\Operations\OperationsAccess
    {
        public array $admitted = [];

        public array $inventoryModes = [];

        public function __construct(private ?Closure $afterRecord) {}

        public function record(\App\Models\Tenant $tenant, \App\Models\User $user, TeamOperationRecord $record, bool $write = false): void
        {
            parent::record($tenant, $user, $record, $write);
            $this->admitted[] = $record->id;
            if ($this->afterRecord !== null) {
                ($this->afterRecord)($tenant, $user, $record);
            }
        }

        public function readCollection(\App\Models\Tenant $tenant, \App\Models\User $user, \App\Models\TeamArea $area, callable $read, bool $collectionAsana = false): mixed
        {
            $this->inventoryModes[] = $collectionAsana;

            return parent::readCollection($tenant, $user, $area, $read, $collectionAsana);
        }
    };
}

function scopedPerformanceCustomers($test, array $events): MarketingProfile
{
    $profile = MarketingProfile::create(['tenant_id' => $test->tenant->id, 'first_name' => 'Target buyer']);
    foreach ($events as $index => $event) {
        performanceCustomerOriginal($test, $event, $profile, 'cohort-'.$index, 1070, 0, 100 + $index);
    }

    return $profile;
}

function scopedPerformanceAsana($test, TeamOperationRecord $event, ?Closure $taskResponse = null): \App\Models\TeamProviderConnection
{
    $connection = \App\Models\TeamProviderConnection::create(['id' => (string) Str::uuid(), 'tenant_id' => $test->tenant->id,
        'area_id' => $test->area->id, 'user_id' => $test->owner->id, 'provider' => 'asana', 'account_key' => hash('sha256', Str::uuid()),
        'status' => 'connected', 'generation' => 1, 'expires_at' => now()->addHour(), 'credentials' => ['access_token' => 'fictional'],
        'settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '200']]]);
    $event->update(['connection_id' => $connection->id, 'data' => ['_generation' => 1, 'source_workspace' => '100', 'source_project' => '200', 'source_id' => '300']]);
    $task = ['gid' => '300', 'workspace' => ['gid' => '100'], 'memberships' => [['project' => ['gid' => '200']]]];
    Http::fake(['*projects/200/tasks*' => Http::response(['data' => [$task], 'next_page' => null]),
        '*tasks/300*' => fn () => $taskResponse !== null ? $taskResponse($task) : Http::response(['data' => $task])]);

    return $connection;
}

test('one filtered event freshly admits its own source rather than unrelated retained events', function () {
    $selected = performanceEvent($this, 'Selected fair');
    $outside = performanceEvent($this, 'Unrelated retained fair', '2024-11-07');
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $outside->update(['status' => 'withdrawn']);
    $access = scopedPerformanceAccess();
    app()->instance(\App\Services\Team\Operations\OperationsAccess::class, $access);
    $this->actingAs($this->owner)->getJson($this->url.'&event_id='.$selected->id)->assertOk()
        ->assertJsonPath('summary.event_count', 1)->assertJsonPath('summary.net_sales_minor', 6000);
    expect($access->admitted)->toBe([$selected->id])->and($access->inventoryModes)->toBe([false]);
});

test('pagination freshly admits all summary contributors including off-page events', function () {
    $events = collect(range(1, 53))->map(fn ($n) => performanceEvent($this, 'In-period fair '.$n));
    performanceEvent($this, 'Outside the date filter', '2024-11-07');
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $access = scopedPerformanceAccess();
    app()->instance(\App\Services\Team\Operations\OperationsAccess::class, $access);
    $this->actingAs($this->owner)->getJson($this->url.'&page=2')->assertOk()->assertJsonCount(3, 'rows')
        ->assertJsonPath('summary.event_count', 53)->assertJsonPath('summary.net_sales_minor', 318000);
    expect(collect($access->admitted)->sort()->values()->all())->toBe($events->pluck('id')->sort()->values()->all())
        ->and($access->inventoryModes)->toBe([true]);
});

test('fresh exact task admission is repeated on the next small-cohort request and revocation clears data', function () {
    $event = performanceEvent($this);
    $reads = 0;
    scopedPerformanceAsana($this, $event, function ($task) use (&$reads) {
        return ++$reads === 1 ? Http::response(['data' => $task]) : Http::response([], 403);
    });
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $this->actingAs($this->owner)->getJson($this->url.'&event_id='.$event->id)->assertOk();
    $this->getJson($this->url.'&event_id='.$event->id)->assertForbidden()->assertDontSee('Fictional fair');
    expect($reads)->toBe(2);
    Http::assertSentCount(3);
});

test('operator-only contributions are admitted when historical staff and current operator labels differ', function () {
    $visible = performanceEvent($this, 'First event', changes: ['staff' => [['id' => 'user:1', 'name' => 'Visible person']],
        'operators' => [['user_id' => '1', 'name' => 'Visible person', 'sale_count' => 1, 'net_sales_minor' => 2000, 'known_net_sales_minor' => 2000, 'complete' => true]]]);
    $operatorOnly = performanceEvent($this, 'Historical event', changes: ['staff' => [['id' => 'user:1', 'name' => 'Historical label']],
        'operators' => [['user_id' => '1', 'name' => 'Visible person', 'sale_count' => 1, 'net_sales_minor' => 1000, 'known_net_sales_minor' => 1000, 'complete' => true]]]);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $access = scopedPerformanceAccess();
    app()->instance(\App\Services\Team\Operations\OperationsAccess::class, $access);
    $url = $this->url.'&group_by=team_member&q=Visible';
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('rows.0.direct_net_sales_minor', 3000);
    expect(collect($access->admitted)->sort()->values()->all())->toBe(collect([$visible->id, $operatorOnly->id])->sort()->values()->all());
    $operatorOnly->update(['status' => 'withdrawn']);
    $this->getJson($url)->assertConflict()->assertDontSee('Visible person');
});

test('customer-total contributors are admitted even when their event titles do not match the row search', function () {
    $selected = performanceEvent($this, 'Target fair');
    $customerTotal = performanceEvent($this, 'Another fair');
    $profile = scopedPerformanceCustomers($this, [$selected, $customerTotal]);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $access = scopedPerformanceAccess();
    app()->instance(\App\Services\Team\Operations\OperationsAccess::class, $access);
    $this->actingAs($this->owner)->getJson($this->url.'&q=Target')->assertOk()->assertJsonPath('summary.event_count', 1)
        ->assertJsonPath('summary.customer_purchase_count', 2);
    expect(collect($access->admitted)->sort()->values()->all())->toBe(collect([$selected->id, $customerTotal->id])->sort()->values()->all());
    $customerTotal->update(['status' => 'withdrawn']);
    $this->getJson($this->url.'&q=Target')->assertOk()
        ->assertJsonPath('summary.customer_count', null)->assertJsonPath('summary.customer_purchase_count', null)
        ->assertJsonPath('summary.paid_total_minor', null)->assertDontSee('Target buyer');
    $this->getJson($this->url.'&group_by=customer&q=Target')->assertOk()->assertJsonPath('status', 'preparing')
        ->assertJsonCount(0, 'rows')->assertDontSee('Target buyer');
    $this->getJson($this->url.'&customer_id='.$profile->id)->assertOk()->assertJsonPath('status', 'preparing')
        ->assertJsonCount(0, 'rows')->assertDontSee('Target buyer');
    Queue::assertPushed(RefreshEventPerformance::class);
});

test('a same-request canonical association change during source admission cannot return old customer facts', function () {
    $event = performanceEvent($this);
    $profile = scopedPerformanceCustomers($this, [$event]);
    $replacement = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Replacement buyer']);
    $link = MarketingProfileLink::where('tenant_id', $this->tenant->id)->where('marketing_profile_id', $profile->id)
        ->where('source_type', 'square_order')->firstOrFail();
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $access = scopedPerformanceAccess(fn () => $link->update(['marketing_profile_id' => $replacement->id]));
    app()->instance(\App\Services\Team\Operations\OperationsAccess::class, $access);
    $this->actingAs($this->owner)->getJson($this->url.'&group_by=customer')->assertConflict()->assertDontSee('Target buyer');
});

test('withdrawal or membership loss during admission fails before private rows are returned', function (string $change, int $status) {
    $event = performanceEvent($this);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $access = scopedPerformanceAccess(function ($tenant, $user, $record) use ($change) {
        if ($change === 'withdrawal') {
            $record->update(['status' => 'withdrawn']);
        } else {
            $tenant->users()->updateExistingPivot($user->id, ['membership_active' => false]);
        }
    });
    app()->instance(\App\Services\Team\Operations\OperationsAccess::class, $access);
    $this->actingAs($this->owner)->getJson($this->url.'&event_id='.$event->id)->assertStatus($status)->assertDontSee('Fictional fair');
})->with([['withdrawal', 409], ['membership', 403]]);

test('same-generation source selection changes during exact admission are detected despite hidden model settings', function () {
    $event = performanceEvent($this);
    $connection = scopedPerformanceAsana($this, $event);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $access = scopedPerformanceAccess(fn () => $connection->update(['settings' => ['source' => ['asana_workspace' => '100', 'asana_project' => '999']]]));
    app()->instance(\App\Services\Team\Operations\OperationsAccess::class, $access);
    $this->actingAs($this->owner)->getJson($this->url.'&event_id='.$event->id)->assertConflict()->assertDontSee('Fictional fair');
});

test('a changed reviewed scope cannot reuse a warm financial revision from its prior version', function () {
    $event = performanceEvent($this);
    $event->update(['data' => ['_teams_enrichment' => ['square_orders' => ['ids' => ['first-order']]]]]);
    foreach (['first-order', 'second-order'] as $order) {
        SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $order, 'raw_payload' => []]);
        SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => $order.'-payment', 'square_order_id' => $order, 'raw_payload' => []]);
    }
    $reports = app(EventPerformance::class);
    $reports->signature($this->tenant, $this->area, true);
    $event->update(['version' => 2, 'data' => ['_teams_enrichment' => ['square_orders' => ['ids' => ['second-order']]]]]);
    DB::table('square_payments')->where('square_order_id', 'second-order')->update(['raw_payload' => json_encode(['refund' => 100])]);
    $warm = $reports->signature($this->tenant, $this->area);
    expect($warm)->toBe($reports->signature($this->tenant, $this->area, true));
});

test('a changed original receipt ancestry cannot reuse a warm financial revision', function () {
    performanceEvent($this);
    $first = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_orders_sync', 'status' => 'partial', 'summary' => []]);
    $next = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_orders_sync', 'status' => 'partial', 'summary' => []]);
    $receipt = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_orders_sync', 'status' => 'completed', 'summary' => ['resumed_from_run_id' => $first->id]]);
    TeamOperationRecord::create(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'kind' => 'event_link', 'title' => 'Reviewed scope', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'data' => ['timezone' => 'UTC', 'square_scopes' => [['location_id' => 'reviewed-location', 'starts_on' => '2025-11-07', 'ends_on' => '2025-11-09', 'orders_run_id' => $receipt->id, 'payments_run_id' => $receipt->id]]]]);
    $reports = app(EventPerformance::class);
    $reports->signature($this->tenant, $this->area, true);
    $receipt->update(['summary' => ['resumed_from_run_id' => $next->id]]);
    expect($reports->signature($this->tenant, $this->area))->toBe($reports->signature($this->tenant, $this->area, true));
});

function performanceDigest(string $table, int $tenant, array $columns): string
{
    return (new ReflectionMethod(EventPerformance::class, 'tableDigest'))->invoke(app(EventPerformance::class), $table, $tenant, $columns);
}

test('MySQL indexed digests read only covering hashes and preserve all typed source mutations', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires actual MySQL generated columns and binary aggregates.');
    }
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $replacement = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $definitions = [
        'square_orders' => [
            ['id', 'square_order_id', 'square_customer_id', 'location_id', 'state', 'total_money_amount', 'total_money_currency', 'closed_at', 'raw_payload'],
            ['square_order_id' => 'digest-order', 'square_customer_id' => 'customer-a', 'location_id' => 'location-a', 'state' => 'COMPLETED', 'total_money_amount' => 1000, 'total_money_currency' => 'USD', 'closed_at' => '2025-11-07 18:00:00', 'raw_payload' => '{}'],
            ['square_order_id' => 'digest-order-b', 'square_customer_id' => 'customer-b', 'location_id' => 'location-b', 'state' => 'CANCELED', 'total_money_amount' => 1001, 'total_money_currency' => 'CAD', 'closed_at' => '2025-11-07 18:00:01', 'raw_payload' => '{"refund":1}'], 'raw_payload',
        ],
        'square_payments' => [
            ['id', 'square_payment_id', 'square_order_id', 'square_customer_id', 'location_id', 'amount_money', 'currency', 'status', 'raw_payload'],
            ['square_payment_id' => 'digest-payment', 'square_order_id' => 'digest-order', 'square_customer_id' => 'customer-a', 'location_id' => 'location-a', 'amount_money' => 1070, 'currency' => 'USD', 'status' => 'COMPLETED', 'raw_payload' => '{}'],
            ['square_payment_id' => 'digest-payment-b', 'square_order_id' => 'digest-order-b', 'square_customer_id' => 'customer-b', 'location_id' => 'location-b', 'amount_money' => 1071, 'currency' => 'CAD', 'status' => 'FAILED', 'raw_payload' => '{"refund":1}'], 'raw_payload',
        ],
        'marketing_profiles' => [
            ['id', 'merged_into_profile_id', 'archived_at', 'source_channels'],
            ['merged_into_profile_id' => null, 'archived_at' => null, 'source_channels' => '{}'],
            ['merged_into_profile_id' => $profile->id, 'archived_at' => '2025-11-07 18:00:00', 'source_channels' => '["square"]'], 'source_channels',
        ],
        'marketing_profile_links' => [
            ['id', 'marketing_profile_id', 'source_type', 'source_id', 'source_meta', 'match_method', 'confidence'],
            ['marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => 'digest-order', 'source_meta' => '{}', 'match_method' => 'exact', 'confidence' => 90],
            ['marketing_profile_id' => $replacement->id, 'source_type' => 'square_payment', 'source_id' => 'digest-payment', 'source_meta' => '{"event":2}', 'match_method' => 'reviewed', 'confidence' => 91], 'source_meta',
        ],
    ];
    foreach ($definitions as $table => [$columns, $original, $changes, $jsonColumn]) {
        $id = DB::table($table)->insertGetId(['tenant_id' => $this->tenant->id, ...$original]);
        $digest = performanceDigest($table, $this->tenant->id, $columns);
        foreach ($changes as $column => $value) {
            DB::table($table)->where('id', $id)->update([$column => $value]);
            $changed = performanceDigest($table, $this->tenant->id, $columns);
            expect($changed, $table.'.'.$column)->not->toBe($digest);
            $digest = $changed;
        }
        $typed = [];
        foreach ([null, 'null', '0', '"0"', 'false', '[]', '{}', '[0]', '{"value":0}'] as $value) {
            DB::table($table)->where('id', $id)->update([$jsonColumn => $value]);
            $typed[] = performanceDigest($table, $this->tenant->id, $columns);
        }
        expect(array_unique($typed), $table.' SQL NULL and JSON types')->toHaveCount(9);
        $copy = (array) DB::table($table)->where('id', $id)->first();
        unset($copy['id']);
        $beforeReplace = performanceDigest($table, $this->tenant->id, $columns);
        DB::table($table)->where('id', $id)->delete();
        DB::table($table)->insert($copy);
        expect(performanceDigest($table, $this->tenant->id, $columns), $table.' replacement id')->not->toBe($beforeReplace);
        DB::enableQueryLog();
        DB::flushQueryLog();
        performanceDigest($table, $this->tenant->id, $columns);
        $aggregate = collect(DB::getQueryLog())->first(fn ($query) => str_contains($query['query'], 'BIT_XOR'));
        DB::disableQueryLog();
        expect($aggregate)->not->toBeNull()->and($aggregate['query'])->toContain('HEX(`event_content_hash`)')
            ->not->toContain('raw_payload')->not->toContain('source_meta')->not->toContain('source_channels')->not->toContain('SUBSTRING');
        $actual = DB::selectOne($aggregate['query'], $aggregate['bindings']);
        expect($actual->h, $table.' full SHA-256 aggregate')->toHaveLength(64);
        $plan = DB::select('EXPLAIN '.$aggregate['query'], $aggregate['bindings']);
        expect(collect($plan)->pluck('Extra')->implode(' '), $table.' covering plan')->toContain('Using index');
        $empty = performanceDigest($table, PHP_INT_MAX, $columns);
        expect($empty)->toBeString()->toHaveLength(64)->not->toBe(performanceDigest($table, $this->tenant->id, $columns));
    }
});

test('MySQL indexed digests fall back for new fields and incomplete schema', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires the actual MySQL indexed digest path.');
    }
    $columns = ['id', 'square_payment_id', 'square_order_id', 'square_customer_id', 'location_id', 'amount_money', 'currency', 'status', 'raw_payload'];
    $payment = SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'future-digest', 'raw_payload' => []]);
    $futureColumns = [...$columns, 'updated_at'];
    $before = performanceDigest('square_payments', $this->tenant->id, $futureColumns);
    DB::table('square_payments')->where('id', $payment->id)->update(['updated_at' => '2025-11-07 18:00:00']);
    expect(performanceDigest('square_payments', $this->tenant->id, $futureColumns))->not->toBe($before);
    $available = \Illuminate\Support\Facades\Schema::getColumnListing('square_payments');
    $method = new ReflectionMethod(EventPerformance::class, 'indexedDigest');
    expect($method->invoke(app(EventPerformance::class), 'square_payments', $columns, array_diff($available, ['event_content_hash'])))->toBeNull()
        ->and($method->invoke(app(EventPerformance::class), 'square_payments', [...$columns, 'updated_at'], $available))->toBeNull();
    \Illuminate\Support\Facades\Schema::shouldReceive('getIndexes')->once()->with('square_payments')->andReturn([]);
    expect($method->invoke(app(EventPerformance::class), 'square_payments', $columns, $available))->toBeNull();
});

test('receipt ancestry is tenant scoped cycle safe and bounded in one fresh MySQL query', function () {
    $parent = 999999;
    $expected = [$parent];
    foreach (range(1, 60) as $page) {
        $run = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_orders_sync', 'status' => 'partial', 'summary' => ['resumed_from_run_id' => $parent]]);
        $parent = $run->id;
        $expected[] = $parent;
    }
    $cycle = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_orders_sync', 'status' => 'partial', 'summary' => []]);
    $cycle->update(['summary' => ['resumed_from_run_id' => $cycle->id]]);
    [$foreign] = teamWorkspace();
    $foreignRun = MarketingImportRun::create(['tenant_id' => $foreign->id, 'type' => 'square_orders_sync', 'status' => 'partial', 'summary' => ['resumed_from_run_id' => 999998]]);
    $roots = [$parent, $cycle->id, $foreignRun->id, 999997];
    $expected = [...$expected, $cycle->id, $foreignRun->id, 999997];
    sort($expected, SORT_NUMERIC);
    $method = new ReflectionMethod(EventPerformance::class, 'receiptAncestors');
    DB::enableQueryLog();
    DB::flushQueryLog();
    $actual = $method->invoke(app(EventPerformance::class), $this->tenant, $roots);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($actual)->toBe($expected)->not->toContain(999998);
    if (DB::connection()->getDriverName() === 'mysql') {
        expect($queries)->toHaveCount(1)->and($queries[0]['query'])->toContain('WITH RECURSIVE')->toContain('UNION DISTINCT')->toContain('LIMIT 10001');
    }
    expect(fn () => $method->invoke(app(EventPerformance::class), $this->tenant, range(1, 10001)))
        ->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Original Square pagination needs review.');
});

test('MySQL reviewed window and explicit-order digests cover both original order and payment queries', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Requires actual MySQL covering-index plans.');
    }
    $event = performanceEvent($this);
    $event->update(['data' => ['_teams_enrichment' => ['square_orders' => ['ids' => ['explicit-order']]]]]);
    $receipt = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'type' => 'square_orders_sync', 'status' => 'completed', 'summary' => []]);
    TeamOperationRecord::create(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id,
        'kind' => 'event_link', 'title' => 'Reviewed scope', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'data' => ['timezone' => 'UTC', 'square_scopes' => [['location_id' => 'reviewed-location', 'starts_on' => '2025-11-07', 'ends_on' => '2025-11-09', 'orders_run_id' => $receipt->id, 'payments_run_id' => $receipt->id]]]]);
    foreach (['explicit-order', 'window-order', 'outside-order'] as $index => $order) {
        SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => $order,
            'location_id' => $index === 1 ? 'reviewed-location' : 'other-location', 'closed_at' => '2025-11-07 18:00:00', 'raw_payload' => ['amount' => 100]]);
        SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => $order.'-payment', 'square_order_id' => $order, 'raw_payload' => ['amount' => 100]]);
    }
    DB::enableQueryLog();
    DB::flushQueryLog();
    app(EventPerformance::class)->signature($this->tenant, $this->area, true);
    $queries = collect(DB::getQueryLog())->filter(fn ($query) => str_contains($query['query'], 'HEX(`event_content_hash`)'))->values();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(2);
    foreach ($queries as $query) {
        $plan = DB::select('EXPLAIN '.$query['query'], $query['bindings']);
        foreach ($plan as $table) {
            if (in_array($table->table, ['square_orders', 'square_payments'], true)) {
                expect($table->Extra)->toContain('Using index');
            }
        }
        expect((int) DB::selectOne($query['query'], $query['bindings'])->n)->toBe(2);
    }
});

test('lifetime ranking retains the same show cohort while including original purchases at other shows', function () {
    $event = performanceEvent($this);
    $otherEvent = performanceEvent($this, 'Another fictional show', '2025-11-14');
    $loyal = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Longtime buyer']);
    $large = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Large show buyer']);
    $outsider = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Absent from this show']);
    performanceCustomerOriginal($this, $event, $loyal, 'loyal-show', 1000);
    performanceCustomerOriginal($this, $event, $large, 'large-show', 5000);
    performanceCustomerOriginal($this, $otherEvent, $loyal, 'loyal-other', 10000, 0, 124);
    performanceCustomerOriginal($this, $otherEvent, $outsider, 'outsider-other', 50000, 0, 124);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $url = $this->url.'&group_by=customer&sort=net_sales&event_id='.$event->id;
    $eventRows = $this->actingAs($this->owner)->getJson($url)->assertOk()->json('rows');
    $lifeRows = $this->getJson($url.'&rank_basis=lifetime')->assertOk()->assertJsonPath('summary.paid_total_minor', 16000)->json('rows');
    expect(array_column($eventRows, 'id'))->toBe(array_map('strval', [$large->id, $loyal->id]))
        ->and(array_column($lifeRows, 'id'))->toBe(array_map('strval', [$loyal->id, $large->id]))
        ->and(array_column($lifeRows, 'paid_total_minor'))->toBe([11000, 5000]);
});

test('event customer insights require current financial authority and deny assigned staff private detail', function () {
    $event = performanceEvent($this);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $url = '/team/events/'.$event->id.'/customer-insights?tenant='.$this->tenant->slug;
    $this->actingAs($this->owner)->getJson($url)->assertOk()->assertJsonPath('report.status', 'ready');
    $staff = teamMember($this->tenant, 'member');
    $event->update(['assigned_user_id' => $staff->id]);
    $this->actingAs($staff)->getJson($url)->assertForbidden();
    $this->actingAs($this->owner)->get('/team/events/'.$event->id.'/campaign-results/999?tenant='.$this->tenant->slug)->assertNotFound();
});
