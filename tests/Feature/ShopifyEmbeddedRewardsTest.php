<?php

require_once __DIR__.'/ShopifyEmbeddedTestHelpers.php';

use App\Jobs\DispatchTenantRewardsReminderJob;
use App\Models\CandleCashReward;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashTask;
use App\Models\CandleCashTransaction;
use App\Models\LandlordOperatorAction;
use App\Models\MarketingAutomationEvent;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingSetting;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantCandleCashRewardOverride;
use App\Models\TenantCandleCashTaskOverride;
use App\Models\TenantMarketingSetting;
use App\Models\User;
use App\Services\Marketing\MarketingDeliveryTrackingService;
use App\Services\Marketing\MarketingEmailReadiness;
use App\Services\Marketing\SendGridEmailService;
use App\Services\Marketing\TenantRewardsOperationsService;
use App\Services\Marketing\TenantRewardsReminderLogService;
use App\Services\Marketing\TenantRewardsReminderDispatchService;
use App\Services\Marketing\TenantRewardsReminderAnalyticsService;
use App\Services\Marketing\TenantRewardsReminderScheduleService;
use App\Services\Marketing\TenantRewardsPolicyService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

function retailRewardsApiHeaders(array $headers = []): array
{
    return array_merge([
        'Authorization' => 'Bearer '.retailShopifySessionToken(),
    ], $headers);
}

function parseRewardsCsv(string $csv): array
{
    $lines = preg_split("/\\r\\n|\\n|\\r/", trim($csv)) ?: [];
    $rows = array_map(fn (string $line): array => str_getcsv($line), $lines);
    $header = array_shift($rows);

    if (! is_array($header) || $header === []) {
        return [];
    }

    return collect($rows)
        ->filter(fn (array $row): bool => $row !== [] && count(array_filter($row, fn ($value): bool => (string) $value !== '')) > 0)
        ->map(function (array $row) use ($header): array {
            $row = array_pad($row, count($header), '');

            return array_combine($header, array_slice($row, 0, count($header))) ?: [];
        })
        ->all();
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
        ->assertJsonPath('data.value_model.redeem_increment_dollars', 10)
        ->assertJsonPath('data.automation_and_reporting.automation_mode', 'manual')
        ->assertJsonPath('data.versioning.current_version', 0);

    $fieldControls = (array) data_get($response->json(), 'data.field_controls', []);
    $platformControl = (array) ($fieldControls['redemption_rules.platform_supports_multi_code'] ?? []);

    expect(($platformControl['access'] ?? null))->toBe('restricted')
        ->and((string) data_get($response->json(), 'data.summary'))->not->toBe('')
        ->and(is_array(data_get($response->json(), 'data.message_previews.sms')))->toBeTrue()
        ->and(is_array(data_get($response->json(), 'data.messages.warnings')))->toBeTrue()
        ->and((string) data_get($response->json(), 'data.readiness.headline'))->not->toBe('')
        ->and((string) data_get($response->json(), 'data.runtime_traceability.reminder_trigger_key'))->toBe(TenantRewardsReminderLogService::TRIGGER_KEY);
});

test('shopify embedded rewards notifications route renders review and preview workspace', function () {
    $response = $this->get(route('shopify.embedded.rewards.notifications', retailEmbeddedSignedQuery()));

    $response->assertOk()
        ->assertSeeText('Review and Launch')
        ->assertSeeText('Launch Readiness')
        ->assertSeeText('Customer Reminder Previews')
        ->assertSeeText('Reminder Activity')
        ->assertSeeText('Where Customers Earn Rewards')
        ->assertSeeText('Apply Alpha defaults');
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
        ->assertJsonPath('message', 'Program settings saved.')
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

test('shopify embedded rewards policy review returns non-persisted summary warnings and validation details', function () {
    $beforeVersion = (int) data_get(
        $this->withHeaders(retailRewardsApiHeaders())
            ->getJson(route('shopify.app.api.rewards.policy'))
            ->assertOk()
            ->json(),
        'data.versioning.current_version',
        0
    );

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->postJson(route('shopify.app.api.rewards.policy.review'), [
            'expiration_and_reminders' => [
                'expiration_mode' => 'days_from_issue',
                'expiration_days' => 30,
                'reminder_offsets_days' => [30],
            ],
            'redemption_rules' => [
                'stacking_mode' => 'shipping_only',
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.review_ready', false)
        ->assertJsonFragment(['Reminder timing must occur before the expiration window.'])
        ->assertJsonFragment(['code' => 'stacking_discount_exposure']);

    $afterVersion = (int) data_get(
        $this->withHeaders(retailRewardsApiHeaders())
            ->getJson(route('shopify.app.api.rewards.policy'))
            ->assertOk()
            ->json(),
        'data.versioning.current_version',
        0
    );

    expect($afterVersion)->toBe($beforeVersion);
});

test('shopify embedded rewards policy update blocks restricted field writes', function () {
    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'redemption_rules' => [
                'platform_supports_multi_code' => true,
            ],
        ])
        ->assertStatus(422)
        ->assertJsonPath('ok', false)
        ->assertJsonFragment(['Platform compatibility is managed by operations and cannot be changed here.']);
});

test('shopify embedded rewards policy update increments version and writes audit history', function () {
    expect(Schema::hasTable('landlord_operator_actions'))->toBeTrue();

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'program_identity' => [
                'program_name' => 'Version Test One',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('data.versioning.current_version', 1);

    $second = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'program_identity' => [
                'program_name' => 'Version Test Two',
            ],
        ]);

    $second->assertOk()
        ->assertJsonPath('data.versioning.current_version', 2)
        ->assertJsonPath('data.audit_history.0.policy_version', 2);

    $meta = (array) TenantMarketingSetting::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('key', TenantRewardsPolicyService::VERSION_META_KEY)
        ->firstOrFail()
        ->value;

    expect((int) data_get($meta, 'current_version'))->toBe(2);

    $audit = LandlordOperatorAction::query()
        ->where('tenant_id', $this->tenant->id)
        ->whereIn('action_type', ['tenant_rewards_policy_save', 'tenant_rewards_policy_publish'])
        ->orderByDesc('id')
        ->first();

    expect($audit)->not->toBeNull()
        ->and((int) data_get((array) $audit?->result, 'policy_version', 0))->toBe(2);
});

test('shopify embedded rewards alpha defaults endpoint applies starter policy', function () {
    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->postJson(route('shopify.app.api.rewards.policy.defaults.alpha'));

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.program_identity.program_name', 'Candle Cash')
        ->assertJsonPath('data.value_model.redeem_increment_dollars', 10)
        ->assertJsonPath('data.value_model.minimum_purchase_dollars', 50)
        ->assertJsonPath('data.earning_rules.second_order_reward_amount', 10)
        ->assertJsonPath('data.redemption_rules.code_strategy', 'unique_per_customer')
        ->assertJsonPath('data.redemption_rules.stacking_mode', 'no_stacking')
        ->assertJsonPath('data.expiration_and_reminders.expiration_days', 90)
        ->assertJsonPath('data.expiration_and_reminders.reminder_offsets_days.0', 14)
        ->assertJsonPath('data.expiration_and_reminders.sms_reminder_offsets_days.0', 3)
        ->assertJsonPath('data.alpha_preset.matches_recommended_default', true);
});

test('tenant rewards reminder schedule service generates tenant policy reminder instances with version traceability', function () {
    $service = app(TenantRewardsReminderScheduleService::class);

    $policy = app(TenantRewardsPolicyService::class)->resolve($this->tenant->id, [
        'editable' => true,
        'sms_channel_enabled' => true,
    ]);
    $policy['expiration_and_reminders']['email_enabled'] = true;
    $policy['expiration_and_reminders']['sms_enabled'] = true;
    $policy['expiration_and_reminders']['email_reminder_offsets_days'] = [14, 7, 1];
    $policy['expiration_and_reminders']['reminder_offsets_days'] = [14, 7, 1];
    $policy['expiration_and_reminders']['sms_reminder_offsets_days'] = [3];
    $policy['expiration_and_reminders']['sms_max_per_reward'] = 1;
    $policy['expiration_and_reminders']['sms_quiet_days'] = 3;

    $schedule = $service->evaluate($policy, [
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => 101,
        'reward_identifier' => 'reward:launch-preview',
        'earned_at' => '2026-03-01T10:00:00-05:00',
        'expires_at' => '2026-03-31T10:00:00-04:00',
        'policy_version' => 4,
        'email_contactable' => true,
        'sms_contactable' => true,
    ], [
        'now' => '2026-03-30T10:00:01-04:00',
    ]);

    expect($schedule['should_send'])->toHaveCount(4)
        ->and(collect($schedule['should_send'])->pluck('channel')->sort()->values()->all())->toBe(['email', 'email', 'email', 'sms'])
        ->and((int) data_get($schedule, 'summary.policy_version'))->toBe(4)
        ->and((int) data_get($schedule, 'should_send.0.policy_version'))->toBe(4);
});

test('tenant rewards reminder schedule service skips reminders after expiration redemption and cancellation', function () {
    $service = app(TenantRewardsReminderScheduleService::class);
    $policy = app(TenantRewardsPolicyService::class)->resolve($this->tenant->id, [
        'editable' => true,
        'sms_channel_enabled' => true,
    ]);
    $policy['expiration_and_reminders']['email_enabled'] = true;
    $policy['expiration_and_reminders']['email_reminder_offsets_days'] = [7, 1];
    $policy['expiration_and_reminders']['reminder_offsets_days'] = [7, 1];

    $expired = $service->evaluate($policy, [
        'tenant_id' => $this->tenant->id,
        'reward_identifier' => 'reward:expired',
        'earned_at' => '2026-02-01T10:00:00-05:00',
        'expires_at' => '2026-02-10T10:00:00-05:00',
    ], ['now' => '2026-02-12T10:00:00-05:00']);

    $redeemed = $service->evaluate($policy, [
        'tenant_id' => $this->tenant->id,
        'reward_identifier' => 'reward:redeemed',
        'earned_at' => '2026-02-01T10:00:00-05:00',
        'expires_at' => '2026-02-20T10:00:00-05:00',
        'redeemed_at' => '2026-02-05T10:00:00-05:00',
    ], ['now' => '2026-02-06T10:00:00-05:00']);

    $canceled = $service->evaluate($policy, [
        'tenant_id' => $this->tenant->id,
        'reward_identifier' => 'reward:canceled',
        'earned_at' => '2026-02-01T10:00:00-05:00',
        'expires_at' => '2026-02-20T10:00:00-05:00',
        'canceled_at' => '2026-02-05T10:00:00-05:00',
    ], ['now' => '2026-02-06T10:00:00-05:00']);

    expect($expired['should_send'])->toBeEmpty()
        ->and(collect($expired['skipped'])->pluck('skip_reason')->unique()->all())->toContain('already_expired')
        ->and($redeemed['should_send'])->toBeEmpty()
        ->and(collect($redeemed['skipped'])->pluck('skip_reason')->unique()->all())->toContain('already_redeemed')
        ->and($canceled['should_send'])->toBeEmpty()
        ->and(collect($canceled['skipped'])->pluck('skip_reason')->unique()->all())->toContain('already_canceled');
});

test('tenant rewards reminder schedule service prevents duplicate reminder timings and enforces sms cap', function () {
    $policy = app(TenantRewardsPolicyService::class)->resolve($this->tenant->id, [
        'editable' => true,
        'sms_channel_enabled' => true,
    ]);
    $policy['expiration_and_reminders']['email_enabled'] = true;
    $policy['expiration_and_reminders']['sms_enabled'] = true;
    $policy['expiration_and_reminders']['email_reminder_offsets_days'] = [14, 7, 1];
    $policy['expiration_and_reminders']['reminder_offsets_days'] = [14, 7, 1];
    $policy['expiration_and_reminders']['sms_reminder_offsets_days'] = [7, 3, 1];
    $policy['expiration_and_reminders']['sms_max_per_reward'] = 1;
    $policy['expiration_and_reminders']['sms_quiet_days'] = 3;

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Reminder',
        'last_name' => 'Duplicate',
        'email' => 'reminder.duplicate@example.com',
        'normalized_email' => 'reminder.duplicate@example.com',
    ]);

    $logService = app(TenantRewardsReminderLogService::class);
    $logService->record([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'reward_identifier' => 'reward:duplicate',
        'channel' => 'email',
        'timing_days_before_expiration' => 7,
        'policy_version' => 6,
        'scheduled_at' => '2026-03-24T10:00:00-04:00',
        'status' => 'scheduled',
    ]);

    $schedule = app(TenantRewardsReminderScheduleService::class)->evaluate($policy, [
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'reward_identifier' => 'reward:duplicate',
        'earned_at' => '2026-03-01T10:00:00-05:00',
        'expires_at' => '2026-03-31T10:00:00-04:00',
        'email_contactable' => true,
        'sms_contactable' => true,
    ], [
        'now' => '2026-03-29T10:00:00-04:00',
    ]);

    expect(collect($schedule['skipped'])->pluck('skip_reason')->all())->toContain('duplicate_prevented')
        ->and(
            collect($schedule['should_send'])
                ->merge($schedule['upcoming'])
                ->where('channel', 'sms')
                ->count()
        )->toBe(1);
});

test('tenant rewards reminder dispatch service sends due reminder email and text through existing delivery paths', function () {
    config()->set('marketing.sms.enabled', true);
    config()->set('marketing.twilio.enabled', true);
    config()->set('marketing.sms.dry_run', false);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Launch',
        'last_name' => 'Ready',
        'email' => 'launch.ready@example.com',
        'normalized_email' => 'launch.ready@example.com',
        'phone' => '+15552220001',
        'normalized_phone' => '+15552220001',
        'accepts_sms_marketing' => true,
    ]);

    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'consent',
        'source_id' => 'dispatch:fixture',
        'description' => 'Dispatch fixture',
    ]);
    $transaction->forceFill([
        'created_at' => '2026-03-01 10:00:00',
        'updated_at' => '2026-03-01 10:00:00',
    ])->saveQuietly();

    $emailReadiness = \Mockery::mock(MarketingEmailReadiness::class);
    $emailReadiness->shouldReceive('summary')
        ->once()
        ->with($this->tenant->id)
        ->andReturn([
            'can_send_live' => true,
            'notes' => [],
            'missing_requirements' => [],
        ]);
    $emailReadiness->shouldReceive('providerContextForDelivery')
        ->atLeast()->once()
        ->with($this->tenant->id)
        ->andReturn([
            'provider' => 'sendgrid',
            'can_send_live' => true,
            'notes' => [],
            'missing_requirements' => [],
        ]);
    app()->instance(MarketingEmailReadiness::class, $emailReadiness);

    $senderConfig = Mockery::mock(TwilioSenderConfigService::class);
    $senderConfig->shouldReceive('defaultSender')
        ->atLeast()->once()
        ->andReturn([
            'key' => 'primary',
            'label' => 'Primary Sender',
        ]);
    app()->instance(TwilioSenderConfigService::class, $senderConfig);

    $sendGrid = Mockery::mock(SendGridEmailService::class);
    $sendGrid->shouldReceive('sendEmail')
        ->once()
        ->andReturn([
            'success' => true,
            'provider' => 'sendgrid',
            'message_id' => 'email-123',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'payload' => [],
            'dry_run' => false,
            'retryable' => false,
            'tenant_id' => $this->tenant->id,
        ]);
    app()->instance(SendGridEmailService::class, $sendGrid);

    $twilio = Mockery::mock(TwilioSmsService::class);
    $twilio->shouldReceive('sendSms')
        ->once()
        ->andReturn([
            'success' => true,
            'provider' => 'twilio',
            'provider_message_id' => 'sms-123',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'sender_key' => 'primary',
            'sender_label' => 'Primary Sender',
            'from_identifier' => '+15553330000',
            'payload' => [],
            'dry_run' => false,
        ]);
    app()->instance(TwilioSmsService::class, $twilio);

    $tracking = Mockery::mock(MarketingDeliveryTrackingService::class);
    $tracking->shouldReceive('mapProviderStatus')->once()->andReturn('sent');
    $tracking->shouldReceive('appendEvent')->once()->andReturn(null);
    app()->instance(MarketingDeliveryTrackingService::class, $tracking);

    $policy = app(TenantRewardsPolicyService::class)->resolve($this->tenant->id, [
        'editable' => true,
        'sms_channel_enabled' => true,
    ]);
    $policy['expiration_and_reminders']['email_enabled'] = true;
    $policy['expiration_and_reminders']['sms_enabled'] = true;
    $policy['expiration_and_reminders']['expiration_mode'] = 'days_from_issue';
    $policy['expiration_and_reminders']['expiration_days'] = 30;
    $policy['expiration_and_reminders']['email_reminder_offsets_days'] = [1];
    $policy['expiration_and_reminders']['reminder_offsets_days'] = [1];
    $policy['expiration_and_reminders']['sms_reminder_offsets_days'] = [1];
    $policy['expiration_and_reminders']['sms_max_per_reward'] = 1;
    $policy['access_state']['launch_state'] = 'published';
    $policy['access_state']['test_mode'] = false;

    $result = app(TenantRewardsReminderDispatchService::class)->processTenant($this->tenant->id, $policy, [
        'now' => '2026-03-30T10:00:00-04:00',
        'policy_version' => 3,
        'limit' => 20,
    ]);

    expect((int) data_get($result, 'summary.sent_count'))->toBe(2)
        ->and((int) data_get($result, 'summary.failed_count'))->toBe(0)
        ->and((int) data_get($result, 'summary.skipped_count'))->toBe(0)
        ->and(MarketingEmailDelivery::query()->count())->toBe(1)
        ->and(MarketingMessageDelivery::query()->count())->toBe(1)
        ->and(MarketingAutomationEvent::query()->where('trigger_key', TenantRewardsReminderLogService::TRIGGER_KEY)->where('status', 'sent')->count())->toBe(2)
        ->and((string) data_get(MarketingAutomationEvent::query()->where('status', 'sent')->orderByDesc('id')->first()?->context, 'reminder_key'))->toContain('|v3')
        ->and((int) data_get(MarketingAutomationEvent::query()->where('status', 'sent')->orderByDesc('id')->first()?->context, 'policy_version'))->toBe(3)
        ->and((string) data_get($result, 'processed_items.0.reward_identifier'))->toBe('earned-bucket:tx:'.$transaction->id);
});

test('tenant rewards reminder dispatch service suppresses expired rewards and duplicate sends', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Late',
        'last_name' => 'Reminder',
        'email' => 'late.reminder@example.com',
        'normalized_email' => 'late.reminder@example.com',
    ]);

    $expiredTransaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'consent',
        'source_id' => 'dispatch:expired',
        'description' => 'Expired reminder fixture',
    ]);
    $expiredTransaction->forceFill([
        'created_at' => '2026-01-01 10:00:00',
        'updated_at' => '2026-01-01 10:00:00',
    ])->saveQuietly();

    $duplicateTransaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'consent',
        'source_id' => 'dispatch:duplicate',
        'description' => 'Duplicate reminder fixture',
    ]);
    $duplicateTransaction->forceFill([
        'created_at' => '2026-03-01 10:00:00',
        'updated_at' => '2026-03-01 10:00:00',
    ])->saveQuietly();

    app(TenantRewardsReminderLogService::class)->record([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'reward_identifier' => 'earned-bucket:tx:'.$duplicateTransaction->id,
        'channel' => 'email',
        'timing_days_before_expiration' => 1,
        'policy_version' => 4,
        'status' => 'sent',
        'scheduled_at' => '2026-03-30T10:00:00-04:00',
        'sent_at' => '2026-03-30T10:05:00-04:00',
    ]);

    $emailReadiness = \Mockery::mock(MarketingEmailReadiness::class);
    $emailReadiness->shouldReceive('summary')
        ->once()
        ->with($this->tenant->id)
        ->andReturn([
            'can_send_live' => true,
            'notes' => [],
            'missing_requirements' => [],
        ]);
    $emailReadiness->shouldReceive('providerContextForDelivery')->andReturn([
        'provider' => 'sendgrid',
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    app()->instance(MarketingEmailReadiness::class, $emailReadiness);

    $policy = app(TenantRewardsPolicyService::class)->resolve($this->tenant->id, [
        'editable' => true,
        'sms_channel_enabled' => false,
    ]);
    $policy['expiration_and_reminders']['email_enabled'] = true;
    $policy['expiration_and_reminders']['sms_enabled'] = false;
    $policy['expiration_and_reminders']['expiration_mode'] = 'days_from_issue';
    $policy['expiration_and_reminders']['expiration_days'] = 30;
    $policy['expiration_and_reminders']['email_reminder_offsets_days'] = [1];
    $policy['expiration_and_reminders']['reminder_offsets_days'] = [1];
    $policy['access_state']['launch_state'] = 'published';
    $policy['access_state']['test_mode'] = false;

    $expiredResult = app(TenantRewardsReminderDispatchService::class)->processTenant($this->tenant->id, $policy, [
        'reward_identifier' => 'earned-bucket:tx:'.$expiredTransaction->id,
        'now' => '2026-03-30T10:00:00-04:00',
        'policy_version' => 4,
    ]);

    $duplicateResult = app(TenantRewardsReminderDispatchService::class)->processTenant($this->tenant->id, $policy, [
        'reward_identifier' => 'earned-bucket:tx:'.$duplicateTransaction->id,
        'now' => '2026-03-30T10:00:00-04:00',
        'policy_version' => 4,
    ]);

    expect((int) data_get($expiredResult, 'summary.sent_count'))->toBe(0)
        ->and((int) data_get($expiredResult, 'summary.schedule_skip_count'))->toBeGreaterThan(0)
        ->and(collect((array) data_get($expiredResult, 'schedule_skipped_items'))->pluck('skip_reason')->all())->toContain('already_expired')
        ->and((int) data_get($duplicateResult, 'summary.sent_count'))->toBe(0)
        ->and(collect((array) data_get($duplicateResult, 'schedule_skipped_items'))->pluck('skip_reason')->all())->toContain('duplicate_prevented')
        ->and(MarketingEmailDelivery::query()->count())->toBe(0);
});

test('shopify embedded rewards policy review returns publish preview and readiness context', function () {
    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->postJson(route('shopify.app.api.rewards.policy.review'), [
            'program_identity' => [
                'program_name' => 'Preview Ready Rewards',
            ],
            'expiration_and_reminders' => [
                'email_reminder_offsets_days' => [14, 7, 1],
                'sms_reminder_offsets_days' => [3],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.publish_preview.live_version', 0)
        ->assertJsonPath('data.publish_preview.pending_version', 1)
        ->assertJsonFragment(['Program name will change from Candle Cash to Preview Ready Rewards.'])
        ->assertJsonPath('data.runtime_traceability.reminder_trigger_key', TenantRewardsReminderLogService::TRIGGER_KEY);
});

test('shopify embedded rewards policy route includes reminder history entries for operator visibility', function () {
    MarketingAutomationEvent::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => null,
        'trigger_key' => TenantRewardsReminderLogService::TRIGGER_KEY,
        'channel' => 'email',
        'status' => 'sent',
        'reason' => null,
        'context' => [
            'reward_identifier' => 'reward:history',
            'timing_days_before_expiration' => 7,
            'scheduled_at' => now()->subDay()->toIso8601String(),
            'sent_at' => now()->subHours(12)->toIso8601String(),
            'policy_version' => 2,
            'reminder_key' => 'reward:history|email|7',
        ],
        'occurred_at' => now()->subHours(12),
        'processed_at' => now()->subHours(12),
        'created_at' => now()->subHours(12),
        'updated_at' => now()->subHours(12),
    ]);

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy'))
        ->assertOk()
        ->assertJsonPath('data.reminder_history.0.reward_identifier', 'reward:history')
        ->assertJsonPath('data.reminder_history.0.policy_version', 2)
        ->assertJsonPath('data.reminder_history.0.channel', 'email');
});

test('shopify embedded rewards policy route includes reminder reporting and launch checklist', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Reporting',
        'last_name' => 'Customer',
        'email' => 'reporting.customer@example.com',
        'normalized_email' => 'reporting.customer@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'consent',
        'source_id' => 'reporting:fixture',
        'description' => 'Reporting fixture',
        'created_at' => '2026-03-01 10:00:00',
        'updated_at' => '2026-03-01 10:00:00',
    ]);

    MarketingAutomationEvent::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'trigger_key' => TenantRewardsReminderLogService::TRIGGER_KEY,
        'channel' => 'email',
        'status' => 'failed',
        'reason' => 'provider failure',
        'context' => [
            'reward_identifier' => 'earned-bucket:tx:fixture',
            'timing_days_before_expiration' => 7,
            'policy_version' => 5,
            'reminder_key' => 'earned-bucket:tx:fixture|email|7|v5',
        ],
        'occurred_at' => now()->subDay(),
        'processed_at' => now()->subDay(),
    ]);

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy'));

    $response->assertOk()
        ->assertJsonPath('data.reminder_reporting.summary_cards.0.label', 'Reminders sent')
        ->assertJsonPath('data.readiness.checklist.0.key', 'program_configured')
        ->assertJsonPath('data.policy_options.channel_strategies.0.value', 'online_only')
        ->assertJsonPath('data.policy_options.channel_strategies.3.available', false);

    expect((string) data_get($response->json(), 'data.exclusions_summary'))->not->toBe('')
        ->and((string) data_get($response->json(), 'data.channel_strategy_summary'))->not->toBe('')
        ->and((int) data_get($response->json(), 'data.reminder_reporting.channel_breakdown.email.failed'))->toBe(1);
});

test('shopify embedded rewards policy update stores stronger exclusions and channel strategy fields', function () {
    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'earning_rules' => [
                'rewardable_channels' => 'show_issued_online_redeemed',
            ],
            'redemption_rules' => [
                'exclusions' => [
                    'wholesale' => true,
                    'sale_items' => true,
                    'bundles' => false,
                    'limited_releases' => true,
                    'subscriptions' => true,
                    'collections' => ['holiday'],
                    'products' => ['candle-deluxe'],
                    'tags' => ['limited'],
                ],
            ],
        ]);

    $response->assertOk()
        ->assertJsonPath('data.earning_rules.rewardable_channels', 'show_issued_online_redeemed')
        ->assertJsonPath('data.redemption_rules.exclusions.limited_releases', true)
        ->assertJsonPath('data.redemption_rules.exclusions.collections.0', 'holiday')
        ->assertJsonPath('data.redemption_rules.exclusions.products.0', 'candle-deluxe')
        ->assertJsonPath('data.redemption_rules.exclusions.tags.0', 'limited');

    $program = (array) TenantMarketingSetting::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('key', TenantRewardsPolicyService::PROGRAM_KEY)
        ->firstOrFail()
        ->value;

    expect((string) data_get($program, 'rewardable_channels'))->toBe('show_issued_online_redeemed')
        ->and((bool) data_get($program, 'exclusions.limited_releases'))->toBeTrue()
        ->and((array) data_get($program, 'exclusions.collections'))->toBe(['holiday'])
        ->and((string) data_get($response->json(), 'data.channel_strategy_summary'))->toContain('shows')
        ->and((string) data_get($response->json(), 'data.exclusions_summary'))->toContain('limited releases');
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
        ->assertJsonFragment(['Text reminders require plan access for SMS and a ready channel setup.'])
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

test('shopify embedded rewards reminder explain endpoint returns evaluated timings and audit trace', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Explain',
        'last_name' => 'Reminder',
        'email' => 'explain.reminder@example.com',
        'normalized_email' => 'explain.reminder@example.com',
    ]);

    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'order',
        'source_id' => 'explain:fixture',
        'description' => 'Reminder explain fixture',
    ]);
    $transaction->forceFill([
        'created_at' => now()->subDays(20),
        'updated_at' => now()->subDays(20),
    ])->saveQuietly();

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->postJson(route('shopify.app.api.rewards.policy.reminders.explain'), [
            'reward_identifier' => 'earned-bucket:tx:'.$transaction->id,
            'reason' => 'Support review',
        ]);

    $response->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('data.status', 'ok')
        ->assertJsonPath('data.items.0.reward_identifier', 'earned-bucket:tx:'.$transaction->id);

    expect(data_get($response->json(), 'data.items.0.schedule_explanation.evaluated_timings'))->toBeArray()
        ->and((int) data_get($response->json(), 'data.items.0.schedule_explanation.eligibility_checks.history_entries'))->toBeGreaterThanOrEqual(0)
        ->and(LandlordOperatorAction::query()->where('tenant_id', $this->tenant->id)->where('action_type', 'tenant_rewards_reminder_explain')->exists())->toBeTrue();
});

test('shopify embedded rewards policy route includes finance summary visibility', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Finance',
        'last_name' => 'Customer',
        'email' => 'finance.customer@example.com',
        'normalized_email' => 'finance.customer@example.com',
    ]);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 12,
        'source' => 'order',
        'source_id' => 'finance:issued',
        'description' => 'Finance issued fixture',
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);

    $reward = CandleCashReward::query()->where('reward_type', 'coupon')->orderByDesc('id')->firstOrFail();

    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 5,
        'platform' => 'shopify',
        'redemption_code' => 'FINANCE-REDEEM',
        'status' => 'redeemed',
        'issued_at' => now()->subDays(8),
        'expires_at' => now()->addDays(10),
        'redeemed_at' => now()->subDays(7),
    ]);

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy'));

    $response->assertOk();

    expect((float) data_get($response->json(), 'data.finance_summary.redeemed.amount'))->toBe(5.0)
        ->and((float) data_get($response->json(), 'data.finance_summary.realized_discount_value.amount'))->toBe(5.0)
        ->and((float) data_get($response->json(), 'data.finance_summary.outstanding_liability.amount'))->toBeGreaterThan(0)
        ->and(is_array(data_get($response->json(), 'data.finance_summary.cards')))->toBeTrue();
});

test('shopify embedded rewards reminder reporting supports filters and health signals', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Filter',
        'last_name' => 'Customer',
        'email' => 'filter.customer@example.com',
        'normalized_email' => 'filter.customer@example.com',
    ]);

    MarketingAutomationEvent::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'trigger_key' => TenantRewardsReminderLogService::TRIGGER_KEY,
        'channel' => 'email',
        'status' => 'failed',
        'reason' => 'provider failure',
        'context' => [
            'reward_identifier' => 'reward:email-failed',
            'timing_days_before_expiration' => 7,
            'policy_version' => 2,
            'reward_source_key' => 'order_purchase_earn',
            'reward_source_label' => 'Order / Reward purchase earn',
        ],
        'occurred_at' => now()->subDays(2),
        'processed_at' => now()->subDays(2),
    ]);

    MarketingAutomationEvent::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'trigger_key' => TenantRewardsReminderLogService::TRIGGER_KEY,
        'channel' => 'sms',
        'status' => 'skipped',
        'reason' => 'channel unavailable',
        'context' => [
            'reward_identifier' => 'reward:sms-skipped',
            'timing_days_before_expiration' => 3,
            'policy_version' => 2,
            'reward_source_key' => 'order_purchase_earn',
            'reward_source_label' => 'Order / Reward purchase earn',
            'skip_reason' => 'channel_not_ready',
        ],
        'occurred_at' => now()->subDay(),
        'processed_at' => now()->subDay(),
    ]);

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy', [
            'channel' => 'sms',
            'status' => 'skipped',
            'reward_type' => 'order_purchase_earn',
        ]));

    $response->assertOk()
        ->assertJsonPath('data.reminder_reporting.filters.channel', 'sms')
        ->assertJsonPath('data.reminder_reporting.filters.status', 'skipped')
        ->assertJsonPath('data.reminder_reporting.activity_table.count', 1)
        ->assertJsonPath('data.reminder_reporting.activity_table.items.0.channel', 'sms')
        ->assertJsonPath('data.reminder_reporting.activity_table.items.0.status', 'skipped');

    expect(collect((array) data_get($response->json(), 'data.reminder_reporting.health_signals'))->pluck('code')->all())
        ->toContain('email_not_configured');
});

test('shopify embedded rewards export endpoints return tenant scoped csv output', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Export',
        'last_name' => 'Customer',
        'email' => 'export.customer@example.com',
        'normalized_email' => 'export.customer@example.com',
    ]);

    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 9,
        'source' => 'order',
        'source_id' => 'export:issued',
        'description' => 'Export issuance fixture',
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ]);

    MarketingAutomationEvent::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'trigger_key' => TenantRewardsReminderLogService::TRIGGER_KEY,
        'channel' => 'email',
        'status' => 'sent',
        'reason' => null,
        'context' => [
            'reward_identifier' => 'earned-bucket:tx:'.$transaction->id,
            'timing_days_before_expiration' => 7,
            'policy_version' => 1,
            'reward_source_key' => 'order_purchase_earn',
            'reward_source_label' => 'Order / Reward purchase earn',
        ],
        'occurred_at' => now()->subDays(3),
        'processed_at' => now()->subDays(3),
    ]);

    $reward = CandleCashReward::query()->where('reward_type', 'coupon')->orderByDesc('id')->firstOrFail();
    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'candle_cash_spent' => 4,
        'platform' => 'shopify',
        'redemption_code' => 'EXPORT-REDEEM',
        'status' => 'redeemed',
        'issued_at' => now()->subDays(4),
        'expires_at' => now()->addDays(10),
        'redeemed_at' => now()->subDays(2),
    ]);

    $reminderExport = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->get(route('shopify.app.api.rewards.policy.exports', [
            'type' => 'reminder_history',
            'date_from' => now()->subDays(30)->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

    $reminderRows = collect(parseRewardsCsv($reminderExport->streamedContent()));

    $reminderExport->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($reminderRows->pluck('reward_identifier')->contains('earned-bucket:tx:'.$transaction->id))->toBeTrue();

    $issuanceExport = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->get(route('shopify.app.api.rewards.policy.exports', [
            'type' => 'reward_issuance',
            'date_from' => now()->subDays(30)->toDateString(),
            'date_to' => now()->toDateString(),
        ]));

    $issuanceRows = collect(parseRewardsCsv($issuanceExport->streamedContent()));

    expect($issuanceRows->pluck('transaction_id')->contains((string) $transaction->id))->toBeTrue()
        ->and($issuanceRows->pluck('formatted_amount')->contains('$9.00'))->toBeTrue();

    $expiringExport = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->get(route('shopify.app.api.rewards.policy.exports', [
            'type' => 'expiring_rewards',
            'date_from' => now()->toDateString(),
            'date_to' => now()->addDays(120)->toDateString(),
        ]));

    $expiringRows = collect(parseRewardsCsv($expiringExport->streamedContent()));

    expect($expiringRows->pluck('reward_identifier')->contains('earned-bucket:tx:'.$transaction->id))->toBeTrue();
});

test('shopify embedded rewards support actions can requeue and skip reminders with audit logging', function () {
    Queue::fake();

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Support',
        'last_name' => 'Customer',
        'email' => 'support.customer@example.com',
        'normalized_email' => 'support.customer@example.com',
    ]);

    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'order',
        'source_id' => 'support:fixture',
        'description' => 'Support fixture',
    ]);
    $transaction->forceFill([
        'created_at' => now()->subDays(29),
        'updated_at' => now()->subDays(29),
    ])->saveQuietly();

    $requeueResponse = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->postJson(route('shopify.app.api.rewards.policy.reminders.requeue'), [
            'reward_identifier' => 'earned-bucket:tx:'.$transaction->id,
            'channel' => 'email',
            'timing_days_before_expiration' => 1,
            'reason' => 'Customer asked for another reminder',
        ]);

    $requeueResponse->assertOk()
        ->assertJsonPath('data.queued_count', 1);

    Queue::assertPushed(DispatchTenantRewardsReminderJob::class, function (DispatchTenantRewardsReminderJob $job) use ($transaction): bool {
        return $job->tenantId === $this->tenant->id
            && $job->rewardIdentifier === 'earned-bucket:tx:'.$transaction->id
            && $job->channel === 'email'
            && $job->timingDaysBeforeExpiration === 1;
    });

    $skipResponse = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->postJson(route('shopify.app.api.rewards.policy.reminders.skip'), [
            'reward_identifier' => 'earned-bucket:tx:'.$transaction->id,
            'channel' => 'email',
            'timing_days_before_expiration' => 1,
            'reason' => 'Customer opted out of manual follow-up',
        ]);

    $skipResponse->assertOk()
        ->assertJsonPath('data.summary.skipped_count', 1);

    expect(MarketingAutomationEvent::query()->where('trigger_key', TenantRewardsReminderLogService::TRIGGER_KEY)->where('status', 'skipped')->exists())->toBeTrue()
        ->and(LandlordOperatorAction::query()->where('tenant_id', $this->tenant->id)->where('action_type', 'tenant_rewards_reminder_requeue')->exists())->toBeTrue()
        ->and(LandlordOperatorAction::query()->where('tenant_id', $this->tenant->id)->where('action_type', 'tenant_rewards_reminder_mark_skipped')->exists())->toBeTrue();
});

test('shopify embedded rewards customer reminder history endpoint returns tenant scoped rows', function () {
    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'History',
        'last_name' => 'Customer',
        'email' => 'history.customer@example.com',
        'normalized_email' => 'history.customer@example.com',
    ]);

    MarketingAutomationEvent::query()->create([
        'tenant_id' => $this->tenant->id,
        'marketing_profile_id' => $profile->id,
        'trigger_key' => TenantRewardsReminderLogService::TRIGGER_KEY,
        'channel' => 'email',
        'status' => 'sent',
        'reason' => null,
        'context' => [
            'reward_identifier' => 'reward:history-customer',
            'timing_days_before_expiration' => 3,
            'policy_version' => 4,
        ],
        'occurred_at' => now()->subHour(),
        'processed_at' => now()->subHour(),
    ]);

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy.reminders.customer-history', [
            'marketing_profile_id' => $profile->id,
        ]));

    $response->assertOk()
        ->assertJsonPath('data.marketing_profile_id', $profile->id)
        ->assertJsonPath('data.count', 1)
        ->assertJsonPath('data.items.0.reward_identifier', 'reward:history-customer');
});

test('tenant rewards reminder dispatch service retries safely after a failed reminder attempt', function () {
    config()->set('marketing.sms.enabled', false);
    config()->set('marketing.twilio.enabled', false);

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Retry',
        'last_name' => 'Customer',
        'email' => 'retry.customer@example.com',
        'normalized_email' => 'retry.customer@example.com',
    ]);

    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'order',
        'source_id' => 'retry:fixture',
        'description' => 'Retry fixture',
    ]);
    $transaction->forceFill([
        'created_at' => '2026-03-01 10:00:00',
        'updated_at' => '2026-03-01 10:00:00',
    ])->saveQuietly();

    $emailReadiness = \Mockery::mock(MarketingEmailReadiness::class);
    $emailReadiness->shouldReceive('summary')->once()->andReturn([
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    $emailReadiness->shouldReceive('providerContextForDelivery')->atLeast()->times(2)->andReturn([
        'provider' => 'sendgrid',
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    app()->instance(MarketingEmailReadiness::class, $emailReadiness);

    $sendGrid = \Mockery::mock(SendGridEmailService::class);
    $sendGrid->shouldReceive('sendEmail')
        ->once()
        ->andReturn([
            'success' => false,
            'provider' => 'sendgrid',
            'message_id' => null,
            'status' => 'failed',
            'error_code' => 'temporary_failure',
            'error_message' => 'Temporary failure',
            'payload' => [],
            'dry_run' => false,
            'retryable' => true,
        ]);
    $sendGrid->shouldReceive('sendEmail')
        ->once()
        ->andReturn([
            'success' => true,
            'provider' => 'sendgrid',
            'message_id' => 'retry-success',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'payload' => [],
            'dry_run' => false,
            'retryable' => false,
        ]);
    app()->instance(SendGridEmailService::class, $sendGrid);

    $policy = app(TenantRewardsPolicyService::class)->resolve($this->tenant->id, [
        'editable' => true,
        'sms_channel_enabled' => false,
    ]);
    $policy['expiration_and_reminders']['email_enabled'] = true;
    $policy['expiration_and_reminders']['sms_enabled'] = false;
    $policy['expiration_and_reminders']['expiration_mode'] = 'days_from_issue';
    $policy['expiration_and_reminders']['expiration_days'] = 30;
    $policy['expiration_and_reminders']['email_reminder_offsets_days'] = [1];
    $policy['expiration_and_reminders']['reminder_offsets_days'] = [1];
    $policy['access_state']['launch_state'] = 'published';
    $policy['access_state']['test_mode'] = false;

    $first = app(TenantRewardsReminderDispatchService::class)->processTenant($this->tenant->id, $policy, [
        'reward_identifier' => 'earned-bucket:tx:'.$transaction->id,
        'channel' => 'email',
        'timing_days_before_expiration' => 1,
        'now' => '2026-03-30T10:00:00-04:00',
        'policy_version' => 9,
    ]);

    $second = app(TenantRewardsReminderDispatchService::class)->processTenant($this->tenant->id, $policy, [
        'reward_identifier' => 'earned-bucket:tx:'.$transaction->id,
        'channel' => 'email',
        'timing_days_before_expiration' => 1,
        'now' => '2026-03-30T10:05:00-04:00',
        'policy_version' => 9,
    ]);

    expect((int) data_get($first, 'summary.failed_count'))->toBe(1)
        ->and((int) data_get($second, 'summary.sent_count'))->toBe(1)
        ->and(MarketingAutomationEvent::query()->where('trigger_key', TenantRewardsReminderLogService::TRIGGER_KEY)->where('status', 'failed')->exists())->toBeTrue()
        ->and(MarketingAutomationEvent::query()->where('trigger_key', TenantRewardsReminderLogService::TRIGGER_KEY)->where('status', 'sent')->exists())->toBeTrue();
});

test('shopify embedded rewards policy route includes automation alerts usage indicators and simulation output', function () {
    $lastRunAt = now()->subHours(6);
    $lastSuccessAt = now()->subDay();
    $lastFailureAt = now()->subHours(6);

    CandleCashTransaction::query()->create([
        'marketing_profile_id' => MarketingProfile::query()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Automation',
            'last_name' => 'Customer',
            'email' => 'automation.customer@example.com',
            'normalized_email' => 'automation.customer@example.com',
        ])->id,
        'type' => 'earn',
        'candle_cash_delta' => 15,
        'source' => 'order',
        'source_id' => 'automation:issued',
        'description' => 'Automation issued fixture',
        'created_at' => now()->subDays(10),
        'updated_at' => now()->subDays(10),
    ]);

    app(TenantRewardsOperationsService::class)->storeRuntimeState($this->tenant->id, [
        'last_run_at' => $lastRunAt->toIso8601String(),
        'last_success_at' => $lastSuccessAt->toIso8601String(),
        'last_failure_at' => $lastFailureAt->toIso8601String(),
        'last_status' => 'error',
        'failure_count' => 2,
        'last_error_message' => 'Reminder worker stalled during the last run.',
    ]);

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'finance_and_safety' => [
                'liability_alert_threshold_dollars' => 1,
            ],
            'automation_and_reporting' => [
                'automation_mode' => 'automatic',
                'alert_no_sends_hours' => 12,
                'alert_high_skip_rate_percent' => 40,
                'alert_failure_spike_count' => 2,
            ],
        ])
        ->assertOk();

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy'));

    $response->assertOk()
        ->assertJsonPath('data.automation.status', 'needs_attention')
        ->assertJsonPath('data.automation.failure_count', 2)
        ->assertJsonPath('data.automation.last_failure_at', $lastFailureAt->toIso8601String())
        ->assertJsonPath('data.usage_indicators.module_enabled', true)
        ->assertJsonPath('data.simulation_view.headline', 'What happens if these settings change?');

    $alertCodes = collect((array) data_get($response->json(), 'data.alerts'))->pluck('code')->all();

    expect($alertCodes)->toContain('automation_needs_attention')
        ->and($alertCodes)->toContain('liability_above_threshold')
        ->and(collect((array) data_get($response->json(), 'data.usage_indicators.items'))->pluck('metric_key')->all())->toContain('rewards_issued')
        ->and((float) data_get($response->json(), 'data.simulation_view.current.reward_value'))->toBeGreaterThanOrEqual(0)
        ->and((string) data_get($response->json(), 'data.simulation_view.estimated_cost_impact.formatted_value'))->toStartWith('$');
});

test('scheduled tenant rewards finance reports send existing export links and update runtime state', function () {
    CandleCashTransaction::query()->create([
        'marketing_profile_id' => MarketingProfile::query()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Finance',
            'last_name' => 'Ops',
            'email' => 'finance.ops.customer@example.com',
            'normalized_email' => 'finance.ops.customer@example.com',
        ])->id,
        'type' => 'earn',
        'candle_cash_delta' => 20,
        'source' => 'order',
        'source_id' => 'finance-report:issued',
        'description' => 'Finance report issued fixture',
        'created_at' => now()->subDays(3),
        'updated_at' => now()->subDays(3),
    ]);

    app(TenantRewardsOperationsService::class)->persistConfig($this->tenant->id, [
        'automation_mode' => 'automatic',
        'report_frequency' => 'daily',
        'report_delivery_mode' => 'download_link',
        'report_email' => 'finance.ops@example.com',
    ], []);

    $sendGrid = Mockery::mock(SendGridEmailService::class);
    $sendGrid->shouldReceive('sendEmail')
        ->once()
        ->withArgs(function (string $toEmail, string $subject, string $body, array $options): bool {
            return $toEmail === 'finance.ops@example.com'
                && str_contains($subject, 'Candle Cash rewards finance report')
                && str_contains($body, 'Download links for the latest rewards finance exports:')
                && str_contains($body, '/rewards/policy/exports/signed/'.$this->tenant->id.'/finance_summary')
                && str_contains($body, '/rewards/policy/exports/signed/'.$this->tenant->id.'/reward_issuance')
                && str_contains($body, '/rewards/policy/exports/signed/'.$this->tenant->id.'/reward_redemption')
                && str_contains($body, '/rewards/policy/exports/signed/'.$this->tenant->id.'/expiring_rewards')
                && data_get($options, 'campaign_type') === 'rewards_finance_report';
        })
        ->andReturn([
            'success' => true,
            'provider' => 'sendgrid',
            'message_id' => 'finance-report-123',
            'status' => 'sent',
            'error_code' => null,
            'error_message' => null,
            'payload' => [],
            'dry_run' => false,
            'retryable' => false,
        ]);
    app()->instance(SendGridEmailService::class, $sendGrid);

    $this->artisan('marketing:send-tenant-rewards-finance-reports', [
        '--tenant' => $this->tenant->id,
        '--force' => true,
    ])->assertExitCode(0);

    $runtime = app(TenantRewardsOperationsService::class)->runtimeState($this->tenant->id);

    expect((string) ($runtime['last_report_status'] ?? null))->toBe('sent')
        ->and((string) ($runtime['last_report_frequency'] ?? null))->toBe('daily')
        ->and((string) ($runtime['last_report_sent_at'] ?? ''))->not->toBe('');
});

test('signed rewards finance export returns csv without app auth', function () {
    CandleCashTransaction::query()->create([
        'marketing_profile_id' => MarketingProfile::query()->create([
            'tenant_id' => $this->tenant->id,
            'first_name' => 'Signed',
            'last_name' => 'Export',
            'email' => 'signed.export@example.com',
            'normalized_email' => 'signed.export@example.com',
        ])->id,
        'type' => 'earn',
        'candle_cash_delta' => 11,
        'source' => 'order',
        'source_id' => 'signed-export:issued',
        'description' => 'Signed export issuance fixture',
        'created_at' => now()->subDays(5),
        'updated_at' => now()->subDays(5),
    ]);

    $signedUrl = URL::temporarySignedRoute('rewards.policy.exports.signed', now()->addMinutes(10), [
        'tenant' => $this->tenant->id,
        'type' => 'finance_summary',
        'date_from' => now()->subDays(30)->toDateString(),
        'date_to' => now()->toDateString(),
    ]);

    $response = $this->get($signedUrl);
    $rows = collect(parseRewardsCsv($response->streamedContent()));

    $response->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    expect($rows)->toHaveCount(1)
        ->and($rows->first())->toHaveKey('outstanding_liability_formatted')
        ->and($rows->first())->toHaveKey('realized_discount_value_formatted');
});

test('signed in tenant users respect rewards team access restrictions for publish and support actions', function () {
    $user = User::factory()->create([
        'name' => 'Marketing Manager',
        'role' => 'marketing_manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'marketing_manager']);
    $this->actingAs($user);

    app(TenantRewardsOperationsService::class)->persistConfig($this->tenant->id, [], [
        'edit_role' => 'marketing_manager_or_admin',
        'publish_role' => 'admin_only',
        'support_role' => 'admin_only',
    ]);

    $policyResponse = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy'));

    $policyResponse->assertOk()
        ->assertJsonPath('data.permissions.mode', 'app_user')
        ->assertJsonPath('data.permissions.current_user_role', 'marketing_manager')
        ->assertJsonPath('data.permissions.actions.edit.allowed', true)
        ->assertJsonPath('data.permissions.actions.publish.allowed', false)
        ->assertJsonPath('data.permissions.actions.support.allowed', false);

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->postJson(route('shopify.app.api.rewards.policy.review'), [
            'program_identity' => [
                'program_name' => 'Review Allowed',
            ],
        ])
        ->assertOk()
        ->assertJsonPath('ok', true);

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'program_identity' => [
                'program_name' => 'Blocked Publish',
            ],
        ])
        ->assertStatus(403)
        ->assertJsonPath('status', 'rewards_action_forbidden')
        ->assertJsonPath('message', 'Your team role cannot publish live rewards changes.');

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->postJson(route('shopify.app.api.rewards.policy.reminders.explain'), [
            'reward_identifier' => 'earned-bucket:tx:999',
            'reason' => 'Support review',
        ])
        ->assertStatus(403)
        ->assertJsonPath('status', 'rewards_action_forbidden')
        ->assertJsonPath('message', 'Your team role cannot use rewards reminder support tools.');
});

test('manual automation mode skips scheduled reminder processing during bulk runs', function () {
    Queue::fake();

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Manual',
        'last_name' => 'Bulk',
        'email' => 'manual.bulk@example.com',
        'normalized_email' => 'manual.bulk@example.com',
    ]);

    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'order',
        'source_id' => 'manual-bulk:fixture',
        'description' => 'Manual bulk fixture',
    ]);
    $transaction->forceFill([
        'created_at' => now()->subDays(29),
        'updated_at' => now()->subDays(29),
    ])->saveQuietly();

    app(TenantRewardsPolicyService::class)->update($this->tenant->id, [
        'expiration_and_reminders' => [
            'email_enabled' => true,
            'sms_enabled' => false,
            'expiration_mode' => 'days_from_issue',
            'expiration_days' => 30,
            'email_reminder_offsets_days' => [1],
            'reminder_offsets_days' => [1],
        ],
        'access_state' => [
            'launch_state' => 'published',
            'test_mode' => false,
        ],
    ], [
        'editable' => true,
        'sms_channel_enabled' => true,
    ]);

    app(TenantRewardsOperationsService::class)->persistConfig($this->tenant->id, [
        'automation_mode' => 'manual',
    ], []);

    $emailReadiness = Mockery::mock(MarketingEmailReadiness::class);
    $emailReadiness->shouldReceive('summary')->andReturn([
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    $emailReadiness->shouldReceive('providerContextForDelivery')->andReturn([
        'provider' => 'sendgrid',
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    app()->instance(MarketingEmailReadiness::class, $emailReadiness);

    $this->artisan('marketing:process-tenant-rewards-reminders', [
        '--queue' => true,
        '--batch-size' => 25,
    ])->assertExitCode(0);

    Queue::assertNothingPushed();

    $runtime = app(TenantRewardsOperationsService::class)->runtimeState($this->tenant->id);

    expect($runtime['last_run_at'] ?? null)->toBeNull();
});

test('manual automation mode still allows tenant-scoped operator reminder runs', function () {
    Queue::fake();

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Manual',
        'last_name' => 'Scoped',
        'email' => 'manual.scoped@example.com',
        'normalized_email' => 'manual.scoped@example.com',
    ]);

    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'order',
        'source_id' => 'manual-scoped:fixture',
        'description' => 'Manual scoped fixture',
    ]);
    $transaction->forceFill([
        'created_at' => now()->subDays(29),
        'updated_at' => now()->subDays(29),
    ])->saveQuietly();

    app(TenantRewardsPolicyService::class)->update($this->tenant->id, [
        'expiration_and_reminders' => [
            'email_enabled' => true,
            'sms_enabled' => false,
            'expiration_mode' => 'days_from_issue',
            'expiration_days' => 30,
            'email_reminder_offsets_days' => [1],
            'reminder_offsets_days' => [1],
        ],
        'access_state' => [
            'launch_state' => 'published',
            'test_mode' => false,
        ],
    ], [
        'editable' => true,
        'sms_channel_enabled' => true,
    ]);

    app(TenantRewardsOperationsService::class)->persistConfig($this->tenant->id, [
        'automation_mode' => 'manual',
    ], []);

    $emailReadiness = Mockery::mock(MarketingEmailReadiness::class);
    $emailReadiness->shouldReceive('summary')->andReturn([
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    $emailReadiness->shouldReceive('providerContextForDelivery')->andReturn([
        'provider' => 'sendgrid',
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    app()->instance(MarketingEmailReadiness::class, $emailReadiness);

    $this->artisan('marketing:process-tenant-rewards-reminders', [
        '--tenant' => $this->tenant->id,
        '--queue' => true,
        '--batch-size' => 25,
    ])->assertExitCode(0);

    Queue::assertPushed(DispatchTenantRewardsReminderJob::class, function (DispatchTenantRewardsReminderJob $job) use ($transaction): bool {
        return $job->tenantId === $this->tenant->id
            && $job->rewardIdentifier === 'earned-bucket:tx:'.$transaction->id
            && $job->channel === 'email'
            && $job->timingDaysBeforeExpiration === 1;
    });
});

test('automatic automation mode allows scheduled reminder processing to queue due reminders', function () {
    Queue::fake();

    $profile = MarketingProfile::query()->create([
        'tenant_id' => $this->tenant->id,
        'first_name' => 'Automatic',
        'last_name' => 'Queued',
        'email' => 'automatic.queued@example.com',
        'normalized_email' => 'automatic.queued@example.com',
    ]);

    $transaction = CandleCashTransaction::query()->create([
        'marketing_profile_id' => $profile->id,
        'type' => 'earn',
        'candle_cash_delta' => 10,
        'source' => 'order',
        'source_id' => 'automatic-queued:fixture',
        'description' => 'Automatic queued fixture',
    ]);
    $transaction->forceFill([
        'created_at' => now()->subDays(29),
        'updated_at' => now()->subDays(29),
    ])->saveQuietly();

    app(TenantRewardsPolicyService::class)->update($this->tenant->id, [
        'expiration_and_reminders' => [
            'email_enabled' => true,
            'sms_enabled' => false,
            'expiration_mode' => 'days_from_issue',
            'expiration_days' => 30,
            'email_reminder_offsets_days' => [1],
            'reminder_offsets_days' => [1],
        ],
        'access_state' => [
            'launch_state' => 'published',
            'test_mode' => false,
        ],
    ], [
        'editable' => true,
        'sms_channel_enabled' => true,
    ]);

    app(TenantRewardsOperationsService::class)->persistConfig($this->tenant->id, [
        'automation_mode' => 'automatic',
    ], []);

    $emailReadiness = Mockery::mock(MarketingEmailReadiness::class);
    $emailReadiness->shouldReceive('summary')->andReturn([
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    $emailReadiness->shouldReceive('providerContextForDelivery')->andReturn([
        'provider' => 'sendgrid',
        'can_send_live' => true,
        'notes' => [],
        'missing_requirements' => [],
    ]);
    app()->instance(MarketingEmailReadiness::class, $emailReadiness);

    $this->artisan('marketing:process-tenant-rewards-reminders', [
        '--queue' => true,
        '--batch-size' => 25,
    ])->assertExitCode(0);

    Queue::assertPushed(DispatchTenantRewardsReminderJob::class, function (DispatchTenantRewardsReminderJob $job) use ($transaction): bool {
        return $job->tenantId === $this->tenant->id
            && $job->rewardIdentifier === 'earned-bucket:tx:'.$transaction->id
            && $job->channel === 'email'
            && $job->timingDaysBeforeExpiration === 1;
    });
});

test('signed in tenant users can be blocked from switching automation mode separately from publishing', function () {
    $user = User::factory()->create([
        'name' => 'Tenant Manager',
        'role' => 'manager',
        'email_verified_at' => now(),
    ]);
    $user->tenants()->attach($this->tenant->id, ['role' => 'manager']);
    $this->actingAs($user);

    app(TenantRewardsOperationsService::class)->persistConfig($this->tenant->id, [], [
        'edit_role' => 'manager_or_admin',
        'publish_role' => 'manager_or_admin',
        'support_role' => 'admin_only',
        'automation_role' => 'admin_only',
    ]);

    $this
        ->withHeaders(retailRewardsApiHeaders())
        ->patchJson(route('shopify.app.api.rewards.policy.update'), [
            'automation_and_reporting' => [
                'automation_mode' => 'automatic',
            ],
        ])
        ->assertStatus(403)
        ->assertJsonPath('status', 'rewards_action_forbidden')
        ->assertJsonPath('message', 'Your team role cannot switch rewards automation mode.');
});

test('legacy automation mode values are normalized for backward compatibility', function () {
    $lastFailureAt = now()->subHours(2);

    app(TenantRewardsOperationsService::class)->persistConfig($this->tenant->id, [
        'automation_mode' => 'paused',
    ], [
        'automation_role' => 'admin_only',
    ]);

    app(TenantRewardsOperationsService::class)->storeRuntimeState($this->tenant->id, [
        'last_failure_at' => $lastFailureAt->toIso8601String(),
        'failure_count' => 3,
        'last_status' => 'error',
    ]);

    $response = $this
        ->withHeaders(retailRewardsApiHeaders())
        ->getJson(route('shopify.app.api.rewards.policy'));

    $response->assertOk()
        ->assertJsonPath('data.automation_and_reporting.automation_mode', 'manual')
        ->assertJsonPath('data.team_access.automation_role', 'admin_only')
        ->assertJsonPath('data.automation.automation_mode', 'manual')
        ->assertJsonPath('data.automation.last_failure_at', $lastFailureAt->toIso8601String());
});
