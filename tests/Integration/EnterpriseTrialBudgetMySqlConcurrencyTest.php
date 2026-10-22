<?php

namespace Tests\Integration;

use App\Enterprise\Trial\TrialBudget;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Testing\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

/** Real independent connections; never runs against a configured customer database. */
final class EnterpriseTrialBudgetMySqlConcurrencyTest extends TestCase
{
    public function createApplication()
    {
        $app = require dirname(__DIR__, 2).'/apps/enterprise/bootstrap/app.php';
        $this->traitsUsedByTest = array_flip(class_uses_recursive(self::class));
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }

    public function test_final_lifetime_allowance_serializes_across_sessions_and_channels_without_refunding_uncertainty(): void
    {
        if (getenv('ENTERPRISE_DISPOSABLE_MYSQL') !== '1' || config('database.connections.enterprise.driver') !== 'mysql') {
            $this->markTestSkipped('Requires explicit disposable Enterprise MySQL; SQLite cannot exercise GET_LOCK concurrency.');
        }
        $cfg = config('database.connections.enterprise');
        if (! app()->environment('testing') || $cfg['database'] !== 'everbranch_enterprise_ci'
            || ! in_array($cfg['host'], ['127.0.0.1', 'localhost'], true)) {
            throw new \RuntimeException('Refusing budget concurrency fixtures outside isolated local Enterprise CI MySQL.');
        }
        Http::preventStrayRequests();
        $this->assertSame(0, $this->app->make(Kernel::class)->call('migrate', ['--force' => true]));
        $this->assertFalse(TeamOperationRecord::query()->where('kind', 'ent_trial_budget')->exists(), 'Requires a fresh disposable campaign ledger.');
        $tenant = Tenant::query()->create(['name' => 'Synthetic trial budget concurrency', 'slug' => 'trial-race-'.Str::lower((string) Str::ulid())]);
        $area = TeamArea::query()->create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'name' => 'Synthetic trial budget only',
            'slug' => 'synthetic-trial', 'profile' => 'enterprise_department', 'version' => 1, 'settings' => ['synthetic_fixture' => true]]);
        $trial = ['enabled' => true, 'tenant_id' => $tenant->id, 'area_id' => $area->id, 'prior_spend_verified' => true,
            'prior_spend_microusd' => 2000000, 'prior_spend_evidence' => 'Synthetic prior-cost fixture, not provider evidence'];
        config(['enterprise.runtime' => true, 'enterprise.trial' => $trial]);
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enterprise-trial-concurrency-'.Str::uuid();
        mkdir($dir);
        $processes = [];
        $held = false;
        try {
            $budget = app(TrialBudget::class);
            $old = $budget->reserve('acceptance', 'synthetic-prior-acceptance', hash('sha256', 'prior'), 2500000);
            $budget->settle($old['id'], 'accepted', ['status' => 'synthetic-accepted'], 2500000);
            $this->assertSame(4500000, $budget->status()['used_or_reserved_microusd']);
            $this->assertSame(1, (int) DB::selectOne("SELECT GET_LOCK('everbranch-reviewer-trial-budget', 1) AS acquired")->acquired);
            $held = true;
            $worker = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'apps/enterprise/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
config(['enterprise.runtime' => true, 'enterprise.trial' => $d['trial']]);
Illuminate\Support\Facades\Http::preventStrayRequests();
$session = new Illuminate\Session\Store('synthetic-trial-race', new Illuminate\Session\ArraySessionHandler(120));
$session->start();
$session->put('enterprise.trial.session', $d['session']);
$request = Illuminate\Http\Request::create('https://synthetic.example.invalid/enterprise/trial', 'POST');
$request->setLaravelSession($session);
$app->instance('request', $request);
// Hold the first admitted insert long enough to expose an unprotected read/check/write race.
App\Models\TeamOperationRecord::creating(function ($row) use ($d) {
    if ($row->kind !== 'ent_trial_charge') return;
    $lock = Illuminate\Support\Facades\DB::selectOne("SELECT IS_USED_LOCK('everbranch-reviewer-trial-budget') AS holder, CONNECTION_ID() AS own_id");
    if ((int) $lock->holder !== (int) $lock->own_id) throw new RuntimeException('Reservation did not own the campaign named lock.');
    file_put_contents($d['dir'].'/admitted-'.$d['channel'], 'inside critical section');
    usleep(300000);
});
$connection = Illuminate\Support\Facades\DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
file_put_contents($d['dir'].'/ready-'.$d['channel'], (string) $connection);
$deadline = microtime(true) + 10;
while (!file_exists($d['dir'].'/go') && microtime(true) < $deadline) usleep(10000);
if (!file_exists($d['dir'].'/go')) throw new RuntimeException('Parent barrier did not open.');
file_put_contents($d['dir'].'/attempt-'.$d['channel'], 'attempt');
try {
    $reserved = app(App\Enterprise\Trial\TrialBudget::class)->reserve($d['channel'], $d['key'], $d['digest'], 500000);
    $result = ['status' => 200, 'charge_id' => $reserved['id']];
} catch (Symfony\Component\HttpKernel\Exception\HttpException $e) {
    $result = ['status' => $e->getStatusCode()];
}
Illuminate\Support\Facades\Http::assertNothingSent();
echo json_encode($result + ['channel' => $d['channel'], 'session' => $session->get('enterprise.trial.session'), 'connection' => (int) $connection, 'no_http' => true], JSON_THROW_ON_ERROR);
PHP;
            $env = ['APP_ENV' => 'testing', 'ENTERPRISE_APP_ENV' => 'testing', 'ENTERPRISE_RUNTIME_ISOLATED' => 'true',
                'ENTERPRISE_APP_KEY' => config('app.key'), 'ENTERPRISE_DB_DRIVER' => 'mysql', 'ENTERPRISE_DB_HOST' => $cfg['host'],
                'ENTERPRISE_DB_PORT' => (string) $cfg['port'], 'ENTERPRISE_DB_DATABASE' => $cfg['database'],
                'ENTERPRISE_DB_USERNAME' => $cfg['username'], 'ENTERPRISE_DB_PASSWORD' => $cfg['password']];
            $inputs = [];
            foreach (['sms', 'email', 'ai', 'acceptance'] as $channel) {
                $session = (string) Str::uuid();
                $inputs[$channel] = ['key' => 'synthetic-session:'.$session, 'digest' => hash('sha256', $session)];
                $process = new Process([PHP_BINARY, '-r', $worker], base_path(), $env,
                    json_encode(['trial' => $trial, 'channel' => $channel, 'session' => $session, 'dir' => $dir, ...$inputs[$channel]], JSON_THROW_ON_ERROR), 25);
                $process->start();
                $processes[] = $process;
            }
            $this->waitForFiles($dir.'/ready-*', 4);
            file_put_contents($dir.'/go', 'go');
            $this->waitForFiles($dir.'/attempt-*', 4);
            usleep(200000);
            $this->assertCount(0, glob($dir.'/admitted-*'), 'No reservation may bypass the externally held campaign lock.');
            foreach ($processes as $process) {
                $this->assertTrue($process->isRunning(), 'Each independent caller must wait for the campaign lock.');
            }
            $this->assertSame(1, (int) DB::selectOne("SELECT RELEASE_LOCK('everbranch-reviewer-trial-budget') AS released")->released);
            $held = false;
            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }
            $this->assertEqualsCanonicalizing([200, 429, 429, 429], array_column($results, 'status'));
            $this->assertEqualsCanonicalizing(['sms', 'email', 'ai', 'acceptance'], array_column($results, 'channel'));
            $this->assertCount(4, array_unique(array_column($results, 'connection')), 'Independent real MySQL sessions are required.');
            $this->assertCount(4, array_unique(array_column($results, 'session')));
            $this->assertSame([true, true, true, true], array_column($results, 'no_http'));
            $this->assertCount(1, glob($dir.'/admitted-*'));
            $this->assertSame(2, TeamOperationRecord::query()->where(['tenant_id' => $tenant->id, 'kind' => 'ent_trial_charge'])->count());
            $status = $budget->status();
            $this->assertSame(TrialBudget::CAP, $status['used_or_reserved_microusd']);
            $this->assertSame(2000000, $status['prior_microusd']);
            $this->assertSame(0, $status['remaining_microusd']);
            $winner = collect($results)->firstWhere('status', 200);
            $input = $inputs[$winner['channel']];
            $budget->claimDispatch($winner['charge_id'], $input['digest']);
            $budget->settle($winner['charge_id'], 'uncertain', ['status' => 'synthetic-timeout-no-provider-call'], 0);
            $charge = TeamOperationRecord::query()->findOrFail($winner['charge_id']);
            $this->assertSame('uncertain', $charge->status);
            $this->assertSame(500000, $charge->data['charged_microusd'], 'An uncertain outcome cannot refund its reservation, even if a caller supplies zero.');
            $this->assertSame(TrialBudget::CAP, $budget->status()['used_or_reserved_microusd']);
            foreach ([['channel' => 'email', 'key' => 'synthetic-next-session', 'digest' => 'new', 'maximum' => 1, 'status' => 429],
                ['channel' => $winner['channel'], ...$input, 'maximum' => 500000, 'status' => 409]] as $attempt) {
                try {
                    $budget->reserve($attempt['channel'], $attempt['key'], $attempt['digest'], $attempt['maximum']);
                    $this->fail('A new or uncertain paid action unexpectedly bypassed the lifetime cap.');
                } catch (HttpException $e) {
                    $this->assertSame($attempt['status'], $e->getStatusCode());
                }
            }
            Http::assertNothingSent();
            $this->assertNull(DB::selectOne("SELECT IS_USED_LOCK('everbranch-reviewer-trial-budget') AS holder")->holder, 'Named locks must be released after rejected reservations.');
        } finally {
            if ($held) {
                DB::selectOne("SELECT RELEASE_LOCK('everbranch-reviewer-trial-budget') AS released");
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
            DB::table('team_operation_revisions')->where('tenant_id', $tenant->id)->delete();
            DB::table('team_operation_records')->where('tenant_id', $tenant->id)->delete();
            DB::table('team_work_locks')->where('tenant_id', $tenant->id)->delete();
            $area->delete();
            $tenant->delete();
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    private function waitForFiles(string $pattern, int $count): void
    {
        $deadline = microtime(true) + 8;
        while (count(glob($pattern)) < $count && microtime(true) < $deadline) {
            usleep(10000);
        }
        $this->assertCount($count, glob($pattern), 'Every independent worker must reach the synchronization barrier.');
    }
}
