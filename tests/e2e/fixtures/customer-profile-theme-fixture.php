<?php

require dirname(__DIR__, 3).'/vendor/autoload.php';
$app = require dirname(__DIR__, 3).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! app()->environment('testing') || config('database.default') !== 'sqlite'
    || basename((string) config('database.connections.sqlite.database')) !== 'customer-profile-theme.sqlite') {
    exit(90);
}
$tenant = App\Models\Tenant::create(['name' => 'Fictional customer shop', 'slug' => 'modern-forestry']);
$owner = App\Models\User::factory()->create(['name' => 'Fictional Owner', 'email' => 'customer-profile@example.test',
    'password' => Illuminate\Support\Facades\Hash::make('Fictional-profile-2026!'), 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
$owner->tenants()->attach($tenant, ['role' => 'owner', 'membership_active' => true]);
App\Models\TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'messaging',
    'availability_status' => 'available', 'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan',
    'entitlement_source' => 'test', 'price_source' => 'catalog']);
$profile = App\Models\MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Customer', 'email' => 'buyer@example.test']);
App\Models\TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => 'candle_cash_program_config', 'value' => ['label' => 'Candle Cash']]);
App\Models\CandleCashBalance::create(['marketing_profile_id' => $profile->id, 'balance' => 7.25]);
App\Models\CandleCashTransaction::create(['marketing_profile_id' => $profile->id, 'type' => 'earn', 'points' => 7, 'candle_cash_delta' => 7.25, 'source' => 'admin']);
app(App\Services\Marketing\BirthdayProfileService::class)->captureForProfile($profile, ['birth_month' => 2, 'birth_day' => 29, 'source' => 'customer_signup']);
App\Models\SquareCustomer::create(['tenant_id' => $tenant->id, 'square_customer_id' => 'fictional-source-customer', 'given_name' => 'Fictional', 'family_name' => 'Customer', 'email' => $profile->email]);
App\Models\SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => 'fictional-source-order', 'state' => 'COMPLETED', 'closed_at' => now()->subDay(),
    'location_id' => 'fictional-location', 'total_money_amount' => 1240, 'total_money_currency' => 'USD']);
App\Models\SquarePayment::create(['tenant_id' => $tenant->id, 'square_payment_id' => 'fictional-source-payment', 'square_order_id' => 'fictional-source-order',
    'status' => 'COMPLETED', 'amount_money' => 1240, 'currency' => 'USD', 'location_id' => 'fictional-location', 'created_at_source' => now()->subDay()]);
foreach (['square_customer' => 'fictional-source-customer', 'square_order' => 'fictional-source-order', 'square_payment' => 'fictional-source-payment'] as $type => $id) {
    App\Models\MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => $type, 'source_id' => $id]);
}
echo json_encode(['tenant' => $tenant->slug, 'tenant_id' => $tenant->id, 'profile_id' => $profile->id]);
