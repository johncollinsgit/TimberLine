<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CandleCashTask;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Shopify\ShopifyEmbeddedCustomersGridService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * @return array{
 *   alice:MarketingProfile,
 *   bob:MarketingProfile,
 *   clara:MarketingProfile
 * }
 */
function seedEmbeddedCustomersGridFixtures(): array
{
    $now = CarbonImmutable::parse('2026-03-01 12:00:00');

    $alice = MarketingProfile::query()->create([
        'first_name' => 'Alice',
        'last_name' => 'Aster',
        'email' => 'alice@example.com',
        'normalized_email' => 'alice@example.com',
        'phone' => '555-123-0001',
        'normalized_phone' => '15551230001',
    ]);

    $bob = MarketingProfile::query()->create([
        'first_name' => 'Bob',
        'last_name' => 'Briar',
        'email' => 'bob@example.com',
        'normalized_email' => 'bob@example.com',
        'phone' => '555-123-0002',
        'normalized_phone' => '15551230002',
    ]);

    $clara = MarketingProfile::query()->create([
        'first_name' => 'Clara',
        'last_name' => 'Cove',
        'email' => 'clara@example.com',
        'normalized_email' => 'clara@example.com',
        'phone' => '555-123-0003',
        'normalized_phone' => '15551230003',
    ]);

    DB::table('marketing_profiles')->where('id', $alice->id)->update([
        'created_at' => $now->subDays(40),
        'updated_at' => $now->subDays(40),
    ]);
    DB::table('marketing_profiles')->where('id', $bob->id)->update([
        'created_at' => $now->subDays(39),
        'updated_at' => $now->subDays(39),
    ]);
    DB::table('marketing_profiles')->where('id', $clara->id)->update([
        'created_at' => $now->subDays(38),
        'updated_at' => $now->subDays(38),
    ]);

    DB::table('candle_cash_balances')->insert([
        [
            'marketing_profile_id' => $alice->id,
            'balance' => 120,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'marketing_profile_id' => $bob->id,
            'balance' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ],
        [
            'marketing_profile_id' => $clara->id,
            'balance' => 45,
            'created_at' => $now,
            'updated_at' => $now,
        ],
    ]);

    $taskIds = collect([
        ['handle' => 'candle-club-join', 'title' => 'Candle Club Join'],
        ['handle' => 'refer-a-friend', 'title' => 'Refer a Friend'],
        ['handle' => 'product-review', 'title' => 'Product Review'],
        ['handle' => 'birthday-signup', 'title' => 'Birthday Signup'],
    ])->mapWithKeys(function (array $task) use ($now): array {
        $model = CandleCashTask::query()->firstOrCreate(
            ['handle' => $task['handle']],
            [
                'title' => $task['title'],
                'task_type' => 'manual_submission',
                'reward_amount' => 1,
                'enabled' => true,
                'display_order' => 10,
                'created_at' => $now,
                'updated_at' => $now,
            ]
        );

        return [$task['handle'] => $model->id];
    });

    DB::table('candle_cash_task_completions')->insert([
        [
            'candle_cash_task_id' => $taskIds['candle-club-join'],
            'marketing_profile_id' => $alice->id,
            'status' => 'awarded',
            'reward_amount' => 1,
            'reward_candle_cash' => 10,
            'created_at' => $now->subDays(7),
            'updated_at' => $now->subDays(7),
        ],
        [
            'candle_cash_task_id' => $taskIds['refer-a-friend'],
            'marketing_profile_id' => $alice->id,
            'status' => 'awarded',
            'reward_amount' => 1,
            'reward_candle_cash' => 10,
            'created_at' => $now->subDays(6),
            'updated_at' => $now->subDays(6),
        ],
        [
            'candle_cash_task_id' => $taskIds['product-review'],
            'marketing_profile_id' => $alice->id,
            'status' => 'awarded',
            'reward_amount' => 1,
            'reward_candle_cash' => 10,
            'created_at' => $now->subDays(5),
            'updated_at' => $now->subDays(5),
        ],
        [
            'candle_cash_task_id' => $taskIds['birthday-signup'],
            'marketing_profile_id' => $alice->id,
            'status' => 'awarded',
            'reward_amount' => 1,
            'reward_candle_cash' => 10,
            'created_at' => $now->subDays(4),
            'updated_at' => $now->subDays(4),
        ],
        [
            'candle_cash_task_id' => $taskIds['product-review'],
            'marketing_profile_id' => $clara->id,
            'status' => 'submitted',
            'reward_amount' => 1,
            'reward_candle_cash' => 10,
            'created_at' => $now->subDays(2),
            'updated_at' => $now->subDays(2),
        ],
    ]);

    DB::table('candle_cash_referrals')->insert([
        [
            'referrer_marketing_profile_id' => $alice->id,
            'referred_marketing_profile_id' => $bob->id,
            'referral_code' => 'ALICE1',
            'status' => 'rewarded',
            'referrer_reward_status' => 'rewarded',
            'referred_reward_status' => 'rewarded',
            'first_seen_at' => $now->subDays(10),
            'qualified_at' => $now->subDays(7),
            'rewarded_at' => $now->subDays(6),
            'created_at' => $now->subDays(10),
            'updated_at' => $now->subDays(6),
        ],
    ]);

    DB::table('customer_external_profiles')->insert([
        [
            'marketing_profile_id' => $bob->id,
            'provider' => 'shopify',
            'integration' => 'shopify',
            'store_key' => 'wholesale',
            'external_customer_id' => 'wholesale-bob',
            'email' => $bob->email,
            'normalized_email' => $bob->normalized_email,
            'last_activity_at' => $now->subDay(),
            'synced_at' => $now->subDay(),
            'created_at' => $now->subDay(),
            'updated_at' => $now->subDay(),
        ],
    ]);

    DB::table('customer_birthday_profiles')->insert([
        [
            'marketing_profile_id' => $alice->id,
            'birth_month' => 5,
            'birth_day' => 17,
            'reward_last_issued_at' => $now->subDays(30),
            'created_at' => $now->subDays(30),
            'updated_at' => $now->subDays(30),
        ],
    ]);

    DB::table('candle_cash_transactions')->insert([
        [
            'marketing_profile_id' => $alice->id,
            'type' => 'earn',
            'points' => 50,
            'source' => 'admin',
            'source_id' => 'alice-seed',
            'description' => 'Seed activity',
            'created_at' => $now->subDays(8),
            'updated_at' => $now->subDays(8),
        ],
        [
            'marketing_profile_id' => $clara->id,
            'type' => 'earn',
            'points' => 20,
            'source' => 'admin',
            'source_id' => 'clara-seed',
            'description' => 'Seed activity',
            'created_at' => $now->subDays(3),
            'updated_at' => $now->subDays(3),
        ],
    ]);

    return [
        'alice' => $alice,
        'bob' => $bob,
        'clara' => $clara,
    ];
}

function startEmbeddedCustomersSession(\Illuminate\Foundation\Testing\TestCase $testCase): void
{
    $testCase->get(route('shopify.app', retailEmbeddedSignedQuery()))->assertOk();
}

function shopifyAppCustomersManageUrl(array $query = []): string
{
    return route('shopify.app.customers.manage', array_merge($query, retailEmbeddedSignedQuery()));
}

function seedOrderForEmail(string $email): void
{
    $payload = [
        'source' => 'manual',
        'status' => 'new',
        'created_at' => now(),
        'updated_at' => now(),
    ];

    foreach (['customer_email', 'email', 'billing_email', 'shipping_email'] as $column) {
        if (Schema::hasColumn('orders', $column)) {
            $payload[$column] = $email;
            break;
        }
    }

    DB::table('orders')->insert($payload);
}

test('/customers/manage renders real customer rows from local data', function () {
    configureEmbeddedRetailStore();
    $fixtures = seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl());

    $response->assertOk()
        ->assertSeeText('All customers')
        ->assertSeeText($fixtures['alice']->email)
        ->assertSeeText($fixtures['bob']->email)
        ->assertSeeText('Rewards Balance')
        ->assertDontSeeText('Tier Name');
});

test('/customers without Shopify context shows the embedded context missing page', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $this->get('/customers')
        ->assertStatus(400)
        ->assertSeeText('Context Missing')
        ->assertSeeText('This page must be opened from Shopify Admin');
});

test('/shopify/app/customers and /shopify/app/customers/manage match manage page output', function (string $routeName) {
    configureEmbeddedRetailStore();
    $fixtures = seedEmbeddedCustomersGridFixtures();

    $this->get(route($routeName, retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('All customers')
        ->assertSeeText($fixtures['alice']->email);
})->with([
    'alias /shopify/app/customers' => ['shopify.app.customers'],
    'alias /shopify/app/customers/manage' => ['shopify.app.customers.manage'],
]);

test('search by name works for manage customers', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl(['search' => 'Alice']));

    $response->assertOk()
        ->assertSeeText('alice@example.com')
        ->assertDontSeeText('bob@example.com');
});

test('search by email works for manage customers', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl(['search' => 'bob@example.com']));

    $response->assertOk()
        ->assertSeeText('bob@example.com')
        ->assertDontSeeText('alice@example.com');
});

test('email search is case insensitive for customer records', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl(['search' => 'ALICE@EXAMPLE.COM']));

    $response->assertOk()
        ->assertSeeText('alice@example.com')
        ->assertDontSeeText('bob@example.com');
});

test('search by phone and customer id works for manage customers', function () {
    configureEmbeddedRetailStore();
    $fixtures = seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $phoneResponse = $this->get(shopifyAppCustomersManageUrl(['search' => '555-123-0002']));

    $phoneResponse->assertOk()
        ->assertSeeText('bob@example.com')
        ->assertDontSeeText('alice@example.com');

    $idResponse = $this->get(shopifyAppCustomersManageUrl(['search' => (string) $fixtures['clara']->id]));

    $idResponse->assertOk()
        ->assertSeeText('clara@example.com')
        ->assertDontSeeText('alice@example.com');
});

test('no-results state stays clean for unmatched customer searches', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl(['search' => 'nobody-will-match-this']));

    $response->assertOk()
        ->assertSeeText('No customers matched your search or filters.');
});

test('segment filters show zero-result state when no customers match', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl(['segment' => 'needs_contact']));

    $response->assertOk()
        ->assertSeeText('No customers matched your search or filters.');
});

test('sorting by last activity works', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl(['sort' => 'last_activity', 'direction' => 'desc']));

    $response->assertOk()
        ->assertSeeTextInOrder(['bob@example.com', 'clara@example.com', 'alice@example.com']);
});

test('sorting by candle cash works', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl(['sort' => 'candle_cash', 'direction' => 'desc']));

    $response->assertOk()
        ->assertSeeTextInOrder(['alice@example.com', 'clara@example.com', 'bob@example.com']);
});

test('orders sort is stable when order counts tie', function () {
    $alpha = MarketingProfile::query()->create([
        'first_name' => 'Alpha',
        'last_name' => 'Customer',
        'email' => 'alpha@example.com',
        'normalized_email' => 'alpha@example.com',
    ]);
    $beta = MarketingProfile::query()->create([
        'first_name' => 'Beta',
        'last_name' => 'Customer',
        'email' => 'beta@example.com',
        'normalized_email' => 'beta@example.com',
    ]);

    seedOrderForEmail('alpha@example.com');
    seedOrderForEmail('ALPHA@example.com');
    seedOrderForEmail('beta@example.com');
    seedOrderForEmail('BETA@example.com');

    $service = app(ShopifyEmbeddedCustomersGridService::class);
    $result = $service->resolve(Request::create('/shopify/app/customers/manage', 'GET', [
        'sort' => 'orders',
        'direction' => 'desc',
    ]));

    $orderedIds = collect($result['paginator']->items())
        ->pluck('id')
        ->take(2)
        ->values()
        ->all();

    expect($orderedIds)->toBe([$alpha->id, $beta->id]);
});

test('customers grid resolves missing contact data and vip tier fallbacks', function () {
    $fixtures = seedEmbeddedCustomersGridFixtures();

    $missingContact = MarketingProfile::query()->create([
        'first_name' => 'No',
        'last_name' => 'Contact',
        'email' => null,
        'normalized_email' => null,
        'phone' => null,
        'normalized_phone' => null,
    ]);

    $externalProfilePayload = [
        'marketing_profile_id' => $fixtures['clara']->id,
        'provider' => 'shopify',
        'integration' => 'shopify',
        'store_key' => 'retail',
        'external_customer_id' => 'retail-clara',
        'email' => $fixtures['clara']->email,
        'normalized_email' => $fixtures['clara']->normalized_email,
        'created_at' => now(),
        'updated_at' => now(),
    ];
    if (Schema::hasColumn('customer_external_profiles', 'vip_tier')) {
        $externalProfilePayload['vip_tier'] = 'Gold';
    }
    DB::table('customer_external_profiles')->insert([$externalProfilePayload]);

    seedOrderForEmail('alice@example.com');
    seedOrderForEmail('ALICE@example.com');

    $service = app(ShopifyEmbeddedCustomersGridService::class);

    $aliceResult = $service->resolve(Request::create('/shopify/app/customers/manage', 'GET', ['search' => 'alice@example.com']));
    $aliceRow = collect($aliceResult['paginator']->items())->first();

    $bobResult = $service->resolve(Request::create('/shopify/app/customers/manage', 'GET', ['search' => 'bob@example.com']));
    $bobRow = collect($bobResult['paginator']->items())->first();

    $claraResult = $service->resolve(Request::create('/shopify/app/customers/manage', 'GET', ['search' => 'clara@example.com']));
    $claraRow = collect($claraResult['paginator']->items())->first();

    $needsContactResult = $service->resolve(Request::create('/shopify/app/customers/manage', 'GET', ['segment' => 'needs_contact']));
    $needsContactRow = collect($needsContactResult['paginator']->items())->first();

    expect($aliceRow['orders_count'] ?? 0)->toBe(2)
        ->and($aliceRow['vip_tier'] ?? null)->toBe('Candle Club')
        ->and($bobRow['vip_tier'] ?? null)->toBe('Standard')
        ->and($claraRow['vip_tier'] ?? null)->toBe(Schema::hasColumn('customer_external_profiles', 'vip_tier') ? 'Gold' : 'Standard')
        ->and($needsContactRow['id'] ?? null)->toBe($missingContact->id)
        ->and($needsContactRow['status']['key'] ?? null)->toBe('needs_contact');
});

test('status filters work for candle club, referral, review, birthday, and wholesale columns', function (string $filter, string $expectedEmail, string $unexpectedEmail) {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl([$filter => 'yes']));

    $response->assertOk()
        ->assertSeeText($expectedEmail)
        ->assertDontSeeText($unexpectedEmail);
})->with([
    'candle club filter' => ['candle_club', 'alice@example.com', 'bob@example.com'],
    'referral filter' => ['referral', 'alice@example.com', 'bob@example.com'],
    'review filter' => ['review', 'alice@example.com', 'bob@example.com'],
    'birthday filter' => ['birthday', 'alice@example.com', 'bob@example.com'],
    'wholesale filter' => ['wholesale', 'bob@example.com', 'alice@example.com'],
]);

test('pagination preserves query params', function () {
    configureEmbeddedRetailStore();

    for ($i = 1; $i <= 30; $i++) {
        MarketingProfile::query()->create([
            'first_name' => 'Paginate',
            'last_name' => 'User ' . $i,
            'email' => 'paginate' . $i . '@example.com',
            'normalized_email' => 'paginate' . $i . '@example.com',
        ]);
    }

    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl(['search' => 'paginate', 'per_page' => 25]));

    $response->assertOk()
        ->assertSee('search=paginate', false)
        ->assertSee('per_page=25', false)
        ->assertSee('page=2', false);
});

test('row view action links to customer detail and detail route resolves', function () {
    configureEmbeddedRetailStore();
    $fixtures = seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);
    $detailUrl = route('shopify.app.customers.detail', ['marketingProfile' => $fixtures['alice']->id], false);
    $detailSectionsUrl = route('shopify.app.api.customers.detail-sections', ['marketingProfile' => $fixtures['alice']->id], false);

    $this->get(shopifyAppCustomersManageUrl())
        ->assertOk()
        ->assertSee($detailUrl, false)
        ->assertSee($detailSectionsUrl, false);

    $this->get($detailUrl)
        ->assertOk()
        ->assertSeeText('Customer Detail');
});

test('embedded manage page preserves full Shopify context on customer detail links', function () {
    configureEmbeddedRetailStore();
    $fixtures = seedEmbeddedCustomersGridFixtures();

    $response = $this->get(route('shopify.app.customers.manage', retailEmbeddedExtendedSignedQuery()));

    $response->assertOk();

    $content = $response->getContent();
    $detailBase = route('shopify.app.customers.detail', ['marketingProfile' => $fixtures['alice']->id], false);

    expect($content)->toContain($detailBase)
        ->and($content)->toContain('shop=modernforestry.myshopify.com')
        ->and($content)->toContain('host=admin-host-token')
        ->and($content)->toContain('hmac=')
        ->and($content)->toContain('timestamp=')
        ->and($content)->toContain('embedded=1')
        ->and($content)->toContain('id_token=')
        ->and($content)->toContain('locale=en')
        ->and($content)->toContain('session=embedded-session-token');
});

test('embedded manage filters and sort controls preserve full Shopify context', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();

    $response = $this->get(route('shopify.app.customers.manage', retailEmbeddedExtendedSignedQuery()));

    $response->assertOk();

    $content = $response->getContent();

    expect($content)->toContain('name="shop" value="modernforestry.myshopify.com"')
        ->and($content)->toContain('name="host" value="admin-host-token"')
        ->and($content)->toContain('name="embedded" value="1"')
        ->and($content)->toContain('name="id_token" value="eyJhbGciOiJIUzI1NiJ9.test.payload"')
        ->and($content)->toContain('name="locale" value="en"')
        ->and($content)->toContain('name="session" value="embedded-session-token"')
        ->and($content)->toContain('/shopify/app/customers/manage?shop=modernforestry.myshopify.com')
        ->and($content)->toContain('sort=name')
        ->and($content)->toContain('value="email"')
        ->and($content)->toContain('sort=orders')
        ->and($content)->toContain('sort=candle_cash')
        ->and($content)->toContain('sort=last_activity')
        ->and($content)->toContain('id_token=')
        ->and($content)->toContain('locale=en')
        ->and($content)->toContain('session=embedded-session-token');
});

test('embedded manage returns json results for authenticated live search requests', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();

    $response = $this
        ->withHeaders([
            'Authorization' => 'Bearer ' . retailShopifySessionToken(),
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get(shopifyAppCustomersManageUrl(['search' => 'Alice']));

    $response->assertOk()
        ->assertJsonPath('ok', true);

    $resultsHtml = (string) data_get($response->json(), 'data.results_html', '');
    $summaryLabel = (string) data_get($response->json(), 'data.summary_label', '');

    expect($resultsHtml)->toContain('alice@example.com')
        ->and($resultsHtml)->not->toContain('bob@example.com')
        ->and($summaryLabel)->toContain('customer');
});

test('embedded manage json search requires an authenticated shopify session token', function () {
    configureEmbeddedRetailStore();
    seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this
        ->withHeaders([
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get(shopifyAppCustomersManageUrl(['search' => 'Alice']));

    $response->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('embedded manage page and json search are tenant scoped by store tenant', function () {
    $tenantOne = Tenant::query()->create([
        'name' => 'Tenant One',
        'slug' => 'tenant-one-manage',
    ]);
    $tenantTwo = Tenant::query()->create([
        'name' => 'Tenant Two',
        'slug' => 'tenant-two-manage',
    ]);

    configureEmbeddedRetailStore($tenantOne->id);

    MarketingProfile::query()->create([
        'tenant_id' => $tenantOne->id,
        'first_name' => 'Tenant',
        'last_name' => 'One',
        'email' => 'tenant.one@example.com',
        'normalized_email' => 'tenant.one@example.com',
    ]);

    MarketingProfile::query()->create([
        'tenant_id' => $tenantTwo->id,
        'first_name' => 'Tenant',
        'last_name' => 'Two',
        'email' => 'tenant.two@example.com',
        'normalized_email' => 'tenant.two@example.com',
    ]);

    startEmbeddedCustomersSession($this);

    $pageResponse = $this->get(shopifyAppCustomersManageUrl());

    $pageResponse->assertOk()
        ->assertSeeText('tenant.one@example.com')
        ->assertDontSeeText('tenant.two@example.com');

    $jsonResponse = $this
        ->withHeaders([
            'Authorization' => 'Bearer ' . retailShopifySessionToken(),
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get(shopifyAppCustomersManageUrl(['search' => 'tenant']));

    $jsonResponse->assertOk()
        ->assertJsonPath('ok', true);

    $resultsHtml = (string) data_get($jsonResponse->json(), 'data.results_html', '');
    $summaryLabel = (string) data_get($jsonResponse->json(), 'data.summary_label', '');

    expect($resultsHtml)->toContain('tenant.one@example.com')
        ->and($resultsHtml)->not->toContain('tenant.two@example.com')
        ->and($summaryLabel)->toContain('1 customer loaded');
});

test('embedded manage tenant scoping keeps empty state when no customers belong to the current store tenant', function () {
    $tenantOne = Tenant::query()->create([
        'name' => 'Tenant One',
        'slug' => 'tenant-one-empty',
    ]);
    $tenantTwo = Tenant::query()->create([
        'name' => 'Tenant Two',
        'slug' => 'tenant-two-empty',
    ]);

    configureEmbeddedRetailStore($tenantOne->id);

    MarketingProfile::query()->create([
        'tenant_id' => $tenantTwo->id,
        'first_name' => 'Out',
        'last_name' => 'Of Scope',
        'email' => 'outscope@example.com',
        'normalized_email' => 'outscope@example.com',
    ]);

    startEmbeddedCustomersSession($this);

    $pageResponse = $this->get(shopifyAppCustomersManageUrl());

    $pageResponse->assertOk()
        ->assertSeeText('No customers synced yet')
        ->assertDontSeeText('outscope@example.com');

    $jsonResponse = $this
        ->withHeaders([
            'Authorization' => 'Bearer ' . retailShopifySessionToken(),
            'Accept' => 'application/json',
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->get(shopifyAppCustomersManageUrl(['search' => 'outscope']));

    $jsonResponse->assertOk()
        ->assertJsonPath('ok', true);

    $resultsHtml = (string) data_get($jsonResponse->json(), 'data.results_html', '');
    $summaryLabel = (string) data_get($jsonResponse->json(), 'data.summary_label', '');

    expect($resultsHtml)->toContain('No customers matched your search or filters.')
        ->and($resultsHtml)->not->toContain('outscope@example.com')
        ->and($summaryLabel)->toContain('0 customers loaded');
});
