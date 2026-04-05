<?php

use App\Services\Shopify\ShopifyGraphqlClient;
use Illuminate\Support\Facades\Http;

test('shopify graphql client sends an empty variables object when variables are omitted', function (): void {
    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => function ($request) {
            expect($request->method())->toBe('POST')
                ->and($request->body())->toContain('"variables":{}');

            return Http::response([
                'data' => [
                    'shop' => [
                        'name' => 'Retail Test',
                    ],
                ],
            ], 200);
        },
    ]);

    $data = (new ShopifyGraphqlClient(
        shopDomain: 'retail-test.myshopify.com',
        accessToken: 'retail-token',
        apiVersion: '2026-01',
    ))->query('query { shop { name } }');

    expect(data_get($data, 'shop.name'))->toBe('Retail Test');
});
