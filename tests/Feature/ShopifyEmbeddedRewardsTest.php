<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';
use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\MarketingSetting;
use App\Models\ShopifyStore;

test('shopify embedded rewards route renders verified rewards admin page', function () {
    configureEmbeddedRetailStore();

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

test('shopify embedded rewards data route returns normalized earn and redeem sections', function () {
    configureEmbeddedRetailStore();

    $response = $this->getJson(route('shopify.app.api.rewards', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.earn.status', 'ok')
        ->assertJsonPath('data.redeem.status', 'ok');

    $earnItems = $response->json('data.earn.items');
    $redeemItems = $response->json('data.redeem.items');

    expect($earnItems)->toBeArray()->not->toBeEmpty()
        ->and($redeemItems)->toBeArray()->not->toBeEmpty()
        ->and(array_key_exists('points_value', $earnItems[0]))->toBeTrue()
        ->and(array_key_exists('points_cost', $redeemItems[0]))->toBeTrue();
});

test('shopify embedded rewards earn update edits the existing task and syncs mapped program config', function () {
    configureEmbeddedRetailStore();

    $task = CandleCashTask::query()->where('handle', 'email-signup')->firstOrFail();

    $response = $this->patchJson(
        route('shopify.app.api.rewards.earn.update', array_merge(['task' => $task->id], retailEmbeddedSignedQuery())),
        [
            'title' => 'Email welcome reward',
            'description' => 'Updated from the embedded rewards admin.',
            'points_value' => 180,
            'enabled' => false,
            'sort_order' => 12,
        ]
    );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Earn rule saved.')
        ->assertJsonPath('rule.title', 'Email welcome reward')
        ->assertJsonPath('rule.points_value', 180)
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
    configureEmbeddedRetailStore();

    $reward = CandleCashReward::query()->where('name', 'Free wax melt')->firstOrFail();

    $response = $this->patchJson(
        route('shopify.app.api.rewards.redeem.update', array_merge(['reward' => $reward->id], retailEmbeddedSignedQuery())),
        [
            'title' => 'Free Wax Melt Duo',
            'description' => 'Updated reward description.',
            'points_cost' => 90,
            'reward_value' => 'wax_melt_duo',
            'enabled' => false,
        ]
    );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Redeem rule saved.')
        ->assertJsonPath('rule.title', 'Free Wax Melt Duo')
        ->assertJsonPath('rule.points_cost', 90)
        ->assertJsonPath('rule.enabled', false);

    $reward->refresh();

    expect((string) $reward->name)->toBe('Free Wax Melt Duo')
        ->and((string) $reward->description)->toBe('Updated reward description.')
        ->and((int) $reward->points_cost)->toBe(90)
        ->and((string) $reward->reward_value)->toBe('wax_melt_duo')
        ->and((bool) $reward->is_active)->toBeFalse();
});

test('shopify embedded rewards blocks inconsistent storefront reward cost edits', function () {
    configureEmbeddedRetailStore();

    $reward = CandleCashReward::query()
        ->where('reward_type', 'coupon')
        ->orderByDesc('id')
        ->firstOrFail();

    $this->patchJson(
        route('shopify.app.api.rewards.redeem.update', array_merge(['reward' => $reward->id], retailEmbeddedSignedQuery())),
        [
            'title' => $reward->name,
            'description' => 'Storefront reward',
            'points_cost' => 301,
            'reward_value' => '10USD',
            'enabled' => true,
        ]
    )
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('errors.points_cost.0', 'Storefront Candle Cash cost is derived from the discount value and current points-per-dollar setting.');
});
