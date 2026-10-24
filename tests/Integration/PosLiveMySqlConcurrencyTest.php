<?php

use App\Services\Pos\NativePosCommerce;
use App\Services\Pos\PosException;
use App\Services\Pos\PosSchema;
use App\Services\Pos\PosStore;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(Tests\TestCase::class);

it('holds the last location stock unit for exactly one of four simultaneous cashiers', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Requires explicitly authorized local disposable MySQL and process forking.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe POS contention test.');
    }
    PosSchema::install();
    config(['pos_live.runtime_id' => 'stock_race_fixture']);
    $store = app(PosStore::class);
    $tenant = 900000001;
    $item = bin2hex(random_bytes(16));
    $sales = [];
    $store->rows('items', $tenant)->insert($store->values($tenant, ['id' => $item, 'location_id' => 'fixture_location', 'sku' => $item, 'title' => 'Fictional final stock unit',
        'kind' => 'merchandise', 'unit_amount_minor' => 1000, 'tax_bps' => 0, 'tax_basis' => 'Fictional reviewed test', 'tax_reviewed_at' => now(),
        'reviewed_by' => 900000002, 'revision' => str_repeat('a', 64), 'tracked' => true, 'quantity' => 1, 'active' => true]));
    $snapshot = ['terms' => ['source' => 'native', 'selections' => [['item_id' => $item, 'quantity' => 1, 'revision' => str_repeat('a', 64)]], 'stock' => [['item_id' => $item, 'quantity' => 1, 'revision' => str_repeat('a', 64)]]]];
    for ($i = 0; $i < 4; $i++) {
        $sales[] = $id = bin2hex(random_bytes(16));
        $store->rows('sales', $tenant)->insert($store->values($tenant, ['id' => $id, 'register_id' => str_repeat((string) ($i + 1), 32), 'location_id' => 'fixture_location',
            'actor_id' => 900000002 + $i, 'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(),
            'snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => str_repeat('b', 64), 'amount_minor' => 1000, 'currency' => 'usd', 'commerce_source' => 'native',
            'state' => 'quoted', 'created_at' => now(), 'expires_at' => now()->addMinutes(5)]));
    }
    $directory = sys_get_temp_dir().'/pos-stock-test-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        foreach ($sales as $i => $id) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork stock race.');
            }
            if ($pid === 0) {
                try {
                    $child = new PosStore(DB::connection(), app('encrypter'));
                    try {
                        (new NativePosCommerce($child))->prepare($child->rows('sales', $tenant)->where('id', $id)->firstOrFail(), $snapshot);
                        $result = 'held';
                    } catch (PosException $error) {
                        $result = $error->blocker;
                    }
                    file_put_contents($directory.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($i) => file_get_contents($directory.'/'.$i), range(0, 3));
        expect(count(array_filter($results, fn ($v) => $v === 'held')))->toBe(1)
            ->and(count(array_filter($results, fn ($v) => $v === 'stock_unavailable')))->toBe(3)
            ->and((int) DB::table('pos_live_holds')->whereIn('sale_id', $sales)->sum('quantity'))->toBe(1);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        DB::table('pos_live_holds')->whereIn('sale_id', $sales)->delete();
        DB::table('pos_live_events')->whereIn('sale_id', $sales)->delete();
        DB::table('pos_live_sales')->whereIn('id', $sales)->delete();
        DB::table('pos_live_items')->where('id', $item)->delete();
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        } rmdir($directory);
    }
});

it('grants exactly one of four concurrent cashiers the same Stripe reader before stock commitment', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('POS_LIVE_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Disposable MySQL and fork required.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName()) || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Unsafe contention DB.');
    }
    PosSchema::install();
    \App\Services\Pos\PosStripeSchema::install();
    config(['pos_live.runtime_id' => 'reader_race_fixture']);
    $store = app(PosStore::class);
    $tenant = 900000001;
    $register = bin2hex(random_bytes(16));
    $connection = bin2hex(random_bytes(16));
    $reader = bin2hex(random_bytes(16));
    $sales = [];
    $store->rows('readers', $tenant)->insert($store->values($tenant, ['id' => $reader, 'register_id' => $register, 'connection_id' => $connection, 'reader_id' => 'tmr_race', 'location_id' => 'tml_race', 'identity_hash' => hash('sha256', $reader), 'state' => 'online']));
    for ($i = 0; $i < 4; $i++) {
        $sales[] = $id = bin2hex(random_bytes(16));
        $store->rows('sales', $tenant)->insert($store->values($tenant, ['id' => $id, 'register_id' => $register, 'location_id' => 'race', 'actor_id' => 900000002 + $i,
            'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(), 'snapshot' => $store->encrypt(['fixture' => true]), 'snapshot_hash' => str_repeat('a', 64),
            'amount_minor' => 1000, 'currency' => 'usd', 'commerce_source' => 'native', 'state' => 'quoted', 'created_at' => now(), 'expires_at' => now()->addMinute()]));
    }
    $dir = sys_get_temp_dir().'/pos-reader-race-'.bin2hex(random_bytes(8));
    mkdir($dir, 0700);
    DB::purge();
    $children = [];
    try {
        foreach ($sales as $i => $id) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork');
            }
            if ($pid === 0) {
                try {
                    $s = new PosStore(DB::connection(), app('encrypter'));
                    $p = new \App\Services\Pos\StripeTerminalProvider($s, new \App\Services\Pos\StripeTerminalClient($s));
                    try {
                        $p->reserve(['tenant_id' => $tenant, 'connection' => (object) ['id' => $connection, 'provider_location_id' => 'tml_race']], $s->rows('sales', $tenant)->where('id', $id)->firstOrFail());
                        $result = 'reserved';
                    } catch (PosException $e) {
                        $result = $e->blocker;
                    }file_put_contents($dir.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable) {
                    exit(1);
                }
            }
            $children[] = $pid;
        }
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            expect(pcntl_wexitstatus($status))->toBe(0);
        }
        $results = array_map(fn ($i) => file_get_contents($dir.'/'.$i), range(0, 3));
        expect(count(array_filter($results, fn ($r) => $r === 'reserved')))->toBe(1)->and(count(array_filter($results, fn ($r) => $r === 'reader_busy')))->toBe(3);
        expect(DB::table('pos_live_stripe_operations')->whereIn('id', $sales)->whereNotNull('active_reader_claim')->count())->toBe(1);
        expect(DB::table('pos_live_holds')->whereIn('sale_id', $sales)->count())->toBe(0);
    } finally {
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        DB::table('pos_live_stripe_operations')->whereIn('id', $sales)->delete();
        DB::table('pos_live_sales')->whereIn('id', $sales)->delete();
        DB::table('pos_live_readers')->where('id', $reader)->delete();
        foreach (glob($dir.'/*') as $file) {
            unlink($file);
        }rmdir($dir);
    }
});
