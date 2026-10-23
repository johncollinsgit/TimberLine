<?php

namespace Tests\Integration;

use App\Enterprise\Provisioning\ClientProvisioner;
use App\Enterprise\Provisioning\ClientSilo;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/** Ordered before other retained MySQL fixtures: first claim requires a genuinely empty DB. */
final class EnterpriseClientProvisioningMySqlTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_real_mysql_first_claim_serializes_and_durable_index_repair_preserves_the_client_receipt(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || config('database.connections.enterprise.driver') !== 'mysql') {
            $this->markTestSkipped('Requires explicit disposable MySQL 8.4; SQLite is not concurrency evidence.');
        }
        $cfg = config('database.connections.enterprise');
        if (! app()->environment('testing') || $cfg['database'] !== 'everbranch_enterprise_ci'
            || ! in_array($cfg['host'], ['127.0.0.1', 'localhost'], true)) {
            throw new \RuntimeException('Refusing client provisioning fixtures outside isolated local Enterprise CI MySQL.');
        }
        $runtime = sys_get_temp_dir().'/enterprise-client-mysql-'.Str::uuid();
        foreach (['storage', 'bootstrap/cache'] as $path) {
            mkdir($runtime.'/'.$path, 0777, true);
        }
        file_put_contents($runtime.'/.env', '');
        $previous = getenv('ENTERPRISE_RUNTIME_ROOT');
        $processes = [];
        $held = false;
        $tenantId = null;
        $lock = substr('enterprise-client:'.hash('sha256', $cfg['database']), 0, 64);
        try {
            putenv('ENTERPRISE_RUNTIME_ROOT='.$runtime);
            $this->refreshApplication();
            config(['enterprise_client' => ['client_id' => '70000000-0000-4000-8000-000000000001',
                'silo_id' => '70000000-0000-4000-8000-000000000002', 'storage_id' => '70000000-0000-4000-8000-000000000003',
                'environment' => 'testing', 'database' => $cfg['database']]]);
            Http::preventStrayRequests();
            $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
            $manifest = ['schema_version' => 1, 'client_id' => config('enterprise_client.client_id'), 'silo_id' => config('enterprise_client.silo_id'),
                'storage_id' => config('enterprise_client.storage_id'), 'environment' => 'testing',
                'tenant' => ['business_id' => '70000000-0000-4000-8000-000000000004', 'slug' => 'synthetic-client-concurrency', 'name' => 'Synthetic client concurrency'],
                'branding' => ['organization_name' => 'Synthetic concurrency', 'accent' => '#123456'],
                'departments' => [['id' => '70000000-0000-4000-8000-000000000005', 'key' => 'repairs', 'name' => 'Synthetic repairs', 'visibility' => 'department_only']],
                'locations' => [], 'workflow_defaults' => [], 'role_defaults' => []];
            $plan = app(ClientProvisioner::class)->plan($manifest);
            $this->assertSame(1, (int) DB::selectOne('SELECT GET_LOCK(?, 1) AS acquired', [$lock])->acquired);
            $held = true;
            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'apps/enterprise/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d = json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\Http::preventStrayRequests();
$connection = Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($d['runtime'].'/ready-'.$d['worker'], 'ready');
$result = app(App\Enterprise\Provisioning\ClientProvisioner::class)->apply($d['manifest'], $d['plan_hash']);
Illuminate\Support\Facades\Http::assertNothingSent();
echo json_encode($result + ['connection' => (int) $connection], JSON_THROW_ON_ERROR);
PHP;
            $env = ['APP_ENV' => 'testing', 'ENTERPRISE_APP_ENV' => 'testing', 'ENTERPRISE_RUNTIME_ROOT' => $runtime,
                'ENTERPRISE_RUNTIME_ISOLATED' => 'true', 'ENTERPRISE_APP_KEY' => config('app.key'),
                'ENTERPRISE_CLIENT_ID' => $manifest['client_id'], 'ENTERPRISE_SILO_ID' => $manifest['silo_id'],
                'ENTERPRISE_CLIENT_STORAGE_ID' => $manifest['storage_id'], 'ENTERPRISE_CLIENT_ENVIRONMENT' => 'testing',
                'ENTERPRISE_CLIENT_DATABASE' => $cfg['database'], 'ENTERPRISE_DB_DRIVER' => 'mysql',
                'ENTERPRISE_DB_HOST' => $cfg['host'], 'ENTERPRISE_DB_PORT' => (string) $cfg['port'],
                'ENTERPRISE_DB_DATABASE' => $cfg['database'], 'ENTERPRISE_DB_USERNAME' => $cfg['username'], 'ENTERPRISE_DB_PASSWORD' => $cfg['password']];
            for ($workerId = 0; $workerId < 3; $workerId++) {
                $process = new Process([PHP_BINARY, '-r', $worker], base_path(), $env,
                    json_encode(['runtime' => $runtime, 'worker' => $workerId, 'manifest' => $manifest, 'plan_hash' => $plan['plan_hash']], JSON_THROW_ON_ERROR), 35);
                $process->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            while (count(glob($runtime.'/ready-*')) < 3 && microtime(true) < $deadline) {
                usleep(10000);
            }
            $this->assertCount(3, glob($runtime.'/ready-*'), 'Independent callers must reach the held database lock.');
            usleep(150000);
            $this->assertFileDoesNotExist(app(ClientSilo::class)->markerPath());
            $this->assertSame(0, DB::table('tenants')->count());
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), $process->getErrorOutput());
            }
            $this->assertSame(1, (int) DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock])->released);
            $held = false;
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 16, JSON_THROW_ON_ERROR);
            }
            $this->assertEqualsCanonicalizing([false, true, true], array_column($results, 'replayed'));
            $this->assertCount(3, array_unique(array_column($results, 'connection')));
            $this->assertSame(1, DB::table('tenants')->count());
            $this->assertSame(1, DB::table('team_areas')->count());
            $this->assertSame(1, DB::table('enterprise_client_silos')->count());
            $tenantId = DB::table('enterprise_client_silos')->value('tenant_id');
            $receipt = DB::table('enterprise_client_silos')->first();
            Schema::table('enterprise_client_silos', function (Blueprint $table): void {
                $table->dropUnique('ecs_silo');
                $table->dropUnique('ecs_tenant');
            });
            $migration = require database_path('migrations/2026_10_22_000500_create_enterprise_client_silo.php');
            $migration->up();
            $migration->up();
            $migration->down();
            $this->assertEquals($receipt, DB::table('enterprise_client_silos')->first());
            $this->assertTrue(Schema::hasIndex('enterprise_client_silos', 'ecs_silo', 'unique'));
            $this->assertTrue(Schema::hasIndex('enterprise_client_silos', 'ecs_tenant', 'unique'));
            $this->assertSame($plan['plan_hash'], app(ClientSilo::class)->status()['plan_hash']);
            foreach (['users', 'tenant_user', 'team_area_members', 'tenant_access_profiles', 'tenant_module_entitlements', 'team_tasks'] as $table) {
                $this->assertSame(0, DB::table($table)->count());
            }
            Http::assertNothingSent();
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            if ($held) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lock]);
            }
            $owned = Schema::hasTable('enterprise_client_silos')
                ? DB::table('enterprise_client_silos')->where('client_id', '70000000-0000-4000-8000-000000000001')->first() : null;
            if ($owned) {
                $tenantId = $owned->tenant_id;
                DB::table('enterprise_client_silos')->where('client_id', $owned->client_id)->delete();
            }
            if ($tenantId !== null) {
                DB::table('team_areas')->where('tenant_id', $tenantId)->delete();
                DB::table('tenants')->where('id', $tenantId)->where('slug', 'synthetic-client-concurrency')->delete();
            }
            putenv($previous === false ? 'ENTERPRISE_RUNTIME_ROOT' : 'ENTERPRISE_RUNTIME_ROOT='.$previous);
            (new Filesystem)->deleteDirectory($runtime);
        }
    }
}
