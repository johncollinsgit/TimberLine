<?php

use App\Models\WebsiteProductVariant;
use App\Services\PaymentsCore\Inventory\InventoryUnavailableException;
use App\Services\PaymentsCore\Inventory\WebsitePaymentInventory;
use App\Services\PaymentsCore\MySqlPaymentJournal;
use App\Services\PaymentsCore\PaymentCore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\Support\PaymentsCore\FixtureAccess;
use Tests\Support\PaymentsCore\InventoryFixture;

require_once __DIR__.'/../Support/PaymentsCore/InventoryFixture.php';
uses(Tests\TestCase::class);
beforeEach(function (): void {
    if (DB::getDriverName() !== 'mysql' || getenv('PAYMENTS_INVENTORY_DISPOSABLE_MYSQL') !== '1') {
        $this->markTestSkipped('Requires local disposable inventory MySQL.');
    }
    InventoryFixture::guard();
    $this->f = InventoryFixture::make();
});
afterEach(function (): void {
    if (isset($this->f)) {
        $this->f->cleanup();
    } Http::assertNothingSent();
});

it('reserves intent and all shared holds before provider and commits stock exactly once after response-loss recovery', function (): void {
    $quote = $this->f->quote();
    $this->f->provider->base->loseResponse = true;
    $row = $this->f->core->startQuoted($quote, (string) Str::uuid());
    expect($row['state'])->toBe('unknown')->and($row['inventory_state'])->toBe('held')
        ->and($this->f->pos->variant->fresh()->inventory_quantity)->toBe(2)
        ->and(DB::table('website_inventory_reservations')->where('payment_core_operation_id', $row['id'])->whereNull('expires_at')->whereNull('website_order_id')->count())->toBe(1)
        ->and(DB::table('website_orders')->where('tenant_id', $this->f->binding->tenantId)->count())->toBe(0);
    $recovered = $this->f->core->recover($this->f->binding, $row['id']);
    expect($recovered['state'])->toBe('succeeded')->and($recovered['inventory_state'])->toBe('committed');
    $this->f->core->recover($this->f->binding, $row['id']);
    expect($this->f->pos->variant->fresh()->inventory_quantity)->toBe(1)
        ->and(DB::table('website_inventory_movements')->where('tenant_id', $this->f->binding->tenantId)->where('reason', 'payments_fixture_sale')->count())->toBe(1)
        ->and($this->f->provider->base->calls($this->f->binding->tenantId))->toBe(1);
    $this->f->provider->base->loseResponse = false;
    $this->f->core->refund($this->f->binding, $row['id'], (string) Str::uuid(), 100, 'other');
    expect($this->f->pos->variant->fresh()->inventory_quantity)->toBe(1);
});

it('refuses absent or revoked local authority before provider and leaves no partial intent or hold', function (): void {
    $quote = $this->f->quote();
    $journal = new MySqlPaymentJournal(DB::connection(), app('encrypter'), new WebsitePaymentInventory(DB::connection(), $this->f->catalog, app('encrypter')));
    $core = new PaymentCore($journal, ['fixture_provider' => $this->f->provider], new FixtureAccess($this->f->binding->tenantId));
    expect(fn () => $core->startQuoted($quote, (string) Str::uuid()))->toThrow(InventoryUnavailableException::class);
    $this->f->authority->enabled = false;
    expect(fn () => $this->f->core->startQuoted($quote, (string) Str::uuid()))->toThrow(InventoryUnavailableException::class);
    expect(DB::table('payment_core_operations')->where('tenant_id', $this->f->binding->tenantId)->count())->toBe(0)
        ->and(DB::table('website_inventory_reservations')->where('tenant_id', $this->f->binding->tenantId)->count())->toBe(0)
        ->and($this->f->provider->base->calls($this->f->binding->tenantId))->toBe(0);
});

it('releases only definitive negative outcomes and never commits a later contradictory success', function (string $outcome): void {
    $this->f->provider->base->nextState = $outcome;
    $row = $this->f->core->startQuoted($this->f->quote(), (string) Str::uuid());
    expect($row['state'])->toBe($outcome)->and($row['inventory_state'])->toBe('released')
        ->and(DB::table('website_inventory_reservations')->where('payment_core_operation_id', $row['id'])->value('status'))->toBe('released');
    $this->f->journal->apply($row['tenant_id'], $row['id'], $this->f->provider->base->evidence($row, 'succeeded', 2));
    expect($this->f->pos->variant->fresh()->inventory_quantity)->toBe(2)
        ->and(DB::table('website_inventory_movements')->where('tenant_id', $row['tenant_id'])->count())->toBe(0);
})->with(['failed', 'cancelled']);

it('quarantines canonical paid stock review before changing any line and preserves canonical effect identity', function (): void {
    $second = WebsiteProductVariant::create(['tenant_id' => $this->f->binding->tenantId, 'website_product_id' => $this->f->pos->product->id,
        'title' => 'Second fictional item', 'sku' => 'FIXTURE-SECOND', 'price_cents' => 100, 'inventory_quantity' => 2, 'is_available' => true]);
    $this->f->provider->base->loseResponse = true;
    $row = $this->f->core->startQuoted($this->f->quote(variantIds: [$this->f->pos->variant->id, $second->id]), (string) Str::uuid());
    DB::table('website_inventory_reservations')->where('payment_core_operation_id', $row['id'])->where('website_product_variant_id', $second->id)->update(['quantity' => 2]);
    $review = $this->f->core->recover($this->f->binding, $row['id']);
    expect($review['state'])->toBe('review')->and($review['inventory_payment_state'])->toBe('succeeded')->and($review['provider_id'])->not->toBeNull()
        ->and($this->f->pos->variant->fresh()->inventory_quantity)->toBe(2)->and($second->fresh()->inventory_quantity)->toBe(2)
        ->and(DB::table('website_inventory_movements')->where('tenant_id', $row['tenant_id'])->count())->toBe(0);
    DB::table('website_inventory_reservations')->where('payment_core_operation_id', $row['id'])->where('website_product_variant_id', $second->id)->update(['quantity' => 1]);
    expect($this->f->core->recover($this->f->binding, $row['id'])['state'])->toBe('succeeded')
        ->and($this->f->pos->variant->fresh()->inventory_quantity)->toBe(1)->and($second->fresh()->inventory_quantity)->toBe(1);
});

it('rejects a quote after Website holds consume stock and rolls back the new payment identity', function (): void {
    $quote = $this->f->quote();
    $order = $this->f->pos->websiteOrder();
    DB::table('website_inventory_reservations')->insert(['tenant_id' => $this->f->binding->tenantId, 'website_product_variant_id' => $this->f->pos->variant->id,
        'website_order_id' => $order->id, 'quantity' => 2, 'status' => 'active', 'expires_at' => now()->addMinutes(10)]);
    expect(fn () => $this->f->core->startQuoted($quote, (string) Str::uuid()))->toThrow(App\Services\PaymentsCore\Quotes\QuoteException::class);
    expect(DB::table('payment_core_operations')->where('tenant_id', $this->f->binding->tenantId)->count())->toBe(0)
        ->and($this->f->provider->base->calls($this->f->binding->tenantId))->toBe(0);
});

function runInventoryRace(array $jobs): array
{
    $dir = sys_get_temp_dir().'/payments-inventory-race-'.Str::uuid();
    mkdir($dir, 0700);
    $db = DB::connection()->getConfig();
    $env = ['APP_ENV' => 'testing', 'APP_KEY' => config('app.key'), 'DB_CONNECTION' => 'mysql', 'DB_HOST' => $db['host'],
        'DB_PORT' => (string) $db['port'], 'DB_SOCKET' => $db['unix_socket'] ?? '', 'DB_DATABASE' => $db['database'],
        'DB_USERNAME' => $db['username'], 'DB_PASSWORD' => $db['password'], 'CACHE_STORE' => 'array', 'MAIL_MAILER' => 'array',
        'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync', 'LOG_CHANNEL' => 'null',
        'PAYMENTS_CORE_DISPOSABLE_MYSQL' => '1', 'PAYMENTS_INVENTORY_DISPOSABLE_MYSQL' => '1'];
    $workers = [];
    try {
        foreach ($jobs as $job) {
            $p = new Process([PHP_BINARY, base_path('tests/Support/PaymentsCore/InventoryWorker.php')], base_path(), $env,
                json_encode([...$job, 'directory' => $dir], JSON_THROW_ON_ERROR), 35);
            $p->start();
            $workers[] = $p;
        }
        $deadline = microtime(true) + 15;
        while (count(glob($dir.'/ready-*') ?: []) !== count($jobs) && microtime(true) < $deadline) {
            foreach ($workers as $p) {
                if (! $p->isRunning() && ! $p->isSuccessful()) {
                    throw new RuntimeException($p->getErrorOutput());
                }
            }
            usleep(10000);
        }
        if (count(glob($dir.'/ready-*') ?: []) !== count($jobs)) {
            throw new RuntimeException('Inventory race readiness expired.');
        }
        file_put_contents($dir.'/go', 'go');
        $results = [];
        foreach ($workers as $p) {
            $p->wait();
            if (! $p->isSuccessful()) {
                throw new RuntimeException($p->getErrorOutput());
            }
            $results[] = json_decode($p->getOutput(), true, 64, JSON_THROW_ON_ERROR);
        }

        return $results;
    } finally {
        foreach ($workers as $p) {
            if ($p->isRunning()) {
                $p->stop(1);
            }
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($dir);
    }
}

it('serializes eight identical requests into one held intent and one provider dispatch', function (): void {
    $quote = $this->f->quote();
    $jobs = array_fill(0, 8, ['tenant' => $this->f->binding->tenantId, 'quote' => $quote->id, 'request' => (string) Str::uuid()]);
    $results = runInventoryRace($jobs);
    expect(array_unique(array_column($results, 'id')))->toHaveCount(1)
        ->and(DB::table('website_inventory_reservations')->where('tenant_id', $this->f->binding->tenantId)->sum('quantity'))->toBe('1')
        ->and($this->f->provider->base->calls($this->f->binding->tenantId))->toBe(1);
});

it('shares scarce stock with the real Website checkout reservation transaction across nine workers', function (): void {
    $this->f->pos->variant->update(['inventory_quantity' => 1]);
    $jobs = [];
    for ($i = 0; $i < 8; $i++) {
        $binding = Tests\Support\PaymentsCore\MySqlFixture::binding($this->f->binding->tenantId, 'counter_'.$i);
        $quote = $this->f->quote($binding);
        $jobs[] = ['tenant' => $binding->tenantId, 'quote' => $quote->id, 'request' => (string) Str::uuid()];
    }
    App\Models\TenantPaymentAccount::create(['tenant_id' => $this->f->binding->tenantId, 'provider_account_id' => 'acct_fixture_inventory',
        'status' => 'ready', 'charges_enabled' => true, 'payouts_enabled' => true, 'platform_fee_bps' => 0]);
    $cart = App\Models\WebsiteCart::create(['tenant_id' => $this->f->binding->tenantId, 'tenant_site_id' => $this->f->pos->site->id,
        'token' => (string) Str::uuid(), 'currency' => 'usd', 'status' => 'active']);
    App\Models\WebsiteCartItem::create(['tenant_id' => $this->f->binding->tenantId, 'website_cart_id' => $cart->id,
        'website_product_variant_id' => $this->f->pos->variant->id, 'quantity' => 1]);
    $jobs[] = ['tenant' => $this->f->binding->tenantId, 'cart' => $cart->id];
    $results = runInventoryRace($jobs);
    expect(collect($results)->where('state', 'blocked'))->toHaveCount(8)
        ->and(DB::table('website_inventory_reservations')->where('tenant_id', $this->f->binding->tenantId)->where('status', 'active')->sum('quantity'))->toBe('1')
        ->and($this->f->pos->variant->fresh()->inventory_quantity)->toBe(1);
});
