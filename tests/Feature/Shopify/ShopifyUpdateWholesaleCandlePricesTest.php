<?php

use App\Console\Commands\ShopifyUpdateWholesaleCandlePrices;
use App\Services\Shopify\ShopifyCliAdminClient;
use App\Services\Shopify\WholesaleCandlePriceVariantClassifier;

it('plans wholesale baseline candle repricing including draft standard candles', function (): void {
    $command = new class(app(ShopifyCliAdminClient::class), app(WholesaleCandlePriceVariantClassifier::class)) extends ShopifyUpdateWholesaleCandlePrices
    {
        /**
         * @param  array<int,array<string,mixed>>  $products
         * @return array{
         *   changed_rows:array<int,array<string,mixed>>,
         *   unchanged_rows:array<int,array<string,mixed>>,
         *   change_groups:array<string,array<int,array<string,mixed>>>,
         *   summary:array<string,mixed>
         * }
         */
        public function exposeBuildPlan(array $products): array
        {
            return $this->buildPlan($products);
        }
    };

    $plan = $command->exposeBuildPlan([
        [
            'id' => 'gid://shopify/Product/1',
            'title' => 'Wholesale Peppermint Milkshake',
            'handle' => 'wholesale-peppermint-milkshake-soy-candle',
            'productType' => 'Soy Candles',
            'tags' => ['Holiday Collection', 'wholesale'],
            'status' => 'DRAFT',
            'variants' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/11',
                        'title' => '16oz Cedar Wick',
                        'price' => '15.00',
                        'selectedOptions' => [],
                    ],
                ],
            ],
        ],
        [
            'id' => 'gid://shopify/Product/2',
            'title' => 'Wholesale Autumn Flight',
            'handle' => 'wholesale-autumn-flight',
            'productType' => 'Wholesale',
            'tags' => [],
            'status' => 'DRAFT',
            'variants' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/22',
                        'title' => 'Default Title',
                        'price' => '20.00',
                        'selectedOptions' => [],
                    ],
                ],
            ],
        ],
        [
            'id' => 'gid://shopify/Product/3',
            'title' => 'Wholesale Coffeehouse',
            'handle' => 'wholesale-coffeehouse',
            'productType' => 'wholesale',
            'tags' => ['Classic Collection', 'wholesale'],
            'status' => 'ACTIVE',
            'variants' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/33',
                        'title' => '8oz Cotton Wick',
                        'price' => '10.00',
                        'selectedOptions' => [],
                    ],
                ],
            ],
        ],
    ]);

    expect($plan['changed_rows'])->toHaveCount(1)
        ->and($plan['changed_rows'][0]['Product Title'])->toBe('Wholesale Peppermint Milkshake')
        ->and($plan['changed_rows'][0]['Old Price'])->toBe('15.00')
        ->and($plan['changed_rows'][0]['New Price'])->toBe('16.00')
        ->and($plan['unchanged_rows'])->toHaveCount(2)
        ->and($plan['unchanged_rows'][0]['Reason'])->toBe('audit separately: wholesale flight product')
        ->and($plan['unchanged_rows'][1]['Reason'])->toBe('already at target price');
});

it('leaves wholesale candle variants unchanged when they are not on the old wholesale ladder', function (): void {
    $command = new class(app(ShopifyCliAdminClient::class), app(WholesaleCandlePriceVariantClassifier::class)) extends ShopifyUpdateWholesaleCandlePrices
    {
        /**
         * @param  array<int,array<string,mixed>>  $products
         * @return array{
         *   changed_rows:array<int,array<string,mixed>>,
         *   unchanged_rows:array<int,array<string,mixed>>,
         *   change_groups:array<string,array<int,array<string,mixed>>>,
         *   summary:array<string,mixed>
         * }
         */
        public function exposeBuildPlan(array $products): array
        {
            return $this->buildPlan($products);
        }
    };

    $plan = $command->exposeBuildPlan([
        [
            'id' => 'gid://shopify/Product/4',
            'title' => 'Wholesale Coffeehouse',
            'handle' => 'wholesale-coffeehouse',
            'productType' => 'Wholesale',
            'tags' => ['wholesale'],
            'status' => 'ACTIVE',
            'variants' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/44',
                        'title' => 'Wax Melt',
                        'price' => '4.00',
                        'selectedOptions' => [],
                    ],
                ],
            ],
        ],
    ]);

    expect($plan['changed_rows'])->toBeEmpty()
        ->and($plan['unchanged_rows'])->toHaveCount(1)
        ->and($plan['unchanged_rows'][0]['Reason'])->toBe('special pricing context')
        ->and($plan['unchanged_rows'][0]['New Price'])->toBe('3.50');
});
