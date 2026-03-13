<?php

use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\EventInstance;
use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingConsentRequest;
use App\Models\MarketingGroup;
use App\Models\MarketingGroupMember;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\User;
use App\Services\Marketing\CandleCashRedemptionReconciliationService;
use App\Services\Marketing\CandleCashService;
use Illuminate\Support\Arr;

test('canonical event slug backfill and public route canonicalization work', function () {
    $event = EventInstance::query()->create([
        'title' => 'Florida Strawberry Festival 2026',
        'public_slug' => null,
        'starts_at' => now()->addDays(5),
    ]);

    $this->artisan('marketing:backfill-event-slugs', ['--limit' => 10])
        ->assertExitCode(0);

    $event->refresh();
    expect((string) $event->public_slug)->not->toBe('');

    $event->forceFill(['public_slug' => 'strawberry-fest-public'])->save();

    $this->get(route('marketing.public.events.optin', ['eventSlug' => 'florida-strawberry-festival-2026']))
        ->assertRedirect(route('marketing.public.events.optin', ['eventSlug' => 'strawberry-fest-public']));

    $this->get(route('marketing.public.events.optin', ['eventSlug' => 'strawberry-fest-public']))
        ->assertOk()
        ->assertSeeText('Event Opt-In');
});

test('shopify storefront endpoints require valid signature and hide internal-only data', function () {
    config()->set('marketing.shopify.signing_secret', 'stage8-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $this->getJson(route('marketing.shopify.rewards.available'))
        ->assertStatus(401);

    $invalidHeaders = storefrontSignedHeaders('GET', '/shopify/marketing/rewards/available', [], '', 'wrong-secret');
    $this->withHeaders($invalidHeaders)
        ->getJson(route('marketing.shopify.rewards.available'))
        ->assertStatus(401);

    $validHeaders = storefrontSignedHeaders('GET', '/shopify/marketing/rewards/available', [], '', 'stage8-secret');
    $this->withHeaders($validHeaders)
        ->getJson(route('marketing.shopify.rewards.available'))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('version', 'v1')
        ->assertJsonStructure(['ok', 'version', 'data' => ['rewards']]);
});

test('consent request confirm flow persists states and awards incentive only once', function () {
    config()->set('marketing.consent_bonus_points.sms', 9);

    $response = $this->post(route('marketing.consent.optin.store'), [
        'email' => 'stage8.consent@example.com',
        'phone' => '5551237777',
        'first_name' => 'Stage',
        'last_name' => 'Eight',
        'award_bonus' => 1,
    ]);

    $response->assertRedirect();
    $location = (string) $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
    $token = (string) ($query['token'] ?? '');

    expect($token)->not->toBe('');

    $request = MarketingConsentRequest::query()->where('token', $token)->firstOrFail();
    expect($request->status)->toBe('requested');

    $this->post(route('marketing.consent.verify.store'), ['token' => $token])
        ->assertRedirect();

    $request->refresh();
    expect($request->status)->toBe('confirmed')
        ->and((int) $request->reward_awarded_points)->toBe(9);

    expect(MarketingConsentEvent::query()
        ->where('marketing_profile_id', $request->marketing_profile_id)
        ->where('event_type', 'requested')
        ->exists())->toBeTrue()
        ->and(MarketingConsentEvent::query()
            ->where('marketing_profile_id', $request->marketing_profile_id)
            ->where('event_type', 'confirmed')
            ->exists())->toBeTrue();

    $transactionCount = CandleCashTransaction::query()
        ->where('marketing_profile_id', $request->marketing_profile_id)
        ->where('source', 'consent')
        ->count();
    expect($transactionCount)->toBe(1);

    $this->post(route('marketing.consent.verify.store'), ['token' => $token])
        ->assertRedirect();

    expect(CandleCashTransaction::query()
        ->where('marketing_profile_id', $request->marketing_profile_id)
        ->where('source', 'consent')
        ->count())->toBe(1);
});

test('shopify redemption reconciliation validates codes and stays idempotent', function () {
    $profile = MarketingProfile::query()->create([
        'first_name' => 'Redeem',
        'email' => 'redeem.stage8@example.com',
        'normalized_email' => 'redeem.stage8@example.com',
        'phone' => '5552223333',
        'normalized_phone' => '+15552223333',
    ]);
    app(CandleCashService::class)->addPoints($profile, 400, 'earn', 'admin', 'seed', 'seed');
    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('points_cost')->firstOrFail();
    $issued = app(CandleCashService::class)->redeemReward($profile, $reward, 'shopify');

    $order = Order::query()->create([
        'source' => 'shopify',
        'shopify_store_key' => 'retail',
        'shopify_order_id' => 998877,
        'order_number' => '#998877',
        'status' => 'new',
    ]);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'order',
        'source_id' => (string) $order->id,
        'match_method' => 'exact_email',
    ]);

    $summary = app(CandleCashRedemptionReconciliationService::class)->reconcileShopifyOrder($order, [
        'codes' => [(string) ($issued['code'] ?? '')],
    ]);

    $redemption = CandleCashRedemption::query()->findOrFail((int) ($issued['redemption_id'] ?? 0));
    expect($summary['reconciled'])->toBe(1)
        ->and($redemption->fresh()->status)->toBe('redeemed')
        ->and((string) $redemption->external_order_source)->toBe('order')
        ->and((string) $redemption->external_order_id)->toBe((string) $order->id);

    $again = app(CandleCashRedemptionReconciliationService::class)->reconcileShopifyOrder($order, [
        'codes' => [(string) ($issued['code'] ?? '')],
    ]);
    expect($again['already_reconciled'])->toBe(1);

    $issued2 = app(CandleCashService::class)->redeemReward($profile, $reward, 'shopify');
    $redemption2 = CandleCashRedemption::query()->findOrFail((int) ($issued2['redemption_id'] ?? 0));
    $redemption2->forceFill(['status' => 'canceled', 'canceled_at' => now()])->save();

    $rejected = app(CandleCashRedemptionReconciliationService::class)->reconcileShopifyOrder($order, [
        'codes' => [(string) ($issued2['code'] ?? '')],
    ]);
    expect($rejected['rejected'])->toBe(1);
});

test('manual square reconciliation actions persist status and audit fields', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Manual Square']);
    app(CandleCashService::class)->addPoints($profile, 300, 'earn', 'admin', 'seed', 'seed');
    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('points_cost')->firstOrFail();
    $issued = app(CandleCashService::class)->redeemReward($profile, $reward, 'square');
    $redemption = CandleCashRedemption::query()->findOrFail((int) ($issued['redemption_id'] ?? 0));

    $this->actingAs($admin)
        ->post(route('marketing.customers.candle-cash.redemptions.mark-redeemed', [$profile, $redemption]), [
            'platform' => 'square',
            'external_order_source' => 'square_manual',
            'external_order_id' => 'SQ-ORDER-100',
            'notes' => 'Staff reconciled at booth',
        ])
        ->assertRedirect(route('marketing.customers.show', $profile));

    $redemption->refresh();
    expect($redemption->status)->toBe('redeemed')
        ->and((string) $redemption->external_order_id)->toBe('SQ-ORDER-100')
        ->and((int) $redemption->redeemed_by)->toBe((int) $admin->id);
});

test('customer detail shows storefront links and redemption lifecycle details', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Timeline']);
    MarketingProfileLink::query()->create([
        'marketing_profile_id' => $profile->id,
        'source_type' => 'shopify_widget_contact',
        'source_id' => 'shopify_widget_contact:test',
        'match_method' => 'exact_email',
        'source_meta' => ['endpoint' => 'consent_optin'],
    ]);

    app(CandleCashService::class)->addPoints($profile, 300, 'earn', 'admin', 'seed', 'seed');
    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('points_cost')->firstOrFail();
    $issued = app(CandleCashService::class)->redeemReward($profile, $reward, 'shopify');

    $this->actingAs($admin)
        ->get(route('marketing.customers.show', $profile))
        ->assertOk()
        ->assertSeeText('Storefront / Public Flow Links')
        ->assertSeeText('shopify_widget_contact')
        ->assertSeeText((string) ($issued['code'] ?? ''));
});

test('campaign detail shows reward-assisted conversion drilldown', function () {
    $admin = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $profile = MarketingProfile::query()->create(['first_name' => 'Campaign Reward']);
    $campaign = MarketingCampaign::query()->create([
        'name' => 'Stage8 Reward Campaign',
        'status' => 'active',
        'channel' => 'sms',
    ]);
    MarketingCampaignConversion::query()->create([
        'campaign_id' => $campaign->id,
        'marketing_profile_id' => $profile->id,
        'attribution_type' => 'last_touch',
        'source_type' => 'order',
        'source_id' => '12345',
        'converted_at' => now()->subDay(),
        'order_total' => 50.00,
    ]);

    $reward = CandleCashReward::query()->where('is_active', true)->orderBy('points_cost')->firstOrFail();
    CandleCashRedemption::query()->create([
        'marketing_profile_id' => $profile->id,
        'reward_id' => $reward->id,
        'points_spent' => (int) $reward->points_cost,
        'platform' => 'shopify',
        'redemption_code' => 'CC-STAGE8-XYZ1',
        'status' => 'redeemed',
        'issued_at' => now()->subDays(2),
        'redeemed_at' => now()->subDay(),
        'external_order_source' => 'order',
        'external_order_id' => '12345',
        'redeemed_channel' => 'shopify_ingest',
    ]);

    $this->actingAs($admin)
        ->get(route('marketing.campaigns.show', $campaign))
        ->assertOk()
        ->assertSeeText('Reward-assisted conversions: 1');
});

test('shopify customer status excludes internal groups from storefront payload', function () {
    config()->set('marketing.shopify.signing_secret', 'stage8-secret');
    config()->set('marketing.shopify.allow_legacy_token', false);

    $profile = MarketingProfile::query()->create([
        'first_name' => 'Group Visibility',
        'email' => 'group.visibility@example.com',
        'normalized_email' => 'group.visibility@example.com',
        'phone' => '5556667788',
        'normalized_phone' => '+15556667788',
    ]);
    $internal = MarketingGroup::query()->create(['name' => 'Internal Only', 'is_internal' => true]);
    $public = MarketingGroup::query()->create(['name' => 'VIP Public', 'is_internal' => false]);
    MarketingGroupMember::query()->create(['marketing_group_id' => $internal->id, 'marketing_profile_id' => $profile->id]);
    MarketingGroupMember::query()->create(['marketing_group_id' => $public->id, 'marketing_profile_id' => $profile->id]);

    $query = ['email' => $profile->email, 'phone' => $profile->phone];
    $path = '/shopify/marketing/customer/status';
    $headers = storefrontSignedHeaders('GET', $path, $query, '', 'stage8-secret');

    $response = $this->withHeaders($headers)
        ->getJson(route('marketing.shopify.customer.status', $query))
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->json();

    $groups = Arr::get($response, 'data.groups', []);
    expect(collect($groups)->pluck('name')->all())->toContain('VIP Public')
        ->not->toContain('Internal Only');
});

/**
 * @param array<string,mixed> $query
 * @return array<string,string>
 */
function storefrontSignedHeaders(string $method, string $path, array $query, string $body, string $secret): array
{
    $timestamp = (string) time();
    $canonicalQuery = storefrontCanonicalQuery($query);
    $bodyHash = hash('sha256', $body);
    $payload = implode("\n", [$timestamp, strtoupper($method), $path, $canonicalQuery, $bodyHash]);
    $signature = hash_hmac('sha256', $payload, $secret);

    return [
        'X-Marketing-Timestamp' => $timestamp,
        'X-Marketing-Signature' => $signature,
    ];
}

/**
 * @param array<string,mixed> $query
 */
function storefrontCanonicalQuery(array $query): string
{
    if ($query === []) {
        return '';
    }

    ksort($query);
    $parts = [];
    foreach ($query as $key => $value) {
        if (is_array($value)) {
            $value = storefrontCanonicalQuery($value);
        } elseif (is_bool($value)) {
            $value = $value ? '1' : '0';
        } elseif ($value === null) {
            $value = '';
        } else {
            $value = (string) $value;
        }
        $parts[] = rawurlencode((string) $key) . '=' . rawurlencode((string) $value);
    }

    return implode('&', $parts);
}

