<?php

use App\Models\TeamOperationRecord;
use App\Services\Team\Operations\EventSchedule;
use App\Services\Team\Operations\OperationsWeather;
use App\Services\Team\Studio\EventIntelligence;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true]);
    Http::preventStrayRequests();
});

function intelligenceRecord($tenant, $area, string $kind, array $extra = []): TeamOperationRecord
{
    return TeamOperationRecord::create(array_replace(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => $kind, 'title' => 'Fictional annual show', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'starts_on' => '2024-11-01', 'ends_on' => '2024-11-03', 'data' => []], $extra));
}

test('an explicit show range preserves the independent task deadline and rejects conflicting evidence', function () {
    $parser = app(EventSchedule::class);
    $schedule = $parser->source(['name' => '11.06.26 - 11.08.26 Fictional show', 'due_on' => '2026-11-06', 'start_on' => null]);
    expect($schedule['starts_on'])->toBe('2026-11-06')->and($schedule['ends_on'])->toBe('2026-11-08')->and($schedule['source_schedule']['due_on'])->toBe('2026-11-06');
    expect($parser->source(['name' => 'Show', 'due_on' => '2026-11-06'])['starts_on'])->toBeNull();
    expect($parser->source(['name' => '02.30.26 - 03.02.26 Show'])['needs_review'])->toBeTrue();
    expect($parser->source(['name' => '11.06.26 - 11.08.26 Show', 'notes' => 'Date: 11.07.26 - 11.09.26'])['basis'])->toBe('conflicting_source_ranges');
});

test('reviewed mappings reconcile annual profit without subtracting COGS twice or inventing daily sales', function () {
    [$t, $owner] = teamWorkspace();
    $area = studioArea($t);
    $series = intelligenceRecord($t, $area, 'series');
    $events = [];
    foreach ([2024, 2025] as $year) {
        $event = intelligenceRecord($t, $area, 'event', ['starts_on' => "$year-11-01", 'ends_on' => "$year-11-03"]);
        $finance = intelligenceRecord($t, $area, 'financial', ['starts_on' => "$year-11-01", 'ends_on' => "$year-11-03", 'visibility' => 'admin', 'data' => ['net_sales_cents' => $year === 2024 ? 100000 : 120000, 'total_expense_cents' => 60000, 'profit_cents' => $year === 2024 ? 40000 : 60000, 'costs' => ['cogs' => 20000, 'lodging' => null], 'source_tab' => (string) $year, 'source_row' => 9]]);
        $mapping = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $event->id, 'financial_id' => $finance->id, 'staff_ids' => [$finance->id], 'allocation_reviewed' => true, 'reason' => 'Reviewed source event identity and totals.', 'venue' => 'Historical venue '.$year, 'timezone' => 'America/New_York', 'verified_selling_days' => 3, 'verified_operating_minutes' => null, 'daily_hours' => []];
        $this->actingAs($owner)->postJson('/team/studio/mapping?tenant='.$t->slug.'&area='.$area->id, $mapping)->assertOk();
        $events[] = $event;
    }
    $report = app(EventIntelligence::class)->detail($t, $owner, $events[1]);
    expect($report['history'])->toHaveCount(2)->and($report['history'][0]['finance']['profit_cents'])->toBe(40000)
        ->and($report['history'][0]['finance']['daily_sales'])->toBeNull()->and($report['history'][0]['finance']['profit_per_hour_cents'])->toBeNull()
        ->and($report['history'][1]['year_over_year']['net_sales_change_percent'])->toBe(20.0)
        ->and($report['annual_history'][1]['year_over_year']['net_sales_change_percent'])->toBe(20.0);
});

test('same titles do not create historical links and source changes invalidate comparable figures', function () {
    [$t, $owner] = teamWorkspace();
    $area = studioArea($t);
    $event = intelligenceRecord($t, $area, 'event');
    intelligenceRecord($t, $area, 'financial');
    $report = app(EventIntelligence::class)->detail($t, $owner, $event);
    expect($report['history'])->toBeEmpty()->and($report['review_items'])->not->toBeEmpty();
});

test('mapping cannot reuse a financial total or cross tenant boundaries and staff cannot see payroll', function () {
    [$t, $owner] = teamWorkspace();
    $a = studioArea($t);
    $event = intelligenceRecord($t, $a, 'event');
    $series = intelligenceRecord($t, $a, 'series');
    $finance = intelligenceRecord($t, $a, 'financial', ['visibility' => 'admin']);
    $i = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $event->id, 'financial_id' => $finance->id, 'staff_ids' => [], 'allocation_reviewed' => false, 'reason' => 'Reviewed event identity only.', 'venue' => 'Fictional hall', 'timezone' => 'America/New_York', 'daily_hours' => []];
    $url = '/team/studio/mapping?tenant='.$t->slug.'&area='.$a->id;
    $this->actingAs($owner)->postJson($url, $i)->assertOk();
    $this->postJson($url, array_replace($i, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'occurrence_id' => intelligenceRecord($t, $a, 'event')->id]))->assertConflict();
    [$other, $foreign] = teamWorkspace();
    $foreignArea = studioArea($other);
    $this->postJson($url, array_replace($i, ['request_id' => (string) Str::uuid(), 'staff_ids' => [intelligenceRecord($other, $foreignArea, 'financial')->id]]))->assertNotFound();
    $member = teamMember($t, 'member');
    DB::table('team_area_members')->insert(['id' => Str::uuid(), 'tenant_id' => $t->id, 'area_id' => $a->id, 'user_id' => $member->id, 'role' => 'editor']);
    $this->actingAs($member)->getJson('/team/events/'.$event->id.'/intelligence?tenant='.$t->slug)->assertOk()->assertJsonMissingPath('history.0.finance')->assertJsonMissingPath('history.0.staff')->assertJsonMissingPath('history.0.mapping.source_evidence')->assertJsonPath('can_view_financials', false);
});

test('weather keeps local historical venue days and show-hour windows with explicit data types', function () {
    [$t, $owner] = teamWorkspace();
    $area = studioArea($t);
    config(['team_operations.weather.enabled' => true, 'team_operations.weather.commercial' => true, 'team_operations.weather.api_key' => 'fictional']);
    $this->travelTo(now()->setDate(2026, 11, 7));
    Http::fake(['*' => Http::response(['daily' => ['time' => ['2024-11-01'], 'temperature_2m_max' => [70], 'temperature_2m_min' => [45], 'precipitation_sum' => [0]], 'daily_units' => ['precipitation_sum' => 'inch'], 'hourly' => ['time' => ['2024-11-01T09:00', '2024-11-01T10:00', '2024-11-01T11:00', '2024-11-01T12:00'], 'temperature_2m' => [50, 55, 60, 65], 'precipitation' => [0, .1, .2, .3]]])]);
    $event = intelligenceRecord($t, $area, 'event', ['starts_on' => '2024-11-01', 'ends_on' => '2024-11-01', 'data' => ['latitude' => 32, 'longitude' => -83, 'timezone' => 'America/New_York', 'daily_hours' => [['date' => '2024-11-01', 'opens' => '10:00', 'closes' => '12:00']]]]);
    $weather = app(OperationsWeather::class)->event($event);
    expect($weather['type'])->toBe('historical_reanalysis')->and(array_column($weather['show_hours'][0]['hourly_samples'], 'time'))->toBe(['2024-11-01T10:00', '2024-11-01T11:00'])->and(array_column($weather['show_hours'][0]['precipitation_intervals'], 'time'))->toBe(['2024-11-01T11:00', '2024-11-01T12:00']);
    Http::assertSent(fn ($r) => str_contains($r->url(), '/archive') && $r['timezone'] === 'America/New_York' && $r['latitude'] === 32);
});

test('workbook total days stay recorded without being silently classified as selling days', function () {
    $mapped = app(\App\Services\Team\Operations\SheetMapper::class)->rows('2026', [['Name', 'Event', 'Start Date', 'Ending Date', 'Total Days'], ['Fictional operator', 'Fictional show', '2026-11-06', '2026-11-08', 5]]);
    expect($mapped['items'][0]['recorded_days'])->toBe(5)->and($mapped['items'][0]['selling_days'])->toBeNull()->and($mapped['items'][0]['source_values']['total days'])->toBe(5);
});

test('unknown payroll components do not become invented zero-cost allowances', function () {
    $rows = app(\App\Services\Team\Operations\SheetMapper::class)->rows('2026', [['Name', 'Event', 'Start Date', 'Ending Date', 'Total Payroll Owed', 'Mileage Owed', 'Per Diem Owed', 'Expense Owed'], ['Fictional operator', 'Fictional show', '2026-11-06', '2026-11-08', 100, '', '', '']]);
    expect($rows['items'][0]['payroll_cents'])->toBe(10000)->and($rows['items'][0]['costs']['staffing'])->toBeNull()->and($rows['items'][0]['costs']['mileage'])->toBeNull();
});

test('annual comparisons combine reviewed occurrences once without double charging staff or included COGS', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $series = intelligenceRecord($tenant, $area, 'series');
    foreach ([[2024, '03', 100000], [2024, '11', 200000], [2025, '03', 150000], [2025, '11', 210000]] as [$year, $month, $net]) {
        $dates = ['starts_on' => "$year-$month-01", 'ends_on' => "$year-$month-02"];
        $event = intelligenceRecord($tenant, $area, 'event', $dates);
        $finance = intelligenceRecord($tenant, $area, 'financial', $dates + ['data' => ['net_sales_cents' => $net, 'total_expense_cents' => 60000, 'costs' => ['cogs' => 20000, 'staffing' => 15000], 'refunds_cents' => null]]);
        app(\App\Services\Team\Studio\TeamsStudio::class)->reviewOccurrence($tenant, $owner, $area, ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $event->id, 'financial_id' => $finance->id, 'staff_ids' => [$finance->id], 'allocation_reviewed' => true, 'reason' => 'Reviewed distinct dated occurrences and source totals.', 'venue' => 'Fictional hall', 'timezone' => 'America/New_York', 'verified_selling_days' => 2, 'daily_hours' => []]);
    }
    $report = app(EventIntelligence::class)->detail($tenant, $owner, $event);
    expect($report['annual_history'])->toHaveCount(2)->and($report['annual_history'][0]['occurrence_count'])->toBe(2)
        ->and($report['annual_history'][0]['net_sales_cents'])->toBe(300000)->and($report['annual_history'][0]['total_expense_cents'])->toBe(120000)
        ->and($report['annual_history'][0]['profit_cents'])->toBe(180000)->and($report['annual_history'][0]['costs']['cogs'])->toBe(40000)
        ->and($report['annual_history'][0]['refunds_cents'])->toBeNull()->and($report['annual_history'][0]['net_sales_per_day_cents'])->toBe(75000)
        ->and($report['annual_history'][0]['profit_per_hour_cents'])->toBeNull()->and($report['annual_history'][1]['year_over_year']['net_sales_change_percent'])->toBe(20.0);
});

test('annual unknown partial duplicated or inaccessible evidence cannot establish completed year changes', function () {
    $finance = ['source' => ['id' => 'original'], 'net_sales_cents' => 10000, 'total_expense_cents' => null, 'profit_cents' => null, 'costs' => [], 'complete' => false, 'partial' => true];
    $history = [['id' => 'one', 'starts_on' => '2026-11-06', 'finance' => $finance], ['id' => 'two', 'starts_on' => '2026-11-08', 'finance' => $finance]];
    $years = app(\App\Services\Team\Studio\AnnualEventComparison::class)->summarize($history, false);
    expect($years[0]['net_sales_cents'])->toBe(10000)->and($years[0]['total_expense_cents'])->toBeNull()->and($years[0]['profit_cents'])->toBeNull()
        ->and($years[0]['verified_selling_days'])->toBeNull()->and($years[0]['complete'])->toBeFalse()->and($years[0]['partial'])->toBeTrue()
        ->and($years[0]['year_over_year'])->toBeNull()->and($years[0]['review_items'])->toHaveCount(3);
});

test('reviewed historical weather uses frozen occurrence dates and venue rather than later source locations', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $series = intelligenceRecord($tenant, $area, 'series');
    $event = intelligenceRecord($tenant, $area, 'event', ['data' => ['latitude' => 40, 'longitude' => -74, 'timezone' => 'America/New_York']]);
    $map = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $event->id, 'financial_id' => null, 'staff_ids' => [], 'allocation_reviewed' => false, 'reason' => 'Reviewed this occurrence at its original historical venue.', 'venue' => 'Historical hall', 'timezone' => 'America/Chicago', 'latitude' => 32, 'longitude' => -83, 'daily_hours' => []];
    app(\App\Services\Team\Studio\TeamsStudio::class)->reviewOccurrence($tenant, $owner, $area, $map);
    $event->update(['starts_on' => '2025-11-01', 'ends_on' => '2025-11-03']);
    config(['team_operations.weather.enabled' => true, 'team_operations.weather.commercial' => true, 'team_operations.weather.api_key' => 'fictional']);
    Http::fake(['*' => Http::response(['daily' => ['time' => ['2024-11-01'], 'temperature_2m_max' => [70]], 'daily_units' => []])]);
    $this->actingAs($owner)->getJson('/team/events/'.$event->id.'/weather?tenant='.$tenant->slug)->assertOk()->assertJsonPath('timezone', 'America/Chicago')->assertJsonPath('venue.latitude', 32);
    Http::assertSent(fn ($r) => $r['latitude'] === 32 && $r['longitude'] === -83 && $r['timezone'] === 'America/Chicago' && $r['start_date'] === '2024-11-01');
    $report = app(EventIntelligence::class)->detail($tenant, $owner, $event);
    expect($report['history'][0]['starts_on'])->toBe('2024-11-01')->and(implode(' ', $report['history'][0]['finance']['review_items']))->toContain('dates changed');
    $link = TeamOperationRecord::findOrFail($map['id']);
    $data = $link->data;
    unset($data['latitude'], $data['longitude']);
    $link->update(['data' => $data]);
    $this->getJson('/team/events/'.$event->id.'/weather?tenant='.$tenant->slug)->assertUnprocessable();
});
