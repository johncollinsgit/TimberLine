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

it('blocks a tenant claim waiting behind committed permanent feedback on MySQL', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! app()->environment('testing')
        || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        $this->markTestSkipped('Requires local disposable MySQL.');
    }
    $tenant = Tenant::create(['name' => 'SES feedback race fixture', 'slug' => 'ses-feedback-test-'.Str::lower((string) Str::ulid())]);
    $sent = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'provider' => 'ses_tenant',
        'email' => 'fixture@example.test', 'status' => 'sent', 'provider_message_id' => 'feedback-receipt',
        'metadata' => ['ses_attempt' => ['state' => 'sent', 'tenant_name' => 'everbranch-tenant-'.$tenant->id,
            'configuration_set' => 'feedback-fixture', 'from' => 'sender@example.test']]]);
    $queued = MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'provider' => 'ses_tenant',
        'email' => $sent->email, 'status' => 'queued']);
    $ready = sys_get_temp_dir().'/ses-feedback-test-'.Str::uuid();
    $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d = json_decode(stream_get_contents(STDIN), true);
config(['cache.default'=>'database','cache.prefix'=>$d['prefix'],'ses_migration.approved_attempt_cost_micros'=>200,'ses_migration.max_per_second'=>100]);
file_put_contents($d['ready'], 'ready');
try {
    $result = app(App\Services\Marketing\Email\SesSendSafety::class)->claim($d['delivery'],$d['tenant'],'fixture@example.test',[
        'TenantName'=>'everbranch-tenant-'.$d['tenant'],'ConfigurationSetName'=>'feedback-fixture','FromEmailAddress'=>'sender@example.test']);
    echo ($result['error_code'] ?? '') === 'ses_recipient_suppressed' ? 'suppressed' : ($result ? 'held' : 'claimed');
} catch (RuntimeException $e) { echo $e->getMessage() === 'SES recipient is held by permanent feedback.' ? 'suppressed' : 'other_failure'; }
WORKER;
    $cfg = config('database.connections.mysql');
    $process = new Process([PHP_BINARY, '-r', $worker], base_path(), [
        'APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $cfg['host'],
        'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'], 'DB_USERNAME' => $cfg['username'],
        'DB_PASSWORD' => $cfg['password'], 'CACHE_STORE' => 'database', 'LOG_CHANNEL' => 'null',
    ], json_encode(['prefix' => $tenant->slug, 'ready' => $ready, 'tenant' => $tenant->id, 'delivery' => $queued->id]), 30);
    try {
        DB::beginTransaction();
        $summary = app(App\Services\Marketing\Email\SesDeliveryEvents::class)->handle([
            'eventType' => 'Complaint', 'mail' => ['messageId' => 'feedback-receipt', 'source' => 'sender@example.test',
                'timestamp' => now()->toIso8601String(), 'destination' => [$sent->email],
                'tags' => ['tenant_id' => [(string) $tenant->id], 'marketing_email_delivery_id' => [(string) $sent->id],
                    'ses:tenant' => ['everbranch-tenant-'.$tenant->id], 'ses:configuration-set' => ['feedback-fixture']]],
            'complaint' => ['timestamp' => now()->toIso8601String(), 'complainedRecipients' => [['emailAddress' => $sent->email]]],
        ], 'feedback-race');
        expect($summary['updated'])->toBe(1);
        $process->start();
        $deadline = microtime(true) + 15;
        while (! file_exists($ready) && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(file_exists($ready))->toBeTrue();
        usleep(200000);
        expect($process->isRunning())->toBeTrue('Claim must wait for the feedback transaction.');
        DB::commit();
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        expect(trim($process->getOutput()))->toBe('suppressed')
            ->and(data_get($queued->fresh()->metadata, 'ses_attempt'))->toBeNull();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        if ($process->isRunning()) {
            $process->stop();
        }
        if (file_exists($ready)) {
            unlink($ready);
        }
        MarketingEmailDelivery::where('tenant_id', $tenant->id)->delete();
        App\Models\MessagingContactChannelState::where('tenant_id', $tenant->id)->delete();
        $tenant->delete();
    }
});

it('admits only three simultaneous reset requests for one recipient across IPs on MySQL', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || ! app()->environment('testing')
        || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        $this->markTestSkipped('Requires local disposable MySQL.');
    }
    $dir = sys_get_temp_dir().'/reset-admission-test-'.Str::uuid();
    mkdir($dir);
    $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d = json_decode(stream_get_contents(STDIN), true);
config(['cache.default'=>'database']);
$request = Illuminate\Http\Request::create('/forgot-password','POST',['email'=>'fixture@example.test'],[],[],['REMOTE_ADDR'=>$d['ip']]);
$route = (new Illuminate\Routing\Route('POST','forgot-password',fn () => null))->name('password.email');
$request->setRouteResolver(fn () => $route);
file_put_contents($d['dir'].'/ready-'.getmypid(),'ready');
for ($i=0; !file_exists($d['dir'].'/go') && $i<1000; $i++) { usleep(10000); }
try {
    app(App\Http\Middleware\ThrottlePasswordResetEmail::class)->handle($request,function () use ($d) {
        file_put_contents($d['dir'].'/admitted-'.getmypid(),'admitted');
        return response('admitted');
    });
    echo 'admitted';
} catch (Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) { echo $e->getStatusCode() === 429 ? 'capped' : 'other_failure'; }
WORKER;
    $cfg = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $cfg['host'], 'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'],
        'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password'], 'CACHE_STORE' => 'database',
        'CACHE_PREFIX' => 'reset-race-'.Str::uuid(), 'LOG_CHANNEL' => 'null'];
    $processes = [];
    try {
        for ($i = 1; $i <= 4; $i++) {
            $process = new Process([PHP_BINARY, '-r', $worker], base_path(), $environment,
                json_encode(['dir' => $dir, 'ip' => '192.0.2.'.$i]), 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (count(glob($dir.'/ready-*')) < 4 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($dir.'/ready-*')))->toBe(4);
        file_put_contents($dir.'/go', 'go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = trim($process->getOutput());
        }
        sort($results);
        expect($results)->toBe(['admitted', 'admitted', 'admitted', 'capped'])
            ->and(count(glob($dir.'/admitted-*')))->toBe(3);
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
    }
});
