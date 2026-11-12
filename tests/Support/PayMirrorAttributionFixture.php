<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\MerchantBinding;
use App\Services\PaymentsCore\PaymentJournal;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\Pos\PosStore;
use App\Services\Pos\StockPosCommerce;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

function payMirrorAttributionFixture(string $sourceType, bool $hasProviderOrder = true, string $state = 'succeeded', bool $selected = false, bool $anonymousRewards = false, array $earningOverrides = [], ?Closure $beforeRewards = null): array
{
    $tenant = Tenant::create(['name' => 'Fictional mirror shop', 'slug' => 'pay-mirror-'.Str::uuid()]);
    $actor = User::factory()->create(['email' => 'pay-mirror-'.Str::uuid().'@example.invalid', 'is_active' => true, 'email_verified_at' => now()]);
    $actor->tenants()->attach($tenant, ['role' => 'admin', 'membership_active' => true]);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Provider suggested person']);
    $store = app(PosStore::class);
    $saleId = bin2hex(random_bytes(16));
    $register = bin2hex(random_bytes(16));
    $request = (string) Str::uuid();
    $operationId = bin2hex(random_bytes(16));
    $orderId = 'original-order-'.$saleId;
    $paymentId = 'original-payment-'.$saleId;
    $binding = (new MerchantBinding($tenant->id, $register, 'square_native', 'fictional-merchant', 'fictional-location', 'live', $store->runtime()))->snapshot();
    $financial = (new SaleSnapshot($saleId, 'usd', [['line_id' => 'line', 'kind' => 'merchandise', 'quantity' => 1, 'unit_amount_minor' => 1000, 'tax_amount_minor' => 80]]))->snapshot();
    $registerConfig = [];
    $rewardTerms = ['enabled' => false];
    if ($anonymousRewards) {
        $program = ['redeem_increment_dollars' => 10];
        $policy = ['earning_rules' => array_replace(['first_order_reward_amount' => 5, 'event_rewards_enabled' => true,
            'enable_milestone_rewards' => true, 'enable_order_based_earning' => true, 'rewardable_channels' => 'show_issued_online_redeemed'], $earningOverrides)];
        foreach (['candle_cash_program_config' => $program, 'candle_cash_policy_config' => $policy, 'candle_cash_access_state' => ['launch_state' => 'published', 'test_mode' => false]] as $key => $value) {
            \App\Models\TenantMarketingSetting::create(['tenant_id' => $tenant->id, 'key' => $key, 'value' => $value]);
        }
        if ($beforeRewards) {
            $beforeRewards($tenant, $profile);
        }
        $registerConfig['customer_rewards'] = ['enabled' => true, 'threshold_mode' => 'highest_per_purchase',
            'policy_version' => app(\App\Services\Pos\PosCustomerRewards::class)->policy($tenant->id)['version']];
        $rewardTerms = app(\App\Services\Pos\PosCustomerRewards::class)->freeze(['tenant_id' => $tenant->id,
            'register' => (object) ['configuration' => $store->encrypt($registerConfig)]], $selected ? ['id' => $profile->id] : null);
    }
    $snapshot = ['binding' => $binding, 'sale' => $financial, 'customer' => $selected ? ['id' => $profile->id] : null, 'register_name' => 'Reviewed register', 'location_name' => 'Reviewed venue',
        'configuration' => $registerConfig, 'customer_rewards' => $rewardTerms, 'terms' => ['display' => [['item_id' => 'fixture-candle', 'title' => 'Fictional candle', 'quantity' => 1]], 'total_minor' => 1080, 'subtotal_minor' => 1000, 'tax_minor' => 80]];
    $store->rows('sales', $tenant->id)->insert($store->values($tenant->id, ['id' => $saleId, 'register_id' => $register, 'location_id' => 'fictional-location',
        'actor_id' => $actor->id, 'request_id' => (string) Str::uuid(), 'payment_request_id' => $request, 'sdk_attempt_id' => (string) Str::uuid(),
        'snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => Contract::hash($snapshot), 'amount_minor' => 1080, 'currency' => 'usd',
        'commerce_source' => StockPosCommerce::VERSION, 'provider_order_id' => $hasProviderOrder ? $orderId : null, 'state' => $state, 'attempt_id' => $operationId,
        'stock_state' => 'committed', 'created_at' => now()->subHour(), 'expires_at' => now()->addHour()]));
    $bindingId = DB::table('payment_core_bindings')->insertGetId(['tenant_id' => $tenant->id, 'register_id' => $register, 'fingerprint' => Contract::hash($binding), 'binding' => $store->encrypt($binding)]);
    DB::table('payment_core_operations')->insert(['id' => $operationId, 'tenant_id' => $tenant->id, 'binding_id' => $bindingId, 'register_id' => $register,
        'sale_id' => $saleId, 'payment_sale_key' => $saleId, 'request_id' => $request, 'kind' => 'payment', 'terms_hash' => Contract::hash([$binding, $financial]),
        'binding' => $store->encrypt($binding), 'terms' => $store->encrypt($financial), 'amount_minor' => 1080, 'currency' => 'usd', 'state' => $state, 'provider_id' => $state === 'succeeded' ? $paymentId : null,
        'effect_scope' => Contract::hash(['square_native', 'fictional-merchant', 'live'])]);
    $operation = ['id' => $operationId, 'kind' => 'payment', 'state' => 'succeeded', 'sale_id' => $saleId, 'request_id' => $request, 'binding' => $binding,
        'amount_minor' => 1080, 'currency' => 'usd', 'provider_id' => $paymentId];
    $journal = Mockery::mock(PaymentJournal::class);
    $journal->shouldReceive('get')->with($tenant->id, $operationId)->andReturn($operation);
    app()->instance(PaymentJournal::class, $journal);
    if ($sourceType === 'square_order') {
        SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => $orderId, 'state' => 'COMPLETED', 'closed_at' => now()->subHour(),
            'location_id' => 'fictional-location', 'total_money_amount' => 1080, 'total_money_currency' => 'USD']);
    }
    SquarePayment::create(['tenant_id' => $tenant->id, 'square_payment_id' => $paymentId, 'square_order_id' => $orderId, 'status' => 'COMPLETED',
        'amount_money' => 1080, 'currency' => 'USD', 'location_id' => 'fictional-location', 'source_type' => 'CARD', 'created_at_source' => now()->subHour(),
        'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => $sourceType,
        'source_id' => $sourceType === 'square_order' ? $orderId : $paymentId, 'source_meta' => ['original_provider_customer_association' => true]]);

    return compact('tenant', 'actor', 'profile', 'store', 'saleId', 'orderId', 'paymentId', 'operationId', 'snapshot', 'operation', 'register', 'registerConfig');
}
