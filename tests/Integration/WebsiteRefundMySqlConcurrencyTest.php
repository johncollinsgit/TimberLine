<?php

use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\WebsiteOrder;
use App\Models\WebsiteOrderNotification;
use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\WebsitePaymentService;
use App\Services\ManagedWebsite\WebsiteStripeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\WebsiteRefundMySqlWorker;

require_once __DIR__.'/../Support/WebsiteRefundMySqlWorker.php';

uses(Tests\TestCase::class);

it('keeps installment refund totals and receipts current across an older MySQL repeatable-read snapshot', function (): void {
    // No RefreshDatabase transaction: independently booted workers must see
    // committed fictional fixture rows and use separate real MySQL sessions.
    WebsiteRefundMySqlWorker::guard();
    $directory = sys_get_temp_dir().'/website-refund-mysql-'.Str::uuid();
    if (! mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create the refund race directory.');
    }
    $tenant = null;
    $processes = [];
    try {
        $tenant = Tenant::query()->create(['name' => 'Fictional refund RR fixture', 'slug' => 'refund-test-'.Str::lower(Str::random(12))]);
        $site = TenantSite::query()->create(['tenant_id' => $tenant->id, 'subdomain' => $tenant->slug, 'status' => 'draft', 'public_enabled' => false]);
        $order = WebsiteOrder::query()->create(['tenant_id' => $tenant->id, 'tenant_site_id' => $site->id,
            'number' => 'REFUND-RR', 'lookup_token' => Str::random(56), 'currency' => 'usd', 'source' => 'staff_draft_invoice',
            'payment_status' => 'paid', 'subtotal_cents' => 4000, 'total_cents' => 4000,
            'customer_snapshot' => ['name' => 'Fictional refund buyer', 'email' => 'refund-fixture@example.test']]);
        $payments = collect([2000, 2000])->map(fn (int $amount, int $index) => WebsitePayment::query()->create([
            'tenant_id' => $tenant->id, 'website_order_id' => $order->id, 'provider' => 'stripe', 'status' => 'paid',
            'amount_cents' => $amount, 'currency' => 'usd', 'provider_payment_intent_id' => 'pi_refundRR'.$index,
            'metadata' => ['paid_confirmed' => true, 'checkout_version' => 2, 'inventory_committed' => true,
                'routing_mode' => 'direct', 'account_id' => 'acct_refundRRMerchant', 'fingerprint_version' => 2,
                'platform_fingerprint' => app(WebsiteStripeService::class)->accountFingerprint()],
        ]));
        $db = DB::connection()->getConfig();
        // Symfony otherwise inherits the host environment. Keep only OS process
        // essentials, then bind every application/DB setting to this test.
        $environment = array_fill_keys(array_keys(getenv()), false);
        foreach (['PATH', 'SystemRoot', 'WINDIR', 'TEMP', 'TMP', 'TMPDIR'] as $key) {
            if (getenv($key) !== false) {
                $environment[$key] = getenv($key);
            }
        }
        $environment = array_merge($environment, ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'mysql', 'DB_HOST' => $db['host'], 'DB_PORT' => (string) $db['port'],
            'DB_DATABASE' => $db['database'], 'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => (string) $db['password'],
            'DB_URL' => '', 'DATABASE_URL' => '', 'DB_SOCKET' => '', 'WEBSITE_REFUND_DISPOSABLE_MYSQL' => '1',
            'APP_CONFIG_CACHE' => $directory.'/no-config.php', 'APP_ROUTES_CACHE' => $directory.'/no-routes.php',
            'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'LOG_CHANNEL' => 'null', 'TELESCOPE_ENABLED' => 'false', 'NIGHTWATCH_ENABLED' => 'false', 'PULSE_ENABLED' => 'false']);
        $start = function (string $role, WebsitePayment $payment, int $amount) use ($environment, $directory, &$processes): Process {
            $process = new Process([PHP_BINARY, base_path('tests/Support/WebsiteRefundMySqlWorker.php')], base_path(), $environment,
                json_encode(['role' => $role, 'tenant_id' => $payment->tenant_id, 'payment_id' => $payment->id,
                    'amount' => $amount, 'directory' => $directory], JSON_THROW_ON_ERROR), 35);
            $process->start();
            $processes[] = $process;

            return $process;
        };
        $second = $start('second', $payments[1], 750);
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/second-snapshot.json') && microtime(true) < $deadline && $second->isRunning()) {
            usleep(10000);
        }
        if (! is_file($directory.'/second-snapshot.json')) {
            throw new RuntimeException('Second refund did not establish its snapshot: '.$second->getErrorOutput());
        }
        $snapshot = json_decode(file_get_contents($directory.'/second-snapshot.json'), true, 512, JSON_THROW_ON_ERROR);
        expect($snapshot['refunded_cents'])->toBe(0)->and($snapshot['transaction_level'])->toBe(1)
            ->and($snapshot['isolation'])->toBe('REPEATABLE-READ');
        $first = $start('first', $payments[0], 1250);
        $deadline = microtime(true) + 15;
        while (! is_file($directory.'/first-locks-held.json') && microtime(true) < $deadline && $first->isRunning()) {
            usleep(10000);
        }
        if (! is_file($directory.'/first-locks-held.json')) {
            throw new RuntimeException('First refund did not acquire its locks: '.$first->getErrorOutput());
        }
        $holder = json_decode(file_get_contents($directory.'/first-locks-held.json'), true, 512, JSON_THROW_ON_ERROR);
        WebsiteRefundMySqlWorker::write($directory.'/second-lock-go.json', ['go' => true]);
        $deadline = microtime(true) + 5;
        do {
            $waiting = (int) DB::selectOne('SELECT COUNT(*) AS waiting
                FROM performance_schema.data_lock_waits AS waits
                JOIN performance_schema.threads AS requester ON requester.THREAD_ID = waits.REQUESTING_THREAD_ID
                JOIN performance_schema.threads AS blocker ON blocker.THREAD_ID = waits.BLOCKING_THREAD_ID
                WHERE requester.PROCESSLIST_ID = ? AND blocker.PROCESSLIST_ID = ?', [
                $snapshot['connection_id'], $holder['connection_id'],
            ])->waiting;
            if ($waiting === 0) {
                usleep(10000);
            }
        } while ($waiting === 0 && microtime(true) < $deadline && $first->isRunning() && $second->isRunning());
        expect($waiting)->toBeGreaterThan(0);
        WebsiteRefundMySqlWorker::write($directory.'/first-commit-go.json', ['go' => true]);
        $first->wait();
        if (! $first->isSuccessful()) {
            throw new RuntimeException('First refund failed: '.$first->getErrorOutput());
        }
        $firstResult = json_decode($first->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        expect($firstResult['refunded_cents'])->toBe(1250)->and($firstResult['connection_id'])->toBe($holder['connection_id'])
            ->and($order->fresh()->refunded_cents)->toBe(1250);
        WebsiteRefundMySqlWorker::write($directory.'/second-continue.json', ['go' => true]);
        $second->wait();
        if (! $second->isSuccessful()) {
            throw new RuntimeException('Second refund failed: '.$second->getErrorOutput());
        }
        $secondResult = json_decode($second->getOutput(), true, 512, JSON_THROW_ON_ERROR);
        expect($firstResult['connection_id'])->not->toBe($secondResult['connection_id'])
            ->and($secondResult['connection_id'])->toBe($snapshot['connection_id'])
            ->and($secondResult['refunded_cents'])->toBe(2000)->and($order->fresh()->refunded_cents)->toBe(2000)
            ->and($order->fresh()->payment_status)->toBe('partially_refunded');
        foreach ([$firstResult, $secondResult] as $result) {
            expect($result['isolation'])->toBe('REPEATABLE-READ')->and($result['http_count'])->toBe(1)
                ->and(array_slice($result['locks'], 0, 3))->toBe(['tenant_sites', 'website_orders', 'website_payments']);
        }
        $receipts = fn () => WebsiteOrderNotification::query()->forTenantId($tenant->id)->where('website_order_id', $order->id)->where('kind', 'refund_confirmed')->orderBy('id')->get();
        expect($order->events()->where('event_type', 'refund_confirmed')->reorder('id')->get()->pluck('data.refunded_cents')->all())->toBe([1250, 2000])
            ->and($receipts())->toHaveCount(2)->and($receipts()->pluck('snapshot')->pluck('refund_cents')->all())->toBe([1250, 750])
            ->and($receipts()->pluck('snapshot')->pluck('refunded_cents')->all())->toBe([1250, 2000])
            ->and($receipts()->pluck('status')->unique()->all())->toBe(['blocked']);
        WebsiteRefundMySqlWorker::fakeRefunds([[$payments[0], 1250], [$payments[1], 750]]);
        foreach ($payments as $payment) {
            app(WebsitePaymentService::class)->reconcileRefunds($payment->fresh());
        }
        expect($order->fresh()->refunded_cents)->toBe(2000)->and($receipts())->toHaveCount(2)
            ->and($order->events()->where('event_type', 'refund_confirmed')->count())->toBe(2);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop(1);
            }
        }
        // Delete only this test's committed tenant. Foreign keys cascade its
        // Website rows after both owned worker connections have stopped.
        $tenant?->delete();
        foreach (glob($directory.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($directory);
    }
});
