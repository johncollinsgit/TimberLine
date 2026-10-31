<?php

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Services\Marketing\CandleCashChannelPolicy;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\CandleClubMembershipService;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

function channelRewardFixture(string $slug = 'modern-forestry', int $balance = 40): array
{
    Http::preventStrayRequests();
    $tenant = Tenant::query()->firstOrCreate(['slug' => $slug], ['name' => 'Fictional rewards tenant']);
    $profile = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional member', 'source_channels' => ['candle_club']]);
    $reward = CandleCashReward::create(['name' => 'Redeem $10 Reward Credit', 'candle_cash_cost' => 10,
        'reward_type' => 'coupon', 'reward_value' => '10USD', 'is_active' => true]);
    $service = app(CandleCashService::class);
    $service->addPoints($profile, $balance, 'earn', 'test', 'fictional-balance');

    return [$tenant, $profile, $reward, $service];
}

test('modern forestry members redeem five in person and ten online without affecting recurring terms', function () {
    [$tenant, $profile, $reward, $service] = channelRewardFixture();
    $square = $service->redeemReward($profile, $reward, 'square');
    $online = $service->redeemReward($profile, $reward, 'modern_forestry_ios');
    $inPersonCode = CandleCashRedemption::findOrFail($square['redemption_id']);
    $onlineCode = CandleCashRedemption::findOrFail($online['redemption_id']);

    expect($square['ok'])->toBeTrue()->and($online['ok'])->toBeTrue()
        ->and($service->currentBalance($profile))->toBe(25.0)
        ->and($service->redemptionAmountForIssuedCode($inPersonCode))->toBe(5.0)
        ->and($service->redemptionAmountForIssuedCode($onlineCode))->toBe(10.0)
        ->and(data_get($inPersonCode->redemption_context, 'channel_policy.recurring_charges_allowed'))->toBeFalse()
        ->and(app(CandleClubMembershipService::class)->isActiveMember($profile))->toBeTrue()
        ->and($service->redemptionRulesPayload($tenant->id))->toMatchArray([
            'max_redeemable_per_order_dollars' => 10.0, 'in_person_max_redeemable_per_order_dollars' => 5.0,
            'max_redemptions_per_order' => 1, 'recurring_charges_allowed' => false,
        ])
        ->and($service->storefrontRewardPayload($reward, 25, $tenant->id)['description'])->toContain('$5', '$10', 'excluding recurring Candle Club charges');
    $retry = $service->redeemReward($profile, $reward, 'square');
    expect($retry['redemption_id'])->toBe($square['redemption_id'])->and($service->currentBalance($profile))->toBe(25.0);
});

test('another tenant keeps its original in person reward value', function () {
    [$tenant, $profile, $reward, $service] = channelRewardFixture('another-forestry');
    $result = $service->redeemReward($profile, $reward, 'square');
    expect($service->currentBalance($profile))->toBe(30.0)
        ->and($service->redemptionRulesPayload($tenant->id))->not->toHaveKey('in_person_max_redeemable_per_order_dollars')
        ->and(CandleCashRedemption::findOrFail($result['redemption_id'])->redemption_context)->toBeNull();
});

test('previously issued ten dollar in person codes retain their value and prevent a second open code', function () {
    [, $profile, $reward, $service] = channelRewardFixture();
    $old = CandleCashRedemption::create(['marketing_profile_id' => $profile->id, 'reward_id' => $reward->id, 'candle_cash_spent' => 10,
        'platform' => 'square', 'status' => 'issued', 'redemption_code' => 'CC-OLD-TEN', 'expires_at' => now()->addDay()]);
    expect($service->redeemReward($profile, $reward, 'square')['error'])->toBe('existing_in_person_redemption')
        ->and($service->redemptionAmountForIssuedCode($old))->toBe(10.0)
        ->and($old->fresh()->candle_cash_spent)->toBe(10)->and($service->currentBalance($profile))->toBe(40.0);
});

test('in person redemption checks the balance and restores only the original five dollars on cancellation', function () {
    [, $profile, $reward, $service] = channelRewardFixture(balance: 4);
    expect($service->redeemReward($profile, $reward, 'square')['error'])->toBe('insufficient_balance');
    $service->addPoints($profile, 1, 'earn', 'test', 'one-more');
    $result = $service->redeemReward($profile, $reward, 'square');
    $redemption = CandleCashRedemption::findOrFail($result['redemption_id']);
    expect($service->currentBalance($profile))->toBe(0.0);
    app(\App\Services\Marketing\CandleCashRedemptionReconciliationService::class)->cancelRedemption($redemption, ['notes' => 'Fictional cancellation']);
    app(\App\Services\Marketing\CandleCashRedemptionReconciliationService::class)->cancelRedemption($redemption->fresh(), ['notes' => 'Repeated cancellation']);
    expect($service->currentBalance($profile))->toBe(5.0)
        ->and($service->redemptionAmountForIssuedCode($redemption->fresh()))->toBe(5.0);
});

test('in person codes require a Square order and cannot become Shopify discounts', function () {
    [, $profile, $reward, $service] = channelRewardFixture();
    $code = CandleCashRedemption::findOrFail($service->redeemReward($profile, $reward, 'square')['redemption_id']);
    expect(fn () => app(CandleCashChannelPolicy::class)->consumeInPerson($code, 'square_manual', '', 'manual_reconcile'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CandleCashChannelPolicy::class)->consumeInPerson($code, 'order', 'recurring-shopify-order', 'shopify_ingest'))
        ->toThrow(ValidationException::class);
    expect(fn () => app(CandleCashShopifyDiscountService::class)->ensureDiscountForRedemption($code))
        ->toThrow(RuntimeException::class, 'in-person Candle Cash code');
    expect($code->fresh()->status)->toBe('issued');
    Http::assertNothingSent();
});

test('manual and ingested in person use converge and a second reward on the same purchase is rejected', function () {
    [, $profile, $reward, $service] = channelRewardFixture();
    $policy = app(CandleCashChannelPolicy::class);
    $first = CandleCashRedemption::findOrFail($service->redeemReward($profile, $reward, 'square')['redemption_id']);
    $policy->consumeInPerson($first, 'square_manual', 'fictional-order-1', 'manual_reconcile');
    expect($policy->consumeInPerson($first, 'square_order', 'fictional-order-1', 'square_sync')->id)->toBe($first->id);
    $second = CandleCashRedemption::findOrFail($service->redeemReward($profile, $reward, 'square')['redemption_id']);
    expect(fn () => $policy->consumeInPerson($second, 'square_order', 'fictional-order-1', 'square_sync'))->toThrow(ValidationException::class);
    expect($second->fresh()->status)->toBe('issued');
    $policy->consumeInPerson($second, 'square_order', 'fictional-order-2', 'square_sync');
    expect($second->fresh()->status)->toBe('redeemed')->and($service->currentBalance($profile))->toBe(30.0);
});

test('Square reconciliation uses the same channel guards and a preview leaves the code unused', function () {
    [$tenant, $profile, $reward, $service] = channelRewardFixture();
    $square = CandleCashRedemption::findOrFail($service->redeemReward($profile, $reward, 'square')['redemption_id']);
    $online = CandleCashRedemption::findOrFail($service->redeemReward($profile, $reward, 'shopify')['redemption_id']);
    $reconcile = app(\App\Services\Marketing\CandleCashRedemptionReconciliationService::class);
    $args = [[$square->redemption_code], 'square_order', 'fictional-order', [$profile->id], 'square_sync', [], $tenant->id];
    expect($reconcile->reconcileCodes(...[...$args, true])['reconciled'])->toBe(1)->and($square->fresh()->status)->toBe('issued');
    expect($reconcile->reconcileCodes(...$args)['reconciled'])->toBe(1)->and($square->fresh()->status)->toBe('redeemed');
    expect($reconcile->reconcileCodes(...$args)['already_reconciled'])->toBe(1);
    expect($reconcile->reconcileCodes([$online->redemption_code], 'square_order', 'different-order', [$profile->id], 'square_sync', [], $tenant->id)['rejected'])->toBe(1);
    expect(fn () => $reconcile->markRedeemedManually($online, ['platform' => 'square', 'external_order_id' => 'different-order']))->toThrow(ValidationException::class);
    expect($online->fresh()->status)->toBe('issued');
});
