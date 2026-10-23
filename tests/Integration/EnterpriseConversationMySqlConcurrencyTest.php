<?php

namespace Tests\Integration;

use App\Enterprise\Trial\TrialBudget;
use App\Models\TeamOperationRecord;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Support\EnterpriseConversationFixture;

/** Actual authenticated routes and independent MySQL workers; provider HTTP is always fake. */
final class EnterpriseConversationMySqlConcurrencyTest extends EnterpriseConversationFixture
{
    protected function setUp(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || getenv('ENTERPRISE_DB_DRIVER') !== 'mysql') {
            $this->markTestSkipped('Requires explicitly disposable local Enterprise MySQL for real provider-route concurrency.');
        }
        parent::setUp();
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
            throw new \RuntimeException('Refusing conversation fixtures outside explicit disposable local Enterprise CI MySQL.');
        }
        // Requires an empty fixture database and preserves the managed-runtime destructive-command guard.
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        $this->assertFalse(DB::table('tenants')->exists(), 'Run before retained MySQL recovery fixtures.');
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

    public function test_actual_provider_route_serializes_final_allowance_and_exact_duplicates_across_workers(): void
    {
        config(['enterprise.trial.prior_spend_microusd' => 4979700]);
        $this->approve();
        $results = $this->workers(false);
        $this->assertEqualsCanonicalizing([200, 429, 429, 429], array_column($results, 'status'));
        $this->assertCount(4, array_unique(array_column($results, 'connection')));
        $this->assertSame(1, array_sum(array_column($results, 'provider_calls')));
        $this->assertSame(4980050, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame('accepted', TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail()->status);

        // A second independent-worker race repeats the exact winning paid turn/key. No new HTTP.
        $winner = collect($results)->firstWhere('status', 200);
        $replays = $this->workers(true, $winner['session_id'], $winner['request_id']);
        $this->assertSame([200, 200, 200, 200], array_column($replays, 'status'));
        $this->assertSame([true, true, true, true], array_column($replays, 'replayed'));
        $this->assertSame(0, array_sum(array_column($replays, 'provider_calls')));
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(4980050, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->assertNull(DB::selectOne("SELECT IS_USED_LOCK('everbranch-reviewer-trial-budget') AS holder")->holder);
    }

    public function test_simultaneous_identical_initial_turns_have_only_one_durable_provider_dispatch(): void
    {
        config(['enterprise.trial.prior_spend_microusd' => 4979700]);
        $this->approve();
        $results = $this->workers(false, (string) \Illuminate\Support\Str::uuid(), (string) \Illuminate\Support\Str::uuid());
        $this->assertContains(200, array_column($results, 'status'));
        foreach ($results as $result) {
            $this->assertContains($result['status'], [200, 409]);
        }
        $this->assertSame(1, array_sum(array_column($results, 'provider_calls')));
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame(4980050, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
    }

    public function test_uncertain_provider_outcome_cannot_release_allowance_to_competing_workers(): void
    {
        config(['enterprise.trial.prior_spend_microusd' => 4979700]);
        $this->approve();
        $results = $this->workers(false, uncertain: true);
        foreach ($results as $result) {
            $this->assertContains($result['status'], [429, 503]);
        }
        $this->assertSame(1, array_sum(array_column($results, 'provider_calls')));
        $this->assertSame(1, TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->count());
        $this->assertSame('uncertain', TeamOperationRecord::query()->where('kind', 'ent_trial_charge')->firstOrFail()->status);
        $this->assertSame(TrialBudget::CAP, app(TrialBudget::class)->status()['used_or_reserved_microusd']);
        $this->assertTrue(app(TrialBudget::class)->status()['paused']);
        $this->turn()->assertStatus(503);
    }

    private function workers(bool $replay, ?string $sessionId = null, ?string $requestId = null, bool $uncertain = false): array
    {
        $dir = $this->runtime.'/race-'.bin2hex(random_bytes(4));
        mkdir($dir);
        $processes = [];
        $held = false;
        $cfg = $this->databaseConfiguration();
        $settings = [];
        foreach (['app.url', 'app.key', 'app.env', 'enterprise', 'enterprise_runtime', 'enterprise_client', 'team_work', 'session.path', 'session.driver', 'cache.default'] as $name) {
            $settings[$name] = config($name);
        }
        $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'apps/enterprise/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$app['config']->set($d['config']);
$app->detectEnvironment(fn()=>'testing');
Illuminate\Support\Facades\Http::preventStrayRequests();
$calls=0;
Illuminate\Support\Facades\Http::fake(['https://api.openai.com/v1/responses'=>function($request)use(&$calls,$d){
    $calls++;
    $row=App\Models\TeamOperationRecord::query()->where(['kind'=>'ent_trial_charge','status'=>'dispatching'])->firstOrFail();
    if($row->data['reserved_microusd']!==20300)throw new RuntimeException('No full-context reservation before provider I/O.');
    file_put_contents($d['dir'].'/provider-'.$d['worker'],'durable claim before fake HTTP');
    usleep(300000);
    if($d['uncertain']) throw new Illuminate\Http\Client\ConnectionException('Synthetic timeout after submit');
    $body=$request->data();$payload=json_decode($body['input'][0]['content'],true,32,JSON_THROW_ON_ERROR);
    return Illuminate\Support\Facades\Http::response(['id'=>'resp_synthetic','model'=>$body['model'],'service_tier'=>'default','status'=>'completed',
        'usage'=>['input_tokens'=>100,'output_tokens'=>50],
        'output'=>[['type'=>'message','role'=>'assistant','content'=>[['type'=>'output_text','text'=>json_encode(['answer'=>'Inspect this current saved project.','refs'=>array_slice(array_column($payload['sources'],'ref'),0,1)])]]]]]);
}]);
$connection=Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($d['dir'].'/ready-'.$d['worker'],(string)$connection);
$deadline=microtime(true)+10;while(!file_exists($d['dir'].'/go')&&microtime(true)<$deadline)usleep(10000);
if(!file_exists($d['dir'].'/go'))throw new RuntimeException('Parent barrier did not open.');
file_put_contents($d['dir'].'/attempt-'.$d['worker'],'attempt');
$app->make('auth')->guard()->setUser(App\Models\User::query()->findOrFail($d['user_id']));
$request=Illuminate\Http\Request::create('https://client.example.invalid/enterprise-campus/enterprise/conversation/turns?tenant='.$d['slug'],'POST',[],[],[],
    ['CONTENT_TYPE'=>'application/json','HTTP_ACCEPT'=>'application/json','SCRIPT_FILENAME'=>$app->publicPath('index.php'),
        'SCRIPT_NAME'=>'/enterprise-campus/index.php','PHP_SELF'=>'/enterprise-campus/index.php'],json_encode($d['input'],JSON_THROW_ON_ERROR));
$response=$app->make(Illuminate\Contracts\Http\Kernel::class)->handle($request);
$result=json_decode($response->getContent(),true);
echo json_encode(['status'=>$response->getStatusCode(),'provider_calls'=>$calls,'connection'=>(int)$connection,
    'replayed'=>$result['replayed']??false,'session_id'=>$d['input']['session_id'],'request_id'=>$d['input']['request_id']],JSON_THROW_ON_ERROR);
WORKER;
        try {
            if (! $replay) {
                $this->assertSame(1, (int) DB::selectOne("SELECT GET_LOCK('everbranch-reviewer-trial-budget', 1) AS acquired")->acquired);
                $held = true;
            }
            for ($i = 0; $i < 4; $i++) {
                $input = ['area_id' => $this->area, 'intent' => 'overview', 'question' => 'What work needs attention?',
                    'session_id' => $sessionId ?? (string) \Illuminate\Support\Str::uuid(), 'request_id' => $requestId ?? (string) \Illuminate\Support\Str::uuid()];
                $env = ['APP_ENV' => 'testing', 'ENTERPRISE_APP_ENV' => 'testing', 'ENTERPRISE_RUNTIME_ISOLATED' => 'true', 'ENTERPRISE_RUNTIME_ROOT' => $this->runtime,
                    'ENTERPRISE_APP_KEY' => config('app.key'), 'ENTERPRISE_DB_DRIVER' => 'mysql', 'ENTERPRISE_DB_HOST' => $cfg['host'],
                    'ENTERPRISE_DB_PORT' => (string) $cfg['port'], 'ENTERPRISE_DB_DATABASE' => $cfg['database'],
                    'ENTERPRISE_DB_USERNAME' => $cfg['username'], 'ENTERPRISE_DB_PASSWORD' => $cfg['password']];
                $process = new Process([PHP_BINARY, '-r', $worker], dirname(__DIR__, 2), $env,
                    json_encode(['config' => $settings, 'dir' => $dir, 'worker' => $i, 'uncertain' => $uncertain, 'user_id' => $this->user->id, 'slug' => $this->tenant->slug, 'input' => $input], JSON_THROW_ON_ERROR), 25);
                $process->start();
                $processes[] = $process;
            }
            $this->waitForFiles($dir.'/ready-*', 4);
            file_put_contents($dir.'/go', 'go');
            $this->waitForFiles($dir.'/attempt-*', 4);
            if ($held) {
                usleep(300000);
                $this->assertCount(0, glob($dir.'/provider-*'), 'No actual provider path may bypass the externally held campaign lock.');
                foreach ($processes as $process) {
                    $this->assertTrue($process->isRunning(), $process->getErrorOutput().$process->getOutput());
                }
                DB::selectOne("SELECT RELEASE_LOCK('everbranch-reviewer-trial-budget') AS released");
                $held = false;
            }
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertCount($replay ? 0 : 1, glob($dir.'/provider-*'));

            return $results;
        } finally {
            if ($held) {
                DB::selectOne("SELECT RELEASE_LOCK('everbranch-reviewer-trial-budget') AS released");
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    private function waitForFiles(string $pattern, int $count): void
    {
        $deadline = microtime(true) + 10;
        while (count(glob($pattern)) < $count && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertCount($count, glob($pattern));
    }
}
