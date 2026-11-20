<?php

use App\Models\MarketingImportRun;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Marketing\SquareConnectionContext;
use App\Services\Team\Studio\EventSalesHistory;
use App\Services\Team\Studio\EventSquareCoverage;
use App\Services\Team\Studio\TeamsStudio;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    $this->travelTo(now()->setDate(2026, 11, 15));
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $this->binding = ['connection_id' => 'fixture-source', 'connection_generation' => 1, 'merchant_id' => 'fixture-merchant', 'environment' => 'production', 'location_ids' => ['retired-location']];
    $context = Mockery::mock(SquareConnectionContext::class);
    $context->shouldReceive('assertCurrent')->with($this->tenant->id, $this->binding)->andReturnNull();
    app()->instance(SquareConnectionContext::class, $context);
    $this->runs = [];
    foreach (['orders', 'payments'] as $kind) {
        $this->runs[$kind] = MarketingImportRun::create(['tenant_id' => $this->tenant->id, 'created_by' => $this->owner->id, 'type' => 'square_'.$kind.'_sync', 'status' => 'completed', 'started_at' => '2026-01-05', 'finished_at' => '2026-01-05',
            'summary' => ['source_binding' => $this->binding, 'mode' => 'live-sync', 'errors' => 0, 'since' => '2020-01-01T00:00:00Z', 'initial_cursor' => null, 'checkpoint' => ['cursor' => null, 'errors' => 0]]]);
    }
});

function salesHistoryRecord($test, string $kind, array $extra = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $test->tenant->id, 'area_id' => $test->area->id, 'kind' => $kind, 'title' => 'Fictional annual market', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'starts_on' => '2025-11-01', 'ends_on' => '2025-11-02', 'data' => []], $extra));
}
function salesHistoryMap($test, $event, $series, array $extra = []): array
{
    $input = array_replace(['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $event->id, 'financial_id' => null, 'staff_ids' => [], 'allocation_reviewed' => true, 'reason' => 'Reviewed original location, source periods and event identity.', 'venue' => 'Fictional hall', 'timezone' => 'America/New_York', 'verified_selling_days' => 2, 'daily_hours' => [],
        'square_scopes' => [['location_id' => 'retired-location', 'starts_on' => $event->starts_on->toDateString(), 'ends_on' => $event->ends_on->toDateString(), 'orders_run_id' => $test->runs['orders']->id, 'payments_run_id' => $test->runs['payments']->id]]], $extra);

    return app(TeamsStudio::class)->reviewOccurrence($test->tenant, $test->owner, $test->area, $input);
}
function salesHistoryOrder($test, string $date, int $net, int $tip = 0): void
{
    $id = 'fixture-'.Str::uuid();
    $total = $net + 70 + $tip;
    SquareOrder::create(['tenant_id' => $test->tenant->id, 'square_order_id' => $id, 'location_id' => 'retired-location', 'state' => 'COMPLETED', 'total_money_amount' => $total, 'total_money_currency' => 'USD', 'closed_at' => $date.' 16:00:00', 'synced_at' => '2026-01-04',
        'raw_payload' => ['_source_binding' => $test->binding, 'total_tax_money' => ['amount' => 70, 'currency' => 'USD'], 'total_tip_money' => ['amount' => $tip, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $test->tenant->id, 'square_payment_id' => 'payment-'.$id, 'square_order_id' => $id, 'location_id' => 'retired-location', 'amount_money' => $net + 70, 'currency' => 'USD', 'status' => 'COMPLETED', 'source_type' => 'CARD', 'created_at_source' => $date.' 16:00:00', 'synced_at' => '2026-01-04',
        'raw_payload' => ['_source_binding' => $test->binding, 'amount_money' => ['amount' => $net + 70, 'currency' => 'USD'], 'tip_money' => ['amount' => $tip, 'currency' => 'USD'], 'total_money' => ['amount' => $total, 'currency' => 'USD'], 'refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
}

test('sales history uses verified inactive location windows and merchandise net without tips', function () {
    $series = salesHistoryRecord($this, 'series');
    $old = salesHistoryRecord($this, 'event');
    $current = salesHistoryRecord($this, 'event', ['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']);
    $finance = salesHistoryRecord($this, 'financial', ['visibility' => 'admin', 'data' => ['person_name' => 'Fictional former operator', 'net_sales_cents' => 1500, 'payroll_cents' => 90000, 'costs' => ['private' => 3000]]]);
    salesHistoryOrder($this, '2025-11-01', 1000, 20);
    salesHistoryOrder($this, '2025-11-02', 2000);
    salesHistoryOrder($this, '2025-10-31', 999999);
    salesHistoryMap($this, $old, $series, ['financial_id' => $finance->id, 'staff_ids' => [$finance->id]]);
    salesHistoryMap($this, $current, $series, ['square_scopes' => []]);
    $report = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $current);
    expect($report['history'][0]['net_sales_minor'])->toBe(3000)
        ->and($report['history'][0]['staff'][0]['name'])->toBe('Fictional former operator')
        ->and($report['history'][0]['reconciliation']['difference_minor'])->toBe(1500)
        ->and($report['benchmarks']['average_event_net_sales_minor'])->toBe(3000)
        ->and($report['benchmarks']['average_day_net_sales_minor'])->toBe(1500)
        ->and($report['comparisons']['event_day'][0]['historical_average_net_sales_minor'])->toBe(1000)
        ->and($report['comparisons']['weekday'])->toHaveCount(2);
    expect(json_encode($report))->not->toContain('payroll_cents')->not->toContain('private');
});

test('a blank reviewed workbook total remains unknown without falsely reporting a source change', function () {
    $series = salesHistoryRecord($this, 'series');
    $event = salesHistoryRecord($this, 'event');
    $finance = salesHistoryRecord($this, 'financial', ['data' => ['net_sales_cents' => null]]);
    salesHistoryOrder($this, '2025-11-01', 1000);
    salesHistoryMap($this, $event, $series, ['financial_id' => $finance->id]);
    $current = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $event)['current'];
    expect($current['reported_net_sales_minor'])->toBeNull()->and($current['known_net_sales_minor'])->toBe(1000)
        ->and($current['review_items'])->toContain('A reviewed workbook row has no reported sales total yet.')
        ->not->toContain('A reported sales row changed after mapping review.');
    $finance->update(['version' => 2, 'data' => ['net_sales_cents' => 1200]]);
    expect(app(EventSalesHistory::class)->read($this->tenant, $this->owner, $event)['current']['review_items'])
        ->toContain('A reported sales row changed after mapping review.');
});

test('assigned staff see narrow exports while unassigned and foreign actors cannot see event history', function () {
    $member = teamMember($this->tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $series = salesHistoryRecord($this, 'series');
    $old = salesHistoryRecord($this, 'event');
    $current = salesHistoryRecord($this, 'event', ['assigned_user_id' => $member->id, 'starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']);
    salesHistoryOrder($this, '2025-11-01', 1000);
    salesHistoryMap($this, $old, $series);
    salesHistoryMap($this, $current, $series, ['square_scopes' => []]);
    $url = '/team/events/'.$current->id.'/sales-history?tenant='.$this->tenant->slug;
    $this->actingAs($member)->getJson($url)->assertOk()->assertJsonPath('can_manage', false)->assertJsonPath('benchmarks.average_event_net_sales_minor', 1000)->assertJsonMissingPath('history.0.reconciliation');
    $current->update(['assigned_user_id' => null]);
    $this->getJson($url)->assertForbidden();
    [$foreign, $foreignOwner] = teamWorkspace();
    $this->actingAs($foreignOwner)->getJson('/team/events/'.$current->id.'/sales-history?tenant='.$foreign->slug)->assertNotFound();
});

test('partial pagination and unknown first page cannot establish source coverage or a zero benchmark', function () {
    $series = salesHistoryRecord($this, 'series');
    $old = salesHistoryRecord($this, 'event');
    $current = salesHistoryRecord($this, 'event', ['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']);
    salesHistoryMap($this, $old, $series);
    salesHistoryMap($this, $current, $series, ['square_scopes' => []]);
    foreach ([['checkpoint' => ['cursor' => 'remaining']], ['initial_cursor' => 'skipped-first-page'], ['initial_cursor' => null, 'resumed_from_run_id' => 999999]] as $change) {
        $this->runs['orders']->update(['summary' => array_replace($this->runs['orders']->summary, $change)]);
        $report = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $current);
        expect($report['benchmarks']['average_event_net_sales_minor'])->toBeNull()->and($report['history'][0]['net_sales_minor'])->toBeNull();
    }
});

test('complete source coverage distinguishes a verified zero-selling day from missing coverage', function () {
    $series = salesHistoryRecord($this, 'series');
    $old = salesHistoryRecord($this, 'event');
    salesHistoryMap($this, $old, $series);
    $report = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $old);
    expect($report['current']['net_sales_minor'])->toBe(0)->and($report['current']['daily'])->toHaveCount(2)
        ->and($report['current']['daily'][0]['net_sales_minor'])->toBe(0);
    $this->runs['orders']->update(['status' => 'partial']);
    $report = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $old);
    expect($report['current']['net_sales_minor'])->toBeNull()->and($report['current']['daily'])->toBeEmpty();
});

test('workbook segments add only reviewed nonoverlapping periods and never gate valid Square benchmarks on expenses', function () {
    $series = salesHistoryRecord($this, 'series');
    $event = salesHistoryRecord($this, 'event', ['starts_on' => '2025-11-01', 'ends_on' => '2025-11-08']);
    $one = salesHistoryRecord($this, 'financial', ['data' => ['net_sales_cents' => 1000], 'starts_on' => '2025-11-01', 'ends_on' => '2025-11-02']);
    $two = salesHistoryRecord($this, 'financial', ['data' => ['net_sales_cents' => 2000], 'starts_on' => '2025-11-03', 'ends_on' => '2025-11-08']);
    $map = salesHistoryMap($this, $event, $series, ['verified_selling_days' => 8, 'financial_sources' => [['record_id' => $one->id, 'treatment' => 'add'], ['record_id' => $two->id, 'treatment' => 'add']]]);
    expect(app(EventSalesHistory::class)->read($this->tenant, $this->owner, $event)['current']['reported_net_sales_minor'])->toBe(3000);
    $two->update(['starts_on' => '2025-11-01']);
    expect(fn () => salesHistoryMap($this, $event, $series, ['id' => $map['id'], 'expected_version' => $map['version'], 'financial_sources' => [['record_id' => $one->id, 'treatment' => 'add'], ['record_id' => $two->id, 'treatment' => 'add']]]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});

test('a source connection generation change invalidates previously complete window evidence', function () {
    $scope = ['location_id' => 'retired-location', 'starts_on' => '2025-11-01', 'ends_on' => '2025-11-02', 'orders_run_id' => $this->runs['orders']->id, 'payments_run_id' => $this->runs['payments']->id];
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $scope, 'America/New_York'))->toBeTrue();
    $context = Mockery::mock(SquareConnectionContext::class);
    $context->shouldReceive('assertCurrent')->andThrow(new \RuntimeException('generation changed'));
    app()->instance(SquareConnectionContext::class, $context);
    expect(app(EventSquareCoverage::class)->proven($this->tenant->id, $scope, 'America/New_York'))->toBeFalse();
});

test('same-local-time pace uses confirmed original minute evidence and discloses partial current coverage', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-15T18:00:00Z'));
    $series = salesHistoryRecord($this, 'series');
    $old = salesHistoryRecord($this, 'event');
    $current = salesHistoryRecord($this, 'event', ['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']);
    salesHistoryOrder($this, '2025-11-02', 2000);
    salesHistoryOrder($this, '2025-11-02', 3000);
    $late = SquareOrder::forTenantId($this->tenant->id)->latest('id')->first();
    $late->update(['closed_at' => '2025-11-02 23:00:00']);
    salesHistoryOrder($this, '2026-11-15', 1000);
    salesHistoryMap($this, $old, $series);
    salesHistoryMap($this, $current, $series);
    $report = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $current);
    expect($report['comparisons']['intraday'])->toMatchArray(['available' => true, 'current_net_sales_minor' => 1000,
        'historical_average_net_sales_minor' => 2000, 'through_local_time' => '13:00', 'event_day' => 2,
        'sample_count' => 1, 'current_source_coverage_complete' => false]);
});

test('elapsed current selling days retain partial comparison labels until their source coverage is proved', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-15T18:00:00Z'));
    $series = salesHistoryRecord($this, 'series');
    $old = salesHistoryRecord($this, 'event');
    $current = salesHistoryRecord($this, 'event', ['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']);
    salesHistoryOrder($this, '2025-11-01', 2000);
    salesHistoryOrder($this, '2025-11-02', 3000);
    salesHistoryOrder($this, '2026-11-14', 1000);
    salesHistoryOrder($this, '2026-11-15', 1500);
    salesHistoryMap($this, $old, $series);
    salesHistoryMap($this, $current, $series);
    $report = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $current);
    $saturday = collect($report['current']['daily'])->firstWhere('date', '2026-11-14');
    expect($report['current']['source_coverage_complete'])->toBeFalse()
        ->and($saturday['net_sales_minor'])->toBe(1000)
        ->and($saturday['partial'])->toBeTrue()
        ->and($report['comparisons']['event_day'][0]['partial'])->toBeTrue()
        ->and(collect($report['comparisons']['weekday'])->firstWhere('label', 'Sat')['partial'])->toBeTrue()
        ->and(collect($report['history'][0]['daily'])->pluck('partial')->unique()->all())->toBe([false]);
});

test('reviewed persistent staff identities replace repeated payroll row identities', function () {
    $series = salesHistoryRecord($this, 'series');
    $event = salesHistoryRecord($this, 'event');
    $finance = salesHistoryRecord($this, 'financial', ['data' => ['person_name' => 'Fictional former operator', 'net_sales_cents' => 1000]]);
    $staff = salesHistoryRecord($this, 'staff', ['data' => ['person_name' => 'Fictional former operator']]);
    salesHistoryMap($this, $event, $series, ['financial_id' => $finance->id, 'staff_ids' => [$staff->id]]);
    $row = app(EventSalesHistory::class)->read($this->tenant, $this->owner, $event)['current'];
    expect($row['staff'])->toHaveCount(1)->and($row['staff'][0]['id'])->toBe($staff->id)->and($row['staff'][0]['identity_review_required'])->toBeFalse();
});

test('worker scope purchases include canonical historical customers without leaking identities through staff history', function () {
    $series = salesHistoryRecord($this, 'series');
    $event = salesHistoryRecord($this, 'financial', ['data' => ['net_sales_cents' => 1000, 'payroll_cents' => 90000]]);
    salesHistoryOrder($this, '2025-11-01', 1000);
    $order = SquareOrder::forTenantId($this->tenant->id)->first();
    $profile = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional buyer', 'email' => 'private-fixture@example.test']);
    \App\Models\MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id,
        'source_type' => 'square_order', 'source_id' => $order->square_order_id]);
    $map = salesHistoryMap($this, $event, $series, ['financial_id' => $event->id]);
    $service = app(EventSalesHistory::class);
    $evidence = $service->scopePurchases($this->tenant, $event, TeamOperationRecord::findOrFail($map['id']));
    expect($evidence['purchases'])->toHaveCount(1)->and($evidence['purchases'][0])->toMatchArray(['source' => 'square_order', 'customer_id' => $profile->id,
        'source_id' => $order->square_order_id, 'amount_minor' => 1070, 'net_sales_minor' => 1000, 'currency' => 'USD'])
        ->and(json_encode($evidence))->not->toContain('private-fixture@example.test');
    $this->actingAs($this->owner)->getJson('/team/events/'.$event->id.'/sales-history?tenant='.$this->tenant->slug)
        ->assertOk()->assertJsonPath('current.net_sales_minor', 1000)->assertJsonMissingPath('current.customer_id');
    $member = teamMember($this->tenant, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'user_id' => $member->id, 'role' => 'editor']);
    $event->update(['assigned_user_id' => $member->id]);
    $this->actingAs($member)->getJson('/team/events/'.$event->id.'/sales-history?tenant='.$this->tenant->slug)->assertForbidden();
    $context = Mockery::mock(SquareConnectionContext::class);
    $context->shouldReceive('assertCurrent')->andThrow(new \RuntimeException('source revoked'));
    app()->instance(SquareConnectionContext::class, $context);
    expect($service->scopePurchases($this->tenant, $event, TeamOperationRecord::findOrFail($map['id']))['purchases'])->toBeEmpty();
});

test('ambiguous scope customer associations retain sales as unattributed instead of selecting a person', function () {
    $series = salesHistoryRecord($this, 'series');
    $event = salesHistoryRecord($this, 'event');
    salesHistoryOrder($this, '2025-11-01', 1000);
    $order = SquareOrder::forTenantId($this->tenant->id)->first();
    foreach (['One' => 'square_order', 'Two' => 'square_payment'] as $name => $source) {
        $profile = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => $name]);
        \App\Models\MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id,
            'source_type' => $source, 'source_id' => $source === 'square_order' ? $order->square_order_id : SquarePayment::forTenantId($this->tenant->id)->first()->square_payment_id]);
    }
    $map = salesHistoryMap($this, $event, $series);
    $evidence = app(EventSalesHistory::class)->scopePurchases($this->tenant, $event, TeamOperationRecord::findOrFail($map['id']));
    expect($evidence['purchases'])->toHaveCount(1)->and($evidence['purchases'][0]['customer_id'])->toBeNull()
        ->and($evidence['purchases'][0]['net_sales_minor'])->toBe(1000)->and($evidence['customer_review_items'])->not->toBeEmpty();
});

test('show texts select opted-in customers from reviewed prior editions and selected nearby shows', function () {
    $series = salesHistoryRecord($this, 'series');
    $nearby = salesHistoryRecord($this, 'series', ['title' => 'Fictional nearby show']);
    $current = salesHistoryRecord($this, 'event', ['starts_on' => '2026-11-14', 'ends_on' => '2026-11-15']);
    $past = salesHistoryRecord($this, 'event', ['starts_on' => '2024-11-02', 'ends_on' => '2024-11-03']);
    $related = salesHistoryRecord($this, 'event', ['starts_on' => '2023-11-04', 'ends_on' => '2023-11-05']);
    salesHistoryMap($this, $current, $series, ['square_scopes' => []]);
    salesHistoryMap($this, $past, $series);
    salesHistoryMap($this, $related, $nearby);
    $customers = [];
    foreach (['2024-11-02' => '+15550001010', '2023-11-04' => '+15550001011', '2024-11-01' => '+15550001012'] as $date => $phone) {
        salesHistoryOrder($this, $date, 1000);
        $order = SquareOrder::forTenantId($this->tenant->id)->latest('id')->firstOrFail();
        $profile = \App\Models\MarketingProfile::create(['tenant_id' => $this->tenant->id, 'phone' => $phone, 'accepts_sms_marketing' => true]);
        \App\Models\MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id,
            'source_type' => 'square_order', 'source_id' => $order->square_order_id]);
        $customers[] = $profile->id;
    }
    $audience = app(\App\Services\Marketing\TeamShowTextAudience::class);
    $context = $audience->context($current);
    $settings = $audience->settings($current, $context);
    $settings['nearby_series'] = [$nearby->id];
    expect(array_column($audience->build($this->tenant, $current, $context, $settings, ['show', 'nearby'])['rows'], 'id'))
        ->toBe(array_slice($customers, 0, 2));
    expect(array_column($audience->build($this->tenant, $current, $context, $settings, ['show'])['rows'], 'id'))
        ->toBe([$customers[0]]);
});
