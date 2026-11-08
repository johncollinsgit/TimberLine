<?php

use App\Models\TeamOperationRecord;
use App\Services\Team\Operations\EventSchedule;
use App\Services\Team\Operations\OperationsWeather;
use App\Services\Team\Studio\AnnualEventComparison;
use App\Services\Team\Studio\EventCostCalculator;
use App\Services\Team\Studio\EventIntelligence;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    Cache::flush();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'team_operations.weather.enabled' => true, 'team_operations.weather.commercial' => true, 'team_operations.weather.api_key' => 'fictional']);
    $this->travelTo(now('UTC')->setDate(2026, 11, 7)->setTime(12, 0));
});

function datedCalendarSession(string $kind, ?string $date, ?string $opens = null, ?string $closes = null): array
{
    return ['id' => (string) Str::uuid(), 'kind' => $kind, 'date' => $date, 'opens' => $opens, 'closes' => $closes];
}

function datedCalendarWeatherEvent(array $data): TeamOperationRecord
{
    return new TeamOperationRecord(['kind' => 'event', 'starts_on' => '2024-11-01', 'ends_on' => '2024-11-03', 'data' => array_replace(['latitude' => 10, 'longitude' => 20, 'timezone' => 'America/Chicago'], $data)]);
}

function datedCalendarWeatherResponse(array $dates, array $hours = []): array
{
    return ['daily' => ['time' => $dates, 'temperature_2m_max' => array_map(fn ($i) => 60 + $i, array_keys($dates))], 'daily_units' => ['temperature_2m_max' => 'F'],
        'hourly' => ['time' => $hours, 'temperature_2m' => array_fill(0, count($hours), 60), 'precipitation' => array_fill(0, count($hours), .1)]];
}

function datedCalendarCostPolicy(): array
{
    return ['name' => 'Fictional dated event costs', 'currency' => 'USD', 'effective_on' => '2026-01-01', 'per_diem_selection' => 'highest', 'required_categories' => [], 'requires_confirmation' => [], 'rules' => [
        ['id' => 'hourly', 'name' => 'Hourly event work plus fixed setup allowance', 'category' => 'base_pay', 'priority' => 10, 'when' => ['field' => 'staff.pay_type', 'op' => 'eq', 'value' => 'hourly'], 'effect' => ['kind' => 'hourly', 'rate_minor' => 1200, 'setup_minutes' => 60, 'long_event_days' => 3, 'long_setup_minutes' => 180]],
        ['id' => 'long_day', 'name' => 'Explicit qualifying event time', 'category' => 'per_diem', 'priority' => 10, 'when' => ['field' => 'day.event_minutes', 'op' => 'gt', 'value' => 480], 'effect' => ['kind' => 'per_day', 'rate_minor' => 5500]],
        ['id' => 'setup', 'name' => 'Explicit qualifying setup', 'category' => 'per_diem', 'priority' => 10, 'when' => ['field' => 'day.setup', 'op' => 'eq', 'value' => true], 'effect' => ['kind' => 'per_day', 'rate_minor' => 3500]],
        ['id' => 'drive', 'name' => 'Explicit qualifying drive time', 'category' => 'per_diem', 'priority' => 10, 'when' => ['field' => 'day.drive_minutes', 'op' => 'gte', 'value' => 180], 'effect' => ['kind' => 'per_day', 'rate_minor' => 3500]],
    ]];
}

test('dated weather excludes gap setup and travel dates while preserving aligned provider values', function () {
    $sessions = [datedCalendarSession('setup', '2024-10-31', '09:00', '17:00'), datedCalendarSession('selling', '2024-11-01', '10:00', '12:00'), datedCalendarSession('selling', '2024-11-03', '11:00', '13:00'), datedCalendarSession('travel', '2024-11-04', '09:00', '11:00')];
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-10-31', '2024-11-01', '2024-11-02', '2024-11-03', '2024-11-04']))]);
    $result = app(OperationsWeather::class)->event(datedCalendarWeatherEvent(['sessions' => $sessions, 'schedule_complete' => true]));

    expect($result['daily']['time'])->toBe(['2024-11-01', '2024-11-03'])
        ->and($result['daily']['temperature_2m_max'])->toBe([61, 63])
        ->and(array_keys($result['day_sources']))->toBe(['2024-11-01', '2024-11-03'])
        ->and(array_column($result['show_hours'], 'session_id'))->toBe([$sessions[1]['id'], $sessions[2]['id']])
        ->and($result['coverage']['selling_dates'])->toBe(['2024-11-01', '2024-11-03'])
        ->and($result['coverage']['schedule_complete'])->toBeTrue();
    Http::assertSent(fn ($r) => $r['start_date'] === '2024-11-01' && $r['end_date'] === '2024-11-03' && $r['timezone'] === 'America/Chicago');
});

test('two selling windows on one date retain separate windows and one daily weather entry', function () {
    $sessions = [datedCalendarSession('selling', '2024-11-01', '10:00', '12:00'), datedCalendarSession('selling', '2024-11-01', '14:00', '16:00')];
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-11-01'], ['2024-11-01T10:00', '2024-11-01T11:00', '2024-11-01T14:00', '2024-11-01T15:00']))]);
    $result = app(OperationsWeather::class)->event(datedCalendarWeatherEvent(['sessions' => $sessions, 'schedule_complete' => true]));

    expect($result['daily']['time'])->toBe(['2024-11-01'])->and($result['show_hours'])->toHaveCount(2)
        ->and(array_column($result['show_hours'][0]['hourly_samples'], 'time'))->toBe(['2024-11-01T10:00', '2024-11-01T11:00'])
        ->and(array_column($result['show_hours'][1]['hourly_samples'], 'time'))->toBe(['2024-11-01T14:00', '2024-11-01T15:00']);
});

test('unknown selling dates and show hours remain partial and never borrow legacy show windows', function () {
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-11-01', '2024-11-02']))]);
    $event = datedCalendarWeatherEvent(['sessions' => [datedCalendarSession('selling', '2024-11-01'), datedCalendarSession('selling', null)], 'schedule_complete' => false, 'daily_hours' => [['date' => '2024-11-02', 'opens' => '10:00', 'closes' => '18:00']]]);
    $result = app(OperationsWeather::class)->event($event);

    expect($result['daily']['time'])->toBe(['2024-11-01'])->and($result['show_hours'])->toBeEmpty()
        ->and($result['coverage']['unknown_selling_date_count'])->toBe(1)
        ->and($result['coverage']['show_hours_unknown_dates'])->toBe(['2024-11-01'])
        ->and($result['coverage']['schedule_complete'])->toBeFalse()->and($result['coverage']['complete'])->toBeFalse();
});

test('an undated selling schedule does not request range weather or manufacture show days', function () {
    $result = app(OperationsWeather::class)->event(datedCalendarWeatherEvent(['sessions' => [datedCalendarSession('selling', null), datedCalendarSession('setup', '2024-10-31')], 'schedule_complete' => false]));

    expect($result['available'])->toBeFalse()->and($result['type'])->toBe('selling_dates_unknown')
        ->and($result['daily']['time'])->toBeEmpty()->and($result['show_hours'])->toBeEmpty();
    Http::assertNothingSent();
});

test('missing provider dates and hourly samples are disclosed without filled weather values', function () {
    $event = datedCalendarWeatherEvent(['sessions' => [datedCalendarSession('selling', '2024-11-01', '10:00', '12:00'), datedCalendarSession('selling', '2024-11-03', '11:00', '13:00')], 'schedule_complete' => true]);
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-11-01'], ['2024-11-01T10:00']))]);
    $result = app(OperationsWeather::class)->event($event);

    expect($result['daily']['time'])->toBe(['2024-11-01'])->and($result['coverage']['missing_dates'])->toBe(['2024-11-03'])
        ->and($result['show_hours'][0]['missing_hourly_sample_times'])->toBe(['2024-11-01T11:00'])
        ->and($result['show_hours'][0]['missing_precipitation_interval_times'])->toBe(['2024-11-01T11:00', '2024-11-01T12:00'])
        ->and($result['show_hours'][1]['type'])->toBe('unavailable')
        ->and($result['coverage']['hourly_windows_complete'])->toBeFalse()->and($result['coverage']['complete'])->toBeFalse();
});

test('current event weather uses the reviewed enrichment calendar before mutable source sessions', function () {
    $reviewed = ['timezone' => 'America/Chicago', 'sessions' => [datedCalendarSession('selling', '2024-11-01', '10:00', '12:00')], 'schedule_complete' => true, 'source_version' => 1];
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-11-01', '2024-11-03']))]);
    $event = datedCalendarWeatherEvent(['timezone' => 'UTC', 'sessions' => [datedCalendarSession('selling', '2024-11-03', '12:00', '14:00')], 'schedule_complete' => true, 'source_version' => 1, '_teams_enrichment' => ['calendar' => $reviewed]]);
    $result = app(OperationsWeather::class)->event($event);

    expect($result['daily']['time'])->toBe(['2024-11-01'])->and($result['timezone'])->toBe('America/Chicago');
    Http::assertSent(fn ($r) => $r['timezone'] === 'America/Chicago' && $r['start_date'] === '2024-11-01' && $r['end_date'] === '2024-11-01');
});

test('historical weather uses only the frozen comparison calendar dates venue and timezone', function () {
    $event = datedCalendarWeatherEvent(['latitude' => 22, 'longitude' => 33, 'timezone' => 'Pacific/Auckland', 'sessions' => [datedCalendarSession('selling', '2025-11-03', '12:00', '14:00')], 'schedule_complete' => true]);
    $event->starts_on = '2025-11-03';
    $event->ends_on = '2025-11-03';
    $frozen = ['starts_on' => '2024-11-01', 'ends_on' => '2024-11-03', 'latitude' => 11, 'longitude' => 21, 'timezone' => 'America/Chicago', 'calendar' => ['timezone' => 'America/Chicago', 'sessions' => [datedCalendarSession('selling', '2024-11-01', '10:00', '12:00')], 'schedule_complete' => true, 'basis' => 'explicit_sessions']];
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-11-01', '2024-11-02', '2024-11-03']))]);
    $result = app(OperationsWeather::class)->event($event, $frozen);

    expect($result['daily']['time'])->toBe(['2024-11-01'])->and($result['timezone'])->toBe('America/Chicago')
        ->and($result['venue'])->toBe(['latitude' => 11, 'longitude' => 21])->and($result['coverage']['reviewed_calendar'])->toBeTrue();
    Http::assertSent(fn ($r) => $r['latitude'] === 11 && $r['longitude'] === 21 && $r['timezone'] === 'America/Chicago' && $r['start_date'] === '2024-11-01' && $r['end_date'] === '2024-11-01');
});

test('legacy comparison weather remains labeled range context and cannot inherit a current calendar', function () {
    $event = datedCalendarWeatherEvent(['sessions' => [datedCalendarSession('selling', '2024-11-03', '12:00', '14:00')], 'schedule_complete' => true]);
    $frozen = ['starts_on' => '2024-11-01', 'ends_on' => '2024-11-02', 'latitude' => 11, 'longitude' => 21, 'timezone' => 'America/Chicago', 'calendar' => ['basis' => 'not_reviewed', 'sessions' => [], 'schedule_complete' => false], 'daily_hours' => [['date' => '2024-11-01', 'opens' => '10:00', 'closes' => '12:00']]];
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-11-01', '2024-11-02', '2024-11-03']))]);
    $result = app(OperationsWeather::class)->event($event, $frozen);

    expect($result['daily']['time'])->toBe(['2024-11-01', '2024-11-02'])
        ->and($result['coverage']['basis'])->toBe('legacy_unclassified_event_range')
        ->and($result['coverage']['selling_dates'])->toBeNull()->and($result['coverage']['complete'])->toBeFalse()
        ->and(array_column($result['show_hours'], 'date'))->toBe(['2024-11-01']);
});

test('missing frozen weather evidence cannot fall back to a current venue or date range', function () {
    $event = datedCalendarWeatherEvent([]);
    foreach ([['starts_on' => '2024-11-01', 'ends_on' => '2024-11-02', 'timezone' => 'America/Chicago'], ['latitude' => 11, 'longitude' => 21, 'timezone' => 'America/Chicago']] as $incomplete) {
        try {
            app(OperationsWeather::class)->event($event, $incomplete);
            $this->fail('An incomplete historical review must not borrow source evidence.');
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            expect($e->getStatusCode())->toBe(422);
        }
    }
    Http::assertNothingSent();
});

test('legacy overnight final show weather includes next local day interval ends without adding a selling date', function () {
    $event = datedCalendarWeatherEvent(['daily_hours' => [['date' => '2024-11-01', 'opens' => '22:00', 'closes' => '02:00']]]);
    $event->ends_on = '2024-11-01';
    $times = ['2024-11-01T21:00', '2024-11-01T22:00', '2024-11-01T23:00', '2024-11-02T00:00', '2024-11-02T01:00', '2024-11-02T02:00'];
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-11-01', '2024-11-02'], $times))]);
    $result = app(OperationsWeather::class)->event($event);

    expect($result['daily']['time'])->toBe(['2024-11-01'])->and(array_keys($result['day_sources']))->toBe(['2024-11-01'])
        ->and(array_column($result['show_hours'][0]['hourly_samples'], 'time'))->toBe(array_slice($times, 1, 4))
        ->and(array_column($result['show_hours'][0]['precipitation_intervals'], 'time'))->toBe(array_slice($times, 2, 4));
    Http::assertSent(fn ($r) => $r['end_date'] === '2024-11-02' && $r['timezone'] === 'America/Chicago');
});

test('forecast day labels use the venue local date rather than the UTC date', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-07T01:00:00Z'));
    $event = datedCalendarWeatherEvent(['sessions' => [datedCalendarSession('selling', '2026-11-06', '18:00', '20:00')], 'schedule_complete' => true]);
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2026-11-06']))]);
    $result = app(OperationsWeather::class)->event($event);

    expect($result['day_sources']['2026-11-06']['type'])->toBe('forecast');
    Http::assertSent(fn ($r) => str_contains($r->url(), '/forecast') && $r['timezone'] === 'America/Chicago');
});

test('a far future legacy range remains unavailable before timezone completion', function () {
    $event = datedCalendarWeatherEvent(['timezone' => null]);
    $event->starts_on = '2027-03-01';
    $event->ends_on = '2027-03-02';
    $result = app(OperationsWeather::class)->event($event);

    expect($result['available'])->toBeFalse()->and($result['type'])->toBe('outside_forecast_horizon');
    Http::assertNothingSent();
});

test('sparse crew dates receive no gap allowance and use only the reviewed selling day threshold', function () {
    $days = array_map(fn ($date) => ['date' => $date, 'event_minutes' => 540, 'setup' => false, 'travel' => false, 'drive_minutes' => 0], ['2026-11-06', '2026-11-08']);
    $inputs = studioInputs(['selling_days' => 2, 'crew' => [['id' => 'fictional-one', 'name' => 'Fictional worker', 'pay_type' => 'hourly', 'work_minutes' => 960, 'days' => $days]]]);
    $result = app(EventCostCalculator::class)->preview($inputs, datedCalendarCostPolicy());

    expect($result['total_cost_minor'])->toBe(31400)
        ->and(array_values(array_filter($result['lines'], fn ($l) => $l['category'] === 'per_diem')))->toHaveCount(2)
        ->and($result['lines'][0]['amount_minor'])->toBe(20400)
        ->and(implode(' ', array_column($result['lines'], 'label')))->not->toContain('2026-11-07');
});

test('selling setup and travel qualifications on one crew date select one allowance', function () {
    $day = ['date' => '2026-11-05', 'event_minutes' => 540, 'setup' => true, 'travel' => true, 'drive_minutes' => 240];
    $inputs = studioInputs(['selling_days' => 2, 'crew' => [['id' => 'fictional-one', 'name' => 'Fictional worker', 'pay_type' => 'hourly', 'work_minutes' => 0, 'days' => [$day]]]]);
    $result = app(EventCostCalculator::class)->preview($inputs, datedCalendarCostPolicy());
    $allowances = array_values(array_filter($result['lines'], fn ($l) => $l['category'] === 'per_diem'));

    expect($allowances)->toHaveCount(1)->and($allowances[0]['amount_minor'])->toBe(5500)
        ->and($allowances[0]['rules'])->toHaveCount(3)->and($result['total_cost_minor'])->toBe(6700);
    $inputs['crew'][0]['days'][] = $day;
    expect(fn () => app(EventCostCalculator::class)->preview($inputs, datedCalendarCostPolicy()))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class, 'Use one day entry per person per date.');
});

test('organizer setup and show windows do not establish paid shifts or per diem qualification', function () {
    $calendar = app(EventSchedule::class)->calendar(['timezone' => 'America/Chicago', 'schedule_complete' => true, 'sessions' => [datedCalendarSession('setup', '2026-11-05', '09:00', '18:00'), datedCalendarSession('selling', '2026-11-06', '10:00', '18:00'), datedCalendarSession('selling', '2026-11-08', '11:00', '17:00')]]);
    $inputs = studioInputs(['selling_days' => $calendar['selling_day_count'], 'facts' => ['organizer_calendar' => $calendar], 'crew' => [['id' => 'fictional-one', 'name' => 'Fictional assignee', 'pay_type' => 'hourly', 'work_minutes' => null, 'days' => []]]]);
    $result = app(EventCostCalculator::class)->preview($inputs, datedCalendarCostPolicy());

    expect($calendar['selling_day_count'])->toBe(2)->and($calendar['operating_minutes'])->toBe(840)
        ->and($result['lines'])->toHaveCount(1)->and($result['lines'][0]['amount_minor'])->toBeNull()->and($result['total_cost_minor'])->toBeNull();
    $inputs['selling_days'] = null;
    $inputs['crew'][0]['work_minutes'] = 480;
    expect(app(EventCostCalculator::class)->preview($inputs, datedCalendarCostPolicy())['total_cost_minor'])->toBeNull();
});

test('comparison normalization uses reviewed selling dates and show minutes without allocating daily sales', function () {
    $calendar = app(EventSchedule::class)->calendar(['timezone' => 'America/Chicago', 'schedule_complete' => true, 'sessions' => [datedCalendarSession('setup', '2024-10-31', '09:00', '17:00'), datedCalendarSession('selling', '2024-11-01', '10:00', '12:00'), datedCalendarSession('selling', '2024-11-03', '11:00', '13:00')]]);
    $finance = ['source' => ['id' => 'fictional-finance'], 'net_sales_cents' => 100000, 'total_expense_cents' => 60000, 'profit_cents' => 40000, 'costs' => [], 'selling_days' => $calendar['selling_day_count'], 'operating_minutes' => $calendar['operating_minutes'], 'daily_sales' => null, 'complete' => true, 'partial' => false];
    $history = [['id' => 'fictional-occurrence', 'starts_on' => '2024-11-01', 'finance' => $finance]];
    $year = app(AnnualEventComparison::class)->summarize($history, true)[0];

    expect($year['verified_selling_days'])->toBe(2)->and($year['verified_operating_minutes'])->toBe(240)
        ->and($year['net_sales_per_day_cents'])->toBe(50000)->and($year['profit_per_hour_cents'])->toBe(10000)
        ->and($year)->not->toHaveKey('daily_sales');
    $history[0]['finance']['selling_days'] = null;
    $history[0]['finance']['operating_minutes'] = null;
    $unknown = app(AnnualEventComparison::class)->summarize($history, true)[0];
    expect($unknown['net_sales_per_day_cents'])->toBeNull()->and($unknown['profit_per_hour_cents'])->toBeNull();
});

test('reading a new calendar leaves a legacy frozen budget inputs hash policy and preview unchanged', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event', 'title' => 'Fictional legacy budget', 'version' => 1, 'status' => 'accepted', 'source_key' => hash('sha256', Str::uuid()), 'starts_on' => '2026-11-06', 'ends_on' => '2026-11-08', 'data' => ['timezone' => 'America/Chicago', 'sessions' => [datedCalendarSession('selling', '2026-11-06', '10:00', '12:00'), datedCalendarSession('selling', '2026-11-08', '10:00', '12:00')], 'schedule_complete' => true]]);
    $inputs = studioInputs(['crew' => [['id' => 'fictional-one', 'name' => 'Fictional worker', 'pay_type' => 'hourly', 'work_minutes' => 480, 'days' => []]]]);
    $policy = datedCalendarCostPolicy();
    $preview = app(EventCostCalculator::class)->preview($inputs, $policy);
    $frozen = ['event_id' => $event->id, 'policy_id' => (string) Str::uuid(), 'policy_version' => 1, 'policy_snapshot' => $policy, 'inputs' => $inputs, 'preview' => $preview, 'frozen_at' => now()->toIso8601String()];
    $budget = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_budget', 'title' => 'Fictional frozen budget', 'version' => 1, 'status' => 'frozen', 'source_key' => hash('sha256', Str::uuid()), 'visibility' => 'admin', 'data' => $frozen]);
    $result = app(EventIntelligence::class)->detail($tenant, $owner, $event)['budget_revisions'][0];

    expect($result['inputs'])->toBe($inputs)->and($result['preview'])->toBe($preview)
        ->and($result['estimated_profitability']['estimated_cost_minor'])->toBeNull()
        ->and($result['estimated_profitability']['cost_lines'][0]['amount_minor'])->toBe(13200)
        ->and($budget->fresh()->data)->toBe($frozen)->and($result['inputs'])->not->toHaveKey('sessions');
});

test('new source date evidence cannot silently replace reviewed historical selling denominators venue or calendar', function () {
    [$tenant, $owner] = teamWorkspace();
    $area = studioArea($tenant);
    $attributes = ['tenant_id' => $tenant->id, 'area_id' => $area->id, 'title' => 'Fictional historical occurrence', 'version' => 1, 'status' => 'accepted', 'starts_on' => '2024-11-01', 'ends_on' => '2024-11-03'];
    $record = fn (string $kind, array $data = []) => TeamOperationRecord::create($attributes + ['id' => (string) Str::uuid(), 'source_key' => hash('sha256', Str::uuid()), 'kind' => $kind, 'data' => $data]);
    $event = $record('event', ['source_version' => 1, 'source_schedule' => ['due_on' => '2024-11-01']]);
    $series = $record('series');
    $finance = $record('financial', ['net_sales_cents' => 100000, 'total_expense_cents' => 60000, 'costs' => []]);
    $sessions = [datedCalendarSession('setup', '2024-10-31', '09:00', '17:00'), datedCalendarSession('selling', '2024-11-01', '10:00', '12:00'), datedCalendarSession('selling', '2024-11-03', '11:00', '13:00')];
    $mapping = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'series_id' => $series->id, 'occurrence_id' => $event->id, 'financial_id' => $finance->id, 'staff_ids' => [], 'allocation_reviewed' => true, 'reason' => 'Reviewed historical dated sessions and original totals.', 'venue' => 'Fictional original venue', 'timezone' => 'America/Chicago', 'latitude' => 11, 'longitude' => 21, 'verified_selling_days' => 2, 'verified_operating_minutes' => 240, 'daily_hours' => [], 'calendar' => ['timezone' => 'America/Chicago', 'sessions' => $sessions, 'schedule_complete' => true]];
    app(\App\Services\Team\Studio\TeamsStudio::class)->reviewOccurrence($tenant, $owner, $area, $mapping);
    $frozen = TeamOperationRecord::findOrFail($mapping['id'])->data;
    $event->update(['starts_on' => '2025-12-01', 'ends_on' => '2025-12-05', 'data' => ['latitude' => 22, 'longitude' => 33, 'timezone' => 'Pacific/Auckland', 'source_version' => 2, 'source_schedule' => ['due_on' => '2025-12-01'], 'sessions' => [datedCalendarSession('selling', '2025-12-01', '08:00', '18:00')], 'schedule_complete' => true]]);
    $history = app(EventIntelligence::class)->detail($tenant, $owner, $event)['history'][0];
    Http::fake(['*' => Http::response(datedCalendarWeatherResponse(['2024-11-01', '2024-11-02', '2024-11-03']))]);
    $weather = app(OperationsWeather::class)->event($event, $frozen);

    expect($history['starts_on'])->toBe('2024-11-01')->and($history['ends_on'])->toBe('2024-11-03')
        ->and($history['venue'])->toBe('Fictional original venue')->and($history['timezone'])->toBe('America/Chicago')
        ->and($history['finance']['selling_days'])->toBe(2)->and($history['finance']['operating_minutes'])->toBe(240)
        ->and($history['finance']['net_sales_per_day_cents'])->toBe(50000)->and($history['finance']['complete'])->toBeFalse()
        ->and(implode(' ', $history['finance']['review_items']))->toContain('dates changed')
        ->and($weather['daily']['time'])->toBe(['2024-11-01', '2024-11-03'])
        ->and($weather['venue'])->toBe(['latitude' => 11, 'longitude' => 21])
        ->and(TeamOperationRecord::findOrFail($mapping['id'])->data)->toBe($frozen);
});
