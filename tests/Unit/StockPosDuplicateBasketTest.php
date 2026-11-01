<?php

use App\Services\Pos\PosException;
use App\Services\Pos\StockPosCommerce;

uses(Tests\TestCase::class);

it('rejects duplicate item IDs before pricing or inventory for both catalog sources', function (string $source): void {
    $scope = ['tenant_id' => 1, 'register' => (object) ['catalog_source' => $source]];
    $item = str_repeat('a', 32);
    // Different component selections still share a pricing key until line IDs are supported.
    $input = ['items' => [
        ['item_id' => $item, 'quantity' => 1, 'components' => [['item_id' => str_repeat('b', 32), 'quantity' => 1]]],
        ['item_id' => $item, 'quantity' => 1, 'components' => [['item_id' => str_repeat('c', 32), 'quantity' => 1]]],
    ]];
    expect(fn () => app(StockPosCommerce::class)->quote($scope, $input))
        ->toThrow(PosException::class, 'duplicate_catalog_item');
})->with(['native', 'shopify']);
