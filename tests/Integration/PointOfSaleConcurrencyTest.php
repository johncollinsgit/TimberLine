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
use App\Models\WebsiteOrderEvent;
use App\Models\WebsitePayment;
use App\Models\WebsiteProductVariant;
use App\Services\PointOfSale\PosAccess;
use App\Services\PointOfSale\PosCatalogLookup;
use App\Services\PointOfSale\PosCustomerDirectory;
use App\Services\PointOfSale\PosService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
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
    $fixture = PosFixture::merchant('race-'.Str::lower((string) Str::ulid()), in_array($scenario, ['last_item', 'pos_web_last_item', 'pos_web_existing_customer', 'cash_last_item'], true) ? 1 : 5);
    $registers = [$fixture->register->id, $fixture->register->id];
    if (in_array($scenario, ['last_item', 'same_key_other_register', 'cash_last_item'], true)) {
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
    } elseif ($data['scenario'] === 'cash_last_item') {
        $service = app(App\Services\PointOfSale\PosService::class);
        $cashInput = ['request_id' => $data['request_id'], 'lines' => [['variant_id' => $data['variant'], 'quantity' => 1]],
            'tender' => 'cash', 'cash_received_cents' => 3000];
        $attempt = $service->start($tenant, $actor, $data['register'], $cashInput);
        $replay = $service->start($tenant, $actor, $data['register'], $cashInput);
        if ($attempt->id !== $replay->id || $attempt->status !== 'succeeded' || $replay->status !== 'succeeded') {
            throw new RuntimeException('The fictional cash sale did not settle and replay exactly once.');
        }
        $result = ['state' => 'settled', 'attempt' => $attempt->id];
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
            'cash_last_item' => ['blocked', 'settled'],
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
        if ($scenario === 'cash_last_item') {
            $attempt = PosPaymentAttempt::query()->forTenantId($fixture->tenant->id)->sole();
            expect($attempt->status)->toBe('succeeded')->and($attempt->snapshot['tender'])->toBe('cash')
                ->and($attempt->snapshot['cash_received_cents'])->toBe(3000)->and($attempt->snapshot['change_cents'])->toBe(294)
                ->and($attempt->order->payment_status)->toBe('paid')
                ->and(WebsiteOrder::query()->forTenantId($fixture->tenant->id)->count())->toBe(1)
                ->and(PosSimulatorObject::query()->forTenantId($fixture->tenant->id)->count())->toBe(1)
                ->and((int) WebsiteInventoryReservation::query()->forTenantId($fixture->tenant->id)->where('status', 'active')->sum('quantity'))->toBe(0)
                ->and(WebsiteInventoryReservation::query()->forTenantId($fixture->tenant->id)->where('status', 'confirmed')->count())->toBe(1)
                ->and($fixture->variant->fresh()->inventory_quantity)->toBe(0)
                ->and(WebsiteInventoryMovement::query()->forTenantId($fixture->tenant->id)->where('reason', 'pos_simulation_sale')->count())->toBe(1)
                ->and(WebsiteOrderEvent::query()->forTenantId($fixture->tenant->id)->where('event_type', 'pos_simulation_paid')->count())->toBe(1);

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
})->with(['same_register', 'same_key', 'same_key_other_register', 'last_item', 'refund_cap', 'pos_web_last_item', 'pos_web_existing_customer', 'cash_last_item']);

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
    $customer = $revocation === 'customer' ? WebsiteCustomer::query()->create(['tenant_id' => $fixture->tenant->id,
        'first_name' => 'Fictional returning customer', 'email' => 'pos-revoked@example.test', 'status' => 'active']) : null;
    $table = match ($revocation) {
        'actor' => 'users', 'membership' => 'tenant_user', 'register' => 'pos_registers', 'origin' => 'website_fulfillment_locations',
        'customer' => 'website_customers',
    };
    $column = match ($revocation) {
        'actor' => 'is_active', 'membership' => 'membership_active', 'customer' => 'status', default => 'active',
    };
    $identity = match ($revocation) {
        'actor' => ['id' => $fixture->cashier->id],
        'membership' => ['tenant_id' => $fixture->tenant->id, 'user_id' => $fixture->cashier->id],
        'register' => ['id' => $fixture->register->id],
        'origin' => ['id' => $fixture->location->id],
        'customer' => ['id' => $customer->id],
    };
    $allowedValue = $revocation === 'customer' ? 'active' : true;
    $revokedValue = $revocation === 'customer' ? 'inactive' : false;
    try {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        $before = DB::table($table)->where($identity)->value($column);
        expect($revocation === 'customer' ? $before : (bool) $before)->toBe($allowedValue);
        $writer->table($table)->where($identity)->update([$column => $revokedValue]);
        // A plain read still sees the old snapshot. Action authorization must
        // use a current read and reject the independently committed revocation.
        $stale = DB::table($table)->where($identity)->value($column);
        expect($revocation === 'customer' ? $stale : (bool) $stale)->toBe($allowedValue);
        $operations = $revocation === 'customer' ? [
            fn () => app(PosService::class)->start($fixture->tenant, $fixture->cashier, $fixture->register->id,
                [...$fixture->input(), 'website_customer_id' => $customer->id]),
        ] : [
            fn () => app(PosAccess::class)->register($fixture->tenant, $fixture->cashier, $fixture->register->id),
            fn () => app(PosService::class)->start($fixture->tenant, $fixture->cashier, $fixture->register->id, $fixture->input()),
        ];
        foreach ($operations as $operation) {
            try {
                $operation();
                test()->fail('Committed POS revocation must override a stale authorization snapshot.');
            } catch (HttpException $exception) {
                expect($exception->getStatusCode())->toBe(in_array($revocation, ['actor', 'membership'], true) ? 403 : 404);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $exception) {
                expect(in_array($revocation, ['register', 'origin', 'customer'], true))->toBeTrue();
                if ($revocation === 'customer') {
                    expect($exception->getModel())->toBe(WebsiteCustomer::class);
                }
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
})->with(['actor', 'membership', 'register', 'origin', 'customer']);

it('rejects an independently committed native email duplicate despite a retained MySQL snapshot', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('POS_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing POS customer fixtures outside local disposable MySQL.');
    }
    Http::preventStrayRequests();
    $fixture = PosFixture::merchant('email-'.Str::lower((string) Str::ulid()));
    config()->set('database.connections.pos_stale_email_writer', config('database.connections.mysql'));
    DB::purge('pos_stale_email_writer');
    $writer = DB::connection('pos_stale_email_writer');
    try {
        DB::statement('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        DB::beginTransaction();
        expect(DB::table('website_customers')->where('tenant_id', $fixture->tenant->id)->count())->toBe(0);
        $existingId = $writer->table('website_customers')->insertGetId([
            'tenant_id' => $fixture->tenant->id, 'email' => 'CASEY@EXAMPLE.TEST',
            'first_name' => 'Existing fictional customer', 'status' => 'active',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // The ordinary view remains empty after the other connection commits.
        // Duplicate detection must use current reads, not fall through to SQL500.
        expect(DB::table('website_customers')->where('tenant_id', $fixture->tenant->id)->count())->toBe(0);
        try {
            app(PosCustomerDirectory::class)->create($fixture->tenant, $fixture->cashier, $fixture->register->id, [
                'request_id' => (string) Str::uuid(), 'first_name' => 'Different fictional contact',
                'email' => ' casey@example.test ',
            ]);
            test()->fail('A current native email match must require explicit selection instead of duplicate creation.');
        } catch (ValidationException $exception) {
            expect($exception->status)->toBe(422)->and($exception->errors())->toHaveKey('email');
        }
        $customers = $writer->table('website_customers')->where('tenant_id', $fixture->tenant->id)->get();
        expect($customers)->toHaveCount(1)->and((int) $customers->sole()->id)->toBe($existingId)
            ->and($customers->sole()->email)->toBe('CASEY@EXAMPLE.TEST')
            ->and($customers->sole()->first_name)->toBe('Existing fictional customer');
        foreach (['pos_payment_attempts', 'pos_simulator_objects', 'website_orders', 'website_inventory_reservations',
            'marketing_profiles', 'marketing_consent_events', 'marketing_consent_requests'] as $table) {
            expect($writer->table($table)->where('tenant_id', $fixture->tenant->id)->count())->toBe(0);
        }
        Http::assertNothingSent();
    } finally {
        if (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        DB::disconnect('pos_stale_email_writer');
        DB::purge('pos_stale_email_writer');
        foreach (['pos_refunds', 'pos_payment_attempts', 'pos_simulator_objects', 'pos_registers'] as $table) {
            DB::table($table)->where('tenant_id', $fixture->tenant->id)->delete();
        }
        $fixture->tenant->delete();
        $fixture->cashier->delete();
        $fixture->manager->delete();
    }
});

it('uses exact SKU bytes on MySQL while excluding other tenant and site catalogs', function (): void {
    if (DB::connection()->getDriverName() !== 'mysql' || getenv('POS_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires explicitly authorized disposable MySQL.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', DB::connection()->getDatabaseName())
        || ! in_array(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing POS SKU fixtures outside local disposable MySQL.');
    }
    Http::preventStrayRequests();
    $fixture = PosFixture::merchant('sku-'.Str::lower((string) Str::ulid()));
    $other = PosFixture::merchant('foreign-sku-'.Str::lower((string) Str::ulid()));
    $copyVariant = function (string $sku, ?int $siteId = null) use ($fixture): WebsiteProductVariant {
        $product = $fixture->product->replicate();
        $product->handle = 'lookup-'.Str::lower((string) Str::ulid());
        $product->tenant_site_id = $siteId ?? $fixture->site->id;
        $product->save();
        $variant = $fixture->variant->replicate();
        $variant->website_product_id = $product->id;
        $variant->sku = $sku;
        $variant->save();

        return $variant;
    };
    try {
        // Earlier nonexact rows must not consume the query's two-match limit.
        $fixture->variant->update(['sku' => 'abc123']);
        $copyVariant('ABC123');
        $exact = $copyVariant('AbC123');
        expect(WebsiteProductVariant::query()->forTenantId($fixture->tenant->id)->where('sku', 'AbC123')->count())->toBe(3);
        // Each tenant has one site. Deliberately malformed ownership exercises
        // the separate tenant and site fences without weakening that constraint.
        $foreignSiteVariant = $copyVariant('AbC123', $other->site->id);
        $other->variant->update(['sku' => 'AbC123']);
        $lookup = app(PosCatalogLookup::class);
        $found = $lookup->find($fixture->tenant, $fixture->cashier, $fixture->register->id, 'AbC123');
        expect($found['variant_id'])->toBe($exact->id)->and($found['sku'])->toBe('AbC123')->and($found['price_cents'])->toBe(2500);
        try {
            $lookup->find($fixture->tenant, $fixture->cashier, $fixture->register->id, 'aBc123');
            test()->fail('A case-insensitive SQL match must not substitute another SKU.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(404);
        }
        $foreignSiteVariant->update(['sku' => 'FOREIGN-ONLY']);
        $other->variant->update(['sku' => 'FOREIGN-ONLY']);
        try {
            $lookup->find($fixture->tenant, $fixture->cashier, $fixture->register->id, 'FOREIGN-ONLY');
            test()->fail('Foreign tenant or site catalog rows must not enter register lookup.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(404);
        }
        $copyVariant('AbC123');
        try {
            $lookup->find($fixture->tenant, $fixture->cashier, $fixture->register->id, 'AbC123');
            test()->fail('Duplicate exact native SKUs require a catalog review.');
        } catch (HttpException $exception) {
            expect($exception->getStatusCode())->toBe(409);
        }
        expect(PosPaymentAttempt::query()->whereIn('tenant_id', [$fixture->tenant->id, $other->tenant->id])->count())->toBe(0)
            ->and(PosSimulatorObject::query()->whereIn('tenant_id', [$fixture->tenant->id, $other->tenant->id])->count())->toBe(0)
            ->and(WebsiteOrder::query()->whereIn('tenant_id', [$fixture->tenant->id, $other->tenant->id])->count())->toBe(0);
        Http::assertNothingSent();
    } finally {
        foreach ([$fixture, $other] as $catalog) {
            foreach (['pos_refunds', 'pos_payment_attempts', 'pos_simulator_objects', 'pos_registers'] as $table) {
                DB::table($table)->where('tenant_id', $catalog->tenant->id)->delete();
            }
            $catalog->tenant->delete();
            $catalog->cashier->delete();
            $catalog->manager->delete();
        }
    }
});
