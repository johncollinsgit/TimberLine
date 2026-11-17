<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\ManagedWebsite\WebsitePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use RuntimeException;

/** Only fictional, explicitly opted-in, runner-local MySQL fixtures. */
final class WebsiteRefundMySqlWorker
{
    public static function guard(): void
    {
        $config = DB::connection()->getConfig();
        if (getenv('WEBSITE_REFUND_DISPOSABLE_MYSQL') !== '1' || ! app()->environment('testing')
            || app()->configurationIsCached() || ($config['driver'] ?? null) !== 'mysql'
            || ! in_array($config['host'] ?? null, ['127.0.0.1', 'localhost'], true)
            || ! preg_match('/^everbranch_(?:ci|test_[a-z0-9_]+)$/D', (string) ($config['database'] ?? ''))
            || ! empty($config['url']) || ! empty($config['unix_socket']) || ! empty($config['read']) || ! empty($config['write'])) {
            throw new RuntimeException('Refusing Website refunds outside the opted-in local disposable MySQL schema.');
        }
        Http::preventStrayRequests();
        Mail::fake();
        Queue::fake();
        config(['services.stripe.secret' => 'sk_test_refund_rr_fixture', 'services.stripe.account_id' => 'acct_refundRRPlatform',
            'managed_website.order_notifications_enabled' => false,
            'managed_website.order_notifications_sender_approved' => false,
            'managed_website.order_notifications_mailer' => '', 'mail.default' => 'array']);
    }

    public static function fakeRefunds(array $refunds): void
    {
        Http::fake(function ($request) use ($refunds) {
            if ($request->method() !== 'GET' || ! $request->hasHeader('Stripe-Account', 'acct_refundRRMerchant')) {
                throw new RuntimeException('Unexpected fictional refund transport.');
            }
            foreach ($refunds as [$payment, $amount]) {
                if ($request->url() === 'https://api.stripe.com/v1/refunds?'.http_build_query([
                    'payment_intent' => $payment->provider_payment_intent_id, 'limit' => 100,
                ])) {
                    return Http::response(['data' => [['id' => 're_refundRR'.$payment->id,
                        'payment_intent' => $payment->provider_payment_intent_id, 'currency' => 'usd',
                        'amount' => $amount, 'status' => 'succeeded']], 'has_more' => false]);
                }
            }

            throw new RuntimeException('Unexpected fictional refund transport.');
        });
    }

    public static function write(string $path, array $data): void
    {
        $bytes = json_encode($data, JSON_THROW_ON_ERROR);
        $stream = fopen($path.'.part', 'x');
        if ($stream === false) {
            throw new RuntimeException('Cannot reserve a refund race barrier.');
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! fflush($stream)) {
                throw new RuntimeException('Cannot finish a refund race barrier.');
            }
        } finally {
            fclose($stream);
        }
        // Readers only see complete JSON, never the empty newly-created file.
        if (file_exists($path) || ! rename($path.'.part', $path)) {
            throw new RuntimeException('Cannot publish a refund race barrier.');
        }
    }

    public static function wait(string $path): void
    {
        $deadline = microtime(true) + 15;
        while (! is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (! is_file($path)) {
            throw new RuntimeException('Website refund race barrier timed out.');
        }
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    try {
        // Refuse inherited non-test connections before loading Laravel or PDO.
        if (getenv('APP_ENV') !== 'testing' || getenv('DB_CONNECTION') !== 'mysql'
            || getenv('WEBSITE_REFUND_DISPOSABLE_MYSQL') !== '1'
            || ! in_array(getenv('DB_HOST'), ['127.0.0.1', 'localhost'], true)
            || ! preg_match('/^everbranch_(?:ci|test_[a-z0-9_]+)$/D', (string) getenv('DB_DATABASE'))
            || getenv('DB_URL') || getenv('DATABASE_URL') || getenv('DB_SOCKET')) {
            throw new RuntimeException('Refusing a non-disposable refund worker environment.');
        }
        $data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
        $directory = realpath($data['directory'] ?? '');
        if (! $directory || dirname($directory) !== realpath(sys_get_temp_dir())
            || ! preg_match('/^website-refund-mysql-[a-f0-9-]{36}$/D', basename($directory))
            || ! in_array($data['role'] ?? null, ['first', 'second'], true)
            || ! is_int($data['payment_id'] ?? null) || ! is_int($data['tenant_id'] ?? null)
            || ! in_array($data['amount'] ?? null, [1250, 750], true)) {
            throw new RuntimeException('Invalid fictional refund worker input.');
        }
        require __DIR__.'/../../vendor/autoload.php';
        $app = require __DIR__.'/../../bootstrap/app.php';
        if ($app->configurationIsCached()) {
            throw new RuntimeException('Refund workers require uncached test configuration.');
        }
        $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        WebsiteRefundMySqlWorker::guard();
        $db = DB::connection();
        $db->statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $db->statement('SET SESSION innodb_lock_wait_timeout = 10');
        $connection = (int) $db->selectOne('SELECT CONNECTION_ID() AS id')->id;
        $isolation = (string) $db->selectOne('SELECT @@transaction_isolation AS isolation_level')->isolation_level;
        $payment = WebsitePayment::query()->forTenantId($data['tenant_id'])->findOrFail($data['payment_id']);
        WebsiteRefundMySqlWorker::fakeRefunds([[$payment, $data['amount']]]);
        $primed = false;
        $held = false;
        $siteAcquired = false;
        $locks = [];
        $db->beforeExecuting(function (string $query) use ($db, $data, $directory, $payment, $connection, $isolation, &$primed, &$held): void {
            if ($data['role'] === 'second' && ! $primed && str_contains($query, '`tenant_sites`')
                && str_ends_with(strtolower($query), 'for update') && $db->transactionLevel() > 0) {
                $primed = true;
                // locked() has already read its order scope. This SELECT proves
                // the same service-owned transaction retains that old RR view.
                $snapshot = (int) $db->table('website_orders')->where('tenant_id', $data['tenant_id'])
                    ->where('id', $payment->website_order_id)
                    ->value('refunded_cents');
                WebsiteRefundMySqlWorker::write($directory.'/second-snapshot.json', ['refunded_cents' => $snapshot,
                    'connection_id' => $connection, 'isolation' => $isolation, 'transaction_level' => $db->transactionLevel()]);
                WebsiteRefundMySqlWorker::wait($directory.'/second-lock-go.json');
            }
            if ($data['role'] === 'first' && ! $held && str_contains($query, '`website_payments`')
                && str_contains($query, 'paid_confirmed') && $db->transactionLevel() > 0) {
                $held = true;
                // The first refund now owns the canonical site/order/payment
                // locks. Let the other real session wait on that site lock.
                WebsiteRefundMySqlWorker::write($directory.'/first-locks-held.json', ['connection_id' => $connection]);
                WebsiteRefundMySqlWorker::wait($directory.'/first-commit-go.json');
            }
        });
        $db->listen(function ($event) use ($data, $directory, &$locks, &$siteAcquired): void {
            if (str_ends_with(strtolower($event->sql), 'for update')) {
                foreach (['tenant_sites', 'website_orders', 'website_payments'] as $table) {
                    if (str_contains($event->sql, '`'.$table.'`')) {
                        $locks[] = $table;
                    }
                }
                if ($data['role'] === 'second' && ! $siteAcquired && str_contains($event->sql, '`tenant_sites`')) {
                    $siteAcquired = true;
                    // Keep B at the acquired canonical site lock until the
                    // parent observes A's committed 1250, avoiding a timing-
                    // dependent intermediate-total assertion in the parent.
                    WebsiteRefundMySqlWorker::write($directory.'/second-site-acquired.json', ['acquired' => true]);
                    WebsiteRefundMySqlWorker::wait($directory.'/second-continue.json');
                }
            }
        });
        app(WebsitePaymentService::class)->reconcileRefunds($payment);
        $order = WebsiteOrder::query()->forTenantId($data['tenant_id'])->findOrFail($payment->website_order_id);
        echo json_encode(['refunded_cents' => $order->refunded_cents, 'payment_status' => $order->payment_status,
            'connection_id' => $connection, 'isolation' => $isolation, 'locks' => $locks,
            'http_count' => Http::recorded()->count()], JSON_THROW_ON_ERROR);
    } catch (\Throwable $error) {
        fwrite(STDERR, get_class($error).': '.$error->getMessage().PHP_EOL);
        exit(1);
    }
}
