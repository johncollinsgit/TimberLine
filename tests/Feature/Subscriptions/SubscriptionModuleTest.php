<?php

use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantModuleEntitlement;
use App\Services\Shopify\ShopifyEmbeddedPageRegistry;
use App\Services\Subscriptions\SubscriptionModuleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../ShopifyEmbeddedTestHelpers.php';

test('subscriptions module is a paid shopify add-on with an embedded app link', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    configureEmbeddedRetailStore((int) $tenant->id);

    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id,
        'module_key' => 'subscriptions',
        'availability_status' => 'available',
        'enabled_status' => 'enabled',
        'billing_status' => 'add_on_comped',
        'entitlement_source' => 'test',
        'price_source' => 'test',
    ]);

    $page = collect(app(ShopifyEmbeddedPageRegistry::class)->pages())
        ->firstWhere('key', 'subscriptions');

    expect(config('module_catalog.modules.subscriptions.billing_mode'))->toBe('add_on')
        ->and(config('module_catalog.addons.subscriptions.modules'))->toBe(['subscriptions'])
        ->and($page['route_name'])->toBe('shopify.app.subscriptions');

    $this->get(route('home', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSee('href="/shopify/app/subscriptions?shop=', false);

    $this->get(route('shopify.app.subscriptions', retailEmbeddedSignedQuery()))
        ->assertOk()
        ->assertSeeText('Subscriptions');
});

test('migration dry-run is local only and cutover requires recharge billing pause confirmation', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $service = app(SubscriptionModuleService::class);
    $dryRun = $service->createMigrationDryRun((int) $tenant->id, null, [[
        'id' => 'recharge-sub-1',
        'customer' => ['email' => 'club@example.com'],
        'shopify_product_variant_gid' => 'gid://shopify/ProductVariant/1',
        'shopify_selling_plan_gid' => 'gid://shopify/SellingPlan/1',
        'product_title' => 'Candle Club',
        'status' => 'active',
    ]]);

    expect($dryRun['status'])->toBe('ready_for_cutover')
        ->and((int) $dryRun['summary']['valid_rows'])->toBe(1)
        ->and(DB::table('subscription_contracts')->where('tenant_id', $tenant->id)->count())->toBe(0);

    $blocked = $service->approveCutover((int) $tenant->id, (int) $dryRun['id'], null, false);
    expect($blocked['ok'])->toBeFalse()
        ->and($blocked['status'])->toBe('recharge_billing_not_paused');

    $approved = $service->approveCutover((int) $tenant->id, (int) $dryRun['id'], null, true);
    expect($approved['ok'])->toBeTrue()
        ->and($approved['status'])->toBe('approved')
        ->and(DB::table('subscription_module_settings')
            ->where('tenant_id', $tenant->id)
            ->where('billing_scheduler_enabled', false)
            ->exists())->toBeTrue();
});

test('facebook style otp voting allows one active candle club vote per shopify contract', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $profile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'club@example.com',
        'normalized_email' => 'club@example.com',
    ]);

    $customerId = DB::table('subscription_customers')->insertGetId([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'shopify_customer_gid' => 'gid://shopify/Customer/1',
        'email' => 'club@example.com',
        'normalized_email' => 'club@example.com',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contractId = DB::table('subscription_contracts')->insertGetId([
        'tenant_id' => $tenant->id,
        'subscription_customer_id' => $customerId,
        'marketing_profile_id' => $profile->id,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/1',
        'shopify_customer_gid' => 'gid://shopify/Customer/1',
        'status' => 'active',
        'is_candle_club' => true,
        'metadata' => json_encode(['normalized_email' => 'club@example.com'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pollId = DB::table('subscription_polls')->insertGetId([
        'tenant_id' => $tenant->id,
        'title' => 'Vote for next month',
        'status' => 'open',
        'opens_at' => now()->subMinute(),
        'closes_at' => now()->addDay(),
        'share_token' => Str::random(40),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $optionId = DB::table('subscription_poll_options')->insertGetId([
        'tenant_id' => $tenant->id,
        'subscription_poll_id' => $pollId,
        'label' => 'Coffeehouse',
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service = app(SubscriptionModuleService::class);
    $code = $service->requestVoteCode((int) $tenant->id, $pollId, 'club@example.com', 'facebook');

    expect($code['ok'])->toBeTrue()
        ->and($code['debug_code'])->not->toBeEmpty();

    $vote = $service->castVoteWithCode(
        (int) $tenant->id,
        $pollId,
        $optionId,
        (int) $code['verification_token_id'],
        (string) $code['debug_code'],
        'facebook'
    );

    expect($vote['ok'])->toBeTrue()
        ->and(DB::table('subscription_votes')
            ->where('tenant_id', $tenant->id)
            ->where('subscription_poll_id', $pollId)
            ->where('subscription_contract_id', $contractId)
            ->count())->toBe(1);

    $secondCode = $service->requestVoteCode((int) $tenant->id, $pollId, 'club@example.com', 'facebook');
    $duplicate = $service->castVoteWithCode(
        (int) $tenant->id,
        $pollId,
        $optionId,
        (int) $secondCode['verification_token_id'],
        (string) $secondCode['debug_code'],
        'facebook'
    );

    expect($duplicate['ok'])->toBeFalse()
        ->and($duplicate['status'])->toBe('already_voted');
});

test('storefront poll payload reads the same active candle club poll', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $pollId = DB::table('subscription_polls')->insertGetId([
        'tenant_id' => $tenant->id,
        'title' => 'Vote for the next scent',
        'status' => 'open',
        'opens_at' => now()->subMinute(),
        'closes_at' => now()->addDay(),
        'share_token' => Str::random(40),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('subscription_poll_options')->insert([
        'tenant_id' => $tenant->id,
        'subscription_poll_id' => $pollId,
        'label' => 'Coffeehouse',
        'position' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = app(SubscriptionModuleService::class)->storefrontPollPayload((int) $tenant->id);

    expect(data_get($payload, 'eligible_poll.id'))->toBe($pollId)
        ->and(data_get($payload, 'eligible_poll.options.0.label'))->toBe('Coffeehouse')
        ->and(data_get($payload, 'verification.required'))->toBeTrue();
});

test('paused or cancelled candle club contracts cannot request a voting code', function (string $status): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $customerId = DB::table('subscription_customers')->insertGetId([
        'tenant_id' => $tenant->id,
        'email' => 'paused@example.com',
        'normalized_email' => 'paused@example.com',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('subscription_contracts')->insert([
        'tenant_id' => $tenant->id,
        'subscription_customer_id' => $customerId,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/'.$status,
        'status' => $status,
        'is_candle_club' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pollId = DB::table('subscription_polls')->insertGetId([
        'tenant_id' => $tenant->id,
        'title' => 'Vote for next month',
        'status' => 'open',
        'opens_at' => now()->subMinute(),
        'closes_at' => now()->addDay(),
        'share_token' => Str::random(40),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(SubscriptionModuleService::class)->requestVoteCode((int) $tenant->id, $pollId, 'paused@example.com', 'facebook');

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe('not_eligible');
})->with(['paused', 'cancelled', 'failed']);

test('john preview account gets unlocked candle club menus while normal accounts stay locked', function (): void {
    $tenant = Tenant::query()->create([
        'id' => 1,
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $john = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'johncollinesmail@gmail.com',
        'normalized_email' => 'johncollinesmail@gmail.com',
    ]);

    $normal = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'not-club@example.com',
        'normalized_email' => 'not-club@example.com',
    ]);

    $service = app(SubscriptionModuleService::class);
    $preview = $service->customerCandleClubPayload($john);
    $locked = $service->customerCandleClubPayload($normal);

    expect($preview['eligible'])->toBeTrue()
        ->and($preview['preview'])->toBeTrue()
        ->and(data_get($preview, 'contract.status'))->toBe('active')
        ->and(data_get($preview, 'active_poll.status'))->toBe('open')
        ->and(data_get($preview, 'actions.can_vote'))->toBeTrue()
        ->and(data_get($preview, 'actions.can_pause'))->toBeTrue()
        ->and(data_get($preview, 'actions.can_cancel'))->toBeTrue()
        ->and(data_get($preview, 'actions.can_update_address'))->toBeTrue()
        ->and(data_get($preview, 'actions.can_update_card'))->toBeTrue()
        ->and(data_get($preview, 'actions.can_swap_to_active_16oz_scent'))->toBeTrue()
        ->and($locked['eligible'])->toBeFalse()
        ->and(data_get($locked, 'actions.can_vote'))->toBeFalse()
        ->and(data_get($locked, 'actions.can_pause'))->toBeFalse();
});

test('john preview profile records mobile candle club actions as shopify preview intents', function (): void {
    $tenant = Tenant::query()->create([
        'id' => 1,
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $john = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'johncollinsemail@gmail.com',
        'normalized_email' => 'johncollinsemail@gmail.com',
    ]);

    $result = app(SubscriptionModuleService::class)->recordCustomerCandleClubAction($john, 'update_payment_card', [
        'action' => 'update_payment_card',
        'source' => 'ios_app',
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('preview_recorded')
        ->and($result['action'])->toBe('send_payment_update_email')
        ->and($result['shopify_mutation'])->toBe('customerPaymentMethodSendUpdateEmail')
        ->and(data_get($result, 'candle_club.preview'))->toBeTrue();

    $event = DB::table('subscription_lifecycle_events')->first();

    expect($event)->not->toBeNull()
        ->and($event->subscription_contract_id)->toBeNull()
        ->and($event->event_type)->toBe('send_payment_update_email')
        ->and($event->source)->toBe('mobile_app')
        ->and($event->status)->toBe('shopify_preview_recorded')
        ->and(data_get(json_decode((string) $event->metadata, true), 'shopify_mode'))->toBe('preview_no_live_mutation');
});

test('non candle club profile cannot record mobile candle club actions', function (): void {
    $tenant = Tenant::query()->create([
        'id' => 1,
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $profile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'not-club@example.com',
        'normalized_email' => 'not-club@example.com',
    ]);

    $result = app(SubscriptionModuleService::class)->recordCustomerCandleClubAction($profile, 'pause', [
        'action' => 'pause',
        'source' => 'ios_app',
    ]);

    expect($result['ok'])->toBeFalse()
        ->and($result['status'])->toBe('not_eligible')
        ->and(data_get($result, 'candle_club.eligible'))->toBeFalse()
        ->and(DB::table('subscription_lifecycle_events')->count())->toBe(0);
});
