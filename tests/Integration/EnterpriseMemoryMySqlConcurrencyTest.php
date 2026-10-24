<?php

namespace Tests\Integration;

use App\Enterprise\Conversation\MemoryRetention;
use App\Enterprise\Conversation\PrivateMemory;
use App\Models\TeamOperationRecord;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\EnterpriseConversationFixture;

/** Independent real MySQL row locks; no provider or production database. */
final class EnterpriseMemoryMySqlConcurrencyTest extends EnterpriseConversationFixture
{
    protected function setUp(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || getenv('ENTERPRISE_DB_DRIVER') !== 'mysql') {
            $this->markTestSkipped('Requires explicitly disposable local Enterprise MySQL for memory retention/write concurrency.');
        }
        parent::setUp();
        config(['enterprise.conversation.memory_enabled' => true, 'enterprise.conversation.memory_retention_ready' => true]);
    }

    protected function databaseConfiguration(): array
    {
        return ['driver' => 'mysql', 'host' => getenv('ENTERPRISE_DB_HOST'), 'port' => getenv('ENTERPRISE_DB_PORT') ?: '3306',
            'database' => getenv('ENTERPRISE_DB_DATABASE'), 'username' => getenv('ENTERPRISE_DB_USERNAME'),
            'password' => getenv('ENTERPRISE_DB_PASSWORD') ?: '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '', 'strict' => true];
    }

    protected function prepareDatabase(): void
    {
        $cfg = DB::connection()->getConfig();
        if (! app()->environment('testing') || $cfg['driver'] !== 'mysql' || $cfg['database'] !== 'everbranch_enterprise_ci'
            || ! in_array($cfg['host'], ['localhost', '127.0.0.1'], true)) {
            throw new \RuntimeException('Refusing memory fixtures outside disposable local Enterprise CI MySQL.');
        }
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        $this->assertFalse(DB::table('tenants')->exists(), 'Requires an empty fictional fixture database.');
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->tenant)) {
                foreach (['team_operation_revisions', 'team_operation_attempts', 'team_operation_records', 'team_project_members', 'team_work_events', 'team_work_receipts', 'team_work_versions',
                    'team_projects', 'team_work_locks', 'tenant_access_profiles', 'tenant_module_entitlements', 'team_areas'] as $table) {
                    DB::table($table)->where('tenant_id', $this->tenant->id)->delete();
                }
                DB::table('enterprise_client_silos')->where('client_id', '10000000-0000-4000-8000-000000000001')->delete();
                DB::table('tenants')->where('id', $this->tenant->id)->where('slug', 'synthetic-workshop')->delete();
                DB::table('users')->where('id', $this->user->id)->where('email', 'conversation@example.invalid')->delete();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function test_renewal_winning_the_row_lock_prevents_a_waiting_retention_worker_from_scrubbing_the_new_value(): void
    {
        $this->race(true);
    }

    public function test_retention_winning_the_row_lock_prevents_a_waiting_stale_edit_from_resurrecting_expired_content(): void
    {
        $this->race(false);
    }

    private function race(bool $renewalWins): void
    {
        $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'area_id' => $this->area,
            'expected_version' => 0, 'confirmed' => true, 'type' => 'preference', 'key' => 'reply_length', 'value' => 'brief', 'retention_days' => 7];
        app(PrivateMemory::class)->save($this->tenant, $this->user, $input);
        $row = TeamOperationRecord::query()->findOrFail($input['id']);
        $row->data = array_replace($row->data, ['expires_at' => now()->subMinute()->toIso8601String()]);
        $row->ends_on = now()->subDay()->toDateString();
        $row->save();
        $edit = array_replace($input, ['request_id' => (string) Str::uuid(), 'expected_version' => 1, 'value' => 'standard']);
        $dir = $this->runtime.'/memory-race';
        mkdir($dir);
        $settings = [];
        foreach (['app.url', 'app.key', 'app.env', 'enterprise', 'enterprise_runtime', 'enterprise_client', 'team_work', 'session.path', 'session.driver', 'cache.default'] as $name) {
            $settings[$name] = config($name);
        }
        $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app=require 'apps/enterprise/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$app['config']->set($d['config']);$app->detectEnvironment(fn()=>'testing');
Illuminate\Support\Facades\Http::preventStrayRequests();
$connection=Illuminate\Support\Facades\DB::connection();
$id=$connection->selectOne('SELECT CONNECTION_ID() AS id')->id;
$connection->beforeExecuting(function($sql,$bindings)use($d){
    if(str_contains(strtolower($sql),'for update')&&str_contains($sql,'team_operation_records')&&in_array(App\Enterprise\Conversation\PrivateMemory::KIND,$bindings,true))file_put_contents($d['dir'].'/lock-attempt','attempt');
});
if($d['prune'])$result=['pruned'=>app(App\Enterprise\Conversation\MemoryRetention::class)->prune(),'status'=>200];
else{
    $app->make('auth')->guard()->setUser(App\Models\User::query()->findOrFail($d['user_id']));
    $request=Illuminate\Http\Request::create('https://client.example.invalid/enterprise-campus/enterprise/conversation/memories?tenant='.$d['slug'],'POST',[],[],[],
        ['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','SCRIPT_FILENAME'=>$app->publicPath('index.php'),'SCRIPT_NAME'=>'/enterprise-campus/index.php','PHP_SELF'=>'/enterprise-campus/index.php'],json_encode($d['input'],JSON_THROW_ON_ERROR));
    $response=$app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);$result=['status'=>$response->getStatusCode()];
}
Illuminate\Support\Facades\Http::assertNothingSent();
echo json_encode($result+['connection'=>(int)$id],JSON_THROW_ON_ERROR);
WORKER;
        $cfg = $this->databaseConfiguration();
        $env = ['APP_ENV' => 'testing', 'ENTERPRISE_APP_ENV' => 'testing', 'ENTERPRISE_RUNTIME_ISOLATED' => 'true', 'ENTERPRISE_RUNTIME_ROOT' => $this->runtime,
            'ENTERPRISE_APP_KEY' => config('app.key'), 'ENTERPRISE_DB_DRIVER' => 'mysql', 'ENTERPRISE_DB_HOST' => $cfg['host'],
            'ENTERPRISE_DB_PORT' => (string) $cfg['port'], 'ENTERPRISE_DB_DATABASE' => $cfg['database'],
            'ENTERPRISE_DB_USERNAME' => $cfg['username'], 'ENTERPRISE_DB_PASSWORD' => $cfg['password']];
        $process = new Process([PHP_BINARY, '-r', $worker], dirname(__DIR__, 2), $env,
            json_encode(['config' => $settings, 'dir' => $dir, 'prune' => $renewalWins, 'input' => $edit,
                'user_id' => $this->user->id, 'slug' => $this->tenant->slug], JSON_THROW_ON_ERROR), 20);
        $connection = DB::connection();
        $connection->beginTransaction();
        try {
            TeamOperationRecord::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $deadline = microtime(true) + 10;
            while (! file_exists($dir.'/lock-attempt') && microtime(true) < $deadline && $process->isRunning()) {
                usleep(10000);
            }
            $this->assertFileExists($dir.'/lock-attempt', $process->getErrorOutput().$process->getOutput());
            usleep(100000);
            $this->assertTrue($process->isRunning(), 'The independent worker must wait for the held memory row lock.');
            if ($renewalWins) {
                app(PrivateMemory::class)->save($this->tenant, $this->user, $edit);
            } else {
                $this->assertSame(1, app(MemoryRetention::class)->prune());
            }
            $connection->commit();
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput().$process->getOutput());
            $result = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertNotSame((int) $connection->selectOne('SELECT CONNECTION_ID() AS id')->id, $result['connection']);
            $this->assertSame($renewalWins ? 200 : 409, $result['status']);
            if ($renewalWins) {
                $this->assertSame(0, $result['pruned']);
                $this->assertSame('active', $row->fresh()->status);
                $this->assertSame('standard', $row->fresh()->data['value']);
            } else {
                $this->assertSame('expired', $row->fresh()->status);
                $this->assertSame(['expired_at'], array_keys($row->fresh()->data));
            }
            $this->assertSame(2, $row->fresh()->version);
            $this->assertSame(0, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        } finally {
            if ($connection->transactionLevel()) {
                $connection->rollBack();
            }
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}
