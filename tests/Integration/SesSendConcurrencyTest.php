<?php

use App\Models\MarketingEmailDelivery;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(Tests\TestCase::class);

it('serializes SES claims, replacement deliveries and the final account attempt across MySQL processes', function (string $scenario): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())) {
        $this->markTestSkipped('Requires disposable MySQL.');
    }
    $tenant = Tenant::create(['name' => 'SES concurrency fixture', 'slug' => 'ses-test-'.Str::lower((string) Str::ulid())]);
    $otherTenant = $scenario === 'cap' ? Tenant::create(['name' => 'Other SES fixture', 'slug' => 'ses-test-'.Str::lower((string) Str::ulid())]) : $tenant;
    $first = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'provider' => 'ses_tenant', 'email' => 'fixture@example.test', 'status' => 'sending']);
    $second = $scenario === 'same' ? $first : MarketingEmailDelivery::create(['tenant_id' => $otherTenant->id, 'provider' => 'ses_tenant', 'email' => $scenario === 'replacement' ? $first->email : 'other@example.test', 'status' => 'sending']);
    $dir = sys_get_temp_dir().'/ses-test-'.Str::uuid();
    mkdir($dir);
    $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d = json_decode(stream_get_contents(STDIN), true);
config(['cache.default' => 'database', 'cache.prefix' => $d['prefix'], 'ses_migration.approved_attempt_cost_micros' => 200,
    'ses_migration.max_per_second' => 100, 'ses_migration.account_daily_attempts' => $d['scenario'] === 'cap' ? 1 : 100]);
file_put_contents($d['dir'].'/ready-'.getmypid(), 'ready');
for ($i = 0; ! file_exists($d['dir'].'/go') && $i < 1000; $i++) { usleep(10000); }
try {
    $replay = app(App\Services\Marketing\Email\SesSendSafety::class)->claim($d['delivery'], $d['tenant'], $d['email'], [
        'TenantName' => 'everbranch-tenant-'.$d['tenant'], 'ConfigurationSetName' => 'fixture', 'FromEmailAddress' => 'sender@example.test']);
    echo $replay ? 'held' : 'claimed';
} catch (RuntimeException $e) {
    echo str_contains($e->getMessage(), 'cap reached') ? 'capped' : 'blocked';
}
WORKER;
    $cfg = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'], 'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password'], 'CACHE_STORE' => 'database', 'CACHE_PREFIX' => $tenant->slug, 'LOG_CHANNEL' => 'null'];
    $processes = [];
    try {
        foreach ([$first, $second] as $delivery) {
            $process = new Process([PHP_BINARY, '-r', $worker], base_path(), $environment, json_encode([
                'tenant' => $delivery->tenant_id, 'delivery' => $delivery->id, 'email' => $delivery->email,
                'dir' => $dir, 'scenario' => $scenario, 'prefix' => $tenant->slug,
            ]), 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (count(glob($dir.'/ready-*')) < 2 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($dir.'/ready-*')))->toBe(2);
        file_put_contents($dir.'/go', 'go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        sort($results);
        $expected = match ($scenario) {
            'same' => ['claimed', 'held'], 'replacement' => in_array('held', $results, true) ? ['claimed', 'held'] : ['blocked', 'claimed'], 'cap' => ['capped', 'claimed'],
        };
        expect($results)->toBe($expected)
            ->and(MarketingEmailDelivery::whereIn('tenant_id', [$tenant->id, $otherTenant->id])->where('metadata->ses_attempt->state', 'submitting')->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }
        rmdir($dir);
        MarketingEmailDelivery::whereIn('tenant_id', [$tenant->id, $otherTenant->id])->delete();
        if ($otherTenant->id !== $tenant->id) {
            $otherTenant->delete();
        }
        $tenant->delete();
    }
})->with(['same', 'replacement', 'cap']);
