<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingProfileLink;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\PaymentsCore\Contract;
use App\Services\PaymentsCore\SaleSnapshot;
use App\Services\Pos\PosCustomerRewards;
use App\Services\Pos\PosReceiptCustomers;
use App\Services\Pos\PosService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(Tests\Support\PosLiveTestCase::class);
require_once __DIR__.'/../Support/PayMirrorAttributionFixture.php';

beforeEach(function (): void {
    Http::preventStrayRequests();
    config(['pos_live.runtime_id' => 'pay_mirror_fixture', 'cache.default' => 'array']);
});

function payMirrorReceiptScope(array $fixture): array
{
    return ['tenant_id' => $fixture['tenant']->id, 'actor_id' => $fixture['actor']->id, 'role' => 'admin',
        'register' => (object) ['id' => $fixture['register'], 'catalog_source' => 'native', 'configuration' => $fixture['store']->encrypt($fixture['registerConfig'])]];
}

it('attributes a guest receipt after late Square identification and earns its original policy once without treating itself as prior history', function (string $sourceType): void {
    $fixture = payMirrorAttributionFixture($sourceType, true, 'succeeded', false, true);
    extract($fixture);
    SquarePayment::forTenantId($tenant->id)->where('square_payment_id', $paymentId)->update(['raw_payload' => []]);
    $sale = $store->rows('sales', $tenant->id)->where('id', $saleId)->firstOrFail();
    $scope = payMirrorReceiptScope($fixture);
    $customers = app(PosReceiptCustomers::class);
    $input = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $profile->id];
    expect(app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant->id)['confirmed_purchase_count'])->toBe(0);

    $first = $customers->attach($scope, $sale, $snapshot, $input);
    $again = $customers->attach($scope, $sale, $snapshot, $input);
    $decorated = $customers->decorate($sale, app(PosService::class)->snapshot($sale));
    expect($first)->toBe($again)->and($first['associated'])->toBeTrue()
        ->and($decorated['customer_rewards']['anonymous'])->toBeFalse()
        ->and($decorated['customer_rewards']['historical_review_required'])->toBeFalse()
        ->and($decorated['customer_rewards']['order_number'])->toBe(1)
        ->and($decorated['customer_rewards']['member'])->toBeFalse()
        ->and(CandleCashBalance::where('marketing_profile_id', $profile->id)->value('balance'))->toBe(5.0)
        ->and(CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('source', 'everbranch_pay')->count())->toBe(1);
    app(PosCustomerRewards::class)->apply($sale);
    expect(CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('source', 'everbranch_pay')->count())->toBe(1)
        ->and($store->rows('sales', $tenant->id)->where('id', $saleId)->value('snapshot_hash'))->toBe(Contract::hash($snapshot))
        ->and(app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant->id)['confirmed_purchase_count'])->toBe(1);
    Http::assertNothingSent();
})->with(['square_order', 'square_payment']);

it('retains another prior conflicting Pay mirror as historical identity review while excluding only the current receipt', function (): void {
    $fixture = payMirrorAttributionFixture('square_order', true, 'succeeded', false, true);
    extract($fixture);
    $priorId = bin2hex(random_bytes(16));
    $priorOrder = 'prior-order-'.$priorId;
    $prior = $snapshot;
    $prior['sale'] = (new SaleSnapshot($priorId, 'usd', $snapshot['sale']['lines']))->snapshot();
    $store->rows('sales', $tenant->id)->insert($store->values($tenant->id, ['id' => $priorId, 'register_id' => $register, 'location_id' => 'fictional-location',
        'actor_id' => $actor->id, 'request_id' => (string) Str::uuid(), 'payment_request_id' => (string) Str::uuid(), 'sdk_attempt_id' => (string) Str::uuid(),
        'snapshot' => $store->encrypt($prior), 'snapshot_hash' => Contract::hash($prior), 'amount_minor' => 1080, 'currency' => 'usd',
        'commerce_source' => $store->rows('sales', $tenant->id)->where('id', $saleId)->value('commerce_source'), 'provider_order_id' => $priorOrder,
        'state' => 'succeeded', 'stock_state' => 'committed', 'created_at' => now()->subDay(), 'expires_at' => now()->addHour()]));
    SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => $priorOrder, 'state' => 'COMPLETED', 'closed_at' => now()->subDay(),
        'location_id' => 'fictional-location', 'total_money_amount' => 1080, 'total_money_currency' => 'USD']);
    SquarePayment::create(['tenant_id' => $tenant->id, 'square_payment_id' => 'prior-payment-'.$priorId, 'square_order_id' => $priorOrder,
        'status' => 'COMPLETED', 'amount_money' => 1080, 'currency' => 'USD', 'location_id' => 'fictional-location', 'source_type' => 'CARD',
        'created_at_source' => now()->subDay(), 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => $priorOrder]);
    $sale = $store->rows('sales', $tenant->id)->where('id', $saleId)->firstOrFail();
    app(PosReceiptCustomers::class)->attach(payMirrorReceiptScope($fixture), $sale, $snapshot, ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $profile->id]);
    $decorated = app(PosReceiptCustomers::class)->decorate($sale, $snapshot);
    expect($decorated['customer_rewards']['historical_review_required'])->toBeTrue()
        ->and(CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('source', 'everbranch_pay')->count())->toBe(0)
        ->and($store->rows('outbox', $tenant->id)->where('sale_id', $saleId)->value('error_code'))->toBe('customer_rewards_historical_review')
        ->and(app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenant->id)['issues'])->toContain('customer_identity_review_required');
    Http::assertNothingSent();
});

it('preserves the current club membership review policy when current receipt mirrors are excluded', function (): void {
    $fixture = payMirrorAttributionFixture('square_order', true, 'succeeded', false, true,
        ['candle_club_multiplier_enabled' => true, 'candle_club_multiplier_value' => 2]);
    extract($fixture);
    $sale = $store->rows('sales', $tenant->id)->where('id', $saleId)->firstOrFail();
    app(PosReceiptCustomers::class)->attach(payMirrorReceiptScope($fixture), $sale, $snapshot, ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $profile->id]);
    $decorated = app(PosReceiptCustomers::class)->decorate($sale, $snapshot);
    expect($decorated['customer_rewards']['historical_review_required'])->toBeTrue()
        ->and(CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('source', 'everbranch_pay')->count())->toBe(0)
        ->and($store->rows('outbox', $tenant->id)->where('sale_id', $saleId)->value('error_code'))->toBe('customer_rewards_historical_review');
    Http::assertNothingSent();
});

it('keeps prior unknown refund eligibility in review and lets an owner clear it once for anonymous or preselected receipts', function (bool $selected): void {
    $fixture = payMirrorAttributionFixture('square_order', true, 'succeeded', $selected, true, ['second_order_reward_amount' => 2],
        function ($tenant, $profile): void {
            $original = 'prior-unknown-refund-'.Str::uuid();
            SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => $original, 'state' => 'COMPLETED', 'closed_at' => now()->subDay(),
                'location_id' => 'fictional-location', 'total_money_amount' => 1000, 'total_money_currency' => 'USD']);
            SquarePayment::create(['tenant_id' => $tenant->id, 'square_payment_id' => 'payment-'.$original, 'square_order_id' => $original,
                'status' => 'COMPLETED', 'amount_money' => 1000, 'currency' => 'USD', 'location_id' => 'fictional-location', 'source_type' => 'CARD', 'created_at_source' => now()->subDay(), 'raw_payload' => []]);
            MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => $original]);
        });
    extract($fixture);
    $sale = $store->rows('sales', $tenant->id)->where('id', $saleId)->firstOrFail();
    $scope = payMirrorReceiptScope($fixture);
    $customers = app(PosReceiptCustomers::class);
    if ($selected) {
        expect($snapshot['customer_rewards']['historical_review_required'])->toBeTrue()->and($snapshot['customer_rewards']['order_number'])->toBeNull();
        MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'pos_sale', 'source_id' => $store->runtime().':'.$saleId]);
    }
    $customers->attach($scope, $sale, $snapshot, ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $profile->id]);
    $waiting = $customers->decorate($sale, $snapshot);
    expect($waiting['customer_rewards']['historical_review_required'])->toBeTrue()
        ->and(CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('source', 'everbranch_pay')->count())->toBe(0)
        ->and($store->rows('outbox', $tenant->id)->where('sale_id', $saleId)->value('error_code'))->toBe('customer_rewards_historical_review');
    $input = ['request_id' => (string) Str::uuid(), 'customer_profile_id' => $profile->id,
        'reward_evidence' => ['prior_confirmed_orders' => 1, 'member_at_purchase' => false, 'reason' => 'Owner reviewed original history and confirmed one prior purchase.']];
    expect(fn () => $customers->attach([...$scope, 'role' => 'member'], $sale, $snapshot, $input))
        ->toThrow(\App\Services\Pos\PosException::class, 'reward_owner_review_required');
    $first = $customers->attach($scope, $sale, $snapshot, $input);
    $replayed = $customers->attach($scope, $sale, $snapshot, $input);
    $reviewed = $customers->decorate($sale, $snapshot);
    expect($first)->toBe($replayed)->and($reviewed['customer_rewards']['historical_review_required'])->toBeFalse()
        ->and($reviewed['customer_rewards']['order_number'])->toBe(2)->and($reviewed['customer_rewards']['member'])->toBeFalse()
        ->and($reviewed['customer_rewards']['rules']['second_order_reward_amount'])->toBe(2)
        ->and(CandleCashBalance::where('marketing_profile_id', $profile->id)->value('balance'))->toBe(2.0)
        ->and(CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('source', 'everbranch_pay')->count())->toBe(1)
        ->and($store->rows('sales', $tenant->id)->where('id', $saleId)->value('snapshot_hash'))->toBe(Contract::hash($snapshot));
    app(PosCustomerRewards::class)->apply($sale);
    expect(CandleCashTransaction::where('marketing_profile_id', $profile->id)->where('source', 'everbranch_pay')->count())->toBe(1);
    Http::assertNothingSent();
})->with([false, true]);
