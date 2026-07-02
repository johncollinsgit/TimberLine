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

test('authenticated mobile candle club action records one vote per active contract', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $profile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'club-app@example.com',
        'normalized_email' => 'club-app@example.com',
    ]);

    DB::table('subscription_contracts')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/app-vote',
        'shopify_customer_gid' => 'gid://shopify/Customer/app-vote',
        'status' => 'active',
        'is_candle_club' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $pollId = DB::table('subscription_polls')->insertGetId([
        'tenant_id' => $tenant->id,
        'poll_type' => SubscriptionModuleService::CANDLE_CLUB_TYPE,
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
    $result = $service->recordCustomerCandleClubAction($profile, 'vote_for_next_month', [
        'poll_id' => $pollId,
        'option_id' => $optionId,
    ]);

    $payload = $service->customerCandleClubPayload($profile);
    $duplicate = $service->recordCustomerCandleClubAction($profile, 'vote_for_next_month', [
        'poll_id' => $pollId,
        'option_id' => $optionId,
    ]);

    expect($result['ok'])->toBeTrue()
        ->and($result['status'])->toBe('vote_recorded')
        ->and(DB::table('subscription_votes')->where('tenant_id', $tenant->id)->where('subscription_poll_id', $pollId)->count())->toBe(1)
        ->and(data_get($payload, 'active_poll.already_voted'))->toBeTrue()
        ->and(data_get($payload, 'vote_history.0.option_label'))->toBe('Coffeehouse')
        ->and($duplicate['ok'])->toBeFalse()
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

test('active candle club payload exposes commitment menus shipping and payment summaries', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $profile = MarketingProfile::factory()->create([
        'tenant_id' => $tenant->id,
        'email' => 'member@example.com',
        'normalized_email' => 'member@example.com',
    ]);

    DB::table('subscription_candle_club_settings')->insert([
        'tenant_id' => $tenant->id,
        'commitment_months' => 6,
        'allowed_pauses_per_commitment' => 2,
        'pause_duration_options' => json_encode([1, 2], JSON_THROW_ON_ERROR),
        'cancellation_prompt' => 'How can we keep you?',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customerId = DB::table('subscription_customers')->insertGetId([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'shopify_customer_gid' => 'gid://shopify/Customer/1',
        'email' => 'member@example.com',
        'normalized_email' => 'member@example.com',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('subscription_payment_methods')->insert([
        'tenant_id' => $tenant->id,
        'subscription_customer_id' => $customerId,
        'shopify_payment_method_gid' => 'gid://shopify/CustomerPaymentMethod/1',
        'status' => 'active',
        'brand' => 'Visa',
        'last_digits' => '4242',
        'expiry_month' => '12',
        'expiry_year' => '2028',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $contractId = DB::table('subscription_contracts')->insertGetId([
        'tenant_id' => $tenant->id,
        'subscription_customer_id' => $customerId,
        'marketing_profile_id' => $profile->id,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/1',
        'shopify_customer_gid' => 'gid://shopify/Customer/1',
        'shopify_payment_method_gid' => 'gid://shopify/CustomerPaymentMethod/1',
        'status' => 'active',
        'is_candle_club' => true,
        'completed_cycles' => 4,
        'pause_count_current_commitment' => 1,
        'commitment_ends_on' => now()->addMonths(2)->toDateString(),
        'shipping_address' => json_encode(['address1' => '123 Forest Ln', 'city' => 'Asheville'], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('subscription_contract_lines')->insert([
        'tenant_id' => $tenant->id,
        'subscription_contract_id' => $contractId,
        'shopify_product_variant_gid' => 'gid://shopify/ProductVariant/16',
        'product_title' => 'Coffeehouse 16oz Candle',
        'variant_title' => '16oz',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $payload = app(SubscriptionModuleService::class)->customerCandleClubPayload($profile);

    expect($payload['eligible'])->toBeTrue()
        ->and(data_get($payload, 'commitment.allowed_pauses'))->toBe(2)
        ->and(data_get($payload, 'commitment.pauses_remaining'))->toBe(1)
        ->and(data_get($payload, 'payment_method.last_digits'))->toBe('4242')
        ->and(data_get($payload, 'shipping_address.address1'))->toBe('123 Forest Ln')
        ->and(data_get($payload, 'action_menus.pause_duration_options'))->toBe([1, 2])
        ->and(data_get($payload, 'action_menus.swap_options.0.product_variant_gid'))->toBe('gid://shopify/ProductVariant/16');
});

test('pause action validates pause allowance and records structured payload', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id]);

    DB::table('subscription_candle_club_settings')->insert([
        'tenant_id' => $tenant->id,
        'commitment_months' => 6,
        'allowed_pauses_per_commitment' => 2,
        'pause_duration_options' => json_encode([1, 2], JSON_THROW_ON_ERROR),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('subscription_contracts')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/2',
        'status' => 'active',
        'is_candle_club' => true,
        'pause_count_current_commitment' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $result = app(SubscriptionModuleService::class)->recordCustomerCandleClubAction($profile, 'pause', [
        'duration_months' => 2,
    ]);

    $event = DB::table('subscription_lifecycle_events')->first();

    expect($result['ok'])->toBeTrue()
        ->and($event->event_type)->toBe('pause')
        ->and(data_get(json_decode((string) $event->after_payload, true), 'duration_months'))->toBe(2);
});

test('candle club scent feedback exports to native reviews as pending', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id]);

    DB::table('subscription_contracts')->insert([
        'tenant_id' => $tenant->id,
        'marketing_profile_id' => $profile->id,
        'shopify_subscription_contract_gid' => 'gid://shopify/SubscriptionContract/3',
        'status' => 'active',
        'is_candle_club' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $monthlyScentId = DB::table('subscription_candle_club_monthly_scents')->insertGetId([
        'tenant_id' => $tenant->id,
        'month' => 7,
        'year' => 2026,
        'title' => 'Coffeehouse',
        'description' => 'Espresso and warm woods.',
        'shopify_product_gid' => 'gid://shopify/Product/77',
        'shopify_product_handle' => 'coffeehouse-candle-club',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $feedback = app(SubscriptionModuleService::class)->submitCandleClubScentFeedback($profile, $monthlyScentId, [
        'rating' => 5,
        'title' => 'Loved it',
        'body' => 'This should become a regular scent.',
    ]);

    $export = app(SubscriptionModuleService::class)->exportCandleClubScentFeedback((int) $tenant->id, (int) $feedback['id']);

    expect($feedback['ok'])->toBeTrue()
        ->and($export['ok'])->toBeTrue()
        ->and(DB::table('marketing_review_histories')->where('integration', 'candle_club')->value('status'))->toBe('pending')
        ->and(DB::table('subscription_candle_club_scent_feedback')->where('id', $feedback['id'])->value('status'))->toBe('exported');
});

test('candle club recipe import keeps oils internal and member cards recipe free', function (): void {
    $tenant = Tenant::query()->create([
        'id' => 1,
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $this->artisan('subscriptions:import-candle-club-recipes', [
        '--tenant' => $tenant->id,
        '--apply' => true,
        '--limit' => 1,
    ])->assertExitCode(0);

    $scent = DB::table('scents')->where('display_name', 'Rose Champagne')->first();
    $monthly = DB::table('subscription_candle_club_monthly_scents')
        ->where('tenant_id', $tenant->id)
        ->where('year', 2021)
        ->where('month', 10)
        ->first();

    expect($scent)->not->toBeNull()
        ->and($monthly)->not->toBeNull()
        ->and((string) $scent->oil_reference_name)->toContain('Love Spell')
        ->and((string) $monthly->description)->toContain('Candle Club exclusive')
        ->and((string) $monthly->description)->not->toContain('Love Spell')
        ->and((string) $monthly->description)->not->toContain('Rose Petal Gelato');

    $cards = app(SubscriptionModuleService::class)->adminPayload((int) $tenant->id)['monthly_scents'];

    expect(data_get($cards, '0.title'))->toBe('Rose Champagne')
        ->and(data_get($cards, '0.body'))->not->toContain('Love Spell')
        ->and(data_get($cards, '0.body'))->not->toContain('Rose Petal Gelato');
});

test('candle club recipe import is strict about modern forestry tenant one', function (): void {
    Tenant::query()->create([
        'id' => 1,
        'name' => 'Different Tenant',
        'slug' => 'different-tenant',
    ]);

    $modernForestry = Tenant::query()->create([
        'id' => 5,
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
    ]);

    $this->artisan('subscriptions:import-candle-club-recipes', [
        '--tenant' => $modernForestry->id,
        '--limit' => 1,
    ])->assertExitCode(1);

    $this->artisan('subscriptions:import-candle-club-recipes', [
        '--tenant' => $modernForestry->id,
        '--allow-nonstandard-tenant' => true,
        '--limit' => 1,
    ])->assertExitCode(0);
});
