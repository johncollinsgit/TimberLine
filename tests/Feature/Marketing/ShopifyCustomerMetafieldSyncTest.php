<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Marketing\GrowaveCustomerMetafieldParser;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.retail.access_token', 'retail-token');
    config()->set('services.shopify.stores.wholesale.shop', null);
    config()->set('services.shopify.stores.wholesale.access_token', null);
});

test('growave parser extracts normalized fields and keeps detected growave metafields', function () {
    $parser = app(GrowaveCustomerMetafieldParser::class);

    $parsed = $parser->parse([
        ['namespace' => 'ssw', 'key' => 'loyalty_points', 'value' => '125', 'type' => 'number_integer'],
        ['namespace' => 'ssw', 'key' => 'vip_tier', 'value' => 'Gold', 'type' => 'single_line_text_field'],
        ['namespace' => 'growave', 'key' => 'referral_link', 'value' => 'https://example.test/r/abc', 'type' => 'url'],
        ['namespace' => 'ssw', 'key' => 'member_status', 'value' => 'active', 'type' => 'single_line_text_field'],
        ['namespace' => 'custom', 'key' => 'other_meta', 'value' => 'ignore-me', 'type' => 'single_line_text_field'],
    ]);

    expect($parsed['points_balance'])->toBe(125)
        ->and($parsed['vip_tier'])->toBe('Gold')
        ->and($parsed['referral_link'])->toBe('https://example.test/r/abc')
        ->and(count($parsed['raw_metafields']))->toBe(4);
});

test('sync upserts customer external profile and prefers Shopify customer ID mapping', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Mapped',
        'email' => 'mapped@example.com',
        'normalized_email' => 'mapped@example.com',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:1001',
        'source_meta' => [],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    CustomerExternalProfile::query()->create([
        'marketing_profile_id' => $profile->id,
        'provider' => 'shopify',
        'integration' => 'growave',
        'store_key' => 'retail',
        'external_customer_id' => '1001',
        'external_customer_gid' => 'gid://shopify/Customer/1001',
        'email' => 'different@example.com',
        'normalized_email' => 'different@example.com',
        'raw_metafields' => [
            ['namespace' => 'ssw', 'key' => 'loyalty_points', 'value' => '120', 'type' => 'number_integer'],
            ['namespace' => 'ssw', 'key' => 'vip_tier', 'value' => 'Silver', 'type' => 'single_line_text_field'],
        ],
        'points_balance' => 120,
        'vip_tier' => 'Silver',
        'referral_link' => null,
        'synced_at' => now()->subHour(),
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyCustomersPayload([[
            'id' => 'gid://shopify/Customer/1001',
            'email' => 'different@example.com',
            'firstName' => 'Mapped',
            'lastName' => 'Customer',
            'metafields' => [
                ['namespace' => 'ssw', 'key' => 'loyalty_points', 'value' => '155', 'type' => 'number_integer'],
                ['namespace' => 'ssw', 'key' => 'vip_tier', 'value' => 'Gold', 'type' => 'single_line_text_field'],
            ],
        ]]), 200),
    ]);

    $this->artisan('shopify:sync-customer-metafields retail --limit=10')
        ->assertExitCode(0);

    $snapshot = CustomerExternalProfile::query()->sole();

    expect(CustomerExternalProfile::query()->count())->toBe(1)
        ->and((int) $snapshot->marketing_profile_id)->toBe((int) $profile->id)
        ->and((int) $snapshot->points_balance)->toBe(155)
        ->and((string) $snapshot->vip_tier)->toBe('Gold')
        ->and((string) $snapshot->provider)->toBe('shopify')
        ->and((string) $snapshot->integration)->toBe('growave');
});

test('sync falls back to email mapping and persists canonical shopify customer link', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Email Match',
        'email' => 'fallback@example.com',
        'normalized_email' => 'fallback@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyCustomersPayload([[
            'id' => 'gid://shopify/Customer/2002',
            'email' => 'FALLBACK@example.com',
            'firstName' => 'Email',
            'lastName' => 'Match',
            'metafields' => [
                ['namespace' => 'growave', 'key' => 'referral_link', 'value' => 'https://example.test/r/fallback', 'type' => 'url'],
            ],
        ]]), 200),
    ]);

    $this->artisan('shopify:sync-customer-metafields retail --limit=10')
        ->assertExitCode(0);

    expect(CustomerExternalProfile::query()->count())->toBe(1)
        ->and(CustomerExternalProfile::query()->first()->marketing_profile_id)->toBe($profile->id)
        ->and(MarketingProfileLink::query()
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:2002')
            ->where('marketing_profile_id', $profile->id)
            ->exists())->toBeTrue();
});

test('dry-run mode performs no writes to external profile snapshot table', function () {
    MarketingProfile::query()->create([
        'first_name' => 'Dry',
        'email' => 'dryrun@example.com',
        'normalized_email' => 'dryrun@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyCustomersPayload([[
            'id' => 'gid://shopify/Customer/3003',
            'email' => 'dryrun@example.com',
            'firstName' => 'Dry',
            'lastName' => 'Run',
            'metafields' => [
                ['namespace' => 'ssw', 'key' => 'loyalty_points', 'value' => '88', 'type' => 'number_integer'],
            ],
        ]]), 200),
    ]);

    $this->artisan('shopify:sync-customer-metafields retail --limit=10 --dry-run')
        ->assertExitCode(0);

    expect(CustomerExternalProfile::query()->count())->toBe(0)
        ->and(MarketingProfileLink::query()->where('source_type', 'shopify_customer')->count())->toBe(0)
        ->and(MarketingImportRun::query()->where('type', 'shopify_customer_metafields_sync')->count())->toBe(1);
});

test('sync fails loudly when fallback email mapping is ambiguous', function () {
    MarketingProfile::query()->create([
        'first_name' => 'Dup A',
        'email' => 'dup@example.com',
        'normalized_email' => 'dup@example.com',
    ]);
    MarketingProfile::query()->create([
        'first_name' => 'Dup B',
        'email' => 'dup@example.com',
        'normalized_email' => 'dup@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyCustomersPayload([[
            'id' => 'gid://shopify/Customer/4040',
            'email' => 'dup@example.com',
            'firstName' => 'Dup',
            'lastName' => 'Conflict',
            'metafields' => [
                ['namespace' => 'ssw', 'key' => 'loyalty_points', 'value' => '10', 'type' => 'number_integer'],
            ],
        ]]), 200),
    ]);

    $this->artisan('shopify:sync-customer-metafields retail --limit=10')
        ->assertExitCode(1);

    expect(CustomerExternalProfile::query()->count())->toBe(0)
        ->and(MarketingImportRun::query()
            ->where('type', 'shopify_customer_metafields_sync')
            ->where('status', 'failed')
            ->exists())->toBeTrue();
});

test('sync fails loudly on shopify graphql API errors', function () {
    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response([
            'errors' => [
                ['message' => 'Access denied'],
            ],
        ], 200),
    ]);

    $this->artisan('shopify:sync-customer-metafields retail --limit=10')
        ->assertExitCode(1);

    expect(MarketingImportRun::query()
        ->where('type', 'shopify_customer_metafields_sync')
        ->where('status', 'failed')
        ->exists())->toBeTrue();
});

/**
 * @param  array<int,array<string,mixed>>  $customers
 * @return array<string,mixed>
 */
function shopifyCustomersPayload(array $customers): array
{
    return [
        'data' => [
            'customers' => [
                'edges' => array_map(function (array $customer, int $index): array {
                    $metafieldEdges = [];
                    foreach ((array) ($customer['metafields'] ?? []) as $metafield) {
                        $metafieldEdges[] = ['node' => [
                            'namespace' => $metafield['namespace'] ?? null,
                            'key' => $metafield['key'] ?? null,
                            'value' => $metafield['value'] ?? null,
                            'type' => $metafield['type'] ?? null,
                        ]];
                    }

                    return [
                        'cursor' => 'cursor-'.($index + 1),
                        'node' => [
                            'id' => $customer['id'] ?? null,
                            'email' => $customer['email'] ?? null,
                            'firstName' => $customer['firstName'] ?? null,
                            'lastName' => $customer['lastName'] ?? null,
                            'metafields' => [
                                'edges' => $metafieldEdges,
                            ],
                        ],
                    ];
                }, $customers, array_keys($customers)),
                'pageInfo' => [
                    'hasNextPage' => false,
                    'endCursor' => count($customers) > 0 ? 'cursor-'.count($customers) : null,
                ],
            ],
        ],
    ];
}
