<?php

use App\Services\Inventory\StockException;
use App\Services\Inventory\StockLedger;
use App\Services\Inventory\StockSchema;

uses(Tests\TestCase::class);

beforeEach(function (): void {
    if (config('database.default') === 'mysql') {
        $db = \Illuminate\Support\Facades\DB::connection();
        if (getenv('STOCK_DISPOSABLE_MYSQL') !== '1' || ! app()->environment('testing')
            || ! preg_match('/(^|_)(ci|test|testing)(_|$)/i', $db->getDatabaseName())
            || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
            throw new RuntimeException('Disposable local stock MySQL required.');
        }
        StockSchema::install();
        foreach (StockSchema::TABLES as $table) {
            $db->table('commerce_stock_'.$table)->whereIn('tenant_id', [1, 2])->delete();
        }
    }
    StockSchema::install();
    $this->stock = app(StockLedger::class);
    $this->item = str_repeat('a', 32);
    $this->stock->rows('items', 1)->insert(['id' => $this->item, 'tenant_id' => 1, 'sku' => 'FIXTURE-1', 'title' => 'Fictional merchandise',
        'product_key' => 'fixture', 'tracked' => true, 'active' => true, 'revision' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);
    $this->warehouse = $this->stock->createPool(1, 'warehouse', 1, 'Warehouse', 'warehouse')['pool_id'];
    $this->show = $this->stock->createPool(1, 'show', 1, 'Fictional show', 'show', 'fixture-event-1')['pool_id'];
});

it('packs and returns physical show stock once without consuming online stock on sale', function (): void {
    $this->stock->count(1, 'opening', 1, $this->warehouse, [$this->item => 20], 'opening_count');
    $this->stock->transfer(1, 'pack', 1, $this->warehouse, $this->show, [$this->item => 5], 'packed');
    $this->stock->transfer(1, 'pack', 1, $this->warehouse, $this->show, [$this->item => 5], 'packed');
    $this->stock->reserve(1, 'sale-1', 1, $this->show, [$this->item => 2], now()->addMinutes(10));
    $this->stock->settle(1, 'sale-1', true, 'square-fixture-payment');
    $this->stock->settle(1, 'sale-1', true, 'square-fixture-payment');
    $this->stock->transfer(1, 'return', 1, $this->show, $this->warehouse, [$this->item => 3], 'returned');
    expect($this->stock->listing(1, $this->warehouse)[0]['available_quantity'])->toBe(18)
        ->and($this->stock->listing(1, $this->show)[0]['available_quantity'])->toBe(0)
        ->and($this->stock->rows('movements', 1)->where('reason', 'sale')->count())->toBe(1);
});

it('blocks uncounted stock, held stock overselling and counts below active reservations', function (): void {
    expect(fn () => $this->stock->reserve(1, 'empty', 1, $this->show, [$this->item => 1], now()->addMinutes(10)))->toThrow(StockException::class, 'stock_unavailable');
    $this->stock->count(1, 'opening', 1, $this->show, [$this->item => 1], 'opening_count');
    $this->stock->reserve(1, 'first', 1, $this->show, [$this->item => 1], now()->addMinutes(10));
    expect(fn () => $this->stock->reserve(1, 'second', 1, $this->show, [$this->item => 1], now()->addMinutes(10)))->toThrow(StockException::class, 'stock_unavailable');
    expect(fn () => $this->stock->count(1, 'bad-count', 1, $this->show, [$this->item => 0], 'physical_count'))->toThrow(StockException::class, 'count_below_reserved_stock');
    $this->stock->settle(1, 'first', false);
    expect($this->stock->listing(1, $this->show)[0]['available_quantity'])->toBe(1);
});

it('rejects cross-tenant IDs and conflicting retries without changing balances', function (): void {
    expect(fn () => $this->stock->count(2, 'foreign', 1, $this->show, [$this->item => 100], 'opening_count'))->toThrow(StockException::class);
    $this->stock->count(1, 'opening', 1, $this->show, [$this->item => 4], 'opening_count');
    expect(fn () => $this->stock->count(1, 'opening', 1, $this->show, [$this->item => 40], 'opening_count'))->toThrow(StockException::class, 'stock_request_conflict');
    expect(fn () => $this->stock->settle(1, 'missing', false))->toThrow(StockException::class, 'stock_hold_missing');
    expect($this->stock->listing(1, $this->show)[0]['available_quantity'])->toBe(4);
});

it('queues shared-pool publication once per movement and keeps separate show movements out of the online channel', function (): void {
    $this->stock->rows('provider_items', 1)->insert(['tenant_id' => 1, 'store_id' => 1, 'item_id' => $this->item,
        'variant_id' => 'gid://shopify/ProductVariant/101', 'location_id' => 'gid://shopify/Location/301']);
    $this->stock->bind(1, 'online', 1, 'shopify', '1', $this->warehouse, [], true);
    $this->stock->count(1, 'opening', 1, $this->warehouse, [$this->item => 8], 'opening_count');
    $this->stock->transfer(1, 'pack', 1, $this->warehouse, $this->show, [$this->item => 3], 'packed');
    expect($this->stock->rows('publications', 1)->orderBy('id')->pluck('quantity')->all())->toBe([8, 5]);
    $this->stock->reserve(1, 'show-sale', 1, $this->show, [$this->item => 1], now()->addMinutes(10));
    $this->stock->settle(1, 'show-sale', true, 'square-fixture');
    expect($this->stock->rows('publications', 1)->count())->toBe(2);
});
