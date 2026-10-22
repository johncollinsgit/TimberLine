<?php

namespace Tests\Feature\Enterprise;

use App\Enterprise\LocationCatalog;
use App\Enterprise\Provisioning\ClientManifest;
use App\Enterprise\Provisioning\ClientProvisioner;
use App\Enterprise\Provisioning\ClientSilo;
use App\Enterprise\Provisioning\RuntimePaths;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

final class EnterpriseClientProvisioningTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-client-test-'.Str::uuid();
        foreach (['storage/framework/views', 'storage/framework/sessions', 'storage/framework/cache/data', 'storage/framework/testing', 'storage/logs', 'bootstrap/cache'] as $path) {
            mkdir($this->runtime.'/'.$path, 0777, true);
        }
        file_put_contents($this->runtime.'/.env', '');
        putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        $app = require dirname(__DIR__, 3).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();
        $app->detectEnvironment(fn () => 'testing');
        $app['config']->set([
            'app.env' => 'testing', 'app.url' => 'https://client.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '',
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'enterprise_client.client_id' => '10000000-0000-4000-8000-000000000001',
            'enterprise_client.silo_id' => '10000000-0000-4000-8000-000000000002',
            'enterprise_client.storage_id' => '10000000-0000-4000-8000-000000000003',
            'enterprise_client.environment' => 'testing', 'enterprise_client.database' => ':memory:',
            'cache.default' => 'array', 'session.driver' => 'array', 'hashing.bcrypt.rounds' => 4,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
    }

    protected function tearDown(): void
    {
        try {
            parent::tearDown();
        } finally {
            putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-client-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function manifest(): array
    {
        return ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
            'environment' => 'testing', 'storage_id' => config('enterprise_client.storage_id'),
            'tenant' => ['business_id' => '20000000-0000-4000-8000-000000000001', 'slug' => 'synthetic-workshop', 'name' => 'Synthetic Workshop B'],
            'branding' => ['organization_name' => 'Synthetic Workshop B', 'accent' => '#123456'],
            'departments' => [['id' => '30000000-0000-4000-8000-000000000001', 'key' => 'repairs', 'name' => 'Repair Department', 'visibility' => 'department_only'],
                ['id' => '30000000-0000-4000-8000-000000000002', 'key' => 'shared', 'name' => 'Shared Work', 'visibility' => 'shared']],
            'locations' => [['id' => '40000000-0000-4000-8000-000000000001', 'key' => 'workshop', 'department_key' => 'repairs',
                'name' => 'Synthetic Workshop', 'source_url' => null, 'source_label' => 'Approved fictional workshop configuration']],
            'workflow_defaults' => [['key' => 'repairs', 'name' => 'Workshop repair template', 'template' => 'maintenance_v1']],
            'role_defaults' => ['manager' => ['label' => 'Repair coordinator', 'view' => 'list', 'density' => 'compact']]];
    }

    private function install(): array
    {
        $provisioner = app(ClientProvisioner::class);

        return $provisioner->apply($this->manifest(), $provisioner->plan($this->manifest())['plan_hash']);
    }

    public function test_dry_run_is_deterministic_read_only_and_cli_requires_the_exact_plan(): void
    {
        $provisioner = app(ClientProvisioner::class);
        $before = (new Filesystem)->allFiles($this->runtime);
        $plan = $provisioner->plan($this->manifest());
        $this->assertSame($plan, $provisioner->plan(array_reverse($this->manifest(), true)));
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('enterprise_client_silos')->count());
        $this->assertCount(count($before), (new Filesystem)->allFiles($this->runtime));
        $this->assertFileDoesNotExist(app(ClientSilo::class)->markerPath());
        $path = $this->runtime.'/manifest.json';
        file_put_contents($path, ClientManifest::canonical($this->manifest()));
        $this->artisan('enterprise:client', ['--manifest' => $path])->assertSuccessful();
        $this->artisan('enterprise:client', ['action' => 'apply', '--manifest' => $path, '--plan-hash' => str_repeat('0', 64)])->assertFailed();
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertFileDoesNotExist(app(ClientSilo::class)->markerPath());
        Http::assertNothingSent();
    }

    public function test_actual_client_runtime_installs_non_campus_configuration_once_without_access_or_live_work(): void
    {
        $this->get('/login')->assertStatus(503)->assertCookieMissing('everbranch_enterprise_session');
        $result = $this->install();
        $this->assertFalse($result['replayed']);
        $this->assertFalse($result['access_provisioned']);
        $this->assertSame('pending_plan_review', $result['backup_status']);
        $this->assertSame('Synthetic Workshop B', DB::table('tenants')->value('name'));
        $this->assertSame(2, DB::table('team_areas')->count());
        $this->assertSame(1, DB::table('team_operation_records')->count());
        $this->assertStringNotContainsString('Synthetic Workshop B', DB::table('enterprise_client_silos')->value('manifest'));
        foreach (['users', 'tenant_user', 'team_area_members', 'tenant_access_profiles', 'tenant_module_entitlements', 'team_tasks', 'team_projects', 'team_provider_connections'] as $table) {
            $this->assertSame(0, DB::table($table)->count(), $table);
        }
        $catalog = app(LocationCatalog::class)->all();
        $this->assertSame([], $catalog['entries'], 'Private department catalog requires existing authenticated access.');
        $this->assertStringNotContainsString('Lees', json_encode($catalog));
        $config = app(ClientSilo::class)->configuration();
        $this->assertSame('Repair coordinator', $config['role_defaults']['manager']['label']);
        $this->assertSame('maintenance_v1', $config['workflow_defaults'][0]['template']);
        $this->get('/login')->assertOk();
        $this->assertTrue($this->install()['replayed']);
        $this->assertSame(1, DB::table('tenants')->count());
        $this->assertSame(2, DB::table('team_areas')->count());
        Http::assertNothingSent();
    }

    public function test_client_catalog_reuses_current_membership_and_department_acl_instead_of_exposing_all_manifest_locations(): void
    {
        $result = $this->install();
        $tenant = \App\Models\Tenant::query()->findOrFail($result['tenant_id']);
        $user = new \App\Models\User;
        $user->forceFill(['name' => 'Synthetic viewer', 'email' => 'client-viewer@example.invalid', 'password' => 'fixture-only',
            'is_active' => true, 'email_verified_at' => now()])->save();
        $tenant->users()->attach($user->id, ['role' => 'member', 'membership_active' => true]);
        \App\Models\TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        \App\Models\TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled',
            'availability_status' => 'available', 'entitlement_source' => 'test']);
        config(['enterprise.enabled' => true, 'enterprise.tenant_allowlist' => [(string) $tenant->id], 'team_work.enabled' => true,
            'team_work.tenant_allowlist' => [(string) $tenant->id], 'enterprise.resources_enabled' => true]);
        $this->actingAs($user);
        $this->getJson('/enterprise/resources/catalog?tenant='.$tenant->slug)->assertOk()->assertJsonCount(0, 'entries');
        DB::table('team_area_members')->insert(['id' => Str::uuid()->toString(), 'tenant_id' => $tenant->id,
            'area_id' => $this->manifest()['departments'][0]['id'], 'user_id' => $user->id, 'role' => 'viewer']);
        $this->getJson('/enterprise/resources/catalog?tenant='.$tenant->slug)->assertOk()->assertJsonPath('entries.0.name', 'Synthetic Workshop')->assertJsonCount(1, 'entries');
        $this->assertStringNotContainsString('Lees', ClientManifest::canonical(app(LocationCatalog::class)->all()));
        DB::table('team_area_members')->where('user_id', $user->id)->delete();
        $this->getJson('/enterprise/resources/catalog?tenant='.$tenant->slug)->assertOk()->assertJsonCount(0, 'entries');
        $this->assertSame(1, DB::table('tenant_user')->count());
    }

    public function test_approved_test_only_owner_sees_installed_client_branding_preferences_and_template_choices_in_real_http_projections(): void
    {
        $result = $this->install();
        $tenant = \App\Models\Tenant::query()->findOrFail($result['tenant_id']);
        $user = new \App\Models\User;
        $user->forceFill(['name' => 'Synthetic Workshop owner', 'email' => 'workshop-owner@example.invalid', 'password' => 'fixture-only',
            'is_active' => true, 'email_verified_at' => now()])->save();
        $tenant->users()->attach($user->id, ['role' => 'owner', 'membership_active' => true]);
        \App\Models\TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        \App\Models\TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled',
            'availability_status' => 'available', 'entitlement_source' => 'test']);
        config(['enterprise.enabled' => true, 'enterprise.writes_enabled' => true, 'enterprise.tenant_allowlist' => [(string) $tenant->id],
            'team_work.enabled' => true, 'team_work.tenant_allowlist' => [(string) $tenant->id], 'enterprise.operations_enabled' => true,
            'enterprise.workflow_enabled' => true, 'enterprise.resources_enabled' => true]);
        $this->actingAs($user)->withoutVite();
        $area = $this->manifest()['departments'][0]['id'];
        $project = $this->postJson('/enterprise/commands?tenant='.$tenant->slug, ['action' => 'project.create', 'area_id' => $area,
            'id' => Str::uuid()->toString(), 'request_id' => Str::uuid()->toString(), 'title' => 'Synthetic Workshop project'])->assertOk()->json('id');
        $shell = $this->get('/?tenant='.$tenant->slug)->assertOk()->assertSee('Synthetic Workshop B')
            ->assertSee('--gold:#123456', false)->assertDontSee('Lees-McRae campus demonstration')->assertDontSee('Campus event readiness - sample');
        $this->assertSame(1, preg_match('~<section id="resource-workspace"[\s\S]*?</section>~', $shell->getContent(), $resourcePanel));
        $this->assertStringNotContainsString('Lees-McRae', $resourcePanel[0]);
        $this->assertStringNotContainsString('data:image/png;base64', $resourcePanel[0]);
        $scope = http_build_query(['tenant' => $tenant->slug, 'area_id' => $area, 'project_id' => $project]);
        $this->get('/projects/workspace?'.$scope)->assertOk()->assertSee('Synthetic Workshop B')->assertSee('--ew-accent:#123456', false);
        $this->getJson('/enterprise/preferences?'.$scope)->assertOk()->assertJsonPath('effective.density', 'compact')
            ->assertJsonPath('effective.view', 'list')->assertJsonPath('role_label', 'Repair coordinator');
        $this->getJson('/enterprise/workflows?'.$scope)->assertOk()->assertJsonCount(1, 'templates')->assertJsonPath('templates.0.key', 'repairs')
            ->assertJsonPath('templates.0.name', 'Workshop repair template')->assertJsonCount(0, 'items');
        $this->postJson('/enterprise/preferences?tenant='.$tenant->slug, ['area_id' => $area, 'request_id' => Str::uuid()->toString(),
            'expected_version' => 0, 'scope' => 'personal', 'settings' => ['density' => 'comfortable', 'view' => 'calendar']])->assertOk();
        $this->getJson('/enterprise/preferences?'.$scope)->assertOk()->assertJsonPath('effective.density', 'comfortable')->assertJsonPath('effective.view', 'calendar');
        $this->assertSame(0, DB::table('team_operation_records')->whereIn('kind', ['ent_workflow', 'ent_wf_run'])->count());
        $this->assertSame(0, DB::table('team_tasks')->count());
        $this->assertSame(1, DB::table('tenant_user')->count());
        Http::assertNothingSent();
    }

    public function test_approved_local_source_pack_freezes_numbers_functions_and_aliases_without_inventing_ownership(): void
    {
        $manifest = $this->manifest();
        $catalog = json_decode(file_get_contents(resource_path('data/enterprise/lees-mcrae-locations.json')), true, 32, JSON_THROW_ON_ERROR);
        $manifest['locations'] = [];
        foreach ($catalog['entries'] as $index => $entry) {
            $manifest['locations'][] = ['id' => sprintf('40000000-0000-4000-8000-%012d', $index + 1), 'key' => 'official-location-'.($index + 1),
                'department_key' => 'shared', 'name' => $entry['name'], 'source_entry' => $catalog['catalog'].':'.$entry['map_number'],
                'source_url' => null, 'source_label' => 'Official map legend; physical placement and ownership unverified'];
        }
        $provisioner = app(ClientProvisioner::class);
        $result = $provisioner->apply($manifest, $provisioner->plan($manifest)['plan_hash']);
        $this->assertSame(68, DB::table('team_operation_records')->where('kind', 'ent_resource_location')->count());
        $installed = app(ClientSilo::class)->configuration()['locations'];
        foreach ($catalog['entries'] as $index => $entry) {
            $metadata = $installed[$index]['source_metadata'];
            $this->assertSame($entry['map_number'], $metadata['map_number']);
            $this->assertSame($entry['name'], $metadata['name']);
            foreach (['official_function_details', 'aliases', 'related_spaces', 'historic_commons'] as $field) {
                $this->assertSame($entry[$field] ?? null, $metadata[$field] ?? null);
            }
            $this->assertNull($metadata['ownership']);
            $this->assertNull($metadata['campus_area']);
            $this->assertNull($metadata['latitude']);
            $this->assertNull($metadata['longitude']);
            $this->assertSame(2, $metadata['source_page']);
        }
        $record = \App\Models\TeamOperationRecord::query()->where('tenant_id', $result['tenant_id'])->where('title', 'Robb Center')->firstOrFail();
        $this->assertSame('Campus Police and Security', $record->data['official_function_details']);
        $this->assertSame('1', $record->data['map_number']);
        $this->assertSame('official-location-1', $record->data['catalog_key']);
        $this->assertFalse($record->data['hierarchy_verified']);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
        $invalid = $manifest;
        $invalid['locations'][0]['name'] = 'Invented source name';
        try {
            ClientManifest::validate($invalid);
            $this->fail('A source reference may not invent source facts.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('match exactly', $error->getMessage());
        }
        $duplicate = $manifest;
        $duplicate['locations'][1]['source_entry'] = $duplicate['locations'][0]['source_entry'];
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        ClientManifest::validate($duplicate);
    }

    public function test_proposed_lees_mcrae_manifest_uses_all_approved_source_entries_without_installing_a_tenant(): void
    {
        $manifest = ClientManifest::validate(ClientManifest::read(resource_path('data/enterprise/provisioning/lees-mcrae-demo.json')));
        $this->assertSame('demo', $manifest['environment']);
        $this->assertCount(68, $manifest['locations']);
        $this->assertCount(68, array_unique(array_column($manifest['locations'], 'source_entry')));
        $this->assertSame(['shared', 'department_only'], array_column($manifest['departments'], 'visibility'));
        $this->assertSame(['campus-shared'], array_values(array_unique(array_column($manifest['locations'], 'department_key'))));
        $entries = array_column(array_column($manifest['locations'], 'source_metadata'), null, 'map_number');
        $this->assertSame('Green House', $entries[50]['name']);
        $this->assertSame('Campus Police and Security', $entries[1]['official_function_details']);
        $this->assertNull($entries[50]['ownership']);
        $this->assertNull($entries[50]['campus_area']);
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('users')->count());
        $this->assertFileDoesNotExist(app(ClientSilo::class)->markerPath());
        Http::assertNothingSent();
    }

    public function test_partial_prepared_claim_resumes_only_the_same_plan_and_never_overwrites_existing_work(): void
    {
        $provisioner = app(ClientProvisioner::class);
        $plan = $provisioner->plan($this->manifest());
        file_put_contents(app(ClientSilo::class)->markerPath(), ClientManifest::canonical(array_intersect_key($plan, array_flip(['manifest_hash', 'binding_hash', 'plan_hash']))));
        $this->assertSame('prepared', $provisioner->plan($this->manifest())['state']);
        $this->assertFalse($provisioner->apply($this->manifest(), $plan['plan_hash'])['replayed']);
        DB::table('team_areas')->where('slug', 'repairs')->update(['name' => 'Existing authorized edit']);
        $this->assertTrue($this->install()['replayed']);
        $this->assertSame('Existing authorized edit', DB::table('team_areas')->where('slug', 'repairs')->value('name'));
        $changed = $this->manifest();
        $changed['branding']['accent'] = '#ffffff';
        $this->expectException(\RuntimeException::class);
        $provisioner->plan($changed);
    }

    public function test_failed_database_initialization_rolls_back_records_and_resumes_its_durable_prepared_marker(): void
    {
        $provisioner = app(ClientProvisioner::class);
        $plan = $provisioner->plan($this->manifest());
        $event = 'eloquent.creating: '.\App\Models\TeamArea::class;
        \Illuminate\Support\Facades\Event::listen($event, fn () => throw new \RuntimeException('Synthetic interrupted initialization'));
        try {
            $provisioner->apply($this->manifest(), $plan['plan_hash']);
            $this->fail('The injected failure must propagate.');
        } catch (\RuntimeException $error) {
            $this->assertSame('Synthetic interrupted initialization', $error->getMessage());
        } finally {
            \Illuminate\Support\Facades\Event::forget($event);
        }
        $this->assertSame(0, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('team_areas')->count());
        $this->assertSame(0, DB::table('enterprise_client_silos')->count());
        $this->assertFileExists(app(ClientSilo::class)->markerPath());
        $this->get('/login')->assertStatus(503);
        $this->assertFalse($provisioner->apply($this->manifest(), $plan['plan_hash'])['replayed']);
        $this->assertSame(1, DB::table('tenants')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
    }

    public function test_wrong_client_database_key_storage_and_extra_tenants_fail_closed_without_creating_access(): void
    {
        $this->install();
        foreach (['client_id' => '90000000-0000-4000-8000-000000000001', 'silo_id' => '90000000-0000-4000-8000-000000000002',
            'storage_id' => '90000000-0000-4000-8000-000000000003', 'environment' => 'production', 'database' => 'wrong_database'] as $key => $value) {
            $old = config('enterprise_client.'.$key);
            config(['enterprise_client.'.$key => $value]);
            $this->get('/login')->assertStatus(503)->assertCookieMissing('everbranch_enterprise_session');
            config(['enterprise_client.'.$key => $old]);
        }
        $key = config('app.key');
        config(['app.key' => 'base64:MjIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=']);
        $this->get('/login')->assertStatus(503);
        config(['app.key' => $key]);
        DB::table('tenants')->insert(['name' => 'Unexpected second tenant', 'slug' => 'unexpected']);
        $this->get('/login')->assertStatus(503);
        $this->assertSame(0, DB::table('tenant_user')->count());
        $this->assertSame(0, DB::table('tenant_module_entitlements')->count());
    }

    public function test_foreign_records_and_malformed_or_unrecognized_manifest_fields_are_not_adopted(): void
    {
        foreach ([['credentials' => ['password' => 'forbidden']], ['departments' => [['id' => Str::uuid()->toString(), 'key' => '../escape', 'name' => 'Invalid', 'visibility' => 'shared']]],
            ['role_defaults' => ['superadmin' => ['label' => 'Invalid', 'view' => 'list', 'density' => 'compact']]]] as $changes) {
            try {
                app(ClientProvisioner::class)->plan(array_replace($this->manifest(), $changes));
                $this->fail('Unrecognized or unsafe manifest must fail.');
            } catch (\Illuminate\Validation\ValidationException) {
                $this->assertSame(0, DB::table('tenants')->count());
            }
        }
        DB::table('users')->insert(['name' => 'Foreign identity', 'email' => 'foreign@example.invalid', 'password' => 'fixture-only']);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('existing records');
        app(ClientProvisioner::class)->plan($this->manifest());
    }

    public function test_unmarked_runtime_files_and_aliased_private_disks_cannot_be_adopted(): void
    {
        $orphan = $this->runtime.'/storage/framework/sessions/orphaned-session';
        file_put_contents($orphan, 'synthetic prior-client bytes');
        try {
            app(ClientProvisioner::class)->plan($this->manifest());
            $this->fail('Existing runtime files must not be adopted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('empty private storage', $error->getMessage());
            $this->assertSame(0, DB::table('tenants')->count());
        }
        unlink($orphan);
        $original = config('filesystems.disks.team_private.root');
        config(['filesystems.disks.team_private.root' => $this->runtime.'/foreign-private']);
        try {
            app(ClientProvisioner::class)->plan($this->manifest());
            $this->fail('Another disk root must not be adopted.');
        } catch (\RuntimeException $error) {
            $this->assertStringContainsString('private disk', $error->getMessage());
        } finally {
            config(['filesystems.disks.team_private.root' => $original]);
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            mkdir($this->runtime.'/storage/app');
            mkdir($this->runtime.'/foreign-private');
            $this->assertTrue(symlink($this->runtime.'/foreign-private', $this->runtime.'/storage/app/team-private'));
            try {
                app(ClientProvisioner::class)->plan($this->manifest());
                $this->fail('A symlinked private directory must not be adopted.');
            } catch (\RuntimeException $error) {
                $this->assertStringContainsString('aliases another location', $error->getMessage());
            } finally {
                unlink($this->runtime.'/storage/app/team-private');
            }
        }
        $this->assertFileDoesNotExist(app(ClientSilo::class)->markerPath());
    }

    public function test_removing_both_runtime_selectors_cannot_reclassify_an_installed_client_database_as_standalone(): void
    {
        $this->install();
        $this->app->instance('enterprise.client_root_selected', false);
        config(['enterprise_client.client_id' => '']);
        $this->assertTrue(app(ClientSilo::class)->enabled());
        $this->get('/login')->assertStatus(503)->assertCookieMissing('everbranch_enterprise_session');
        $this->assertSame(1, DB::table('enterprise_client_silos')->count());
        $this->assertSame(0, DB::table('tenant_user')->count());
    }

    public function test_bootstrap_paths_and_request_inputs_cannot_select_another_client(): void
    {
        $root = realpath($this->runtime);
        $this->assertSame($root, $this->app->environmentPath());
        $this->assertSame($root.'/storage', $this->app->storagePath());
        $this->assertStringStartsWith(str_replace('\\', '/', $root).'/bootstrap/cache', str_replace('\\', '/', $this->app->getCachedConfigPath()));
        $this->assertSame(realpath($this->app->basePath('apps/enterprise/config')), realpath($this->app->configPath()));
        $this->assertSame(realpath($this->app->basePath('apps/enterprise/database')), realpath($this->app->databasePath()));
        $this->install();
        $this->withHeader('X-Enterprise-Runtime-Root', '/some-other-client')->get('/login?ENTERPRISE_RUNTIME_ROOT=/some-other-client')->assertOk();
        $this->assertSame($root, $this->app->environmentPath());
        $this->assertCount(1, DB::table('tenants')->get());
        putenv('ENTERPRISE_RUNTIME_ROOT=relative-directory');
        try {
            RuntimePaths::resolve($this->app->basePath('apps/enterprise'));
            $this->fail('Relative runtime selection must be rejected.');
        } catch (\RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->app->basePath('relative-directory'));
        } finally {
            putenv('ENTERPRISE_RUNTIME_ROOT='.$this->runtime);
        }
    }

    public function test_two_real_bootstraps_keep_generated_configuration_and_private_files_separate(): void
    {
        $second = $this->runtime.'-second';
        foreach (['storage', 'bootstrap/cache'] as $path) {
            mkdir($second.'/'.$path, 0777, true);
        }
        file_put_contents($second.'/.env', '');
        $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'apps/enterprise/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
Illuminate\Support\Facades\Http::preventStrayRequests();
if ($kernel->call('config:cache') !== 0) throw new RuntimeException('Client config cache failed.');
Illuminate\Support\Facades\Storage::disk('local')->put('isolation-proof.txt', getenv('ENTERPRISE_CLIENT_ID'));
echo json_encode(['environment' => $app->environmentPath(), 'storage' => $app->storagePath(),
    'cache' => $app->getCachedConfigPath(), 'key_fingerprint' => hash('sha256', config('app.key')),
    'private_file' => Illuminate\Support\Facades\Storage::disk('local')->get('isolation-proof.txt')], JSON_THROW_ON_ERROR);
PHP;
        try {
            $results = [];
            foreach ([$this->runtime, $second] as $index => $root) {
                $process = new \Symfony\Component\Process\Process([PHP_BINARY, '-r', $worker], base_path(), [
                    'ENTERPRISE_RUNTIME_ROOT' => $root, 'ENTERPRISE_APP_ENV' => 'testing',
                    'ENTERPRISE_CLIENT_ID' => '10000000-0000-4000-8000-00000000000'.($index + 1),
                    'ENTERPRISE_APP_KEY' => $index === 0 ? config('app.key') : 'base64:MjIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
                    'ENTERPRISE_DB_DRIVER' => 'sqlite', 'ENTERPRISE_DB_DATABASE' => ':memory:', 'ENTERPRISE_DB_PASSWORD' => '',
                ], null, 30);
                $process->mustRun();
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            foreach (['environment', 'storage', 'cache', 'key_fingerprint', 'private_file'] as $key) {
                $this->assertNotSame($results[0][$key], $results[1][$key], $key);
            }
            $this->assertFileExists($results[0]['cache']);
            $this->assertFileExists($results[1]['cache']);
            $this->assertSame('10000000-0000-4000-8000-000000000001', file_get_contents($this->runtime.'/storage/app/private/isolation-proof.txt'));
            $this->assertSame('10000000-0000-4000-8000-000000000002', file_get_contents($second.'/storage/app/private/isolation-proof.txt'));
        } finally {
            (new Filesystem)->deleteDirectory($second);
        }
    }

    public function test_two_real_code_root_identities_use_distinct_cache_files_inside_the_same_client_directory(): void
    {
        $paths = [];
        foreach (['release-a', 'release-b'] as $release) {
            $directory = $this->runtime.'/'.$release;
            mkdir($directory);
            // Cache-path behavior belongs to the application instance; cloning
            // avoids replacing the active test container with a second app.
            $app = clone $this->app;
            $app->setBasePath($directory);
            $paths[] = [$app->getCachedConfigPath(), $app->getCachedRoutesPath(), $app->getCachedServicesPath(),
                $app->getCachedPackagesPath(), $app->getCachedEventsPath()];
        }
        foreach ($paths[0] as $index => $path) {
            $this->assertNotSame($path, $paths[1][$index]);
            $this->assertSame(str_replace('\\', '/', realpath($this->runtime)).'/bootstrap/cache', str_replace('\\', '/', dirname($path)));
            $this->assertMatchesRegularExpression('/^[a-f0-9]{20}-(?:config|routes-v7|services|packages|events)\.php$/D', basename($path));
        }
        $this->assertCount(0, (new Filesystem)->directories($this->runtime.'/bootstrap/cache'), 'Release tags require no new cache directories.');
        $this->assertSame(realpath(base_path()), realpath($this->app->basePath()));
    }
}
