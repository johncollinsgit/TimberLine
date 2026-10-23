<?php

namespace Tests\Feature\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** Real isolated runtime, current canonical policies and persisted fictional evidence; no provider calls. */
final class EnterpriseReadOnlyAssistantTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    private Tenant $tenant;

    private User $coordinator;

    private string $area;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-coo-test-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/logs', 'bootstrap/cache'] as $path) {
            mkdir($this->runtime.'/'.$path, 0700, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        putenv('ENTERPRISE_RUNTIME_ROOT');
        $app = (static fn (string $path) => require $path)(dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php');
        $app->useEnvironmentPath($this->runtime);
        $app->useStoragePath($this->runtime.'/storage');
        $app->useBootstrapPath($this->runtime.'/bootstrap');
        $app->instance('enterprise.runtime_root', $this->runtime);
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing', 'app.url' => 'https://building.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '',
            'enterprise_client.client_id' => '',
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4,
            'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
            'enterprise.read_only_assistant_enabled' => true, 'enterprise.briefing_enabled' => true, 'enterprise.planning_enabled' => true,
            'enterprise.operations_enabled' => true, 'enterprise.resources_enabled' => true, 'team_work.enabled' => true,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        $this->tenant = Tenant::query()->create(['name' => 'Synthetic building workspace', 'slug' => 'synthetic-building']);
        $this->coordinator = new User;
        $this->coordinator->forceFill(['name' => 'Synthetic coordinator', 'email' => 'building@example.invalid',
            'password' => 'fixture-only', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()])->save();
        $this->tenant->users()->attach($this->coordinator->id, ['role' => 'member', 'membership_active' => true]);
        TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'team_work',
            'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
        $this->area = (string) Str::uuid();
        TeamArea::query()->create(['id' => $this->area, 'tenant_id' => $this->tenant->id, 'slug' => 'building-operations',
            'name' => 'Synthetic Operations', 'profile' => 'enterprise_department', 'settings' => []]);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'area_id' => $this->area, 'user_id' => $this->coordinator->id, 'role' => 'coordinator']);
        config(['enterprise.tenant_allowlist' => [(string) $this->tenant->id], 'team_work.tenant_allowlist' => [(string) $this->tenant->id]]);
        $this->actingAs($this->coordinator);
    }

    protected function tearDown(): void
    {
        try {
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-coo-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function command(array $input)
    {
        return $this->postJson(route('enterprise.commands', ['tenant' => $this->tenant->slug]), $input + [
            'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area, 'expected_version' => 0]);
    }

    private function building(array $extra = []): array
    {
        return $this->postJson(route('enterprise.resources.command', ['tenant' => $this->tenant->slug]), $extra + [
            'action' => 'location.save', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'area_id' => $this->area, 'expected_version' => 0, 'title' => 'Synthetic North Hall', 'level' => 'facility'])->assertOk()->json();
    }

    private function projection(): array
    {
        return $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')->json();
    }

    private function linkedInput(array $building, array $extra = []): array
    {
        return $extra + ['action' => 'request.create', 'title' => 'Synthetic roof inspection', 'location_id' => $building['id'],
            'location_version' => $building['version'], 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid()];
    }

    private function check(string $intent, array $extra = [])
    {
        return $this->postJson(route('enterprise.read_only_assistant', ['tenant' => $this->tenant->slug]),
            $extra + ['area_id' => $this->area, 'intent' => $intent]);
    }

    private function owner(): void
    {
        $this->tenant->users()->updateExistingPivot($this->coordinator->id, ['role' => 'owner']);
    }

    private function project(string $title = 'Synthetic roof repairs'): string
    {
        return $this->command(['action' => 'project.create', 'title' => $title])->assertOk()->json('id');
    }

    private function cost(string $project, int $amount, string $basis, string $currency = 'USD'): array
    {
        return $this->postJson(route('enterprise.planning.save', ['tenant' => $this->tenant->slug]), [
            'kind' => 'cost', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'area_id' => $this->area, 'expected_version' => 0, 'title' => 'Synthetic '.$basis.' '.$currency,
            'status' => 'recorded', 'project_id' => $project, 'amount_minor' => $amount, 'basis' => $basis, 'currency' => $currency,
        ])->assertOk()->json();
    }

    public function test_overview_is_real_read_only_evidence_with_no_paid_dispatch_or_business_writes(): void
    {
        $project = $this->project();
        $request = $this->command(['action' => 'request.create', 'title' => 'Synthetic leak'])->assertOk()->json();
        $before = array_map(fn ($table) => DB::table($table)->count(), ['team_projects', 'team_tasks', 'team_operation_records', 'team_operation_attempts', 'team_operation_revisions']);
        config(['enterprise.writes_enabled' => false, 'enterprise.ask_enabled' => true, 'enterprise.intelligence.enabled' => true,
            'enterprise.voice_generation_enabled' => true, 'enterprise.sample_data' => true]);
        $this->app->bind(\App\Enterprise\AskModel::class, fn () => throw new \RuntimeException('A read-only check must not resolve a model'));
        $result = $this->check('overview')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('provider_dispatch', false)->assertJsonPath('mode', 'deterministic_records')->assertJsonPath('sample_data', true)
            ->assertJsonPath('scope.user_id', (string) $this->coordinator->id)->json();
        $this->assertSame(['project:'.$project, 'request:'.$request['id']], array_column($result['sources'], 'ref'));
        $this->assertStringContainsString('1 readable projects', $result['reply']);
        $this->assertTrue($result['partial']);
        foreach ($result['sources'] as $source) {
            $this->assertStringContainsString('tenant='.$this->tenant->slug, $source['url']);
            $this->assertStringContainsString('area_id='.$this->area, $source['url']);
        }
        $this->assertSame($before, array_map(fn ($table) => DB::table($table)->count(), ['team_projects', 'team_tasks', 'team_operation_records', 'team_operation_attempts', 'team_operation_revisions']));
        $this->assertSame(0, TeamOperationRecord::query()->whereIn('kind', ['ent_ai_usage', 'ent_voice_session', 'ent_email', 'ent_trial_charge'])->count());
    }

    public function test_real_private_shell_renders_the_read_only_control_and_no_implicit_feature_grants(): void
    {
        $this->get(route('enterprise.index', ['tenant' => $this->tenant->slug]))->assertOk()
            ->assertSee('id="read-only-assistant"', false)->assertSee('No approved budget or staffing capacity is inferred.');
        $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk()
            ->assertJsonPath('features.read_only_assistant', true)->assertJsonPath('features.read_only_costs', false)
            ->assertJsonPath('features.ask', false)->assertJsonPath('features.voice_generation', false)->assertJsonPath('features.email_drafts', false);
    }

    public function test_building_counts_only_authorized_id_links_and_returns_real_priority_jobs_with_sources(): void
    {
        $building = $this->building();
        $linked = $this->command($this->linkedInput($building))->assertOk()->json();
        $second = $this->command($this->linkedInput($building, ['title' => 'Synthetic second link']))->assertOk()->json();
        $legacy = $this->command(['action' => 'request.create', 'title' => 'Unlinked same name', 'location' => $building['title']])->assertOk()->json();
        $project = $this->project();
        $this->command(['action' => 'request.triage', 'id' => $linked['id'], 'expected_version' => 1])->assertOk();
        $assigned = $this->command(['action' => 'request.assign', 'id' => $linked['id'], 'expected_version' => 2,
            'project_id' => $project, 'assignee_id' => $this->coordinator->id])->assertOk()->json();
        $this->postJson(route('enterprise.operations.command', ['tenant' => $this->tenant->slug]), [
            'action' => 'task.configure', 'task_id' => $assigned['task']['id'], 'request_id' => (string) Str::uuid(),
            'expected_version' => 0, 'expected_task_version' => $assigned['task']['version'],
            'owner_user_id' => (string) $this->coordinator->id, 'ball_user_id' => (string) $this->coordinator->id,
            'ball_team' => false, 'next_action' => 'Synthetic roof inspection', 'priority' => 'urgent',
            'checklist' => [['id' => 'inspect', 'label' => 'Synthetic inspection', 'weight' => 1]]])->assertOk();
        config(['enterprise.writes_enabled' => false]);
        $result = $this->check('building', ['building_id' => $building['id']])->assertOk()
            ->assertJsonPath('building.counts.linked_requests', 2)->assertJsonPath('building.counts.open_requests', 2)
            ->assertJsonPath('building.counts.urgent_open_requests', 1)->assertJsonPath('building.counts.unknown_priority_open_requests', 1)->assertJsonPath('jobs.0.priority', 'urgent')->json();
        $this->assertEqualsCanonicalizing(['building:'.$building['id'], 'request:'.$linked['id'], 'request:'.$second['id']], array_column($result['sources'], 'ref'));
        $this->assertStringNotContainsString($legacy['id'], json_encode($result));
        $this->assertStringContainsString('task='.$assigned['task']['id'], $result['jobs'][0]['url']);
        $this->assertArrayNotHasKey('assignee_capacity', $result['jobs'][0]);
        $this->getJson(route('enterprise.operations.show', ['tenant' => $this->tenant->slug, 'id' => $assigned['task']['id']]))->assertOk();
        DB::table('team_area_members')->where('user_id', $this->coordinator->id)->delete();
        $this->check('building', ['building_id' => $building['id']])->assertNotFound();
    }

    public function test_building_checked_counts_stay_bounded_and_do_not_expose_a_private_task_link(): void
    {
        $building = $this->building();
        $request = $this->command($this->linkedInput($building))->assertOk()->json();
        $project = $this->project();
        $this->command(['action' => 'request.triage', 'id' => $request['id'], 'expected_version' => 1])->assertOk();
        $this->command(['action' => 'request.assign', 'id' => $request['id'], 'expected_version' => 2,
            'project_id' => $project, 'assignee_id' => $this->coordinator->id])->assertOk();
        $payload = json_decode(DB::table('team_projects')->where('id', $project)->value('payload'), true);
        $payload['privacy'] = 'private';
        DB::table('team_projects')->where('id', $project)->update(['payload' => json_encode($payload), 'privacy' => 'private']);
        DB::table('team_project_members')->where('project_id', $project)->delete();
        $this->check('building', ['building_id' => $building['id']])->assertOk()
            ->assertJsonPath('building.counts.linked_requests', 0)->assertJsonCount(1, 'sources')->assertJsonCount(0, 'jobs');
        for ($index = 0; $index < 101; $index++) {
            $row = TeamOperationRecord::query()->findOrFail($request['id'])->replicate();
            $row->forceFill(['id' => (string) Str::uuid(), 'source_key' => Str::random(64), 'title' => 'Synthetic visible '.$index,
                'data' => ['location_id' => $building['id'], 'location' => $building['title']]])->save();
        }
        $result = $this->check('building', ['building_id' => $building['id']])->assertOk()
            ->assertJsonPath('building.counts.linked_requests', 100)->assertJsonPath('building.counts.open_requests', 100)
            ->assertJsonPath('building.counts.urgent_open_requests', 0)->assertJsonPath('building.counts.unknown_priority_open_requests', 100)->assertJsonCount(101, 'sources')->json();
        $this->assertTrue($result['partial']);
        $this->assertStringNotContainsString('request:'.$request['id'], json_encode($result));
        $this->assertStringContainsString('checked-sample counts, not building totals', $result['coverage']);
    }

    public function test_cost_math_keeps_currency_basis_missing_and_explicit_zero_separate_with_clickable_inputs(): void
    {
        $this->owner();
        $project = $this->project();
        $records = [
            $this->cost($project, 1000, 'estimate'), $this->cost($project, 1300, 'recorded_cost'), $this->cost($project, 200, 'recorded_cost'),
            $this->cost($project, 900, 'estimate', 'CAD'), $this->cost($project, 400, 'recorded_cost', 'CAD'),
            $this->cost($project, 0, 'estimate', 'EUR'), $this->cost($project, 4, 'recorded_cost', 'EUR'),
            $this->cost($project, 500, 'recorded_cost', 'GBP'),
        ];
        config(['enterprise.writes_enabled' => false]);
        $result = $this->check('costs')->assertOk()->assertJsonPath('provider_dispatch', false)->json();
        $groups = collect($result['comparisons'])->keyBy('currency');
        $this->assertSame(1000, $groups['USD']['estimate_minor']);
        $this->assertSame(1500, $groups['USD']['recorded_cost_minor']);
        $this->assertSame(500, $groups['USD']['difference_minor']);
        $this->assertSame(-500, $groups['CAD']['difference_minor']);
        $this->assertSame(4, $groups['EUR']['difference_minor']);
        $this->assertNull($groups['GBP']['difference_minor']);
        $this->assertSame([], $groups['GBP']['estimate_refs']);
        $this->assertCount(8, array_unique(array_column($result['sources'], 'ref')));
        $this->assertStringContainsString('not an approved-budget variance', $groups['USD']['comparison_label']);
        foreach ($result['sources'] as $source) {
            $id = substr($source['ref'], strlen('cost:'));
            $this->assertStringContainsString('selected='.$id, $source['url']);
            $this->assertStringContainsString('section=contractors', $source['url']);
            $this->assertSame($project, collect($records)->firstWhere('id', $id)['data']['project_id']);
        }
        $this->tenant->users()->updateExistingPivot($this->coordinator->id, ['role' => 'member']);
        $this->check('costs')->assertNotFound();
        $this->getJson(route('enterprise.data', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))
            ->assertOk()->assertJsonPath('features.read_only_costs', false);
    }

    public function test_flags_invalid_inputs_archived_foreign_and_role_preview_fail_closed(): void
    {
        $building = $this->building();
        foreach ([['actor' => ['role' => 'owner']], ['role' => 'owner'], ['role_preview' => 'owner'], ['tools' => ['send']], ['amount_minor' => 1], ['source_url' => 'https://evil.invalid'], ['question' => 'Pay the contractor']] as $extra) {
            $this->check('overview', $extra)->assertUnprocessable();
        }
        $this->check('building')->assertUnprocessable();
        $this->check('other')->assertUnprocessable();
        $this->check('overview', ['building_id' => $building['id']])->assertUnprocessable();
        foreach ([['status' => 'archived'], ['data' => ['level' => 'room']], ['area_id' => (string) Str::uuid()]] as $delta) {
            $row = TeamOperationRecord::query()->findOrFail($building['id'])->replicate();
            if (isset($delta['area_id'])) {
                TeamArea::query()->create(['id' => $delta['area_id'], 'tenant_id' => $this->tenant->id, 'slug' => 'foreign-area', 'name' => 'Hidden area', 'profile' => 'enterprise_department', 'settings' => []]);
            }
            $row->forceFill($delta + ['id' => (string) Str::uuid(), 'source_key' => Str::random(64)])->save();
            $this->check('building', ['building_id' => $row->id])->assertNotFound();
        }
        config(['enterprise.read_only_assistant_enabled' => false]);
        $this->check('overview')->assertNotFound();
        config(['enterprise.read_only_assistant_enabled' => true, 'enterprise.resources_enabled' => false]);
        $this->check('building', ['building_id' => $building['id']])->assertNotFound();
        auth()->logout();
        $this->check('overview')->assertUnauthorized();
    }

    public function test_cost_sources_are_bounded_and_exclude_private_archived_foreign_and_malformed_records(): void
    {
        $this->owner();
        $project = $this->project();
        $cost = $this->cost($project, 1000000000, 'recorded_cost');
        for ($index = 0; $index < 200; $index++) {
            $row = TeamOperationRecord::query()->findOrFail($cost['id'])->replicate();
            $row->forceFill(['id' => (string) Str::uuid(), 'source_key' => Str::random(64)])->save();
        }
        $result = $this->check('costs')->assertOk()->json();
        $this->assertCount(200, $result['sources']);
        $this->assertSame(200000000000, $result['comparisons'][0]['recorded_cost_minor']);
        $this->assertSame(200, count($result['comparisons'][0]['recorded_cost_refs']));
        $this->assertTrue($result['partial']);
        $payload = json_decode(DB::table('team_projects')->where('id', $project)->value('payload'), true);
        $payload['privacy'] = 'private';
        DB::table('team_projects')->where('id', $project)->update(['payload' => json_encode($payload), 'privacy' => 'private']);
        DB::table('team_project_members')->where('project_id', $project)->delete();
        $this->check('costs')->assertOk()->assertJsonCount(0, 'sources')->assertJsonCount(0, 'comparisons');
        $payload['privacy'] = 'workspace';
        $payload['archived'] = true;
        DB::table('team_projects')->where('id', $project)->update(['payload' => json_encode($payload), 'privacy' => 'workspace', 'archived' => true]);
        $this->check('costs')->assertOk()->assertJsonCount(0, 'sources');
        $payload['archived'] = false;
        DB::table('team_projects')->where('id', $project)->update(['payload' => json_encode($payload), 'archived' => false]);
        TeamOperationRecord::query()->where('kind', 'ent_cost')->delete();
        foreach ([['amount_minor' => '900'], ['currency' => 'usd'], ['basis' => 'approved_budget'], ['project_id' => null]] as $delta) {
            $row = new TeamOperationRecord;
            $row->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area,
                'kind' => 'ent_cost', 'source_key' => Str::random(64), 'title' => 'Synthetic invalid', 'status' => 'recorded', 'visibility' => 'area', 'version' => 1,
                'data' => $delta + ['project_id' => $project, 'amount_minor' => 900, 'currency' => 'USD', 'basis' => 'recorded_cost']])->save();
        }
        $otherTenant = Tenant::query()->create(['name' => 'Synthetic other silo', 'slug' => 'coo-other']);
        $foreignArea = (string) Str::uuid();
        TeamArea::query()->create(['id' => $foreignArea, 'tenant_id' => $otherTenant->id, 'slug' => 'other', 'name' => 'Other silo', 'profile' => 'enterprise_department', 'settings' => []]);
        $row->forceFill(['id' => (string) Str::uuid(), 'tenant_id' => $otherTenant->id, 'area_id' => $foreignArea, 'source_key' => Str::random(64),
            'data' => ['project_id' => $project, 'amount_minor' => 900, 'currency' => 'USD', 'basis' => 'recorded_cost']]);
        $row->exists = false;
        $row->save();
        $this->check('costs')->assertOk()->assertJsonCount(0, 'sources')->assertJsonCount(0, 'comparisons');
    }

    public function test_private_object_grant_revocation_during_retrieval_discards_the_old_calculation(): void
    {
        $this->owner();
        $project = $this->project();
        $this->cost($project, 50, 'recorded_cost');
        $planning = $this->app->make(\App\Enterprise\Planning::class);
        $proxy = \Mockery::mock(\App\Enterprise\Planning::class)->makePartial();
        $calls = 0;
        $proxy->shouldReceive('view')->twice()->andReturnUsing(function (...$arguments) use ($planning, $project, &$calls) {
            $result = $planning->view(...$arguments);
            if (++$calls === 1) {
                $payload = json_decode(DB::table('team_projects')->where('id', $project)->value('payload'), true);
                $payload['privacy'] = 'private';
                DB::table('team_projects')->where('id', $project)->update(['payload' => json_encode($payload), 'privacy' => 'private']);
                DB::table('team_project_members')->where('project_id', $project)->delete();
            }

            return $result;
        });
        $this->app->instance(\App\Enterprise\Planning::class, $proxy);
        $this->check('costs')->assertConflict()->assertJsonMissingPath('comparisons');
    }

    public function test_cost_citation_opens_exact_canonical_source_even_when_newer_other_kinds_fill_planning_list(): void
    {
        $this->owner();
        config(['enterprise.sample_data' => true]);
        $cost = $this->cost($this->project(), 50, 'recorded_cost');
        for ($index = 0; $index < 201; $index++) {
            TeamOperationRecord::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
                'area_id' => $this->area, 'kind' => 'ent_contractor', 'source_key' => Str::random(64),
                'title' => 'Synthetic later contractor '.$index, 'status' => 'active', 'visibility' => 'area',
                'version' => 1, 'data' => [], 'created_at' => now()->addDay(), 'updated_at' => now()->addDay()]);
        }
        $listing = $this->getJson(route('enterprise.planning', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk()->json();
        $this->assertCount(200, $listing['items']);
        $this->assertNotContains($cost['id'], array_column($listing['items'], 'id'));
        $source = $this->check('costs')->assertOk()->assertJsonCount(1, 'sources')->json('sources.0');
        $response = $this->get($source['url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertSee('cost:'.$cost['id'])->assertSee('Amount in minor units')->assertSee('<dd>50</dd>', false)
            ->assertSee('<dd>USD</dd>', false)->assertSee('<dd>recorded_cost</dd>', false);
        $this->assertStringContainsString('/assistant-sources/cost/'.$cost['id'], $source['url']);
        if (getenv('ENTERPRISE_ASSISTANT_SOURCE_EVIDENCE')) {
            file_put_contents(getenv('ENTERPRISE_ASSISTANT_SOURCE_EVIDENCE'), $response->getContent());
        }
        $this->tenant->users()->updateExistingPivot($this->coordinator->id, ['role' => 'member']);
        $this->getJson($source['url'])->assertNotFound();
    }

    public function test_request_and_building_citations_do_not_depend_on_their_bounded_workspace_lists(): void
    {
        $building = $this->building();
        $request = $this->command($this->linkedInput($building))->assertOk()->json();
        $original = TeamOperationRecord::query()->findOrFail($request['id']);
        $original->forceFill(['created_at' => now()->subYear(), 'updated_at' => now()->addYear()])->save();
        for ($index = 0; $index < 201; $index++) {
            $asset = TeamOperationRecord::query()->findOrFail($building['id'])->replicate();
            $asset->forceFill(['id' => (string) Str::uuid(), 'kind' => 'ent_resource_asset', 'title' => 'AAA synthetic asset '.$index,
                'source_key' => Str::random(64), 'data' => ['level' => 'asset']])->save();
            if ($index > 100) {
                continue;
            }
            $row = $original->replicate();
            $row->forceFill(['id' => (string) Str::uuid(), 'title' => 'Synthetic newer request '.$index,
                'source_key' => Str::random(64), 'created_at' => now(), 'updated_at' => now()])->save();
        }
        $listing = $this->projection();
        $this->assertCount(100, $listing['requests']);
        $this->assertNotContains($request['id'], array_column($listing['requests'], 'id'));
        $resources = $this->getJson(route('enterprise.resources', ['tenant' => $this->tenant->slug, 'area_id' => $this->area]))->assertOk()->json();
        $this->assertNotContains($building['id'], array_column($resources['items'], 'id'));
        $answer = $this->check('building', ['building_id' => $building['id']])->assertOk()->json();
        foreach (['building:'.$building['id'], 'request:'.$request['id']] as $ref) {
            $source = collect($answer['sources'])->firstWhere('ref', $ref);
            $this->assertNotNull($source);
            $this->get($source['url'])->assertOk()->assertSee($ref)->assertSee($source['title']);
        }
        $this->assertSame(100, $answer['building']['counts']['linked_requests']);
    }

    public function test_exact_source_rechecks_tenant_area_kind_native_acl_and_feature_without_writes(): void
    {
        $this->owner();
        $project = $this->project();
        $cost = $this->cost($project, 75, 'estimate');
        $source = $this->check('costs')->assertOk()->json('sources.0.url');
        $otherArea = (string) Str::uuid();
        TeamArea::query()->create(['id' => $otherArea, 'tenant_id' => $this->tenant->id, 'slug' => 'exact-other',
            'name' => 'Other authorized area', 'profile' => 'enterprise_department', 'settings' => []]);
        $this->getJson(str_replace('area_id='.$this->area, 'area_id='.$otherArea, $source))->assertNotFound();
        $this->getJson(str_replace('/cost/', '/request/', $source))->assertNotFound();
        $payload = json_decode(DB::table('team_projects')->where('id', $project)->value('payload'), true);
        $payload['privacy'] = 'private';
        DB::table('team_projects')->where('id', $project)->update(['payload' => json_encode($payload), 'privacy' => 'private']);
        DB::table('team_project_members')->where('project_id', $project)->delete();
        $this->getJson($source)->assertNotFound();
        config(['enterprise.read_only_assistant_enabled' => false]);
        $this->getJson($source)->assertNotFound();
        config(['enterprise.read_only_assistant_enabled' => true]);
        $this->tenant->users()->updateExistingPivot($this->coordinator->id, ['membership_active' => false]);
        $this->getJson($source)->assertForbidden();
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_cost')->count());
    }

    public function test_access_change_during_retrieval_discards_financial_answer(): void
    {
        $this->owner();
        $this->cost($this->project(), 50, 'recorded_cost');
        $planning = $this->app->make(\App\Enterprise\Planning::class);
        $proxy = \Mockery::mock(\App\Enterprise\Planning::class)->makePartial();
        $proxy->shouldReceive('view')->once()->andReturnUsing(function (...$arguments) use ($planning) {
            $result = $planning->view(...$arguments);
            $this->tenant->users()->updateExistingPivot($this->coordinator->id, ['role' => 'member']);

            return $result;
        });
        $this->app->instance(\App\Enterprise\Planning::class, $proxy);
        $this->check('costs')->assertConflict()->assertJsonMissingPath('comparisons');
    }
}
