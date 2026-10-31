<?php

use App\Services\Inventory\StockException;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockSchema;
use Illuminate\Support\Facades\DB;

uses(Tests\TestCase::class);

it('serializes four buyers for one finished unit and rejects a stale physical count after the winner', function (): void {
    $db = DB::connection();
    if ($db->getDriverName() !== 'mysql' || getenv('STOCK_DISPOSABLE_MYSQL') !== '1' || ! function_exists('pcntl_fork')) {
        $this->markTestSkipped('Explicit disposable MySQL and process forking required.');
    }
    if (! app()->environment('testing') || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName()) || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new RuntimeException('Refusing unsafe stock race.');
    }
    StockSchema::install();
    $tenant = random_int(900100000, 900999999);
    $item = bin2hex(random_bytes(16));
    $stock = new StockLedger($db);
    $stock->rows('items', $tenant)->insert(['id' => $item, 'tenant_id' => $tenant, 'title' => 'Fictional last unit', 'sku' => $item, 'product_key' => $item, 'revision' => str_repeat('a', 64)]);
    $pool = $stock->createPool($tenant, 'pool', 1, 'Fictional show', 'show', 'fixture-race')['pool_id'];
    $stock->count($tenant, 'opening', 1, $pool, [$item => 1], 'opening_count');
    $directory = sys_get_temp_dir().'/finished-stock-race-'.bin2hex(random_bytes(8));
    mkdir($directory, 0700);
    DB::purge();
    $pids = [];
    try {
        for ($i = 0; $i < 4; $i++) {
            $pid = pcntl_fork();
            if ($pid === -1) {
                throw new RuntimeException('Cannot fork stock race.');
            }
            if ($pid === 0) {
                try {
                    $child = new StockLedger(DB::connection());
                    try {
                        $child->reserve($tenant, 'buyer-'.$i, $i + 1, $pool, [$item => 1], now()->addMinutes(5));
                        $result = 'held';
                    } catch (StockException $error) {
                        $result = $error->reason;
                    }
                    file_put_contents($directory.'/'.$i, $result);
                    DB::disconnect();
                    exit(0);
                } catch (Throwable $error) {
                    file_put_contents($directory.'/error-'.$i, get_class($error).': '.$error->getMessage());
                    exit(1);
                }
            }
            $pids[] = $pid;
        }
        $statuses = [];
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $statuses[] = pcntl_wexitstatus($status);
        }
        if (array_filter($statuses)) {
            throw new RuntimeException(implode("\n", array_map(fn ($f) => file_get_contents($f), glob($directory.'/error-*'))) ?: 'A stock worker failed without a result.');
        }
        expect($statuses)->toBe([0, 0, 0, 0]);
        $results = array_map(fn ($i) => file_get_contents($directory.'/'.$i), range(0, 3));
        $stock = new StockLedger(DB::connection());
        expect(count(array_filter($results, fn ($v) => $v === 'held')))->toBe(1)
            ->and(count(array_filter($results, fn ($v) => $v === 'stock_unavailable')))->toBe(3)
            ->and((int) $stock->rows('balances', $tenant)->value('reserved'))->toBe(1);
        expect(fn () => $stock->count($tenant, 'stale', 1, $pool, [$item => 50], 'physical_count', [$item => 1]))->toThrow(StockException::class, 'stock_count_changed_by_activity');
        expect((int) $stock->rows('balances', $tenant)->value('on_hand'))->toBe(1);
    } finally {
        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status, WNOHANG);
        }
        foreach (StockSchema::TABLES as $table) {
            DB::table('commerce_stock_'.$table)->where('tenant_id', $tenant)->delete();
        }
        foreach (glob($directory.'/*') as $file) {
            unlink($file);
        } rmdir($directory);
    }
});
