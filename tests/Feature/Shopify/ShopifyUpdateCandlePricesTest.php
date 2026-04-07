<?php

use App\Console\Commands\ShopifyUpdateCandlePrices;
use App\Services\Shopify\CandlePriceVariantClassifier;
use App\Services\Shopify\ShopifyCliAdminClient;

it('only plans price changes for baseline retail candle pricing', function (): void {
    $command = new class(app(ShopifyCliAdminClient::class), app(CandlePriceVariantClassifier::class)) extends ShopifyUpdateCandlePrices
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
            'title' => 'Coffeehouse',
            'handle' => 'coffeehouse',
            'productType' => 'Soy Candles',
            'tags' => ['Retail Priced'],
            'status' => 'ACTIVE',
            'variants' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/11',
                        'title' => '8oz Cotton Wick',
                        'price' => '18.00',
                        'selectedOptions' => [],
                    ],
                ],
            ],
        ],
        [
            'id' => 'gid://shopify/Product/2',
            'title' => 'Fundraiser Candle',
            'handle' => 'fundraiser-candle',
            'productType' => 'Soy Candles',
            'tags' => ['Fundraiser'],
            'status' => 'ACTIVE',
            'variants' => [
                'nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/22',
                        'title' => '8oz Cotton Wick',
                        'price' => '10.80',
                        'selectedOptions' => [],
                    ],
                ],
            ],
        ],
    ]);

    expect($plan['changed_rows'])->toHaveCount(1)
        ->and($plan['changed_rows'][0]['Product Title'])->toBe('Coffeehouse')
        ->and($plan['changed_rows'][0]['Old Price'])->toBe('18.00')
        ->and($plan['changed_rows'][0]['New Price'])->toBe('20.00')
        ->and($plan['unchanged_rows'])->toHaveCount(1)
        ->and($plan['unchanged_rows'][0]['Product Title'])->toBe('Fundraiser Candle')
        ->and($plan['unchanged_rows'][0]['Reason'])->toBe('special pricing context');
});
