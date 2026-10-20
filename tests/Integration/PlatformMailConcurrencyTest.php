<?php

use App\Models\MarketingEmailDelivery;
use App\Models\PlatformEmailDelivery;
use App\Models\PlatformEmailMessage;
use App\Models\Tenant;
use App\Services\Mail\PlatformSesOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

uses(Tests\TestCase::class);

it('serializes platform claims recipient holds and shared tenant account admission on MySQL', function (string $scenario): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('SES_PLATFORM_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicit disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing concurrency fixtures outside local disposable MySQL.');
    }
    $tenant = Tenant::create(['name' => 'Platform concurrency fixture', 'slug' => 'ses-platform-test-'.Str::lower((string) Str::ulid())]);
    $messages = [];
    $rows = [];
    foreach ([0, 1] as $index) {
        if ($index === 1 && $scenario === 'same') {
            $rows[] = $rows[0];

            continue;
        }
        $email = $index === 1 && $scenario !== 'replacement' ? 'other@example.test' : 'fixture@example.test';
        $message = PlatformEmailMessage::create(['group_key' => hash('sha256', Str::uuid()), 'payload_hash' => hash('sha256', 'fixture'),
            'mime' => "From: no-reply@theeverbranch.com\r\nTo: $email\r\nSubject: Fixture\r\n\r\nNon-customer fixture", 'tenant_id' => $tenant->id,
            'from_email' => 'no-reply@theeverbranch.com', 'configuration_set' => 'platform-concurrency-fixture']);
        $messages[] = $message->id;
        $rows[] = PlatformEmailDelivery::create(['platform_email_message_id' => $message->id,
            'recipient' => $email, 'recipient_hash' => app(PlatformSesOutbox::class)->recipientHash($email), 'provider_cost_micros' => 1000, 'state' => 'queued']);
    }
    $marketing = $scenario === 'mixed_cap' ? MarketingEmailDelivery::create(['tenant_id' => $tenant->id, 'provider' => 'ses_tenant', 'email' => 'tenant@example.test', 'status' => 'sending']) : null;
    $dir = sys_get_temp_dir().'/ses-platform-test-'.Str::uuid();
    mkdir($dir);
    $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$d = json_decode(stream_get_contents(STDIN), true);
config(['cache.default' => 'database', 'cache.prefix' => $d['prefix'], 'ses_migration.platform_enabled' => true,
    'ses_migration.platform_key' => 'fixture', 'ses_migration.platform_secret' => 'fixture',
    'ses_migration.platform_from' => 'no-reply@theeverbranch.com', 'ses_migration.platform_configuration_set' => 'platform-concurrency-fixture',
    'ses_migration.platform_recipients' => ['fixture@example.test','other@example.test'], 'ses_migration.approved_attempt_cost_micros' => 1000,
    'ses_migration.max_per_second' => 100, 'ses_migration.platform_daily_attempts' => 100,
    'ses_migration.account_daily_attempts' => str_contains($d['scenario'], 'cap') ? 1 : 100]);
$client = new Aws\SesV2\SesV2Client(['version'=>'latest','region'=>'us-east-1','credentials'=>false,'retries'=>0,
    'handler' => function () use ($d) { file_put_contents($d['dir'].'/send-'.getmypid(),'attempt'); throw new RuntimeException('fixture unknown'); }]);
$outbox = new class($client) extends App\Services\Mail\PlatformSesOutbox {
    public function __construct(private Aws\SesV2\SesV2Client $fixture) {}
    protected function client(): Aws\SesV2\SesV2Client { return $this->fixture; }
};
file_put_contents($d['dir'].'/ready-'.getmypid(),'ready');
for ($i=0; !file_exists($d['dir'].'/go') && $i<1000; $i++) { usleep(10000); }
try {
    if ($d['marketing']) {
        app(App\Services\Marketing\Email\SesSendSafety::class)->claim($d['marketing'],$d['tenant'],'tenant@example.test',[
            'TenantName'=>'everbranch-tenant-'.$d['tenant'],'ConfigurationSetName'=>'fixture-marketing','FromEmailAddress'=>'sender@example.test']);
        file_put_contents($d['dir'].'/send-'.getmypid(),'marketing-claim');
        echo 'marketing_claimed';
    } else { echo $outbox->submit($d['delivery']); }
} catch (RuntimeException $e) { echo str_contains($e->getMessage(),'cap reached') ? 'rate_limited' : 'blocked'; }
WORKER;
    $cfg = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $cfg['host'],
        'DB_PORT' => (string) $cfg['port'], 'DB_DATABASE' => $cfg['database'], 'DB_USERNAME' => $cfg['username'], 'DB_PASSWORD' => $cfg['password'],
        'CACHE_STORE' => 'database', 'CACHE_PREFIX' => $tenant->slug, 'LOG_CHANNEL' => 'null'];
    $processes = [];
    try {
        foreach ($rows as $index => $row) {
            $process = new Process([PHP_BINARY, '-r', $worker], base_path(), $environment, json_encode([
                'prefix' => $tenant->slug, 'scenario' => $scenario, 'dir' => $dir, 'delivery' => $row->id, 'tenant' => $tenant->id,
                'marketing' => $index === 1 ? $marketing?->id : null,
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
        $states = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $states[] = trim($process->getOutput());
        }
        expect(count(glob($dir.'/send-*')))->toBe(1);
        $submitted = PlatformEmailDelivery::whereIn('platform_email_message_id', $messages)->whereNotNull('submitted_at')->count();
        if ($marketing?->fresh()->metadata['ses_attempt'] ?? null) {
            $submitted++;
        }
        expect($submitted)->toBe(1);
        if ($scenario === 'replacement') {
            expect(in_array('held', $states, true))->toBeTrue(json_encode($states));
            expect($states)->toContain('unknown');
        }
        if (str_contains($scenario, 'cap')) {
            expect($states)->toContain('rate_limited');
        }
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
        DB::table('platform_email_deliveries')->whereIn('platform_email_message_id', $messages)->delete();
        DB::table('platform_email_messages')->whereIn('id', $messages)->delete();
        $marketing?->delete();
        $tenant->delete();
    }
})->with(['same', 'replacement', 'cap', 'mixed_cap']);
