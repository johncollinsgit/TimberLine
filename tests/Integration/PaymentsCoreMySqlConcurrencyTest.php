<?php

use App\Services\PaymentsCore\ClosedPaymentAccess;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentCore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PaymentsCore\DurableFixtureProvider;
use Tests\Support\PaymentsCore\MySqlFixture;

require_once __DIR__.'/../Support/PaymentsCore/MySqlFixture.php';

uses(Tests\TestCase::class);

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('PAYMENTS_CORE_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly opted-in local disposable MySQL.');
    }
    MySqlFixture::guard();
    $migration = require database_path('migrations/2026_10_22_190000_create_payment_core_journal_tables.php');
    $migration->up();
    $this->tenants = collect([MySqlFixture::tenant()]);
    $this->binding = MySqlFixture::binding($this->tenants[0]->id);
    $this->provider = new DurableFixtureProvider;
    $this->journal = MySqlFixture::journal();
    $this->core = MySqlFixture::core($this->binding->tenantId, $this->provider);
});

afterEach(function (): void {
    foreach ($this->tenants ?? [] as $tenant) {
        MySqlFixture::purgeTenant($tenant->id);
    }
    Http::assertNothingSent();
});

function runPaymentCoreRace(array $jobs, bool $snapshots = false): array
{
    $directory = sys_get_temp_dir().'/payments-core-race-'.Str::uuid();
    mkdir($directory, 0700);
    $db = DB::connection()->getConfig();
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
        'DB_CONNECTION' => 'mysql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
        'DB_SOCKET' => (string) ($db['unix_socket'] ?? ''), 'DB_DATABASE' => $db['database'],
        'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => (string) $db['password'],
        'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync',
        'SESSION_DRIVER' => 'array', 'LOG_CHANNEL' => 'null', 'PAYMENTS_CORE_DISPOSABLE_MYSQL' => '1'];
    $processes = [];
    $wait = function (string $pattern) use ($directory, $jobs, &$processes): void {
        $deadline = microtime(true) + 15;
        while (count(glob($directory.'/'.$pattern) ?: []) !== count($jobs) && microtime(true) < $deadline) {
            foreach ($processes as $process) {
                if (! $process->isRunning() && ! $process->isSuccessful()) {
                    throw new RuntimeException('Payment worker failed before barrier: '.$process->getErrorOutput());
                }
            }
            usleep(10000);
        }
        if (count(glob($directory.'/'.$pattern) ?: []) !== count($jobs)) {
            throw new RuntimeException('Payment workers failed to reach bounded barrier.');
        }
    };
    try {
        foreach ($jobs as $index => $job) {
            $process = new Process([PHP_BINARY, base_path('tests/Support/PaymentsCore/MySqlWorker.php')], base_path(),
                $environment, json_encode([...$job, 'index' => $index, 'directory' => $directory], JSON_THROW_ON_ERROR), 35);
            $process->start();
            $processes[] = $process;
        }
        $wait('ready-*');
        file_put_contents($directory.'/go', 'go');
        if ($snapshots) {
            $wait('snapshot-*');
            file_put_contents($directory.'/snapshot-go', 'go');
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            if (! $process->isSuccessful()) {
                throw new RuntimeException('Payment concurrency worker failed: '.$process->getErrorOutput());
            }
            $results[] = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
}

it('commits encrypted intent and dispatch claim before independent provider acceptance and recovers response loss', function (): void {
    $this->provider->loseResponse = true;
    $row = $this->core->start($this->binding, MySqlFixture::sale(), 'lost_response');
    expect($row['state'])->toBe('unknown')->and($this->provider->calls($this->binding->tenantId))->toBe(1);
    $raw = DB::table('payment_core_operations')->where('id', $row['id'])->first();
    expect($raw->binding)->not->toContain('alpha_fixture')->and($raw->terms)->not->toContain('printing_service');
    $restarted = MySqlFixture::core($this->binding->tenantId, new DurableFixtureProvider);
    expect($restarted->start($this->binding, MySqlFixture::sale(), 'lost_response')['id'])->toBe($row['id'])
        ->and($restarted->recover($this->binding, $row['id'])['state'])->toBe('succeeded')
        ->and($this->provider->calls($this->binding->tenantId))->toBe(1);
});

it('keeps a post-claim process crash unknown and never issues a replacement operation', function (): void {
    $row = $this->journal->reservePayment($this->binding, MySqlFixture::sale(), 'claimed_before_crash');
    expect($this->journal->claim($this->binding->tenantId, $row['id']))->toBeTrue();
    expect($this->core->start($this->binding, MySqlFixture::sale(), 'claimed_before_crash')['state'])->toBe('processing')
        ->and($this->core->recover($this->binding, $row['id'])['state'])->toBe('unknown');
    expect(fn () => $this->core->start($this->binding, MySqlFixture::sale('replacement'), 'replacement_request'))->toThrow(DomainException::class);
    expect($this->provider->calls($this->binding->tenantId))->toBe(0);
});

it('refuses caller transactions before creation or recovery can contact any provider', function (): void {
    $this->provider->loseResponse = true;
    $row = $this->core->start($this->binding, MySqlFixture::sale(), 'outer_existing');
    DB::beginTransaction();
    try {
        expect(fn () => $this->core->start($this->binding, MySqlFixture::sale('new'), 'outer_new'))->toThrow(DomainException::class, 'committed transaction boundary');
        expect(fn () => $this->core->recover($this->binding, $row['id']))->toThrow(DomainException::class, 'committed transaction boundary');
        expect(fn () => $this->core->inspect($this->binding, $row['id']))->toThrow(DomainException::class, 'committed transaction boundary');
    } finally {
        DB::rollBack();
    }
    expect($this->provider->calls($this->binding->tenantId))->toBe(1);
});

it('keeps inspection provider-free and every binding dimension immutable', function (): void {
    $row = $this->core->start($this->binding, MySqlFixture::sale(), 'inspection');
    $before = DB::table('payment_core_events')->count();
    expect($this->core->inspect($this->binding, $row['id'])['id'])->toBe($row['id'])
        ->and(DB::table('payment_core_events')->count())->toBe($before);
    $changed = new MerchantBinding($this->binding->tenantId, $this->binding->registerId, 'fixture_provider', 'other_merchant', 'fixture_location', 'test');
    expect(fn () => $this->core->inspect($changed, $row['id']))->toThrow(DomainException::class)
        ->and(fn () => $this->core->start($changed, MySqlFixture::sale('other'), 'changed'))->toThrow(DomainException::class);
    expect($this->journal->findByRequest($this->binding->tenantId, 'inspection')['id'])->toBe($row['id'])
        ->and($this->journal->findByRequest($this->binding->tenantId + 1000000, 'inspection'))->toBeNull();
    expect(fn () => (new PaymentCore($this->journal, ['fixture_provider' => $this->provider], new ClosedPaymentAccess))->inspect($this->binding, $row['id']))->toThrow(DomainException::class);
});

it('admits eight concurrent identical requests but dispatches the original effect once', function (): void {
    $tenant = $this->binding->tenantId;
    $jobs = array_fill(0, 8, ['tenant' => $tenant, 'scenario' => 'replay', 'register' => 'fixture_register', 'sale' => 'shared_sale', 'request' => 'shared_request']);
    $results = runPaymentCoreRace($jobs);
    expect(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and($this->provider->calls($tenant))->toBe(1)
        ->and(DB::table('payment_core_operations')->where('tenant_id', $tenant)->count())->toBe(1);
});

it('resolves absent-key competition across eight registers without double-charging one sale', function (): void {
    $tenant = $this->binding->tenantId;
    $jobs = array_map(fn ($i) => ['tenant' => $tenant, 'scenario' => 'sale', 'register' => 'register_'.$i,
        'sale' => 'one_sale', 'request' => 'request_'.$i], range(0, 7));
    $results = runPaymentCoreRace($jobs);
    expect(collect($results)->where('state', 'blocked'))->toHaveCount(7)
        ->and($this->provider->calls($tenant))->toBe(1);
});

it('allows only one unresolved register operation from eight competing sales', function (): void {
    $tenant = $this->binding->tenantId;
    $jobs = array_map(fn ($i) => ['tenant' => $tenant, 'scenario' => 'register', 'register' => 'fixture_register',
        'sale' => 'sale_'.$i, 'request' => 'request_'.$i], range(0, 7));
    $results = runPaymentCoreRace($jobs);
    expect(collect($results)->where('state', 'blocked'))->toHaveCount(7)
        ->and($this->provider->calls($tenant))->toBe(1);
});

it('uses current locking refund reads despite eight stale repeatable-read snapshots', function (): void {
    $tenant = $this->binding->tenantId;
    $payment = $this->core->start($this->binding, MySqlFixture::sale(), 'paid_for_refund_race');
    $jobs = array_fill(0, 8, ['tenant' => $tenant, 'scenario' => 'refund', 'payment_id' => $payment['id']]);
    $results = runPaymentCoreRace($jobs, true);
    expect(collect($results)->where('state', 'blocked'))->toHaveCount(5)
        ->and(DB::table('payment_core_operations')->where('tenant_id', $tenant)->where('kind', 'refund')->sum('amount_minor'))->toBe('900')
        ->and($this->provider->calls($tenant))->toBe(4);
});

it('globally quarantines one canonical effect reused concurrently across eight tenants', function (): void {
    for ($i = 1; $i < 8; $i++) {
        $this->tenants->push(MySqlFixture::tenant());
    }
    $jobs = $this->tenants->map(fn ($tenant, $index) => ['tenant' => $tenant->id, 'scenario' => 'effect',
        'register' => 'register_'.$index, 'sale' => 'sale_'.$index, 'request' => 'request_'.$index])->all();
    $results = runPaymentCoreRace($jobs);
    foreach ($results as $index => $result) {
        $scope = MySqlFixture::binding($this->tenants[$index]->id, 'register_'.$index);
        MySqlFixture::core($scope->tenantId)->recover($scope, $result['id']);
    }
    $query = DB::table('payment_core_operations')->whereIn('tenant_id', $this->tenants->pluck('id'));
    expect((clone $query)->where('state', 'succeeded')->count())->toBe(1)
        ->and((clone $query)->where('state', 'review')->count())->toBe(7)
        ->and((clone $query)->where('provider_id', 'shared_fixture_effect')->count())->toBe(1);
});
