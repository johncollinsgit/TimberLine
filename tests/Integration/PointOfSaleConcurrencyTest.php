<?php

use App\Models\PosPaymentAttempt;
use App\Models\PosRefund;
use App\Models\PosSimulatorObject;
use App\Models\TenantPaymentAccount;
use App\Models\WebsiteCart;
use App\Models\WebsiteCartItem;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteInventoryMovement;
use App\Models\WebsiteInventoryReservation;
use App\Models\WebsiteOrder;
use App\Models\WebsitePayment;
use App\Services\PointOfSale\PosAccess;
use App\Services\PointOfSale\PosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;
use Tests\Support\PosFixture;

uses(Tests\TestCase::class);

it('serializes a simulated register request replay and the last shared catalog item across MySQL processes', function (string $scenario): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('POS_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing POS concurrency fixtures outside local disposable MySQL.');
    }
    $fixture = PosFixture::merchant('race-'.Str::lower((string) Str::ulid()), in_array($scenario, ['last_item', 'pos_web_last_item', 'pos_web_existing_customer'], true) ? 1 : 5);
    $registers = [$fixture->register->id, $fixture->register->id];
    if (in_array($scenario, ['last_item', 'same_key_other_register'], true)) {
        $second = $fixture->register->replicate();
        $second->reader_id = $fixture->register->reader_id.'_second';
        $second->save();
        $registers[1] = $second->id;
    }
    $firstKey = (string) Str::uuid();
    $keys = [$firstKey, in_array($scenario, ['same_key', 'same_key_other_register'], true) ? $firstKey : (string) Str::uuid()];
    $paidAttempt = null;
    if ($scenario === 'refund_cap') {
        $paidAttempt = app(PosService::class)->start($fixture->tenant, $fixture->manager, $fixture->register->id, $fixture->input());
        $paidAttempt = app(PosService::class)->simulate($fixture->tenant, $fixture->manager, $paidAttempt->id, 'approved');
    }
    $websiteCart = null;
    if (in_array($scenario, ['pos_web_last_item', 'pos_web_existing_customer'], true)) {
        TenantPaymentAccount::query()->create(['tenant_id' => $fixture->tenant->id, 'provider_account_id' => 'acct_fictional_'.$fixture->tenant->id,
            'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'platform_fee_bps' => 0]);
        $websiteCart = WebsiteCart::query()->create(['tenant_id' => $fixture->tenant->id, 'tenant_site_id' => $fixture->site->id,
            'token' => (string) Str::uuid(), 'currency' => 'usd', 'status' => 'active']);
        WebsiteCartItem::query()->create(['tenant_id' => $fixture->tenant->id, 'website_cart_id' => $websiteCart->id,
            'website_product_variant_id' => $fixture->variant->id, 'quantity' => 1]);
        if ($scenario === 'pos_web_existing_customer') {
            WebsiteCustomer::query()->create(['tenant_id' => $fixture->tenant->id, 'email' => 'pos-race@example.test',
                'first_name' => 'Fictional', 'last_name' => 'Returning shopper', 'status' => 'active']);
        }
    }
    $directory = sys_get_temp_dir().'/pos-concurrency-'.Str::uuid();
    mkdir($directory);
    $worker = <<<'WORKER'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$data = json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
if (! app()->environment('testing') || getenv('POS_DISPOSABLE_MYSQL') !== '1'
    || Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'mysql'
    || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', Illuminate\Support\Facades\DB::connection()->getDatabaseName())
    || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
    throw new RuntimeException('Unsafe POS concurrency worker environment.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
config(['pos.enabled' => true, 'pos.tenant_ids' => [(int) $data['tenant']], 'cache.default' => 'array', 'mail.default' => 'array']);
$tenant = App\Models\Tenant::findOrFail($data['tenant']);
$actor = App\Models\User::findOrFail($data['actor']);
Illuminate\Support\Facades\DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
Illuminate\Support\Facades\DB::beginTransaction();
// Both contenders retain a consistent snapshot from before the other writer.
// The service must use current locking reads after waiting on register/stock locks.
foreach (['pos_payment_attempts', 'pos_refunds', 'website_inventory_reservations'] as $table) {
    Illuminate\Support\Facades\DB::table($table)->where('tenant_id', $tenant->id)->get();
}
file_put_contents($data['directory'].'/ready-'.getmypid(), 'ready');
for ($i = 0; ! file_exists($data['directory'].'/go') && $i < 1500; $i++) { usleep(10000); }
if (! file_exists($data['directory'].'/go')) { throw new RuntimeException('POS concurrency barrier timed out.'); }
try {
    if ($data['website_cart']) {
        // Exercise the real native checkout reservation transaction. Only provider
        // readiness and provider dispatch are replaced; no credentials or HTTP.
        app()->instance(App\Services\ManagedWebsite\WebsiteCommerceService::class,
            new class extends App\Services\ManagedWebsite\WebsiteCommerceService {
                public function __construct() {}
                public function checkoutReadiness(App\Models\Tenant $tenant): array { return ['ready' => true, 'blockers' => []]; }
            });
        $checkout = new class extends App\Services\ManagedWebsite\WebsiteCheckoutService {
            public function ensureSession(App\Models\WebsitePayment $payment): array {
                return ['order' => App\Models\WebsiteOrder::findOrFail($payment->website_order_id), 'url' => 'https://example.test/fictional'];
            }
        };
        $checkout->begin(App\Models\WebsiteCart::findOrFail($data['website_cart']), [
            'name' => 'Fictional concurrency shopper', 'email' => 'pos-race@example.test', 'fulfillment_method' => 'pickup',
        ], 'https://example.test/fictional-success', 'https://example.test/fictional-cancel');
        $result = ['state' => 'reserved'];
    } elseif ($data['scenario'] === 'refund_cap') {
        $refund = app(App\Services\PointOfSale\PosService::class)->refund($tenant, $actor, $data['paid_attempt'], [
            'request_id' => $data['request_id'], 'amount_cents' => 2000, 'reason' => 'Fictional concurrent partial refund',
        ]);
        $result = ['state' => 'refunded', 'refund' => $refund->id];
    } else {
        $attempt = app(App\Services\PointOfSale\PosService::class)->start($tenant, $actor, $data['register'], [
            'request_id' => $data['request_id'], 'lines' => [['variant_id' => $data['variant'], 'quantity' => 1]],
        ]);
        $result = ['state' => 'reserved', 'attempt' => $attempt->id];
    }
} catch (Illuminate\Validation\ValidationException $exception) {
    $result = ['state' => 'blocked'];
} catch (Symfony\Component\HttpKernel\Exception\HttpException $exception) {
    if ($exception->getStatusCode() !== 409) { throw $exception; }
    $result = ['state' => 'blocked'];
}
Illuminate\Support\Facades\DB::commit();
echo json_encode($result, JSON_THROW_ON_ERROR);
Illuminate\Support\Facades\Http::assertNothingSent();
WORKER;
    $database = config('database.connections.mysql');
    $environment = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql',
        'DB_HOST' => $database['host'], 'DB_PORT' => (string) $database['port'], 'DB_DATABASE' => $database['database'],
        'DB_USERNAME' => $database['username'], 'DB_PASSWORD' => $database['password'], 'CACHE_STORE' => 'array',
        'MAIL_MAILER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null', 'POS_DISPOSABLE_MYSQL' => '1'];
    $processes = [];
    try {
        foreach ([0, 1] as $index) {
            $process = new Process([PHP_BINARY, '-r', $worker], base_path(), $environment, json_encode([
                'directory' => $directory, 'tenant' => $fixture->tenant->id,
                'actor' => $scenario === 'refund_cap' ? $fixture->manager->id : $fixture->cashier->id,
                'register' => $registers[$index], 'variant' => $fixture->variant->id, 'request_id' => $keys[$index],
                'scenario' => $scenario, 'paid_attempt' => $paidAttempt?->id,
                'website_cart' => $index === 1 ? $websiteCart?->id : null,
            ], JSON_THROW_ON_ERROR), 30);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 15;
        while (count(glob($directory.'/ready-*')) < 2 && microtime(true) < $deadline) {
            usleep(10000);
        }
        expect(count(glob($directory.'/ready-*')))->toBe(2);
        file_put_contents($directory.'/go', 'go');
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
            $results[] = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
        }
        $states = array_column($results, 'state');
        sort($states);
        expect($states)->toBe(match ($scenario) {
            'same_key' => ['reserved', 'reserved'],
            'refund_cap' => ['blocked', 'refunded'],
            default => ['blocked', 'reserved'],
        });
        if ($scenario === 'same_key') {
            expect($results[0]['attempt'])->toBe($results[1]['attempt']);
        }
        if (in_array($scenario, ['pos_web_last_item', 'pos_web_existing_customer'], true)) {
            $posCount = PosPaymentAttempt::query()->forTenantId($fixture->tenant->id)->count();
            expect($posCount)->toBeIn([0, 1])
                ->and(WebsiteOrder::query()->forTenantId($fixture->tenant->id)->count())->toBe(1)
                ->and(WebsitePayment::query()->forTenantId($fixture->tenant->id)->count())->toBe(1 - $posCount)
                ->and(PosSimulatorObject::query()->forTenantId($fixture->tenant->id)->count())->toBe($posCount)
                ->and((int) WebsiteInventoryReservation::query()->forTenantId($fixture->tenant->id)->where('status', 'active')->sum('quantity'))->toBe(1)
                ->and($fixture->variant->fresh()->inventory_quantity)->toBe(1)
                ->and(WebsiteInventoryMovement::query()->forTenantId($fixture->tenant->id)->count())->toBe(0);

            return;
        }
        expect(PosPaymentAttempt::query()->forTenantId($fixture->tenant->id)->count())->toBe(1)
            ->and(PosSimulatorObject::query()->forTenantId($fixture->tenant->id)->count())->toBe($scenario === 'refund_cap' ? 2 : 1)
            ->and((int) WebsiteInventoryReservation::query()->forTenantId($fixture->tenant->id)->where('status', 'active')->sum('quantity'))->toBe($scenario === 'refund_cap' ? 0 : 1)
            ->and(WebsiteInventoryMovement::query()->forTenantId($fixture->tenant->id)->count())->toBe($scenario === 'refund_cap' ? 1 : 0);
        if ($scenario === 'refund_cap') {
            expect(PosRefund::query()->forTenantId($fixture->tenant->id)->count())->toBe(1)
                ->and((int) PosRefund::query()->forTenantId($fixture->tenant->id)->where('status', 'succeeded')->sum('amount_cents'))->toBe(2000)
                ->and($paidAttempt->order->fresh()->refunded_cents)->toBe(2000)
                ->and($fixture->variant->fresh()->inventory_quantity)->toBe(4);

            return;
        }
        $attempt = PosPaymentAttempt::query()->forTenantId($fixture->tenant->id)->sole();
        app(PosService::class)->simulate($fixture->tenant, $fixture->manager, $attempt->id, 'approved');
        app(PosService::class)->reconcile($fixture->tenant, $fixture->manager, $attempt->id);
        expect($fixture->variant->fresh()->inventory_quantity)->toBe($scenario === 'last_item' ? 0 : 4)
            ->and(WebsiteInventoryMovement::query()->forTenantId($fixture->tenant->id)->where('reason', 'pos_simulation_sale')->count())->toBe(1);
    } finally {
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        }
        rmdir($directory);
        foreach (['pos_refunds', 'pos_payment_attempts', 'pos_simulator_objects', 'pos_registers'] as $table) {
            DB::table($table)->where('tenant_id', $fixture->tenant->id)->delete();
        }
        $fixture->tenant->delete();
        $fixture->cashier->delete();
        $fixture->manager->delete();
    }
})->with(['same_register', 'same_key', 'same_key_other_register', 'last_item', 'refund_cap', 'pos_web_last_item', 'pos_web_existing_customer']);

it('rechecks committed revocations after a retained MySQL authorization snapshot', function (string $revocation): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('POS_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing POS authorization fixtures outside local disposable MySQL.');
    }
    $fixture = PosFixture::merchant('revoked-'.Str::lower((string) Str::ulid()));
    config()->set('database.connections.pos_stale_auth_writer', config('database.connections.mysql'));
    DB::purge('pos_stale_auth_writer');
    $writer = DB::connection('pos_stale_auth_writer');
    $table = match ($revocation) {
        'actor' => 'users', 'membership' => 'tenant_user', 'register' => 'pos_registers', 'origin' => 'website_fulfillment_locations',
    };
    $column = match ($revocation) {
        'actor' => 'is_active', 'membership' => 'membership_active', default => 'active',
    };
    $identity = match ($revocation) {
        'actor' => ['id' => $fixture->cashier->id],
        'membership' => ['tenant_id' => $fixture->tenant->id, 'user_id' => $fixture->cashier->id],
        'register' => ['id' => $fixture->register->id],
        'origin' => ['id' => $fixture->location->id],
    };
    try {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        expect((bool) DB::table($table)->where($identity)->value($column))->toBeTrue();
        $writer->table($table)->where($identity)->update([$column => false]);
        // A plain read still sees the old snapshot. Action authorization must
        // use a current read and reject the independently committed revocation.
        expect((bool) DB::table($table)->where($identity)->value($column))->toBeTrue();
        foreach ([
            fn () => app(PosAccess::class)->register($fixture->tenant, $fixture->cashier, $fixture->register->id),
            fn () => app(PosService::class)->start($fixture->tenant, $fixture->cashier, $fixture->register->id, $fixture->input()),
        ] as $operation) {
            try {
                $operation();
                test()->fail('Committed POS revocation must override a stale authorization snapshot.');
            } catch (HttpException $exception) {
                expect($exception->getStatusCode())->toBe(in_array($revocation, ['actor', 'membership'], true) ? 403 : 404);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                expect(in_array($revocation, ['register', 'origin'], true))->toBeTrue();
            }
        }
        expect(PosPaymentAttempt::query()->forTenantId($fixture->tenant->id)->count())->toBe(0)
            ->and(PosSimulatorObject::query()->forTenantId($fixture->tenant->id)->count())->toBe(0)
            ->and((int) WebsiteInventoryReservation::query()->forTenantId($fixture->tenant->id)->sum('quantity'))->toBe(0);
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::disconnect('pos_stale_auth_writer');
        DB::purge('pos_stale_auth_writer');
        foreach (['pos_refunds', 'pos_payment_attempts', 'pos_simulator_objects', 'pos_registers'] as $posTable) {
            DB::table($posTable)->where('tenant_id', $fixture->tenant->id)->delete();
        }
        $fixture->tenant->delete();
        $fixture->cashier->delete();
        $fixture->manager->delete();
    }
})->with(['actor', 'membership', 'register', 'origin']);
