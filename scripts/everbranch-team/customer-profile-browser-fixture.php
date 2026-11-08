<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(static function (Throwable $error): never {
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
});
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || ! str_contains((string) config('database.connections.sqlite.database'), '/codex-customer-profile-')) {
    throw new RuntimeException('Private disposable customer-profile browser SQLite only.');
}
Illuminate\Support\Facades\Http::preventStrayRequests();
Illuminate\Support\Facades\Mail::fake();
if (($argv[1] ?? 'seed') === 'report') {
    $tenant = App\Models\Tenant::where('slug', 'fictional-customer-browser')->firstOrFail();
    $profile = App\Models\MarketingProfile::forTenantId($tenant->id)->where('normalized_email', 'ada@customer-fixture.invalid')->firstOrFail();
    $preferences = app(App\Services\Marketing\CustomerPreferenceService::class)->forStaff($profile, $tenant->id);
    echo json_encode(['receipts' => $preferences['receipts'], 'declared' => $preferences['declared'], 'preference_revision' => $preferences['revision'],
        'email_consent' => $profile->accepts_email_marketing, 'sms_consent' => $profile->accepts_sms_marketing,
        'campaign_count' => App\Models\MarketingCampaign::forTenantId($tenant->id)->count(),
        'approval_count' => App\Models\MarketingSendApproval::count(),
        'email_delivery_count' => App\Models\MarketingEmailDelivery::count(), 'sms_delivery_count' => App\Models\MarketingMessageDelivery::count(),
        'consent_event_count' => App\Models\MarketingConsentEvent::count(),
        'email_dispatch_enabled' => config('marketing.customer_profile.email_dispatch_enabled'),
        'sms_dispatch_enabled' => config('marketing.customer_profile.sms_dispatch_enabled')], JSON_THROW_ON_ERROR);
    exit;
}
$tenant = App\Models\Tenant::create(['name' => 'Fictional Customer Shop', 'slug' => 'fictional-customer-browser']);
App\Models\TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'campaigns', 'availability_status' => 'available',
    'enabled_status' => 'enabled', 'billing_status' => 'add_on_comped', 'entitlement_source' => 'fictional_browser_fixture']);
foreach (['owner' => 'owner', 'member' => 'member'] as $name => $role) {
    $user = App\Models\User::factory()->create(['name' => 'Fictional '.ucfirst($name), 'email' => $name.'@customer-fixture.invalid',
        'password' => Illuminate\Support\Facades\Hash::make('Fictional-local-only-2026!'), 'role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($user, ['role' => $role, 'membership_active' => true]);
}
$profile = App\Models\MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Ada', 'last_name' => 'Browser',
    'email' => 'ada@customer-fixture.invalid', 'normalized_email' => 'ada@customer-fixture.invalid', 'phone' => '(864) 555-0101',
    'normalized_phone' => '+18645550101', 'accepts_email_marketing' => false, 'accepts_sms_marketing' => false,
    'notes' => 'PRIVATE marketing-only fixture note']);
foreach ([['Fictional Pine', 3, '60.00'], ['Fictional Cedar', 1, '20.00']] as $index => [$name, $quantity, $amount]) {
    $scent = App\Models\Scent::create(['name' => $name]);
    $order = App\Models\Order::factory()->create(['tenant_id' => $tenant->id, 'shopify_order_id' => 700001 + $index,
        'shopify_store_key' => 'fictional-browser', 'customer_email' => $profile->email, 'customer_name' => 'Ada Browser', 'attribution_meta' => []]);
    $order->update(['attribution_meta' => ['customer_commerce' => ['tenant_id' => $tenant->id, 'shopify_order_id' => (string) $order->shopify_order_id,
        'store_key' => 'fictional-browser', 'financial_status' => 'paid', 'source_name' => 'web', 'total_price' => $amount,
        'refund_total' => '0.00', 'currency' => 'USD']]]);
    App\Models\MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'order', 'source_id' => (string) $order->id]);
    App\Models\OrderLine::create(['order_id' => $order->id, 'scent_id' => $scent->id, 'scent_name' => $scent->name,
        'raw_title' => $name.' candle', 'shopify_product_id' => 81001 + $index, 'quantity' => $quantity, 'ordered_qty' => $quantity]);
}
$foreign = App\Models\Tenant::create(['name' => 'Fictional Foreign Shop', 'slug' => 'fictional-foreign-browser']);
App\Models\TenantAccessProfile::create(['tenant_id' => $foreign->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
$other = App\Models\MarketingProfile::create(['tenant_id' => $foreign->id, 'first_name' => 'Foreign Private Browser Customer']);
echo json_encode(['tenant' => $tenant->slug, 'profile_id' => $profile->id, 'foreign_tenant' => $foreign->slug,
    'foreign_profile_id' => $other->id], JSON_THROW_ON_ERROR);
