<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CandleCashTask;
use App\Models\MarketingProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

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
    ]);

    $bob = MarketingProfile::query()->create([
        'first_name' => 'Bob',
        'last_name' => 'Briar',
        'email' => 'bob@example.com',
        'normalized_email' => 'bob@example.com',
    ]);

    $clara = MarketingProfile::query()->create([
        'first_name' => 'Clara',
        'last_name' => 'Cove',
        'email' => 'clara@example.com',
        'normalized_email' => 'clara@example.com',
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
            'reward_points' => 10,
            'created_at' => $now->subDays(7),
            'updated_at' => $now->subDays(7),
        ],
        [
            'candle_cash_task_id' => $taskIds['refer-a-friend'],
            'marketing_profile_id' => $alice->id,
            'status' => 'awarded',
            'reward_amount' => 1,
            'reward_points' => 10,
            'created_at' => $now->subDays(6),
            'updated_at' => $now->subDays(6),
        ],
        [
            'candle_cash_task_id' => $taskIds['product-review'],
            'marketing_profile_id' => $alice->id,
            'status' => 'awarded',
            'reward_amount' => 1,
            'reward_points' => 10,
            'created_at' => $now->subDays(5),
            'updated_at' => $now->subDays(5),
        ],
        [
            'candle_cash_task_id' => $taskIds['birthday-signup'],
            'marketing_profile_id' => $alice->id,
            'status' => 'awarded',
            'reward_amount' => 1,
            'reward_points' => 10,
            'created_at' => $now->subDays(4),
            'updated_at' => $now->subDays(4),
        ],
        [
            'candle_cash_task_id' => $taskIds['product-review'],
            'marketing_profile_id' => $clara->id,
            'status' => 'submitted',
            'reward_amount' => 1,
            'reward_points' => 10,
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

test('/customers/manage renders real customer rows from local data', function () {
    configureEmbeddedRetailStore();
    $fixtures = seedEmbeddedCustomersGridFixtures();
    startEmbeddedCustomersSession($this);

    $response = $this->get(shopifyAppCustomersManageUrl());

    $response->assertOk()
        ->assertSeeText('Manage customers')
        ->assertSeeText($fixtures['alice']->email)
        ->assertSeeText($fixtures['bob']->email)
        ->assertSeeText('Candle Cash')
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
        ->assertSeeText('Manage customers')
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

    $this->get(shopifyAppCustomersManageUrl())
        ->assertOk()
        ->assertSee($detailUrl, false);

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
