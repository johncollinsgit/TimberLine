<?php

use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\ShopifyStore;
use App\Services\Marketing\GrowaveBirthdayMetafieldParser;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.stores.retail.shop', 'retail-test.myshopify.com');
    config()->set('services.shopify.stores.wholesale.shop', null);
    config()->set('services.shopify.allow_env_token_fallback', false);

    ShopifyStore::query()->updateOrCreate(
        ['store_key' => 'retail'],
        [
            'shop_domain' => 'retail-test.myshopify.com',
            'access_token' => 'retail-token',
            'scopes' => 'read_orders,read_products,read_customers',
            'installed_at' => now(),
        ]
    );
});

test('growave birthday parser extracts birthday date fields from metafields', function () {
    $parser = app(GrowaveBirthdayMetafieldParser::class);

    $parsed = $parser->parse([
        ['namespace' => 'growave', 'key' => 'birthday', 'value' => '1992-04-18', 'type' => 'single_line_text_field'],
        ['namespace' => 'custom', 'key' => 'favorite_color', 'value' => 'green', 'type' => 'single_line_text_field'],
    ]);

    expect($parsed['birth_month'])->toBe(4)
        ->and($parsed['birth_day'])->toBe(18)
        ->and($parsed['birth_year'])->toBe(1992)
        ->and($parsed['birthday_full_date'])->toBe('1992-04-18')
        ->and($parsed['is_partial'])->toBeFalse()
        ->and(count($parsed['raw_metafields']))->toBe(1);
});

test('birthday sync upserts canonical birthday profile with shopify id mapping', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Birthday',
        'email' => 'birthday@example.com',
        'normalized_email' => 'birthday@example.com',
    ]);

    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_customer',
        'source_id' => 'retail:1001',
        'source_meta' => [],
        'match_method' => 'seed',
        'confidence' => 1.00,
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyBirthdayCustomersPayload([[
            'id' => 'gid://shopify/Customer/1001',
            'email' => 'birthday@example.com',
            'firstName' => 'Birthday',
            'lastName' => 'Customer',
            'metafields' => [
                ['namespace' => 'growave', 'key' => 'birthday', 'value' => '1990-08-22', 'type' => 'single_line_text_field'],
            ],
        ]]), 200),
    ]);

    $this->artisan('shopify:sync-customer-birthdays retail --limit=10')
        ->assertExitCode(0);

    $birthday = CustomerBirthdayProfile::query()->sole();

    expect((int) $birthday->marketing_profile_id)->toBe((int) $profile->id)
        ->and((int) $birthday->birth_month)->toBe(8)
        ->and((int) $birthday->birth_day)->toBe(22)
        ->and((int) $birthday->birth_year)->toBe(1990)
        ->and(optional($birthday->birthday_full_date)->toDateString())->toBe('1990-08-22')
        ->and((string) $birthday->source)->toBe('growave_import');
});

test('birthday sync falls back to email mapping when shopify id link is missing', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Email Fallback',
        'email' => 'fallback@example.com',
        'normalized_email' => 'fallback@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyBirthdayCustomersPayload([[
            'id' => 'gid://shopify/Customer/2002',
            'email' => 'FALLBACK@example.com',
            'firstName' => 'Fallback',
            'lastName' => 'Customer',
            'metafields' => [
                ['namespace' => 'ssw', 'key' => 'birth_month', 'value' => '12', 'type' => 'number_integer'],
                ['namespace' => 'ssw', 'key' => 'birth_day', 'value' => '9', 'type' => 'number_integer'],
            ],
        ]]), 200),
    ]);

    $this->artisan('shopify:sync-customer-birthdays retail --limit=10')
        ->assertExitCode(0);

    expect(CustomerBirthdayProfile::query()->count())->toBe(1)
        ->and(CustomerBirthdayProfile::query()->first()->marketing_profile_id)->toBe($profile->id)
        ->and(MarketingProfileLink::query()
            ->where('source_type', 'shopify_customer')
            ->where('source_id', 'retail:2002')
            ->where('marketing_profile_id', $profile->id)
            ->exists())->toBeTrue();
});

test('birthday sync dry run performs no birthday writes', function () {
    MarketingProfile::query()->create([
        'first_name' => 'Dry Run',
        'email' => 'dryrun@example.com',
        'normalized_email' => 'dryrun@example.com',
    ]);

    Http::fake([
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyBirthdayCustomersPayload([[
            'id' => 'gid://shopify/Customer/3003',
            'email' => 'dryrun@example.com',
            'firstName' => 'Dry',
            'lastName' => 'Run',
            'metafields' => [
                ['namespace' => 'growave', 'key' => 'birthday', 'value' => '03/15', 'type' => 'single_line_text_field'],
            ],
        ]]), 200),
    ]);

    $this->artisan('shopify:sync-customer-birthdays retail --limit=10 --dry-run')
        ->assertExitCode(0);

    expect(CustomerBirthdayProfile::query()->count())->toBe(0)
        ->and(MarketingImportRun::query()->where('type', 'shopify_customer_birthdays_sync')->count())->toBe(1);
});

test('birthday sync fails loudly when fallback email mapping is ambiguous', function () {
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
        'https://retail-test.myshopify.com/admin/api/2026-01/graphql.json' => Http::response(shopifyBirthdayCustomersPayload([[
            'id' => 'gid://shopify/Customer/4040',
            'email' => 'dup@example.com',
            'firstName' => 'Dup',
            'lastName' => 'Conflict',
            'metafields' => [
                ['namespace' => 'growave', 'key' => 'birthday', 'value' => '1991-11-05', 'type' => 'single_line_text_field'],
            ],
        ]]), 200),
    ]);

    $this->artisan('shopify:sync-customer-birthdays retail --limit=10')
        ->assertExitCode(1);

    expect(CustomerBirthdayProfile::query()->count())->toBe(0)
        ->and(MarketingImportRun::query()
            ->where('type', 'shopify_customer_birthdays_sync')
            ->where('status', 'failed')
            ->exists())->toBeTrue();
});

/**
 * @param  array<int,array<string,mixed>>  $customers
 * @return array<string,mixed>
 */
function shopifyBirthdayCustomersPayload(array $customers): array
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
