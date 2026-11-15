<?php

use App\Jobs\Team\RefreshEventPerformance;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
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
    $commerce = Mockery::mock(EventCommerceReport::class);
    $commerce->shouldReceive('commerceId')->andReturn(123);
    app()->instance(EventCommerceReport::class, $commerce);
    $cohort = Mockery::mock(EventCustomerCohort::class);
    $cohort->shouldReceive('read')->andReturn(['profile_ids' => $profiles->pluck('id')->all(), 'relations' => [], 'review_items' => []]);
    app()->instance(EventCustomerCohort::class, $cohort);
    $values = Mockery::mock(CustomerEventValueService::class);
    $values->shouldReceive('forProfile')->andReturnUsing(fn ($profile) => ['purchase_journey' => [['purchase_id' => 'original:'.$profile->id,
        'confirmed_purchase' => true, 'purchase_event' => ['event' => ['id' => 123], 'basis' => 'original_pay_receipt_stock_pool'], 'currency' => 'usd', 'amount_minor' => 1070,
        'refunded_minor' => 0, 'occurred_at' => '2025-11-07T15:00:00Z']]]);
    app()->instance(CustomerEventValueService::class, $values);
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
    performanceEvent($this);
    $low = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Mostly refunded']);
    $high = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Highest spender']);
    $middle = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Two purchases']);
    $commerce = Mockery::mock(EventCommerceReport::class);
    $commerce->shouldReceive('commerceId')->andReturn(123);
    app()->instance(EventCommerceReport::class, $commerce);
    $cohort = Mockery::mock(EventCustomerCohort::class);
    $cohort->shouldReceive('read')->andReturn(['profile_ids' => [$low->id, $high->id, $middle->id], 'relations' => [], 'review_items' => []]);
    app()->instance(EventCustomerCohort::class, $cohort);
    $amounts = [$low->id => [[10000, 9000]], $high->id => [[7000, 0]], $middle->id => [[3000, 0], [2000, 0]]];
    $values = Mockery::mock(CustomerEventValueService::class);
    $values->shouldReceive('forProfile')->andReturnUsing(function ($profile) use ($amounts) {
        $journey = [];
        foreach ($amounts[$profile->id] as $index => [$amount, $refund]) {
            $journey[] = ['purchase_id' => 'sorting:'.$profile->id.':'.$index, 'confirmed_purchase' => true,
                'purchase_event' => ['event' => ['id' => 123]], 'currency' => 'usd', 'amount_minor' => $amount, 'refunded_minor' => $refund, 'occurred_at' => '2025-11-07T15:00:00Z'];
        }

        return ['purchase_journey' => $journey];
    });
    app()->instance(CustomerEventValueService::class, $values);
    app(EventPerformance::class)->rebuild($this->tenant, $this->owner, $this->area);
    $rows = $this->actingAs($this->owner)->getJson($this->url.'&group_by=customer&sort=net_sales')->assertOk()->json('rows');
    expect(array_column($rows, 'id'))->toBe(array_map('strval', [$high->id, $middle->id, $low->id]))
        ->and(array_column($rows, 'paid_total_minor'))->toBe([7000, 5000, 1000])
        ->and(array_column($rows, 'net_sales_minor'))->toBe([null, null, null]);
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
    $payment = SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'fictional-original', 'amount_money' => 1070, 'currency' => 'USD', 'status' => 'COMPLETED', 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    $reports = app(EventPerformance::class);
    $signature = $reports->signature($this->tenant, $this->area, true);
    DB::table('square_payments')->where('id', $payment->id)->update(['raw_payload' => json_encode(['refunded_money' => ['amount' => 1070, 'currency' => 'USD']])]);
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
