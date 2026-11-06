<?php

use App\Services\CampusFacilities\DemoStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->opsDirectory = sys_get_temp_dir().'/lmc-ops-'.Str::uuid();
    config()->set([
        'campus_facilities.enabled' => true, 'campus_facilities.storage_path' => $this->opsDirectory,
        'enterprise.reviewer_demo_enabled' => false, 'tenancy.domains.canonical.landlord_host' => 'app.theeverbranch.com',
        'tenancy.landlord.primary_host' => 'app.theeverbranch.com', 'tenancy.landlord.hosts' => ['app.theeverbranch.com'],
        'tenancy.auth.flagship_hosts' => ['app.theeverbranch.com', 'theeverbranch.com'],
    ]);
    $this->opsBase = 'https://app.theeverbranch.com/platform/demos/lees-mcrae/api';
    $this->withHeader('Origin', 'https://app.theeverbranch.com');
    Carbon::setTestNow(Carbon::parse('2026-11-05 10:00:00', 'America/New_York'));
    CarbonImmutable::setTestNow(Carbon::now());
    Http::preventStrayRequests();
});

afterEach(function (): void {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    File::deleteDirectory($this->opsDirectory);
});

function campusOpsSnapshot($test): array
{
    return $test->getJson($test->opsBase.'/operations')->assertOk()->json();
}

function campusOpsRequest($test, string $action, string $recordId, int $revision, array $data = [], string $actor = 'admin', string $role = 'admin', ?string $requestId = null, ?string $generation = null)
{
    $generation ??= campusOpsSnapshot($test)['generation'];

    return $test->postJson($test->opsBase.'/operations', ['id' => $requestId ?? (string) Str::uuid(), 'generation' => $generation, 'actor' => $actor, 'role' => $role, 'action' => $action, 'recordId' => $recordId, 'expectedRevision' => $revision, 'data' => $data]);
}

function campusOpsRow(array $workspace, string $group, string $id): array
{
    return collect($workspace[$group])->firstWhere('id', $id);
}

it('upgrades a prior workspace additively and never exposes unrelated registrations or replaces tickets', function (): void {
    $store = app(DemoStore::class);
    $old = $store->seed();
    unset($old['operations']);
    foreach (array_keys($old['tickets']) as $id) {
        if (str_starts_with($id, 'FM-DEMO-')) {
            unset($old['tickets'][$id], $old['request_keys']['seed-'.$id]);
        }
    }
    $old['participants'] = ['opaque' => ['email' => 'PRIVATE-SENTINEL']];
    $old['usage'] = ['2026-11-05' => 17];
    File::put($store->directory().'/workspace.json', json_encode($old, JSON_THROW_ON_ERROR));
    $response = $this->getJson($this->opsBase.'/operations')->assertOk()->assertDontSee('PRIVATE-SENTINEL');
    expect($response->json('settings.roles'))->toHaveCount(10);
    $saved = $store->access(fn (&$state) => $state, false);
    foreach ($old['tickets'] as $id => $ticket) {
        expect($saved['tickets'][$id])->toBe($ticket);
    }
    expect(count($saved['tickets']))->toBe(count($old['tickets']) + 3)
        ->and($saved['participants'])->toBe($old['participants'])
        ->and($saved['usage'])->toBe($old['usage']);
    campusOpsSnapshot($this);
    expect($store->access(fn (&$state) => count($state['tickets']), false))->toBe(count($old['tickets']) + 3);
});

it('persists editable rules with current permissions, exact replay and stale-setting rejection', function (): void {
    $workspace = campusOpsSnapshot($this);
    $settings = $workspace['settings'];
    $settings['bufferMinutes'] = 25;
    $settings['certificateWarningDays'] = 45;
    $settings['reportColumns'] = ['work_orders', 'completed', 'teams'];
    $settings['roles'][1]['name'] = 'Field technician';
    $requestId = (string) Str::uuid();
    campusOpsRequest($this, 'settings.save', 'settings', 1, $settings, requestId: $requestId)->assertOk()->assertJsonPath('workspace.settings.revision', 2);
    campusOpsRequest($this, 'settings.save', 'settings', 1, $settings, requestId: $requestId)->assertOk()->assertJsonPath('result.replayed', true);
    campusOpsRequest($this, 'settings.save', 'settings', 1, $settings)->assertConflict();
    campusOpsRequest($this, 'settings.save', 'settings', 2, $settings, 'alex', 'staff')->assertForbidden();
    expect(campusOpsSnapshot($this)['settings']['bufferMinutes'])->toBe(25);
    $settings['bufferMinutes'] = 26;
    campusOpsRequest($this, 'settings.save', 'settings', 1, $settings, requestId: $requestId)->assertConflict();
});

it('rejects broken role dependencies and unresolved workflow owners before saving settings', function (): void {
    $settings = campusOpsSnapshot($this)['settings'];
    $settings['roles'][5]['permissions'] = array_values(array_diff($settings['roles'][5]['permissions'], ['finance.view']));
    campusOpsRequest($this, 'settings.save', 'settings', 1, $settings)->assertUnprocessable();
    $settings = campusOpsSnapshot($this)['settings'];
    $settings['workApproval'][] = ['id' => 'finance-work', 'name' => 'Additional review', 'role' => 'finance'];
    campusOpsRequest($this, 'settings.save', 'settings', 1, $settings)->assertUnprocessable();
    expect(campusOpsSnapshot($this)['settings']['revision'])->toBe(1);
});

it('uses the configured work approval chain and refuses the wrong next reviewer', function (): void {
    $settings = campusOpsSnapshot($this)['settings'];
    $settings['roles'][5]['permissions'][] = 'work.approve';
    $settings['workApproval'][] = ['id' => 'finance-work', 'name' => 'Finance coordination', 'role' => 'finance'];
    campusOpsRequest($this, 'settings.save', 'settings', 1, $settings)->assertOk();
    $fields = ['requester' => 'Alex Morgan', 'requesterId' => 'alex', 'department' => 'Academics', 'contact' => 'alex@example.invalid', 'location' => 'Bowman Building', 'room' => '203', 'issue' => 'Example lamp needs a review.', 'onset' => 'This morning', 'impact' => 'Classroom users', 'hazards' => 'No exposed wiring', 'access' => 'After class', 'category' => 'Maintenance'];
    $ticket = $this->postJson($this->opsBase.'/tickets', ['role' => 'staff', 'actor' => 'alex', 'action' => 'create', 'requestKey' => (string) Str::uuid(), 'fields' => $fields, 'photos' => []])->assertOk()->json();
    $first = $this->postJson($this->opsBase.'/tickets', ['role' => 'head', 'actor' => 'morgan', 'action' => 'approve', 'id' => $ticket['id'], 'revision' => 1, 'data' => []])->assertOk()->assertJsonPath('status', 'Pending approval')->json();
    $this->postJson($this->opsBase.'/tickets', ['role' => 'head', 'actor' => 'morgan', 'action' => 'approve', 'id' => $ticket['id'], 'revision' => $first['revision'], 'data' => []])->assertForbidden();
    $this->postJson($this->opsBase.'/tickets', ['role' => 'admin', 'actor' => 'jamie', 'action' => 'approve', 'id' => $ticket['id'], 'revision' => $first['revision'], 'data' => []])->assertOk()->assertJsonPath('status', 'Approved')->assertJsonCount(2, 'approvalDecisions');
});

it('retains a crew checkpoint through emergency work without closing or automatically resuming the original job', function (): void {
    campusOpsRequest($this, 'crew.start', 'plan-hvac', 1)->assertOk();
    campusOpsRequest($this, 'crew.start', 'plan-leak', 1)->assertUnprocessable();
    $tickets = $this->getJson($this->opsBase.'/tickets')->assertOk()->json();
    $leak = collect($tickets)->firstWhere('id', 'FM-1048');
    $this->postJson($this->opsBase.'/tickets', ['role' => 'head', 'actor' => 'morgan', 'action' => 'approve', 'id' => $leak['id'], 'revision' => $leak['revision'], 'data' => []])->assertOk();
    campusOpsRequest($this, 'crew.start', 'plan-leak', 1)->assertUnprocessable();
    campusOpsRequest($this, 'crew.pause', 'plan-hvac', 2, ['checkpoint' => 'Power isolated and tools secured.', 'nextAction' => 'Resume the HVAC diagnosis after the leak response.', 'emergencyTicketId' => 'FM-1048'])->assertOk();
    campusOpsRequest($this, 'crew.start', 'plan-leak', 1)->assertOk();
    campusOpsRequest($this, 'crew.resume', 'plan-hvac', 3)->assertUnprocessable();
    $tickets = $this->getJson($this->opsBase.'/tickets')->assertOk()->json();
    $leak = collect($tickets)->firstWhere('id', 'FM-1048');
    $work = ['summary' => 'Isolated and repaired the example sink leak.', 'minutes' => 25, 'materials' => 'Example gasket', 'outcome' => 'Leak stopped', 'remaining' => 'None'];
    $this->postJson($this->opsBase.'/tickets', ['role' => 'technician', 'actor' => 'devon', 'action' => 'complete', 'id' => $leak['id'], 'revision' => $leak['revision'], 'data' => ['work' => $work]])->assertOk()->assertJsonPath('status', 'Completed');
    $state = app(DemoStore::class)->access(fn (&$state) => $state, false);
    expect($state['tickets']['FM-1045']['status'])->toBe('In progress')
        ->and($state['tickets']['FM-1045']['operations']['status'])->toBe('Paused');
    campusOpsRequest($this, 'crew.resume', 'plan-hvac', 3)->assertOk();
    expect(campusOpsRow(campusOpsSnapshot($this), 'crewPlans', 'plan-hvac')['checkpoint'])->toBe('Power isolated and tools secured.');
});

it('requires a reviewed conflict-free time and leaves the other activity untouched', function (): void {
    $workspace = campusOpsSnapshot($this);
    $athletics = campusOpsRow($workspace, 'bookings', 'booking-athletics');
    campusOpsRequest($this, 'booking.confirm', 'booking-work', 1, ['resolution' => 'Review the proposed audio window.'], 'ellis')->assertUnprocessable();
    $booking = campusOpsRow($workspace, 'bookings', 'booking-work');
    $data = array_intersect_key($booking, array_flip(['title', 'building', 'startsAt', 'endsAt', 'kind', 'owner', 'ticketId', 'resolution']));
    $data['startsAt'] = '2026-11-06T17:00';
    $data['endsAt'] = '2026-11-06T18:00';
    $data['resolution'] = 'Agreed with the coordinator after athletics practice.';
    campusOpsRequest($this, 'booking.save', 'booking-work', 1, $data, 'morgan', 'head')->assertOk();
    $requestId = (string) Str::uuid();
    campusOpsRequest($this, 'booking.confirm', 'booking-work', 2, ['resolution' => $data['resolution']], 'ellis', requestId: $requestId)->assertOk();
    campusOpsRequest($this, 'booking.confirm', 'booking-work', 2, ['resolution' => $data['resolution']], 'ellis', requestId: $requestId)->assertOk()->assertJsonPath('result.replayed', true);
    expect(campusOpsRow(campusOpsSnapshot($this), 'bookings', 'booking-athletics'))->toBe($athletics);
});

it('checks fund purpose and current certificates, follows finance routing and commits an allocation only once', function (): void {
    $workspace = campusOpsSnapshot($this);
    $invoice = campusOpsRow($workspace, 'invoices', 'invoice-hvac');
    $draft = array_intersect_key($invoice, array_flip(['number', 'projectId', 'vendorId', 'fundId', 'amountCents', 'description', 'reference']));
    $draft['fundId'] = 'fund-access';
    campusOpsRequest($this, 'invoice.save', 'invoice-hvac', 1, $draft, 'morgan', 'head')->assertOk();
    campusOpsRequest($this, 'invoice.submit', 'invoice-hvac', 2, actor: 'morgan', role: 'head')->assertUnprocessable();
    $draft['fundId'] = 'fund-operations';
    campusOpsRequest($this, 'invoice.save', 'invoice-hvac', 2, $draft, 'morgan', 'head')->assertOk();
    campusOpsRequest($this, 'invoice.submit', 'invoice-hvac', 3, actor: 'morgan', role: 'head')->assertOk();
    $decision = ['decision' => 'approve', 'reason' => 'Example scope and evidence checked.'];
    campusOpsRequest($this, 'invoice.review', 'invoice-hvac', 4, $decision, 'jamie')->assertForbidden();
    campusOpsRequest($this, 'invoice.review', 'invoice-hvac', 4, $decision, 'morgan', 'head')->assertOk();
    $requestId = (string) Str::uuid();
    campusOpsRequest($this, 'invoice.review', 'invoice-hvac', 5, $decision, 'jamie', requestId: $requestId)->assertOk()->assertJsonPath('workspace.fundBalances.fund-operations.committedCents', 240000);
    campusOpsRequest($this, 'invoice.review', 'invoice-hvac', 5, $decision, 'jamie', requestId: $requestId)->assertOk()->assertJsonPath('result.replayed', true);
    campusOpsRequest($this, 'invoice.record', 'invoice-hvac', 6, ['reference' => 'Demo check EX-104 recorded.'], 'jamie')->assertOk()->assertJsonPath('workspace.fundBalances.fund-operations.committedCents', 240000);
    expect(campusOpsRow(campusOpsSnapshot($this), 'invoices', 'invoice-hvac')['decisions'])->toHaveCount(2);
});

it('retains accepted insurance through replacement review and records a daily reminder without delivery', function (): void {
    $old = campusOpsRow(campusOpsSnapshot($this), 'certificates', 'cert-av');
    campusOpsRequest($this, 'certificate.remind', 'cert-av', 1, actor: 'mika')->assertOk()->assertJsonCount(1, 'workspace.notifications');
    campusOpsRequest($this, 'certificate.remind', 'cert-av', 1, actor: 'mika')->assertOk()->assertJsonCount(1, 'workspace.notifications');
    campusOpsRequest($this, 'certificate.propose', 'cert-av', 1, ['vendorId' => 'vendor-av', 'type' => 'General liability', 'expiresOn' => '2027-11-01', 'reference' => 'Replacement example certificate AV-2027'], 'mika')->assertOk();
    expect(campusOpsRow(campusOpsSnapshot($this), 'certificates', 'cert-av')['expiresOn'])->toBe($old['expiresOn']);
    campusOpsRequest($this, 'certificate.review', 'cert-av', 2, ['decision' => 'approve', 'reason' => 'Replacement metadata reviewed for the demo.'], 'jamie')->assertForbidden();
    campusOpsRequest($this, 'certificate.review', 'cert-av', 2, ['decision' => 'approve', 'reason' => 'Replacement metadata reviewed for the demo.'], 'mika')->assertOk();
    campusOpsRequest($this, 'certificate.remind', 'cert-av', 3, actor: 'mika')->assertUnprocessable();
    expect(campusOpsRow(campusOpsSnapshot($this), 'certificates', 'cert-av')['expiresOn'])->toBe('2027-11-01');
    Http::assertNothingSent();
});

it('limits report sections by role and retains a fresh reviewed snapshot without a submission', function (): void {
    $period = ['start' => '2026-10-01', 'end' => '2026-12-31'];
    $preview = $this->getJson($this->opsBase.'/operations/report?'.http_build_query([...$period, 'actor' => 'sage', 'role' => 'admin']))->assertOk()->json();
    $limited = $this->getJson($this->opsBase.'/operations/report?'.http_build_query([...$period, 'actor' => 'ellis', 'role' => 'admin']))->assertOk()->json();
    expect($limited['omittedColumns'])->toBe(['finance', 'certificates']);
    $data = [...$period, 'digest' => $preview['digest'], 'title' => 'Example quarterly operations report', 'note' => 'Reviewed the fictional source records and reporting window.'];
    campusOpsRequest($this, 'report.review', 'report-quarter', 0, $data, 'sage')->assertOk();
    $frozen = $this->getJson($this->opsBase.'/operations/report?'.http_build_query(['reportId' => 'report-quarter', 'actor' => 'sage', 'role' => 'admin']))->assertOk()->json();
    expect($frozen)->toBe($preview);
    $this->getJson($this->opsBase.'/operations/report?'.http_build_query(['reportId' => 'report-quarter', 'actor' => 'ellis', 'role' => 'admin']))->assertForbidden();
    $this->get($this->opsBase.'/operations/report?'.http_build_query(['reportId' => 'report-quarter', 'actor' => 'sage', 'role' => 'admin', 'format' => 'csv']))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertSee('fictional demo report');
    Http::assertNothingSent();
});

it('uses customized roles and people for existing work and rejects unknown settings references', function (): void {
    $settings = campusOpsSnapshot($this)['settings'];
    $settings['roles'][] = ['id' => 'observer', 'name' => 'Campus observer', 'baseRole' => 'admin', 'enabled' => true, 'permissions' => ['work.view', 'schedule.view']];
    $settings['people'][] = ['id' => 'lee', 'name' => 'Lee Harper', 'initials' => 'LH', 'profile' => 'observer', 'team' => 'Maintenance', 'department' => 'Campus Operations', 'enabled' => true];
    $settings['shortcuts']['observer'] = ['workload', 'schedule'];
    campusOpsRequest($this, 'settings.save', 'settings', 1, $settings)->assertOk();
    $this->postJson($this->opsBase.'/tickets', ['actor' => 'lee', 'role' => 'admin', 'action' => 'priority', 'id' => 'FM-1045', 'revision' => 1, 'data' => ['priority' => 'Urgent']])->assertForbidden();
    $settings = campusOpsSnapshot($this)['settings'];
    $settings['people'][0]['team'] = 'Unknown team';
    campusOpsRequest($this, 'settings.save', 'settings', 2, $settings)->assertUnprocessable();
    $settings = campusOpsSnapshot($this)['settings'];
    $sam = array_search('sam', array_column($settings['people'], 'id'));
    $settings['people'][$sam]['team'] = 'IT';
    campusOpsRequest($this, 'settings.save', 'settings', 2, $settings)->assertUnprocessable();
});

it('retains three-step high-value reviews, rechecks changed funding and preserves reopened evidence', function (): void {
    campusOpsSnapshot($this);
    campusOpsRequest($this, 'invoice.submit', 'invoice-access', 1, actor: 'morgan', role: 'head')->assertOk()->assertJsonCount(3, 'workspace.invoices.1.route');
    $decision = ['decision' => 'approve', 'reason' => 'Reviewed the example invoice and its restricted purpose.'];
    campusOpsRequest($this, 'invoice.review', 'invoice-access', 2, $decision, 'morgan', 'head')->assertOk();
    campusOpsRequest($this, 'invoice.review', 'invoice-access', 3, $decision, 'jamie')->assertOk();
    $fund = campusOpsRow(campusOpsSnapshot($this), 'funds', 'fund-access');
    $data = array_intersect_key($fund, array_flip(['code', 'name', 'budgetCents', 'categories', 'restriction']));
    $data['budgetCents'] = 100;
    campusOpsRequest($this, 'fund.save', 'fund-access', 1, $data, 'jamie')->assertOk();
    campusOpsRequest($this, 'invoice.review', 'invoice-access', 4, $decision, 'quinn')->assertUnprocessable();
    $data['budgetCents'] = 1000000;
    campusOpsRequest($this, 'fund.save', 'fund-access', 2, $data, 'jamie')->assertOk();
    campusOpsRequest($this, 'invoice.review', 'invoice-access', 4, $decision, 'quinn')->assertOk()->assertJsonPath('workspace.fundBalances.fund-access.committedCents', 315000);
    campusOpsRequest($this, 'invoice.reopen', 'invoice-access', 5, ['reason' => 'Correct the example invoice reference before proceeding.'], 'jamie')->assertForbidden();
    campusOpsRequest($this, 'invoice.reopen', 'invoice-access', 5, ['reason' => 'Correct the example invoice reference before proceeding.'], 'quinn')->assertOk()->assertJsonPath('workspace.fundBalances.fund-access.committedCents', 0)->assertJsonCount(1, 'workspace.invoices.1.previousReviews');
    Http::assertNothingSent();
});

it('rejects stale report reviews and neutralizes spreadsheet formulas in exported evidence', function (): void {
    $period = ['start' => '2026-10-01', 'end' => '2026-12-31'];
    $url = $this->opsBase.'/operations/report?'.http_build_query([...$period, 'actor' => 'sage', 'role' => 'admin']);
    $preview = $this->getJson($url)->assertOk()->json();
    app(DemoStore::class)->access(function (&$state): void {
        $state['tickets']['FM-1045']['fields']['issue'] = ' =HYPERLINK("https://example.invalid")';
        $state['tickets']['FM-1045']['title'] = ' =HYPERLINK("https://example.invalid")';
    });
    campusOpsRequest($this, 'report.review', 'report-stale', 0, [...$period, 'digest' => $preview['digest'], 'title' => 'Reviewed sample quarter report', 'note' => 'Reviewed the source records for this example.'], 'sage')->assertConflict();
    $response = $this->get($url.'&format=csv')->assertOk();
    expect($response->getContent())->toContain("' =HYPERLINK");
    $this->getJson($url.'&start=2020-01-01')->assertUnprocessable();
    Http::assertNothingSent();
});

it('rejects commands from an old reset generation while retaining participants and usage', function (): void {
    $old = campusOpsSnapshot($this);
    app(DemoStore::class)->access(function (&$state): void {
        $state['usage']['fixture'] = 3;
        $state['participants']['sentinel'] = ['private' => 'retained'];
    });
    $this->postJson($this->opsBase.'/reset', ['role' => 'admin', 'actor' => 'admin', 'confirm' => true])->assertOk();
    campusOpsRequest($this, 'crew.start', 'plan-hvac', 1, generation: $old['generation'])->assertConflict();
    $state = app(DemoStore::class)->access(fn (&$state) => $state, false);
    expect($state['usage']['fixture'])->toBe(3)->and($state['participants']['sentinel']['private'])->toBe('retained')->and($state['operations']['generation'])->not->toBe($old['generation']);
});

it('upgrades contractor metadata once while retaining company edits and all prior shared state', function (): void {
    $store = app(DemoStore::class);
    $old = $store->seed();
    $old['operations']['schema'] = 1;
    unset($old['operations']['settings']['requireContractorInsurance']);
    foreach ($old['operations']['vendors'] as &$vendor) {
        foreach (['status', 'contactName', 'phone', 'coordinatorId', 'projectIds'] as $key) {
            unset($vendor[$key]);
        }
    }
    unset($vendor);
    $old['operations']['vendors'][0]['name'] = 'Retained company edit';
    $old['operations']['vendors'][0]['notes'] = 'Retained performance notes';
    $old['participants'] = ['private' => ['email' => 'PRIVATE-CONTRACTOR-SENTINEL']];
    $old['usage'] = ['2026-11-05' => 21];
    $old['mail_usage'] = ['2026-11-05' => 7];
    File::put($store->directory().'/workspace.json', json_encode($old, JSON_THROW_ON_ERROR));
    $response = $this->getJson($this->opsBase.'/operations')->assertOk()->assertDontSee('PRIVATE-CONTRACTOR-SENTINEL');
    expect($response->json('schema'))->toBe(2)
        ->and($response->json('settings.requireContractorInsurance'))->toBeTrue();
    $saved = $store->access(fn (&$state) => $state, false);
    foreach (['tickets', 'request_keys', 'participants', 'usage', 'mail_usage', 'attachments'] as $group) {
        expect($saved[$group])->toBe($old[$group]);
    }
    foreach (['certificates', 'invoices', 'receipts', 'history', 'projects', 'crewPlans', 'bookings', 'funds'] as $group) {
        expect($saved['operations'][$group])->toBe($old['operations'][$group]);
    }
    expect($saved['operations']['vendors'][0]['name'])->toBe('Retained company edit')
        ->and($saved['operations']['vendors'][0]['notes'])->toBe('Retained performance notes')
        ->and($saved['operations']['vendors'][0]['projectIds'])->toBe(['project-comfort']);
    campusOpsSnapshot($this);
    expect($store->access(fn (&$state) => $state, false))->toBe($saved);
});

it('assigns and removes contractors with exact receipts without changing the staff lead or status', function (): void {
    $store = app(DemoStore::class);
    $original = $store->access(fn (&$state) => $state['tickets']['FM-DEMO-ACCESS'], false);
    $data = ['vendorId' => 'vendor-access', 'scope' => 'Inspect accessible entry hardware', 'targetDate' => '2026-11-08'];
    $id = (string) Str::uuid();
    campusOpsRequest($this, 'contractor.assign', $original['id'], $original['revision'], $data, requestId: $id)->assertOk();
    campusOpsRequest($this, 'contractor.assign', $original['id'], $original['revision'], $data, requestId: $id)->assertOk()->assertJsonPath('result.replayed', true);
    campusOpsRequest($this, 'contractor.assign', $original['id'], $original['revision'], [...$data, 'scope' => 'Changed scope'], requestId: $id)->assertConflict();
    campusOpsRequest($this, 'contractor.remove', $original['id'], $original['revision'], ['reason' => 'A stale removal'])->assertConflict();
    $assigned = $store->access(fn (&$state) => $state['tickets'][$original['id']], false);
    expect($assigned['assignee'])->toBe($original['assignee'])->and($assigned['status'])->toBe($original['status'])
        ->and($assigned['revision'])->toBe($original['revision'] + 1)->and($assigned['events'])->toHaveCount(count($original['events']) + 1)
        ->and($assigned['contractor']['vendorId'])->toBe('vendor-access');
    $removeId = (string) Str::uuid();
    campusOpsRequest($this, 'contractor.remove', $original['id'], $assigned['revision'], ['reason' => 'Scope retained for campus staff'], requestId: $removeId)->assertOk();
    campusOpsRequest($this, 'contractor.remove', $original['id'], $assigned['revision'], ['reason' => 'Scope retained for campus staff'], requestId: $removeId)->assertOk()->assertJsonPath('result.replayed', true);
    $removed = $store->access(fn (&$state) => $state['tickets'][$original['id']], false);
    expect($removed)->not->toHaveKey('contractor');
    expect($removed['assignee'])->toBe($original['assignee'])->and($removed['status'])->toBe($original['status'])
        ->and($removed['events'][count($original['events'])]['message'])->toContain('Inspect accessible entry hardware')
        ->and($removed['events'][count($original['events']) + 1]['message'])->toContain('Scope retained for campus staff');
});

it('requires work assignment permission and service scope for both contractor actions', function (): void {
    $data = ['vendorId' => 'vendor-access', 'scope' => 'Inspect entry hardware'];
    foreach ([['alex', 'staff'], ['devon', 'technician'], ['mika', 'admin'], ['avery', 'head']] as [$actor, $role]) {
        campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-ACCESS', 1, $data, $actor, $role)->assertForbidden();
        campusOpsRequest($this, 'contractor.remove', 'FM-DEMO-ACCESS', 1, ['reason' => 'Wrong role cannot remove'], $actor, $role)->assertForbidden();
    }
    campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-ACCESS', 1, $data, 'morgan', 'head')->assertOk();
    campusOpsRequest($this, 'contractor.remove', 'FM-DEMO-ACCESS', 2, ['reason' => 'Responsible head removes'], 'morgan', 'head')->assertOk();
});

it('requires accepted current insurance and active status for new assignments while retaining history', function (): void {
    $data = ['vendorId' => 'vendor-av', 'scope' => 'Inspect the event sound system'];
    campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-GYM', 1, $data)->assertUnprocessable();
    campusOpsRequest($this, 'certificate.propose', 'cert-av', 1, ['vendorId' => 'vendor-av', 'type' => 'General liability', 'expiresOn' => '2027-11-01', 'reference' => 'Fictional renewed policy'])->assertOk();
    campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-GYM', 1, $data)->assertUnprocessable();
    campusOpsRequest($this, 'certificate.review', 'cert-av', 2, ['decision' => 'approve', 'reason' => 'Renewed policy reviewed'])->assertOk();
    campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-GYM', 1, $data)->assertOk();
    $vendor = campusOpsRow(campusOpsSnapshot($this), 'vendors', 'vendor-av');
    $form = array_intersect_key($vendor, array_flip(['name', 'trade', 'rating', 'notes', 'contact']));
    foreach (['On hold', 'Archived'] as $status) {
        campusOpsRequest($this, 'vendor.save', $vendor['id'], $vendor['revision'], [...$form, 'status' => $status])->assertOk();
        $vendor = campusOpsRow(campusOpsSnapshot($this), 'vendors', 'vendor-av');
        campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-IT', 1, $data)->assertUnprocessable();
    }
    $ticket = app(DemoStore::class)->access(fn (&$state) => $state['tickets']['FM-DEMO-GYM'], false);
    expect($ticket['contractor']['vendorId'])->toBe('vendor-av');
    campusOpsRequest($this, 'contractor.remove', 'FM-DEMO-GYM', 2, ['reason' => 'Contractor archived after review'])->assertOk();
    expect(campusOpsRow(campusOpsSnapshot($this), 'vendors', 'vendor-av')['status'])->toBe('Archived');
});

it('supports the independent assignment insurance setting and preserves extended fields from older forms', function (): void {
    $w = campusOpsSnapshot($this);
    $settings = $w['settings'];
    $settings['requireContractorInsurance'] = false;
    campusOpsRequest($this, 'settings.save', 'settings', $settings['revision'], $settings)->assertOk();
    campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-GYM', 1, ['vendorId' => 'vendor-av', 'scope' => 'A reviewed insurance-rule exception'])->assertOk();
    $settings = campusOpsSnapshot($this)['settings'];
    unset($settings['requireContractorInsurance']);
    campusOpsRequest($this, 'settings.save', 'settings', $settings['revision'], $settings)->assertOk();
    expect(campusOpsSnapshot($this)['settings']['requireContractorInsurance'])->toBeFalse()
        ->and(campusOpsSnapshot($this)['settings']['requireValidCertificate'])->toBeTrue();
    $vendor = campusOpsRow(campusOpsSnapshot($this), 'vendors', 'vendor-mechanical');
    $form = array_intersect_key($vendor, array_flip(['name', 'trade', 'rating', 'notes', 'contact']));
    campusOpsRequest($this, 'vendor.save', $vendor['id'], 1, [...$form, 'rating' => 5], 'mika')->assertOk();
    campusOpsRequest($this, 'vendor.save', $vendor['id'], 1, $form, 'mika')->assertConflict();
    $saved = campusOpsRow(campusOpsSnapshot($this), 'vendors', $vendor['id']);
    foreach (['status', 'projectIds', 'coordinatorId', 'contactName', 'phone'] as $key) {
        expect($saved[$key])->toBe($vendor[$key]);
    }
    campusOpsRequest($this, 'vendor.save', $vendor['id'], 2, $form, 'morgan', 'head')->assertForbidden();
});

it('rejects contractor assignment to unapproved, closed or unowned work without changing a record', function (): void {
    $store = app(DemoStore::class);
    foreach (['Pending approval', 'Approved', 'Needs details', 'Completed', 'Rejected'] as $status) {
        $store->access(function (&$state) use ($status): void {
            $state['tickets']['FM-DEMO-ACCESS']['status'] = $status;
        });
        campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-ACCESS', 1, ['vendorId' => 'vendor-access', 'scope' => 'Inspect entry hardware'])->assertUnprocessable();
    }
    $store->access(function (&$state): void {
        $state['tickets']['FM-DEMO-ACCESS']['status'] = 'Assigned';
        $state['tickets']['FM-DEMO-ACCESS']['assignee'] = null;
    });
    campusOpsRequest($this, 'contractor.assign', 'FM-DEMO-ACCESS', 1, ['vendorId' => 'vendor-access', 'scope' => 'Inspect entry hardware'])->assertUnprocessable();
    $ticket = $store->access(fn (&$state) => $state['tickets']['FM-DEMO-ACCESS'], false);
    expect($ticket['revision'])->toBe(1)->and($ticket)->not->toHaveKey('contractor');
});
