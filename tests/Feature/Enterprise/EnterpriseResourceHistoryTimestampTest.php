<?php

namespace Tests\Feature\Enterprise;

use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;

/** Actual dedicated schema/routes: history must identify UTC instants without rewriting audit evidence. */
final class EnterpriseResourceHistoryTimestampTest extends TestCase
{
    private string $runtime;

    private string|false $previousRoot;

    private Tenant $tenant;

    private User $coordinator;

    private string $area;

    public function createApplication()
    {
        $this->previousRoot = getenv('ENTERPRISE_RUNTIME_ROOT');
        $this->runtime = sys_get_temp_dir().'/enterprise-history-time-test-'.Str::uuid();
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
            'app.env' => 'testing', 'app.url' => 'https://history.example.invalid',
            'app.key' => 'base64:MTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMjM0NTY3ODkwMTI=',
            'enterprise_runtime.isolated' => true, 'enterprise_runtime.base_path' => '',
            'enterprise_client.client_id' => '',
            'database.connections.enterprise' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
            'session.driver' => 'array', 'cache.default' => 'array', 'hashing.bcrypt.rounds' => 4,
            'enterprise.enabled' => true, 'enterprise.writes_enabled' => true,
            'enterprise.resources_enabled' => true, 'team_work.enabled' => true,
        ]);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        $this->assertSame('UTC', config('app.timezone'));
        $this->tenant = Tenant::query()->create(['name' => 'Synthetic timestamp workspace', 'slug' => 'synthetic-history-time']);
        $this->coordinator = new User;
        $this->coordinator->forceFill(['name' => 'Synthetic coordinator', 'email' => 'history@example.invalid',
            'password' => 'fixture-only', 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()])->save();
        $this->tenant->users()->attach($this->coordinator->id, ['role' => 'member', 'membership_active' => true]);
        TenantAccessProfile::query()->create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
        TenantModuleEntitlement::query()->create(['tenant_id' => $this->tenant->id, 'module_key' => 'team_work',
            'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
        $this->area = (string) Str::uuid();
        TeamArea::query()->create(['id' => $this->area, 'tenant_id' => $this->tenant->id, 'slug' => 'history-operations',
            'name' => 'Synthetic Operations', 'profile' => 'enterprise_department', 'settings' => []]);
        DB::table('team_area_members')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id,
            'area_id' => $this->area, 'user_id' => $this->coordinator->id, 'role' => 'coordinator']);
        config(['enterprise.tenant_allowlist' => [(string) $this->tenant->id], 'team_work.tenant_allowlist' => [(string) $this->tenant->id]]);
        $this->actingAs($this->coordinator);
    }

    protected function tearDown(): void
    {
        try {
            $this->travelBack();
            Http::assertNothingSent();
            parent::tearDown();
        } finally {
            putenv($this->previousRoot === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$this->previousRoot);
            if (str_starts_with($this->runtime, sys_get_temp_dir().'/enterprise-history-time-test-')) {
                (new Filesystem)->deleteDirectory($this->runtime);
            }
        }
    }

    private function createLocation(): array
    {
        return $this->postJson(route('enterprise.resources.command', ['tenant' => $this->tenant->slug]), [
            'action' => 'location.save', 'id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(),
            'area_id' => $this->area, 'expected_version' => 0, 'title' => 'Synthetic inspected facility', 'level' => 'facility',
        ])->assertOk()->json();
    }

    public static function instants(): array
    {
        return [
            'reported October instant' => ['2026-10-23T04:21:00Z', '2026-10-23 00:21:00 -04:00'],
            'before spring DST jump' => ['2026-03-08T06:30:00Z', '2026-03-08 01:30:00 -05:00'],
            'after spring DST jump' => ['2026-03-08T07:30:00Z', '2026-03-08 03:30:00 -04:00'],
            'first fall repeated hour' => ['2026-11-01T05:30:00Z', '2026-11-01 01:30:00 -04:00'],
            'second fall repeated hour' => ['2026-11-01T06:30:00Z', '2026-11-01 01:30:00 -05:00'],
        ];
    }

    #[DataProvider('instants')]
    public function test_history_serializes_the_original_utc_instant_without_mutating_immutable_evidence(string $instant, string $eastern): void
    {
        $clock = CarbonImmutable::parse($instant);
        $this->travelTo($clock);
        $location = $this->createLocation();
        $before = DB::table('team_operation_revisions')->where('record_id', $location['id'])->first();
        $this->assertSame($clock->format('Y-m-d H:i:s'), $before->created_at);
        $this->assertStringNotContainsString('location.save', $before->payload);
        $recordBefore = TeamOperationRecord::query()->findOrFail($location['id'])->getRawOriginal();
        $history = $this->getJson(route('enterprise.resources.history', ['tenant' => $this->tenant->slug, 'id' => $location['id']]))
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.at', $clock->toIso8601String())
            ->assertJsonPath('items.0.version', 1)->assertJsonPath('items.0.actor_id', $this->coordinator->id)
            ->assertJsonPath('items.0.event.action', 'location.save')->assertJsonPath('items.0.event.after.title', 'Synthetic inspected facility');
        $at = $history->json('items.0.at');
        $this->assertSame($clock->getTimestamp(), CarbonImmutable::parse($at)->getTimestamp());
        $this->assertSame($eastern, CarbonImmutable::parse($at)->setTimezone('America/New_York')->format('Y-m-d H:i:s P'));
        $this->assertEquals($before, DB::table('team_operation_revisions')->where('id', $before->id)->first());
        $this->assertSame($recordBefore, TeamOperationRecord::query()->findOrFail($location['id'])->getRawOriginal());
        $this->assertSame(1, DB::table('team_operation_revisions')->where('record_id', $location['id'])->count());
    }

    public function test_current_department_access_is_still_required_before_any_history_is_returned(): void
    {
        $location = $this->createLocation();
        $before = DB::table('team_operation_revisions')->where('record_id', $location['id'])->first();
        DB::table('team_area_members')->where('tenant_id', $this->tenant->id)->where('user_id', $this->coordinator->id)->delete();
        $this->getJson(route('enterprise.resources.history', ['tenant' => $this->tenant->slug, 'id' => $location['id']]))->assertNotFound();
        $this->assertEquals($before, DB::table('team_operation_revisions')->where('id', $before->id)->first());
    }
}
