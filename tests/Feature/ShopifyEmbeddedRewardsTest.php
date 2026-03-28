<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\MarketingSetting;
use App\Models\Tenant;

function retailRewardsApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('entitlements.default_plan', 'growth');
    configureEmbeddedRetailStore();
});

test('shopify embedded rewards route renders verified rewards admin page', function () {
    $response = $this->get(route('shopify.app.rewards', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Rewards')
        ->assertSeeText('Manage Candle Cash rewards and program settings.')
        ->assertSeeText('This page reflects the live Candle Cash tasks and reward rows currently managed by Backstage.')
        ->assertSeeText('How Candle Cash works today')
        ->assertSeeText('Ways to Earn')
        ->assertSeeText('Ways to Redeem')
        ->assertDontSeeText('This page will surface the current Candle Cash program health')
        ->assertHeader('Content-Security-Policy', "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;");
});

test('shopify embedded rewards editor route uses bearer-token bootstrap without legacy context tokens', function () {
    $response = $this->get(route('shopify.embedded.rewards.earn', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Ways to Earn')
        ->assertDontSee('data-context-token', false)
        ->assertDontSee('X-Forestry-Embedded-Context', false);
});

test('tenant-scoped rewards editor route shows the rewards isolation warning instead of the editor', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant-editor',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.embedded.rewards.earn', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('This embedded rewards editor is unavailable for tenant-scoped stores until Candle Cash tasks, rewards, and program settings are isolated per tenant.')
        ->assertDontSee('shopify-rewards-admin', false);
});

test('tenant-scoped rewards overview route suppresses shared global reward previews', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant-overview',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $response = $this->get(route('shopify.app.rewards', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('This embedded rewards editor is unavailable for tenant-scoped stores until Candle Cash tasks, rewards, and program settings are isolated per tenant.')
        ->assertDontSeeText('How Candle Cash works today');
});

test('shopify embedded rewards data route returns normalized earn and redeem sections for a bearer-authenticated embedded session', function () {
    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards'));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.earn.status', 'ok')
        ->assertJsonPath('data.redeem.status', 'ok');

    $earnItems = $response->json('data.earn.items');
    $redeemItems = $response->json('data.redeem.items');

    expect($earnItems)->toBeArray()->not->toBeEmpty()
        ->and($redeemItems)->toBeArray()->not->toBeEmpty()
        ->and(array_key_exists('candle_cash_value', $earnItems[0]))->toBeTrue()
        ->and(array_key_exists('candle_cash_cost', $redeemItems[0]))->toBeTrue()
        ->and(array_key_exists('points_value', $earnItems[0]))->toBeFalse()
        ->and(array_key_exists('points_cost', $redeemItems[0]))->toBeFalse()
        ->and(array_key_exists('legacy_points_per_candle_cash', $response->json('data.meta.program')))->toBeFalse()
        ->and(data_get($response->json(), 'data.meta.program.measurement_label'))->toBe('1 Candle Cash = 1 Candle Cash');
});

test('shopify embedded rewards data route requires bearer token auth and does not fall back to page state', function () {
    $this->get(route('shopify.embedded.rewards.earn', retailEmbeddedSignedQuery()))->assertOk();

    $this->getJson(route('shopify.app.api.rewards'))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth')
        ->assertJsonPath('message', 'Shopify Admin verification is unavailable. Reload rewards from Shopify Admin and try again.');
});

test('shopify embedded rewards data route rejects the legacy embedded context token fallback', function () {
    $this->getJson(route('shopify.app.api.rewards'), [
        'X-Forestry-Embedded-Context' => retailEmbeddedContextToken(),
    ])
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('shopify embedded rewards data route rejects invalid shopify session token auth', function () {
    $this->withHeaders([
        'Authorization' => 'Bearer not-a-valid-shopify-token',
    ])->getJson(route('shopify.app.api.rewards'))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'invalid_session_token')
        ->assertJsonPath('message', 'Shopify Admin verification failed. Reload rewards from Shopify Admin and try again.');
});

test('shopify embedded rewards data route rejects expired shopify session token auth', function () {
    $expiredNow = time() - 120;

    $this->withHeaders([
        'Authorization' => 'Bearer '.retailShopifySessionToken([
            'nbf' => $expiredNow - 60,
            'iat' => $expiredNow - 60,
            'exp' => $expiredNow,
        ]),
    ])->getJson(route('shopify.app.api.rewards'))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'expired_session_token')
        ->assertJsonPath('message', 'Your Shopify Admin session expired. Reload rewards from Shopify Admin and try again.');
});

test('shopify embedded rewards earn update edits the existing task and syncs mapped program config', function () {
    $task = CandleCashTask::query()->where('handle', 'email-signup')->firstOrFail();

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.earn.update', ['task' => $task->id]),
            [
                'title' => 'Email welcome reward',
                'description' => 'Updated from the embedded rewards admin.',
                'candle_cash_value' => 6,
                'enabled' => false,
                'sort_order' => 12,
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Earn rule saved.')
        ->assertJsonPath('rule.title', 'Email welcome reward')
        ->assertJsonPath('rule.candle_cash_value', 6)
        ->assertJsonPath('rule.enabled', false);

    $task->refresh();

    expect((string) $task->title)->toBe('Email welcome reward')
        ->and((string) $task->description)->toBe('Updated from the embedded rewards admin.')
        ->and((float) $task->reward_amount)->toBe(6.0)
        ->and((bool) $task->enabled)->toBeFalse()
        ->and((int) $task->display_order)->toBe(12);

    $programConfig = MarketingSetting::query()->where('key', 'candle_cash_program_config')->firstOrFail()->value;

    expect((float) data_get($programConfig, 'email_signup_reward_amount'))->toBe(6.0);
});

test('shopify embedded rewards redeem update edits the existing reward row in place', function () {
    $reward = CandleCashReward::query()->where('name', 'Free wax melt')->firstOrFail();

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.redeem.update', ['reward' => $reward->id]),
            [
                'title' => 'Free Wax Melt Duo',
                'description' => 'Updated reward description.',
                'candle_cash_cost' => 3,
                'reward_value' => 'wax_melt_duo',
                'enabled' => false,
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Redeem rule saved.')
        ->assertJsonPath('rule.title', 'Free Wax Melt Duo')
        ->assertJsonPath('rule.candle_cash_cost', 3)
        ->assertJsonPath('rule.enabled', false);

    $reward->refresh();

    expect((string) $reward->name)->toBe('Free Wax Melt Duo')
        ->and((string) $reward->description)->toBe('Updated reward description.')
        ->and((int) $reward->candle_cash_cost)->toBe(3)
        ->and((string) $reward->reward_value)->toBe('wax_melt_duo')
        ->and((bool) $reward->is_active)->toBeFalse();
});

test('shopify embedded rewards redeem update returns validation errors for inconsistent storefront reward cost edits', function () {
    $reward = CandleCashReward::query()
        ->where('reward_type', 'coupon')
        ->orderByDesc('id')
        ->firstOrFail();

    $this->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.redeem.update', ['reward' => $reward->id]),
            [
                'title' => $reward->name,
                'description' => 'Storefront reward',
                'candle_cash_cost' => 10.5,
                'reward_value' => '10USD',
                'enabled' => true,
            ]
        )
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.candle_cash_cost.0', 'Storefront Candle Cash cost is derived from the discount value and current Candle Cash value.');
});

test('shopify embedded rewards earn update rejects the legacy embedded context token fallback', function () {
    $task = CandleCashTask::query()->where('handle', 'email-signup')->firstOrFail();

    $this->patchJson(
        route('shopify.app.api.rewards.earn.update', ['task' => $task->id]),
        [
            'title' => 'Blocked',
            'description' => 'Blocked',
            'candle_cash_value' => 5,
            'enabled' => true,
            'sort_order' => 1,
        ],
        [
            'X-Forestry-Embedded-Context' => retailEmbeddedContextToken(),
        ]
    )
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth');
});

test('shopify embedded rewards redeem update rejects expired shopify session token auth', function () {
    $reward = CandleCashReward::query()->where('name', 'Free wax melt')->firstOrFail();
    $expiredNow = time() - 120;

    $this->withHeaders([
        'Authorization' => 'Bearer '.retailShopifySessionToken([
            'nbf' => $expiredNow - 60,
            'iat' => $expiredNow - 60,
            'exp' => $expiredNow,
        ]),
    ])->patchJson(
        route('shopify.app.api.rewards.redeem.update', ['reward' => $reward->id]),
        [
            'title' => 'Expired',
            'description' => 'Expired',
            'candle_cash_cost' => 3,
            'reward_value' => 'wax_melt_duo',
            'enabled' => false,
        ]
    )
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'expired_session_token');
});

test('shopify embedded rewards api is blocked for tenant-scoped stores because rewards config is still global', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant-api',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $this->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards'))
        ->assertStatus(409)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'tenant_scoped_rewards_config_unsupported')
        ->assertJsonPath('message', 'This embedded rewards editor is unavailable for tenant-scoped stores until Candle Cash tasks, rewards, and program settings are isolated per tenant.');
});

test('shopify embedded rewards earn update does not mutate global task rows for tenant-scoped stores', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant-earn',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $task = CandleCashTask::query()->where('handle', 'email-signup')->firstOrFail();
    $originalTitle = (string) $task->title;
    $originalDescription = (string) $task->description;
    $originalRewardAmount = (float) $task->reward_amount;
    $originalDisplayOrder = (int) $task->display_order;
    $programConfig = MarketingSetting::query()->where('key', 'candle_cash_program_config')->firstOrFail()->value;
    $originalConfiguredAmount = (float) data_get($programConfig, 'email_signup_reward_amount');

    $this->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.earn.update', ['task' => $task->id]),
            [
                'title' => 'Blocked tenant update',
                'description' => 'Should not save.',
                'candle_cash_value' => 9,
                'enabled' => false,
                'sort_order' => 99,
            ]
        )
        ->assertStatus(409)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'tenant_scoped_rewards_config_unsupported');

    $task->refresh();
    $programConfig = MarketingSetting::query()->where('key', 'candle_cash_program_config')->firstOrFail()->value;

    expect((string) $task->title)->toBe($originalTitle)
        ->and((string) $task->description)->toBe($originalDescription)
        ->and((float) $task->reward_amount)->toBe($originalRewardAmount)
        ->and((int) $task->display_order)->toBe($originalDisplayOrder)
        ->and((float) data_get($programConfig, 'email_signup_reward_amount'))->toBe($originalConfiguredAmount);
});

test('shopify embedded rewards redeem update does not mutate global reward rows for tenant-scoped stores', function () {
    $tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-tenant-redeem',
    ]);

    configureEmbeddedRetailStore($tenant->id);

    $reward = CandleCashReward::query()->where('name', 'Free wax melt')->firstOrFail();
    $originalName = (string) $reward->name;
    $originalDescription = (string) $reward->description;
    $originalPointsCost = (int) $reward->candle_cash_cost;
    $originalRewardValue = (string) $reward->reward_value;
    $originalActive = (bool) $reward->is_active;

    $this->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.redeem.update', ['reward' => $reward->id]),
            [
                'title' => 'Blocked tenant reward',
                'description' => 'Should not save.',
                'candle_cash_cost' => 4,
                'reward_value' => 'blocked_reward',
                'enabled' => false,
            ]
        )
        ->assertStatus(409)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'tenant_scoped_rewards_config_unsupported');

    $reward->refresh();

    expect((string) $reward->name)->toBe($originalName)
        ->and((string) $reward->description)->toBe($originalDescription)
        ->and((int) $reward->candle_cash_cost)->toBe($originalPointsCost)
        ->and((string) $reward->reward_value)->toBe($originalRewardValue)
        ->and((bool) $reward->is_active)->toBe($originalActive);
});
