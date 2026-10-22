<?php

use App\Enterprise\ReportExport;
use App\Enterprise\RequestWorkflow;
use App\Enterprise\WorkspaceAccess;
use App\Models\TeamOperationRecord;
use App\Services\Team\TeamWorkRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
        'enterprise.reports_enabled' => true, 'enterprise.operations_enabled' => false, 'enterprise.sample_data' => true]);
    Route::middleware('web')->prefix('enterprise-report-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
    $this->travelTo(now()->setDate(2026, 10, 22)->setTime(15, 0));
});

function reportCommand($tenant, $user, array $input): array
{
    return app(RequestWorkflow::class)->execute($tenant, $user, $input + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()]);
}

function reportFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => array_merge(config('enterprise.tenant_allowlist', []), [(string) $tenant->id])]);
    $area = reportCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Synthetic report department', 'visibility' => 'department_only'])['id'];
    $project = reportCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Synthetic report project', 'area_id' => $area])['id'];

    return [$tenant, $owner, $area, $project];
}

function reportWork($tenant, $user, array $command): array
{
    return app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $user, true),
        $command + ['id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()])['result'];
}

function reportInput(string $area, array $extra = []): array
{
    return $extra + ['area_id' => $area, 'mode' => 'calendar_quarter', 'year' => 2026, 'quarter' => 4, 'timezone' => 'America/New_York'];
}

function reportCoordinator($tenant, string $area, string $role = 'coordinator')
{
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $member->id, 'role' => $role]);

    return $member;
}

test('reports separate current facts from selected quarter actions using timezone boundaries and fresh source links', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-01-01T05:00:00Z'));
    [$tenant, $owner, $area, $project] = reportFixture();
    $first = reportWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Original recorded title', 'memberships' => [$project => []]]);
    $this->travelTo(new DateTimeImmutable('2026-04-01T03:59:59Z'));
    $last = reportWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Last included local quarter second', 'memberships' => [$project => []]]);
    $this->travelTo(new DateTimeImmutable('2026-04-01T04:00:00Z'));
    $outside = reportWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Outside recorded period but current', 'memberships' => [$project => []]]);
    $this->travelTo(new DateTimeImmutable('2026-06-02T12:00:00Z'));
    reportWork($tenant, $owner, ['kind' => 'task.update', 'id' => $first['id'], 'expected_version' => 1, 'changes' => ['title' => 'Current renamed title']]);
    $report = app(ReportExport::class)->build($tenant, $owner, reportInput($area, ['quarter' => 1]));
    expect($report['window']['start_utc'])->toBe('2026-01-01T05:00:00+00:00');
    expect($report['window']['end_utc_exclusive'])->toBe('2026-04-01T04:00:00+00:00');
    expect($report['window']['period_in_progress'])->toBeFalse();
    expect(array_column($report['current_records'], 'id'))->toContain($outside['id']);
    $events = array_filter($report['recorded_events'], fn ($row) => $row['entity_type'] === 'task');
    expect(array_column($events, 'id'))->toContain($first['id'], $last['id'])->not->toContain($outside['id']);
    expect(collect($events)->firstWhere('id', $first['id'])['current_title'])->toBe('Current renamed title');
    expect(json_encode($report))->not->toContain('Original recorded title');
    expect($report['basis']['current_state'])->toContain('Not a quarter-end snapshot');
    foreach ($report['current_records'] as $row) {
        expect($row['source_url'])->toContain('tenant='.$tenant->slug, 'area_id='.$area);
    }
    Http::assertNothingSent();
});

test('date windows include a full DST fallback day and clamp event observation to actual generation', function (): void {
    [$tenant, $owner, $area] = reportFixture();
    $input = reportInput($area, ['mode' => 'date_range', 'start_date' => '2026-11-01', 'end_date' => '2026-11-01']);
    $this->travelTo(new DateTimeImmutable('2026-11-03T12:00:00Z'));
    $report = app(ReportExport::class)->build($tenant, $owner, $input);
    expect($report['window']['start_utc'])->toBe('2026-11-01T04:00:00+00:00');
    expect($report['window']['end_utc_exclusive'])->toBe('2026-11-02T05:00:00+00:00');
    $this->travelTo(new DateTimeImmutable('2026-10-22T15:00:00Z'));
    $report = app(ReportExport::class)->build($tenant, $owner, reportInput($area));
    expect($report['window']['observed_through_utc'])->toBe($report['generated_at_utc']);
    expect($report['window']['period_in_progress'])->toBeTrue();
    expect($report['recorded_events'])->not->toBeEmpty('Events at the generation timestamp are observable, not future events.');
    $future = app(ReportExport::class)->build($tenant, $owner, reportInput($area, ['year' => 2027, 'quarter' => 1]));
    expect($future['window']['period_state'])->toBe('future');
    expect($future['window']['period_in_progress'])->toBeFalse();
    expect($future['recorded_events'])->toBeEmpty();
    expect($future['current_records'])->not->toBeEmpty('Current facts are not filtered by future event dates.');
});

test('reports require current manager or coordinator read authority even when operational writes are paused', function (): void {
    [$tenant, $owner, $area] = reportFixture();
    $coordinator = reportCoordinator($tenant, $area);
    $editor = reportCoordinator($tenant, $area, 'editor');
    config(['enterprise.writes_enabled' => false]);
    expect(app(ReportExport::class)->build($tenant, $owner, reportInput($area))['current_records'])->not->toBeEmpty();
    expect(app(ReportExport::class)->build($tenant, $coordinator, reportInput($area))['current_records'])->not->toBeEmpty();
    $this->actingAs($editor)->getJson(route('enterprise.reports.quarterly', ['tenant' => $tenant->slug, ...reportInput($area)]))->assertForbidden();
    DB::table('team_area_members')->where('tenant_id', $tenant->id)->where('user_id', $coordinator->id)->update(['role' => 'viewer']);
    $this->actingAs($coordinator)->getJson(route('enterprise.reports.quarterly.csv', ['tenant' => $tenant->slug, ...reportInput($area)]))->assertForbidden();
    $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    $this->actingAs($owner)->getJson(route('enterprise.reports.quarterly.json', ['tenant' => $tenant->slug, ...reportInput($area)]))->assertForbidden();
});

test('report records and audit actions exclude private projects other departments tenants financial certificates and raw payloads', function (): void {
    [$tenant, $owner, $area, $project] = reportFixture();
    $coordinator = reportCoordinator($tenant, $area);
    $visible = reportCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'expected_version' => 0,
        'title' => 'Readable intake', 'description' => 'HIDDEN DESCRIPTION', 'location' => 'HIDDEN LOCATION']);
    reportCommand($tenant, $owner, ['action' => 'request.triage', 'id' => $visible['id'], 'area_id' => $area, 'expected_version' => 1, 'note' => 'HIDDEN TRIAGE NOTE']);
    $private = reportWork($tenant, $owner, ['kind' => 'project.create', 'name' => 'HIDDEN PRIVATE PROJECT', 'privacy' => 'private', 'enterprise_area_id' => $area]);
    reportWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'HIDDEN PRIVATE TASK', 'memberships' => [$private['id'] => []]]);
    $otherArea = reportCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'HIDDEN DEPARTMENT', 'visibility' => 'department_only'])['id'];
    reportCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $otherArea, 'expected_version' => 0, 'title' => 'HIDDEN OTHER REQUEST']);
    foreach (['ent_cost', 'ent_ops_review', 'ent_certificate', 'ent_insurance', 'ent_document'] as $kind) {
        TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'kind' => $kind,
            'title' => 'HIDDEN '.$kind, 'status' => 'active', 'version' => 1, 'visibility' => 'area', 'source_key' => hash('sha256', $kind), 'data' => ['secret' => 'HIDDEN RAW PAYLOAD']]);
    }
    $report = app(ReportExport::class)->build($tenant, $coordinator, reportInput($area));
    expect(array_column($report['recorded_events'], 'action'))->toContain('request.create', 'request.triage');
    expect(json_encode($report))->not->toContain('HIDDEN', $private['id']);
    expect($report['coverage']['notice'])->toContain('not automatically anonymized');
    $this->actingAs($coordinator)->getJson(route('enterprise.reports.quarterly', ['tenant' => $tenant->slug, ...reportInput($otherArea)]))->assertNotFound();
    $this->getJson(route('enterprise.reports.quarterly', ['tenant' => $tenant->slug, ...reportInput($area, ['project_id' => $private['id']])]))->assertNotFound();
    [$foreign, $foreignOwner] = reportFixture();
    $this->actingAs($foreignOwner)->getJson(route('enterprise.reports.quarterly.json', ['tenant' => $tenant->slug, ...reportInput($area)]))->assertForbidden();
    // Even managers do not get the unsupported finance/certificate/private-quality lanes in this export.
    expect(json_encode(app(ReportExport::class)->build($tenant, $owner, reportInput($area))))->not->toContain('HIDDEN RAW PAYLOAD', 'HIDDEN ent_');
    Http::assertNothingSent();
});

test('a current tenant viewer with stale area coordinator membership can read shared work but cannot export JSON or CSV', function (): void {
    [$tenant, $owner, $area] = reportFixture();
    $coordinator = reportCoordinator($tenant, $area);
    DB::table('team_areas')->where('id', $area)->update(['profile' => 'enterprise_shared']);
    $params = ['tenant' => $tenant->slug, ...reportInput($area)];
    $this->actingAs($coordinator)->getJson(route('enterprise.reports.quarterly', $params))->assertOk();
    $tenant->users()->updateExistingPivot($coordinator->id, ['role' => 'viewer']);
    $this->getJson(route('enterprise.data', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk();
    foreach (['enterprise.reports.quarterly', 'enterprise.reports.quarterly.csv', 'enterprise.reports.quarterly.json'] as $route) {
        $this->getJson(route($route, $params))->assertForbidden();
    }
});

test('fresh downloads use current records permissions sample configuration and safe CSV cells without source payloads', function (): void {
    [$tenant, $owner, $area, $project] = reportFixture();
    foreach (['=SUM(1,2)', '+cmd', '-1+2', '@SUM(A1)', "\u{00A0}=SUM(1,2)"] as $title) {
        reportWork($tenant, $owner, ['kind' => 'task.create', 'title' => $title, 'description' => 'HIDDEN BODY', 'memberships' => [$project => []]]);
    }
    $params = ['tenant' => $tenant->slug, ...reportInput($area), 'sample_data' => false, 'as_of' => '2000-01-01', 'rows' => ['HIDDEN CLIENT ROW']];
    $preview = $this->actingAs($owner)->getJson(route('enterprise.reports.quarterly', $params))->assertOk()->json();
    expect($preview['sample_data'])->toBeTrue()->and($preview['generated_at_utc'])->toStartWith('2026-10-22');
    $response = $this->get(route('enterprise.reports.quarterly.csv', $params))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    expect($response->headers->get('Cache-Control'))->toContain('private', 'no-store');
    expect($response->getContent())->not->toContain('HIDDEN');
    $stream = fopen('php://temp', 'w+');
    fwrite($stream, $response->getContent());
    rewind($stream);
    $head = fgetcsv($stream, 0, ',', '"', '');
    $rows = [];
    while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
        $rows[] = array_combine($head, $row);
    }
    fclose($stream);
    $tasks = array_values(array_filter($rows, fn ($row) => $row['row_type'] === 'current_record' && $row['entity_type'] === 'task'));
    expect($tasks)->toHaveCount(5);
    foreach ($tasks as $task) {
        expect($task['title'])->toStartWith("'");
        expect($task['coverage'])->toContain('request_scan_limit', 'Not a quarter-end snapshot');
    }
    $this->travelTo(new DateTimeImmutable('2026-10-22T16:00:00Z'));
    reportCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'expected_version' => 0, 'title' => 'Added after preview']);
    config(['enterprise.sample_data' => false]);
    $new = $this->getJson(route('enterprise.reports.quarterly.json', $params))->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="everbranch-period-activity.json"')->json();
    expect($new['generated_at_utc'])->not->toBe($preview['generated_at_utc']);
    expect($new['sample_data'])->toBeFalse();
    expect(array_column($new['current_records'], 'title'))->toContain('Added after preview');
    Http::assertNothingSent();
});

test('invalid and oversized windows missing timezone and disabled reports fail closed', function (): void {
    [$tenant, $owner, $area] = reportFixture();
    foreach ([['timezone' => 'Invented/Eastern'], ['quarter' => 0], ['year' => 1999], ['mode' => 'fiscal_quarter'],
        ['mode' => 'date_range', 'start_date' => '2026-02-30', 'end_date' => '2026-03-01'],
        ['mode' => 'date_range', 'start_date' => '2026-02-02', 'end_date' => '2026-02-01'],
        ['mode' => 'date_range', 'start_date' => '2024-01-01', 'end_date' => '2026-01-01']] as $extra) {
        $this->actingAs($owner)->getJson(route('enterprise.reports.quarterly', ['tenant' => $tenant->slug, ...reportInput($area, $extra)]))->assertUnprocessable();
    }
    $missing = reportInput($area);
    unset($missing['timezone']);
    $this->getJson(route('enterprise.reports.quarterly', ['tenant' => $tenant->slug, ...$missing]))->assertUnprocessable();
    config(['enterprise.reports_enabled' => false]);
    $this->getJson(route('enterprise.reports.quarterly.csv', ['tenant' => $tenant->slug, ...reportInput($area)]))->assertNotFound();
});

test('authorized record and event limits are explicit and hidden private rows cannot consume the visible quota', function (): void {
    [$tenant, $owner, $area, $project] = reportFixture();
    $coordinator = reportCoordinator($tenant, $area);
    $source = DB::table('team_projects')->where('id', $project)->first();
    $base = json_decode($source->payload, true);
    foreach (range(1, 105) as $index) {
        $id = (string) Str::uuid();
        $payload = array_replace($base, ['id' => $id, 'name' => 'HIDDEN PRIVATE '.$index, 'privacy' => 'private']);
        DB::table('team_projects')->insert(['id' => $id, 'tenant_id' => $tenant->id, 'owner_user_id' => $owner->id, 'name' => $payload['name'],
            'privacy' => 'private', 'archived' => false, 'version' => 1, 'payload' => json_encode($payload)]);
    }
    $task = reportWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Included current task', 'memberships' => [$project => []]]);
    $payload = ['tenant_id' => (string) $tenant->id, 'entity_type' => 'task', 'entity_id' => $task['id'], 'kind' => 'task.complete', 'at' => now()->toIso8601String(), 'fields' => ['HIDDEN RAW FIELD']];
    foreach (array_chunk(array_fill(0, 1005, ['tenant_id' => $tenant->id, 'entity_type' => 'task', 'entity_id' => $task['id'], 'payload' => json_encode($payload)]), 100) as $batch) {
        DB::table('team_work_events')->insert($batch);
    }
    $report = app(ReportExport::class)->build($tenant, $coordinator, reportInput($area));
    expect($report['current_records'])->toHaveCount(2);
    expect($report['coverage']['current_records']['visible_candidates_in_checked_sources']['project'])->toBe(1);
    expect($report['recorded_events'])->toHaveCount(1000);
    expect($report['coverage']['recorded_events']['omitted_matching_events'])->toBeGreaterThan(0);
    expect(json_encode($report))->not->toContain('HIDDEN');
    expect($report['basis']['recorded_events'])->toContain('completion or reopening');
    expect(array_column($report['recorded_events'], 'version'))->toBe(array_fill(0, 1000, null));
});

test('permissions revoked during projection prevent any previously assembled private export from being returned', function (): void {
    [$tenant, $owner, $area] = reportFixture();
    $coordinator = reportCoordinator($tenant, $area);
    $request = reportCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'expected_version' => 0, 'title' => 'Revocation fixture']);
    $changed = false;
    TeamOperationRecord::retrieved(function ($row) use ($coordinator, $tenant, &$changed): void {
        if ($changed || $row->kind !== 'enterprise_request') {
            return;
        }
        $changed = true;
        DB::table('tenant_user')->where('tenant_id', $tenant->id)->where('user_id', $coordinator->id)->update(['membership_active' => false]);
    });
    $this->actingAs($coordinator)->getJson(route('enterprise.reports.quarterly.json', ['tenant' => $tenant->slug, ...reportInput($area)]))->assertForbidden();
    expect($changed)->toBeTrue();
});

test('a task moved to another readable project during generation cannot escape the selected project scope', function (): void {
    [$tenant, $owner, $area, $project] = reportFixture();
    $other = reportCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Other readable project', 'area_id' => $area])['id'];
    $task = reportWork($tenant, $owner, ['kind' => 'task.create', 'title' => 'Moved during projection', 'memberships' => [$project => []]]);
    reportCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'expected_version' => 0, 'title' => 'Race synchronization fixture']);
    $moved = false;
    TeamOperationRecord::retrieved(function ($row) use ($tenant, $owner, $task, $project, $other, &$moved): void {
        if ($moved || $row->kind !== 'enterprise_request') {
            return;
        }
        $moved = true;
        reportWork($tenant, $owner, ['kind' => 'task.membership', 'id' => $task['id'], 'expected_version' => 1,
            'project_id' => $other, 'acknowledge_sharing' => true]);
        reportWork($tenant, $owner, ['kind' => 'task.membership', 'id' => $task['id'], 'expected_version' => 2,
            'project_id' => $project, 'remove' => true]);
    });
    $this->actingAs($owner)->getJson(route('enterprise.reports.quarterly.json', ['tenant' => $tenant->slug, ...reportInput($area, ['project_id' => $project])]))->assertNotFound();
    expect($moved)->toBeTrue();
    $fresh = app(TeamWorkRepository::class)->state($tenant->id);
    expect($fresh['tasks'][$task['id']]['memberships'])->toHaveKey($other)->not->toHaveKey($project);
});

test('request links changed during generation recheck selected project and current linked project permissions', function (bool $scoped): void {
    [$tenant, $owner, $area, $project] = reportFixture();
    $coordinator = reportCoordinator($tenant, $area);
    $other = reportWork($tenant, $owner, ['kind' => 'project.create', 'name' => 'Changed link target',
        'privacy' => $scoped ? 'workspace' : 'private', 'enterprise_area_id' => $area]);
    $request = reportCommand($tenant, $owner, ['action' => 'request.create', 'area_id' => $area, 'expected_version' => 0, 'title' => 'Relinked request fixture']);
    reportCommand($tenant, $owner, ['action' => 'request.triage', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => 1]);
    reportCommand($tenant, $owner, ['action' => 'request.assign', 'id' => $request['id'], 'area_id' => $area, 'expected_version' => 2,
        'project_id' => $project, 'assignee_id' => $owner->id]);
    $changed = false;
    TeamOperationRecord::retrieved(function ($row) use ($request, $other, &$changed): void {
        if ($changed || $row->id !== $request['id']) {
            return;
        }
        $changed = true;
        // Simulate an internal concurrent relink without modifying the already-read instance.
        $copy = clone $row;
        $copy->data = array_replace($row->data, ['project_id' => $other['id']]);
        $copy->save();
    });
    $input = reportInput($area, $scoped ? ['project_id' => $project] : []);
    $this->actingAs($coordinator)->getJson(route('enterprise.reports.quarterly.csv', ['tenant' => $tenant->slug, ...$input]))->assertNotFound();
    expect($changed)->toBeTrue();
})->with(['selected project changed' => true, 'unscoped link now private' => false]);
