<?php

use App\Models\CandleCashBalance;
use App\Models\CandleCashTransaction;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Tenant;
use App\Models\TenantMarketingSetting;
use App\Services\Marketing\CustomerMergeService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/SquarePurchaseFixtures.php';

beforeEach(fn () => squarePurchaseFixture($this));

function purchaseFixtureText($test, array $changes = []): MarketingMessageDelivery
{
    $campaign = MarketingCampaign::create(['tenant_id' => $test->tenant->id, 'name' => 'Fictional show invitation', 'slug' => 'show-'.Str::random(8), 'status' => 'draft', 'channel' => 'sms']);

    return MarketingMessageDelivery::create(array_replace(['tenant_id' => $test->tenant->id, 'campaign_id' => $campaign->id, 'marketing_profile_id' => $test->profile->id,
        'channel' => 'sms', 'provider' => 'twilio', 'rendered_message' => 'Visit the fictional show.', 'to_phone' => '+12025550123', 'send_status' => 'sent', 'sent_at' => now()->subDay()], $changes));
}

test('payment customer links a missing order customer and earns three percent once without changing sources', function () {
    app(\App\Services\Marketing\CandleCashService::class)->addRewardAmountIdempotent($this->profile, 5, 'campaign_reward', 'original-gift');
    $raw = $this->order->raw_payload;
    $first = $this->effects->reconcile($this->tenant->id, 'original-order');
    $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($first['effects']['reward_posted_minor'])->toBe(180)
        ->and((float) CandleCashBalance::where('marketing_profile_id', $this->profile->id)->value('balance'))->toBe(6.8)
        ->and(CandleCashTransaction::where('source', 'square_purchase')->count())->toBe(1)
        ->and(MarketingProfileLink::where('source_type', 'square_order')->sole()->marketing_profile_id)->toBe($this->profile->id)
        ->and($this->order->fresh()->raw_payload)->toBe($raw)->and($this->order->fresh()->square_customer_id)->toBeNull();
    Http::assertNothingSent();
});

test('preview never creates source links conversion or wallet records', function () {
    purchaseFixtureText($this);
    $result = $this->effects->reconcile($this->tenant->id, 'original-order', true);
    expect($result['effects']['reward_target_minor'])->toBe(180)->and(MarketingProfileLink::count())->toBe(1)
        ->and(CandleCashTransaction::count())->toBe(0)->and(MarketingCampaignConversion::count())->toBe(0);
});

test('sent only latest text has modeled credit and delivery failure withdraws it without reearning', function () {
    $earlier = purchaseFixtureText($this, ['sent_at' => now()->subDays(2), 'send_status' => 'delivered', 'delivered_at' => now()->subDays(2)]);
    $last = purchaseFixtureText($this);
    $first = $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($first['effects']['attribution']['basis'])->toBe('sent_only_modeled')->and(MarketingCampaignConversion::sole()->campaign_id)->toBe($last->campaign_id);
    $last->update(['send_status' => 'undelivered']);
    $second = $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($second['effects']['attribution']['basis'])->toBe('delivered_last_touch')
        ->and(MarketingCampaignConversion::where('attribution_type', 'last_touch')->sole()->campaign_id)->toBe($earlier->campaign_id)
        ->and(MarketingCampaignConversion::where('attribution_type', 'superseded')->count())->toBe(1)
        ->and(CandleCashTransaction::count())->toBe(1);
});

test('failed canceled future and expired touches do not receive credit', function () {
    foreach ([['send_status' => 'failed'], ['send_status' => 'canceled'], ['sent_at' => now()], ['sent_at' => now()->subDays(8)]] as $changes) {
        purchaseFixtureText($this, $changes);
    }
    $this->effects->reconcile($this->tenant->id, 'original-order');
    expect(MarketingCampaignConversion::count())->toBe(0);
});

test('conflicting source ownership blocks linking earning and conversion', function () {
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Other buyer']);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id, 'source_type' => 'square_payment', 'source_id' => 'original-payment']);
    expect($this->effects->reconcile($this->tenant->id, 'original-order')['reason'])->toBe('conflicting_customer_claims')
        ->and(CandleCashTransaction::count())->toBe(0)->and(MarketingProfileLink::where('source_type', 'square_order')->count())->toBe(0);
});

test('older history links without earning and unconfirmed payments remain in review', function () {
    $this->order->update(['closed_at' => '2024-11-16T17:00:00Z']);
    $result = $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($result['effects']['reward_status'])->toBe('outside_earning_window')->and(CandleCashTransaction::count())->toBe(0);
    $this->payment->update(['status' => 'PENDING']);
    expect($this->effects->reconcile($this->tenant->id, 'original-order')['reason'])->toBe('confirmed_payment_required');
});

test('original terms survive a later rate change and full refunds reverse earning only once', function () {
    $this->effects->reconcile($this->tenant->id, 'original-order');
    $versions = TenantMarketingSetting::where('key', 'candle_cash_policy_versions')->sole();
    $new = $versions->value;
    $new['items'][0]['policy_snapshot']['earning_rules']['purchase_earn_bps'] = 1000;
    $versions->update(['value' => $new]);
    expect($this->effects->reconcile($this->tenant->id, 'original-order')['effects']['reward_posted_minor'])->toBe(180);
    $raw = $this->payment->raw_payload;
    $raw['refunded_money']['amount'] = 6540;
    $this->payment->update(['raw_payload' => $raw]);
    $this->effects->reconcile($this->tenant->id, 'original-order');
    $this->effects->reconcile($this->tenant->id, 'original-order');
    expect((float) CandleCashBalance::where('marketing_profile_id', $this->profile->id)->value('balance'))->toBe(0.0)
        ->and(CandleCashTransaction::where('source', 'square_purchase')->count())->toBe(2);
});

test('ambiguous partial refunds retain the existing earning for review', function () {
    $this->effects->reconcile($this->tenant->id, 'original-order');
    $raw = $this->payment->raw_payload;
    $raw['refunded_money']['amount'] = 1000;
    $this->payment->update(['raw_payload' => $raw]);
    $result = $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($result['effects']['reward_status'])->toBe('review_required')->and($result['effects']['reward_posted_minor'])->toBe(180)
        ->and(CandleCashTransaction::count())->toBe(1);
});

test('an internal merge preserves source evidence text history and the original wallet', function () {
    $donor = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Buyer']);
    purchaseFixtureText($this, ['marketing_profile_id' => $donor->id]);
    $merges = app(CustomerMergeService::class);
    $operation = $merges->createOperation($this->tenant->id, [$this->profile->id, $donor->id], $this->profile->id, 'core', (string) Str::uuid());
    $merges->apply($operation);
    $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($donor->fresh()->merged_into_profile_id)->toBe($this->profile->id)
        ->and(MarketingCampaignConversion::sole()->marketing_profile_id)->toBe($this->profile->id);
});

test('revoked enrollment owner and foreign merchant cannot post rewards', function () {
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect(fn () => $this->effects->reconcile($this->tenant->id, 'original-order'))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(CandleCashTransaction::count())->toBe(0);
});

test('wallet cap freezes each purchase award and preserves older balances above the cap', function () {
    $cash = app(\App\Services\Marketing\CandleCashService::class);
    $cash->addRewardAmountIdempotent($this->profile, 9.5, 'gift', 'original');
    expect($this->effects->reconcile($this->tenant->id, 'original-order')['effects']['reward_posted_minor'])->toBe(50);
    $cash->addRewardAmountIdempotent($this->profile, -5, 'test_spend', 'spend');
    expect($this->effects->reconcile($this->tenant->id, 'original-order')['effects']['reward_posted_minor'])->toBe(50)
        ->and($cash->currentBalance($this->profile))->toBe(5.0);
});

test('earning pauses above the cap without forfeiting the balance or earning on a later retry', function () {
    $cash = app(\App\Services\Marketing\CandleCashService::class);
    $cash->addRewardAmountIdempotent($this->profile, 15, 'gift', 'original');
    $first = $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($first['effects']['reward_status'])->toBe('paused_at_wallet_cap')->and($cash->currentBalance($this->profile))->toBe(15.0);
    $cash->addRewardAmountIdempotent($this->profile, -10, 'test_spend', 'spend');
    expect($this->effects->reconcile($this->tenant->id, 'original-order')['effects']['reward_posted_minor'])->toBe(0);
});

test('stale provider refund evidence cannot restore reversed rewards', function () {
    $this->effects->reconcile($this->tenant->id, 'original-order');
    $raw = $this->payment->raw_payload;
    $raw['refunded_money']['amount'] = 6540;
    $this->payment->update(['raw_payload' => $raw]);
    $this->effects->reconcile($this->tenant->id, 'original-order');
    $raw['refunded_money']['amount'] = 0;
    $this->payment->update(['raw_payload' => $raw]);
    $this->effects->reconcile($this->tenant->id, 'original-order');
    $result = $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($result['effects']['refund_minor'])->toBe(6540)->and($result['effects']['reward_status'])->toBe('review_required')
        ->and(CandleCashTransaction::count())->toBe(2);
});

test('refunds after rewards were spent require review without creating a negative wallet', function () {
    $this->effects->reconcile($this->tenant->id, 'original-order');
    app(\App\Services\Marketing\CandleCashService::class)->addRewardAmountIdempotent($this->profile, -1.8, 'test_spend', 'spend');
    $raw = $this->payment->raw_payload;
    $raw['refunded_money']['amount'] = 6540;
    $this->payment->update(['raw_payload' => $raw]);
    $result = $this->effects->reconcile($this->tenant->id, 'original-order');
    expect($result['effects']['reward_status'])->toBe('refund_balance_review_required')
        ->and((float) CandleCashBalance::where('marketing_profile_id', $this->profile->id)->value('balance'))->toBe(0.0);
});

test('core merge requires a current owner reviewed preview and replays the same canonical result', function () {
    $this->withoutVite();
    $donor = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Duplicate']);
    $base = '/workspace/customers/merges';
    $preview = $this->actingAs($this->owner)->postJson($base.'/preview?tenant='.$this->tenant->slug, [
        'profile_ids' => [$this->profile->id, $donor->id], 'survivor_profile_id' => $this->profile->id, 'request_id' => (string) Str::uuid(),
    ])->assertOk()->json();
    $url = $base.'/'.$preview['operation_id'].'/apply?tenant='.$this->tenant->slug;
    $data = ['expected_version' => $preview['version'], 'identity_confirmed' => true];
    $this->postJson($url, $data)->assertOk()->assertJsonPath('profile_id', $this->profile->id);
    $this->postJson($url, $data)->assertOk()->assertJsonPath('profile_id', $this->profile->id);
    $foreign = Tenant::create(['name' => 'Other', 'slug' => 'other-'.Str::random(8)]);
    $this->postJson($base.'/'.$preview['operation_id'].'/apply?tenant='.$foreign->slug, $data)->assertForbidden();
});

test('wallet changes invalidate a merge preview instead of silently merging new financial state', function () {
    $donor = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Duplicate']);
    $preview = $this->actingAs($this->owner)->postJson('/workspace/customers/merges/preview?tenant='.$this->tenant->slug, [
        'profile_ids' => [$this->profile->id, $donor->id], 'survivor_profile_id' => $this->profile->id, 'request_id' => (string) Str::uuid(),
    ])->assertOk()->json();
    app(\App\Services\Marketing\CandleCashService::class)->addRewardAmountIdempotent($donor, 5, 'gift', 'after-preview');
    $this->postJson('/workspace/customers/merges/'.$preview['operation_id'].'/apply?tenant='.$this->tenant->slug,
        ['expected_version' => $preview['version'], 'identity_confirmed' => true])->assertConflict();
    expect($donor->fresh()->merged_into_profile_id)->toBeNull();
});

test('campaign results count original purchases once and distinguish actual delivery from modeled credit', function () {
    $text = purchaseFixtureText($this);
    $this->effects->reconcile($this->tenant->id, 'original-order');
    $this->effects->reconcile($this->tenant->id, 'original-order');
    $results = app(\App\Services\Marketing\EventCampaignResults::class)->forCampaign($text->campaign);
    expect($results)->toMatchArray(['accepted_sends' => 1, 'delivered' => 0, 'sent_only' => 1, 'attributed_buyers' => 1,
        'attributed_purchases' => 1, 'net_sales_minor' => 6000, 'reward_minor' => 180, 'causal_credit' => false]);
});
