<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantCandleCashRewardOverride;
use App\Models\TenantCandleCashTaskOverride;
use App\Models\TenantMarketingSetting;
use Illuminate\Support\Str;

function retailRewardsApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

beforeEach(function () {
    $this->withoutVite();
    config()->set('entitlements.default_plan', 'growth');

    $this->tenant = Tenant::query()->create([
        'name' => 'Retail Tenant',
        'slug' => 'retail-rewards-'.Str::lower(Str::random(8)),
    ]);

    configureEmbeddedRetailStore($this->tenant->id);
});

test('shopify embedded rewards route renders verified rewards admin page for mapped tenant', function () {
    $response = $this->get(route('shopify.app.rewards', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Rewards')
        ->assertSeeText('Manage rewards program settings.')
        ->assertSeeText('This page reflects the live earn and redeem rows currently managed by Backstage.')
        ->assertSeeText('Ways to Earn')
        ->assertSeeText('Ways to Redeem')
        ->assertDontSeeText('This embedded program editor is unavailable for tenant-scoped stores until earn rows, redeem rows, and program settings are isolated per tenant.')
        ->assertHeader('Content-Security-Policy', "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;");
});

test('shopify embedded rewards editor route uses bearer-token bootstrap without legacy context tokens', function () {
    $response = $this->get(route('shopify.embedded.rewards.earn', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Ways to Earn')
        ->assertDontSee('data-context-token', false)
        ->assertDontSee('X-Forestry-Embedded-Context', false);
});

test('shopify embedded rewards data route returns normalized earn and redeem sections for a mapped tenant session', function () {
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
        ->and(data_get($response->json(), 'data.meta.program.measurement_label'))->toBe('1 reward credit = 1 reward credit');
});

test('shopify embedded rewards policy route returns tenant-scoped policy and editability', function () {
    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy'));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('editable', true)
        ->assertJsonPath('status', null)
        ->assertJsonPath('data.program_identity.program_name', 'Candle Cash')
        ->assertJsonPath('data.value_model.redeem_increment_dollars', 10);
});

test('shopify embedded rewards data route requires bearer token auth and does not fall back to page state', function () {
    $this->get(route('shopify.embedded.rewards.earn', retailEmbeddedSignedQuery()))->assertOk();

    $this->getJson(route('shopify.app.api.rewards'))
        ->assertStatus(401)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'missing_api_auth')
        ->assertJsonPath('message', 'Shopify Admin verification is unavailable. Reload this program page from Shopify Admin and try again.');
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
        ->assertJsonPath('message', 'Shopify Admin verification failed. Reload this program page from Shopify Admin and try again.');
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
        ->assertJsonPath('message', 'Your Shopify Admin session expired. Reload this program page from Shopify Admin and try again.');
});

test('shopify embedded rewards earn update stores tenant-scoped overrides and tenant-scoped program config', function () {
    $task = CandleCashTask::query()->where('handle', 'email-signup')->firstOrFail();
    $globalProgramConfigRow = MarketingSetting::query()->where('key', 'candle_cash_program_config')->firstOrFail();
    $globalProgramConfigBefore = (array) $globalProgramConfigRow->value;

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.earn.update', ['task' => $task->id]),
            [
                'title' => 'Tenant Email Reward',
                'description' => 'Tenant-scoped reward edit.',
                'candle_cash_value' => 6,
                'enabled' => false,
                'sort_order' => 12,
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Earn rule saved.')
        ->assertJsonPath('rule.title', 'Tenant Email Reward')
        ->assertJsonPath('rule.candle_cash_value', 6)
        ->assertJsonPath('rule.enabled', false);

    $task->refresh();
    $taskOverride = TenantCandleCashTaskOverride::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('candle_cash_task_id', $task->id)
        ->firstOrFail();

    expect((string) $task->title)->not->toBe('Tenant Email Reward')
        ->and((string) $taskOverride->title)->toBe('Tenant Email Reward')
        ->and((string) $taskOverride->description)->toBe('Tenant-scoped reward edit.')
        ->and((float) $taskOverride->reward_amount)->toBe(6.0)
        ->and((bool) $taskOverride->enabled)->toBeFalse()
        ->and((int) $taskOverride->display_order)->toBe(12);

    $tenantProgramConfig = (array) TenantMarketingSetting::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('key', 'candle_cash_program_config')
        ->firstOrFail()
        ->value;

    $globalProgramConfigAfter = (array) $globalProgramConfigRow->fresh()->value;

    expect((float) data_get($tenantProgramConfig, 'email_signup_reward_amount'))->toBe(6.0)
        ->and((float) data_get($globalProgramConfigAfter, 'email_signup_reward_amount'))->toBe((float) data_get($globalProgramConfigBefore, 'email_signup_reward_amount'));
});

test('shopify embedded rewards redeem update stores tenant-scoped override without mutating global row', function () {
    $reward = CandleCashReward::query()->where('name', 'Free wax melt')->firstOrFail();
    $originalGlobalName = (string) $reward->name;

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.redeem.update', ['reward' => $reward->id]),
            [
                'title' => 'Tenant Free Wax Melt Duo',
                'description' => 'Tenant reward override description.',
                'candle_cash_cost' => 3,
                'reward_value' => 'wax_melt_duo',
                'enabled' => false,
            ]
        );

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Redeem rule saved.')
        ->assertJsonPath('rule.title', 'Tenant Free Wax Melt Duo')
        ->assertJsonPath('rule.candle_cash_cost', 3)
        ->assertJsonPath('rule.enabled', false);

    $reward->refresh();
    $rewardOverride = TenantCandleCashRewardOverride::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('candle_cash_reward_id', $reward->id)
        ->firstOrFail();

    expect((string) $reward->name)->toBe($originalGlobalName)
        ->and((string) $rewardOverride->name)->toBe('Tenant Free Wax Melt Duo')
        ->and((string) $rewardOverride->description)->toBe('Tenant reward override description.')
        ->and((int) $rewardOverride->candle_cash_cost)->toBe(3)
        ->and((string) $rewardOverride->reward_value)->toBe('wax_melt_duo')
        ->and((bool) $rewardOverride->is_active)->toBeFalse();
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
        ->assertJsonPath('errors.candle_cash_cost.0', 'Storefront reward cost is derived from the discount value and current reward value.');
});

test('shopify embedded rewards cross-tenant read and write stay isolated by mapped store tenant', function () {
    $tenantOne = Tenant::query()->create([
        'name' => 'Tenant One',
        'slug' => 'rewards-tenant-one-'.Str::lower(Str::random(6)),
    ]);
    $tenantTwo = Tenant::query()->create([
        'name' => 'Tenant Two',
        'slug' => 'rewards-tenant-two-'.Str::lower(Str::random(6)),
    ]);

    $task = CandleCashTask::query()->where('handle', 'email-signup')->firstOrFail();
    $globalTitle = (string) $task->title;

    configureEmbeddedRetailStore($tenantOne->id);

    $this->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.earn.update', ['task' => $task->id]),
            [
                'title' => 'Tenant One Email Reward',
                'description' => 'Tenant one scope.',
                'candle_cash_value' => 8,
                'enabled' => true,
                'sort_order' => 10,
            ]
        )
        ->assertOk();

    configureEmbeddedRetailStore($tenantTwo->id);

    $this->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.earn.update', ['task' => $task->id]),
            [
                'title' => 'Tenant Two Email Reward',
                'description' => 'Tenant two scope.',
                'candle_cash_value' => 3,
                'enabled' => true,
                'sort_order' => 11,
            ]
        )
        ->assertOk();

    $tenantTwoPayload = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards'))
        ->assertOk()
        ->json('data.earn.items');

    $tenantTwoRow = collect($tenantTwoPayload)->firstWhere('code', 'email-signup');

    configureEmbeddedRetailStore($tenantOne->id);

    $tenantOnePayload = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards'))
        ->assertOk()
        ->json('data.earn.items');

    $tenantOneRow = collect($tenantOnePayload)->firstWhere('code', 'email-signup');

    $task->refresh();

    expect((string) data_get($tenantOneRow, 'title'))->toBe('Tenant One Email Reward')
        ->and((string) data_get($tenantTwoRow, 'title'))->toBe('Tenant Two Email Reward')
        ->and((string) $task->title)->toBe($globalTitle)
        ->and(TenantCandleCashTaskOverride::query()->where('tenant_id', $tenantOne->id)->where('candle_cash_task_id', $task->id)->exists())->toBeTrue()
        ->and(TenantCandleCashTaskOverride::query()->where('tenant_id', $tenantTwo->id)->where('candle_cash_task_id', $task->id)->exists())->toBeTrue();
});

test('shopify embedded rewards program config remains tenant isolated across mapped stores', function () {
    $tenantOne = Tenant::query()->create([
        'name' => 'Program Config Tenant One',
        'slug' => 'program-config-tenant-one-'.Str::lower(Str::random(6)),
    ]);
    $tenantTwo = Tenant::query()->create([
        'name' => 'Program Config Tenant Two',
        'slug' => 'program-config-tenant-two-'.Str::lower(Str::random(6)),
    ]);

    $storefrontReward = CandleCashReward::query()
        ->where('reward_type', 'coupon')
        ->orderByDesc('id')
        ->firstOrFail();

    $globalProgramConfigRow = MarketingSetting::query()->where('key', 'candle_cash_program_config')->firstOrFail();
    $globalProgramConfig = (array) $globalProgramConfigRow->value;
    $globalProgramConfig['redeem_increment_dollars'] = 9.0;
    $globalProgramConfig['storefront_reward_type'] = 'coupon';
    $globalProgramConfig['storefront_reward_value'] = '9USD';
    $globalProgramConfigRow->forceFill([
        'value' => $globalProgramConfig,
    ])->save();

    configureEmbeddedRetailStore($tenantTwo->id);

    $tenantTwoBaseline = (float) data_get(
        $this->withHeaders(retailRewardsApiHeaders())
            ->getJson(route('shopify.app.api.rewards'))
            ->assertOk()
            ->json(),
        'data.meta.program.redeem_increment_dollars'
    );

    configureEmbeddedRetailStore($tenantOne->id);

    $this->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.redeem.update', ['reward' => $storefrontReward->id]),
            [
                'title' => 'Tenant One Storefront Reward',
                'description' => 'Tenant one storefront config override.',
                'candle_cash_cost' => 17,
                'reward_value' => '17USD',
                'enabled' => true,
            ]
        )
        ->assertOk();

    $tenantOneIncrement = (float) data_get(
        $this->withHeaders(retailRewardsApiHeaders())
            ->getJson(route('shopify.app.api.rewards'))
            ->assertOk()
            ->json(),
        'data.meta.program.redeem_increment_dollars'
    );

    configureEmbeddedRetailStore($tenantTwo->id);

    $tenantTwoAfter = (float) data_get(
        $this->withHeaders(retailRewardsApiHeaders())
            ->getJson(route('shopify.app.api.rewards'))
            ->assertOk()
            ->json(),
        'data.meta.program.redeem_increment_dollars'
    );

    $globalProgramConfigAfter = (array) $globalProgramConfigRow->fresh()->value;

    expect($tenantTwoBaseline)->toBe(9.0)
        ->and($tenantOneIncrement)->toBe(17.0)
        ->and($tenantTwoAfter)->toBe(9.0)
        ->and((float) data_get($globalProgramConfigAfter, 'redeem_increment_dollars'))->toBe(9.0)
        ->and(TenantMarketingSetting::query()->where('tenant_id', $tenantOne->id)->where('key', 'candle_cash_program_config')->exists())->toBeTrue()
        ->and(TenantMarketingSetting::query()->where('tenant_id', $tenantTwo->id)->where('key', 'candle_cash_program_config')->exists())->toBeFalse();
});

test('shopify embedded rewards policy update persists tenant scoped settings without mutating global settings', function () {
    $globalProgramConfigRow = MarketingSetting::query()->where('key', 'candle_cash_program_config')->firstOrFail();
    $globalProgramBefore = (array) $globalProgramConfigRow->value;

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'program_identity' => [
                'program_name' => 'Tenant Rewards Co',
                'short_label' => 'TRC',
                'terminology_mode' => 'cash',
            ],
            'value_model' => [
                'redeem_increment_dollars' => 12,
                'max_redeemable_per_order_dollars' => 12,
                'minimum_purchase_dollars' => 50,
            ],
            'finance_and_safety' => [
                'max_open_codes' => 2,
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('message', 'Rewards policy saved.')
        ->assertJsonPath('data.program_identity.program_name', 'Tenant Rewards Co')
        ->assertJsonPath('data.value_model.redeem_increment_dollars', 12)
        ->assertJsonPath('data.finance_and_safety.max_open_codes', 2);

    $tenantProgram = (array) TenantMarketingSetting::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('key', 'candle_cash_program_config')
        ->firstOrFail()
        ->value;

    $globalProgramAfter = (array) $globalProgramConfigRow->fresh()->value;

    expect((string) data_get($tenantProgram, 'label'))->toBe('Tenant Rewards Co')
        ->and((float) data_get($tenantProgram, 'redeem_increment_dollars'))->toBe(12.0)
        ->and((float) data_get($tenantProgram, 'minimum_purchase_dollars'))->toBe(50.0)
        ->and((int) data_get($tenantProgram, 'max_open_codes'))->toBe(2)
        ->and((float) data_get($globalProgramAfter, 'redeem_increment_dollars'))->toBe((float) data_get($globalProgramBefore, 'redeem_increment_dollars'));
});

test('shopify embedded rewards policy update enforces validation guardrails', function () {
    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'value_model' => [
                'currency_mode' => 'points_to_cash',
                'points_per_dollar' => 0,
                'redeem_increment_dollars' => 10,
                'max_redeemable_per_order_dollars' => 10,
            ],
            'expiration_and_reminders' => [
                'expiration_mode' => 'days_from_issue',
                'expiration_days' => 30,
                'reminder_offsets_days' => [30],
                'sms_enabled' => true,
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonFragment(['Points mode requires a points-per-dollar conversion rate greater than zero.'])
        ->assertJsonFragment(['Text reminders require SMS plan/module access and channel readiness.'])
        ->assertJsonFragment(['Reminder timing must occur before the expiration window.']);
});

test('shopify embedded rewards policy is read only when rewards module access is locked', function () {
    $lockedTenant = Tenant::query()->create([
        'name' => 'Locked Tenant',
        'slug' => 'locked-tenant-'.Str::lower(Str::random(8)),
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $lockedTenant->id,
        'plan_key' => 'starter',
        'operating_mode' => 'shopify',
        'source' => 'test',
        'metadata' => [],
    ]);

    configureEmbeddedRetailStore($lockedTenant->id);

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('editable', false)
        ->assertJsonPath('status', 'rewards_plan_locked');

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'program_identity' => [
                'program_name' => 'Blocked Edit',
            ],
        ])
        ->assertStatus(403)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'rewards_plan_locked');
});

test('shopify embedded rewards routes fail closed when store tenant context is missing', function () {
    configureEmbeddedRetailStore(null);

    $task = CandleCashTask::query()->where('handle', 'email-signup')->firstOrFail();

    $this->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards'))
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'tenant_not_mapped')
        ->assertJsonPath('message', 'This Shopify store is not mapped to a tenant yet. Rewards settings are unavailable.');

    $this->withHeaders(retailRewardsApiHeaders())
        ->patchJson(
            route('shopify.app.api.rewards.earn.update', ['task' => $task->id]),
            [
                'title' => 'Blocked',
                'description' => 'Blocked',
                'candle_cash_value' => 5,
                'enabled' => true,
                'sort_order' => 1,
            ]
        )
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonPath('status', 'tenant_not_mapped');

    $this->get(route('shopify.embedded.rewards.earn', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('This Shopify store is not mapped to a tenant yet. Rewards settings are unavailable.')
        ->assertDontSee('shopify-rewards-admin', false);
});

test('cross-tenant regression wall keeps customers detail/manage and dashboard metrics isolated', function () {
    $tenantOne = Tenant::query()->create([
        'name' => 'Isolation Tenant One',
        'slug' => 'isolation-tenant-one-'.Str::lower(Str::random(6)),
    ]);
    $tenantTwo = Tenant::query()->create([
        'name' => 'Isolation Tenant Two',
        'slug' => 'isolation-tenant-two-'.Str::lower(Str::random(6)),
    ]);

    configureEmbeddedRetailStore($tenantOne->id);

    $profileOne = MarketingProfile::query()->create([
        'tenant_id' => $tenantOne->id,
        'first_name' => 'Tenant',
        'last_name' => 'One',
        'email' => 'isolation.tenant.one@example.com',
        'normalized_email' => 'isolation.tenant.one@example.com',
    ]);

    $profileTwo = MarketingProfile::query()->create([
        'tenant_id' => $tenantTwo->id,
        'first_name' => 'Tenant',
        'last_name' => 'Two',
        'email' => 'isolation.tenant.two@example.com',
        'normalized_email' => 'isolation.tenant.two@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileOne->id,
        'type' => 'earn',
        'candle_cash_delta' => 7,
        'source' => 'consent',
        'source_id' => 'isolation:tenant-one',
        'description' => 'Tenant one dashboard fixture',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profileTwo->id,
        'type' => 'earn',
        'candle_cash_delta' => 29,
        'source' => 'consent',
        'source_id' => 'isolation:tenant-two',
        'description' => 'Tenant two dashboard fixture',
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    $manageResponse = $this->get(route('shopify.app.customers.manage', retailEmbeddedSignedQuery()));

    $manageResponse->assertOk()
        ->assertSeeText('isolation.tenant.one@example.com')
        ->assertDontSeeText('isolation.tenant.two@example.com');

    $this->get(route('shopify.app.customers.detail', array_merge(['marketingProfile' => $profileTwo->id], retailEmbeddedSignedQuery())))
        ->assertStatus(404);

    $dashboardResponse = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.dashboard'));

    $dashboardResponse->assertOk()->assertJsonPath('ok', true);

    $earnedMetric = (float) (collect($dashboardResponse->json('data.topMetrics'))->firstWhere('key', 'candle_cash_earned')['value'] ?? 0);

    expect($earnedMetric)->toEqual(7.0);
});
