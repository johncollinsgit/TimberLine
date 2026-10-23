<?php

use App\Enterprise\Briefing;
use App\Enterprise\BriefingPdf;
use App\Enterprise\Intelligence;
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
    config(['team_work.enabled' => true, 'enterprise.runtime' => true, 'enterprise.enabled' => true,
        'enterprise.writes_enabled' => true, 'enterprise.briefing_enabled' => true, 'enterprise.sample_data' => false]);
    Route::middleware('web')->prefix('enterprise-briefing-fixture')->group(base_path('routes/enterprise.php'));
    Route::getRoutes()->refreshNameLookups();
    Http::preventStrayRequests();
});

function briefingCommand($tenant, $user, array $input): array
{
    return app(RequestWorkflow::class)->execute($tenant, $user, $input + ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()]);
}

function briefingFixture(): array
{
    [$tenant, $owner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id]]);
    $area = briefingCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Illustrative campus services', 'visibility' => 'department_only'])['id'];
    $project = briefingCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Illustrative building inspection', 'area_id' => $area])['id'];

    return [$tenant, $owner, $area, $project];
}

function briefingGraph($tenant, $user, array $command): array
{
    return app(TeamWorkRepository::class)->execute($tenant->id, fn () => app(WorkspaceAccess::class)->actor($tenant, $user, true),
        $command + ['id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()])['result'];
}

test('briefing derives current authorized counts and fresh PDF without writes or provider calls', function (): void {
    [$tenant, $owner, $area, $project] = briefingFixture();
    $this->travelTo(now()->setDate(2026, 10, 22)->setTime(15, 0));
    $task = briefingGraph($tenant, $owner, ['kind' => 'task.create', 'title' => 'Check the illustrative lift', 'memberships' => [$project => []], 'due_on' => '2026-10-21']);
    briefingCommand($tenant, $owner, ['action' => 'request.create', 'title' => 'Illustrative floor repair', 'area_id' => $area, 'expected_version' => 0]);
    $before = [DB::table('team_work_events')->count(), DB::table('team_operation_revisions')->count(), DB::table('tenant_user')->count()];
    $url = route('enterprise.briefing', ['tenant' => $tenant->slug, 'area_id' => $area]);
    $json = $this->actingAs($owner)->getJson($url)->assertOk();
    expect($json->headers->get('Cache-Control'))->toContain('no-store', 'private');
    expect($json->json('counts'))->toBe(['projects' => 1, 'tasks' => 1, 'completed_tasks' => 0, 'overdue_tasks' => 1, 'open_requests' => 1]);
    expect($json->json('summary.mode'))->toBe('record_backed');
    expect($json->json('sample_data'))->toBeFalse();
    expect($json->json('coverage'))->toContain('not everything happening on campus');
    $pdf = $this->get(route('enterprise.briefing.pdf', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff')->getContent();
    expect($pdf)->toStartWith('%PDF-1.4')->toContain('/Count 1', '/MediaBox [0 0 612 792]', 'Illustrative building inspection', '/Subtype /Link', '2026-10-22T15:00:00');
    expect($before)->toBe([DB::table('team_work_events')->count(), DB::table('team_operation_revisions')->count(), DB::table('tenant_user')->count()]);
    briefingGraph($tenant, $owner, ['kind' => 'task.complete', 'id' => $task['id'], 'expected_version' => 1, 'completed' => true]);
    $this->travel(1)->minutes();
    $this->getJson($url)->assertOk()->assertJsonPath('counts.completed_tasks', 1)->assertJsonPath('counts.overdue_tasks', 0);
    $newPdf = $this->get(route('enterprise.briefing.pdf', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk()->getContent();
    expect($newPdf)->not->toBe($pdf)->toContain('1 / 1 tasks completed');
    Http::assertNothingSent();
});

test('briefing refuses cross tenant department guest and revoked membership and omits private work', function (): void {
    [$tenant, $owner, $area, $project] = briefingFixture();
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $member->id, 'role' => 'viewer']);
    $private = briefingGraph($tenant, $owner, ['kind' => 'project.create', 'name' => 'DO NOT LEAK PRIVATE PROJECT', 'privacy' => 'private', 'enterprise_area_id' => $area]);
    briefingGraph($tenant, $owner, ['kind' => 'task.create', 'title' => 'DO NOT LEAK PRIVATE TASK', 'memberships' => [$private['id'] => []], 'due_on' => '2020-01-01']);
    briefingGraph($tenant, $owner, ['kind' => 'task.create', 'title' => 'DO NOT LEAK UNHOMED TASK']);
    $url = route('enterprise.briefing', ['tenant' => $tenant->slug, 'area_id' => $area]);
    $reply = $this->actingAs($member)->getJson($url)->assertOk()->assertJsonPath('counts.projects', 1)->assertJsonPath('counts.tasks', 0);
    expect($reply->getContent())->not->toContain('DO NOT LEAK');
    $pdf = $this->get(route('enterprise.briefing.pdf', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertOk()->getContent();
    expect($pdf)->not->toContain('DO NOT LEAK');
    $otherArea = briefingCommand($tenant, $owner, ['action' => 'workspace.create', 'title' => 'Other department', 'visibility' => 'department_only'])['id'];
    $this->getJson(route('enterprise.briefing', ['tenant' => $tenant->slug, 'area_id' => $otherArea]))->assertNotFound();
    [$otherTenant, $otherOwner] = teamWorkspace();
    config(['enterprise.tenant_allowlist' => [(string) $tenant->id, (string) $otherTenant->id]]);
    $this->actingAs($otherOwner)->getJson($url)->assertForbidden();
    $guest = teamMember($tenant, 'guest');
    $this->actingAs($guest)->getJson($url)->assertNotFound();
    DB::table('tenant_user')->where(['tenant_id' => $tenant->id, 'user_id' => $member->id])->update(['membership_active' => false]);
    $this->actingAs($member)->getJson($url)->assertForbidden();
});

test('briefing excludes linked requests whose native task access was revoked and discussion tasks', function (): void {
    [$tenant, $owner, $area, $project] = briefingFixture();
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $member->id, 'role' => 'viewer']);
    $private = briefingGraph($tenant, $owner, ['kind' => 'project.create', 'name' => 'Private assignment', 'privacy' => 'private', 'enterprise_area_id' => $area]);
    $task = briefingGraph($tenant, $owner, ['kind' => 'task.create', 'title' => 'Private assigned task', 'memberships' => [$private['id'] => []]]);
    $request = briefingCommand($tenant, $owner, ['action' => 'request.create', 'title' => 'HIDDEN ASSIGNED REQUEST', 'area_id' => $area, 'expected_version' => 0]);
    $row = TeamOperationRecord::findOrFail($request['id']);
    $row->update(['data' => $row->data + ['task_id' => $task['id']]]);
    briefingGraph($tenant, $owner, ['kind' => 'task.create', 'title' => 'Discussion only', 'memberships' => [$project => []], 'enterprise_discussion_project' => $project]);
    $reply = app(Briefing::class)->build($tenant, $member, $area);
    expect($reply['counts']['tasks'])->toBe(0)->and($reply['counts']['open_requests'])->toBe(0);
    expect(json_encode($reply))->not->toContain('HIDDEN ASSIGNED REQUEST', 'Private assigned task', 'Discussion only');
});

test('briefing caps only after permission filtering and one page stays bounded for long unsafe titles', function (): void {
    [$tenant, $owner, $area, $project] = briefingFixture();
    for ($i = 0; $i < 10; $i++) {
        briefingCommand($tenant, $owner, ['action' => 'project.create', 'title' => str_repeat('W', 90).' (unsafe) \\ <script>', 'area_id' => $area]);
        briefingCommand($tenant, $owner, ['action' => 'request.create', 'title' => str_repeat('W', 70).' ") /JS (evil', 'area_id' => $area, 'expected_version' => 0]);
    }
    config(['enterprise.sample_data' => true]);
    $result = app(Briefing::class)->build($tenant, $owner, $area);
    expect($result['counts']['projects'])->toBe(11)->and($result['counts']['open_requests'])->toBe(10);
    expect($result['projects'])->toHaveCount(8)->and($result['requests'])->toHaveCount(4)->and($result['sources'])->toHaveCount(12);
    expect($result['sample_data'])->toBeTrue()->and($result['label'])->toContain('SAMPLE DATA');
    $pdf = app(BriefingPdf::class)->render($result);
    expect(substr_count($pdf, '/Type /Page '))->toBe(1);
    expect($pdf)->toContain('/Count 1', 'SAMPLE DATA', '1 / 1')->not->toContain('/S /JavaScript', '/OpenAction', '/Launch');
    expect(strlen($pdf))->toBeLessThan(30000);
    // Even a malicious content value is a quoted PDF string, never an executable action.
    $result['scope']['department'] = 'x) /OpenAction (evil';
    expect(app(BriefingPdf::class)->render($result))->toContain('x\\) /OpenAction \\(evil');
});

test('unreadable projects cannot consume the readable project result cap', function (): void {
    [$tenant, $owner, $area, $project] = briefingFixture();
    $member = teamMember($tenant, 'member');
    DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area, 'user_id' => $member->id, 'role' => 'viewer']);
    for ($i = 0; $i < 101; $i++) {
        briefingGraph($tenant, $owner, ['kind' => 'project.create', 'name' => 'A hidden project '.$i, 'privacy' => 'private', 'enterprise_area_id' => $area]);
    }
    $reply = app(Briefing::class)->build($tenant, $member, $area);
    expect($reply['counts']['projects'])->toBe(1)->and($reply['projects'])->toHaveCount(1);
    expect($reply['projects'][0]['id'])->toBe($project);
    expect(json_encode($reply))->not->toContain('A hidden project');
});

test('briefing model selection cannot invent claims or sources and rechecks edits and revoked access', function (): void {
    [$tenant, $owner, $area, $project] = briefingFixture();
    $this->instance(Intelligence::class, new class
    {
        public function generate(...$args): array
        {
            return ['value' => ['refs' => ['project:'.Str::uuid()]], 'usage_id' => 'fixture'];
        }
    });
    $url = route('enterprise.briefing.refresh', ['tenant' => $tenant->slug]);
    $this->actingAs($owner)->postJson($url, ['area_id' => $area, 'request_id' => (string) Str::uuid()])->assertStatus(503);
    $this->instance(Intelligence::class, new class($tenant, $owner, $project)
    {
        public function __construct(private $tenant, private $owner, private $project) {}

        public function generate(...$args): array
        {
            briefingGraph($this->tenant, $this->owner, ['kind' => 'project.update', 'id' => $this->project, 'expected_version' => 1, 'changes' => ['name' => 'Changed during provider processing']]);

            return ['value' => ['refs' => ['project:'.$this->project]], 'usage_id' => 'fixture'];
        }
    });
    $this->postJson($url, ['area_id' => $area, 'request_id' => (string) Str::uuid()])->assertConflict();
    $this->instance(Intelligence::class, new class($tenant, $owner)
    {
        public function __construct(private $tenant, private $owner) {}

        public function generate(...$args): array
        {
            DB::table('tenant_user')->where(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id])->update(['membership_active' => false]);

            return ['value' => ['refs' => []], 'usage_id' => 'fixture'];
        }
    });
    $this->postJson($url, ['area_id' => $area, 'request_id' => (string) Str::uuid()])->assertForbidden();
});

test('briefing AI refresh labels validated selection and unavailable provider fallback honestly', function (): void {
    [$tenant, $owner, $area, $project] = briefingFixture();
    $second = briefingCommand($tenant, $owner, ['action' => 'project.create', 'title' => 'Z illustrative follow-up', 'area_id' => $area])['id'];
    $this->instance(Intelligence::class, new class
    {
        public function generate($tenant, $user, $area, $purpose, $payload, $requestId): array
        {
            expect($purpose)->toBe('briefing')->and($payload['sources'][0])->not->toHaveKey('url');

            return ['value' => ['refs' => [$payload['sources'][1]['ref']]], 'usage_id' => 'fixture'];
        }
    });
    $result = app(Briefing::class)->refresh($tenant, $owner, $area, (string) Str::uuid());
    expect($result['summary']['mode'])->toBe('model_selected_records')->and($result['summary']['priority_refs'])->toBe(['project:'.$second]);
    expect($result['projects'][0]['id'])->toBe($second);
    expect($result['sources'][0]['ref'])->toBe('project:'.$second);
    expect($result['summary']['priority_items'][0])->toMatchArray(['ref' => 'project:'.$second, 'reason' => '0 of 0 checked tasks completed; active project.']);
    expect($result['ai_request']['status'])->toBe('completed');
    $this->instance(Intelligence::class, new class
    {
        public function generate(...$args): array
        {
            abort(503, 'Provider unavailable');
        }
    });
    $result = app(Briefing::class)->refresh($tenant, $owner, $area, (string) Str::uuid());
    expect($result['summary']['mode'])->toBe('record_backed')->and($result['summary']['model_status'])->toContain('unavailable');
    expect($result['ai_request']['status'])->toBe('unavailable');
    $this->instance(Intelligence::class, new class
    {
        public function generate(...$args): array
        {
            abort(503, 'The AI request outcome is uncertain. Its maximum cost remains reserved.');
        }
    });
    $requestId = (string) Str::uuid();
    $result = app(Briefing::class)->refresh($tenant, $owner, $area, $requestId);
    expect($result['ai_request'])->toBe(['status' => 'uncertain', 'request_id' => $requestId]);
    expect($result['summary']['model_status'])->toContain('do not retry with a new key');
    config(['enterprise.briefing_enabled' => false]);
    $this->actingAs($owner)->getJson(route('enterprise.briefing', ['tenant' => $tenant->slug, 'area_id' => $area]))->assertNotFound();
});

test('briefing explicit AI refresh replays across time without another provider call and preserves uncertainty', function (): void {
    [$tenant, $owner, $area, $project] = briefingFixture();
    config(['enterprise.intelligence' => ['enabled' => true, 'tenant_allowlist' => [(string) $tenant->id], 'api_key' => 'synthetic-not-a-real-provider-key',
        'model' => 'fixture-model', 'input_rate_microusd_per_million' => 1000000, 'output_rate_microusd_per_million' => 2000000,
        'rates_valid_until' => now()->addDay()->toIso8601String(), 'max_input' => 12000, 'max_output' => 1800, 'lifetime_cap_microusd' => 1000000]]);
    Http::fake(['https://api.openai.com/v1/responses' => Http::response(['id' => 'fixture-response', 'status' => 'completed',
        'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['refs' => ['project:'.$project]])]]]],
        'usage' => ['input_tokens' => 100, 'output_tokens' => 20, 'input_tokens_details' => ['cached_tokens' => 0]]])]);
    $requestId = (string) Str::uuid();
    $first = app(Briefing::class)->refresh($tenant, $owner, $area, $requestId);
    $this->travel(1)->minutes();
    $again = app(Briefing::class)->refresh($tenant, $owner, $area, $requestId);
    expect($first['ai_request']['status'])->toBe('completed')->and($again['ai_request']['replayed'])->toBeTrue();
    expect($again['as_of'])->not->toBe($first['as_of']);
    Http::assertSentCount(1);
    expect(TeamOperationRecord::where('kind', 'ent_ai_usage')->count())->toBe(1);
    Http::fake(['https://api.openai.com/v1/responses' => Http::failedConnection()]);
    $uncertainKey = (string) Str::uuid();
    $result = app(Briefing::class)->refresh($tenant, $owner, $area, $uncertainKey);
    expect($result['ai_request'])->toBe(['status' => 'uncertain', 'request_id' => $uncertainKey]);
    expect(TeamOperationRecord::where('kind', 'ent_ai_usage')->where('status', 'uncertain')->count())->toBe(1);
    expect(TeamOperationRecord::where('kind', 'ent_ai_usage')->where('status', 'uncertain')->first()->data['reserved_microusd'])->toBeGreaterThan(0);
});
