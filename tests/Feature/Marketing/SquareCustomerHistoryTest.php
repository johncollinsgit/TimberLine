<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\Marketing\SquareCustomerSource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => null]);
    $this->tenant = Tenant::create(['name' => 'Fictional history shop', 'slug' => 'history-'.Str::lower(Str::random(10))]);
    TenantAccessProfile::create(['tenant_id' => $this->tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->tenant->users()->attach($this->owner, ['role' => 'owner', 'membership_active' => true]);
    $this->profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'last_name' => 'History buyer']);
    $this->binding = ['merchant_id' => 'fictional-original-merchant', 'environment' => 'production', 'connection_id' => 'tenant-config', 'connection_generation' => 1];
    $this->customer = SquareCustomer::create(['tenant_id' => $this->tenant->id, 'square_customer_id' => 'fictional-original-customer', 'raw_payload' => ['_source_binding' => $this->binding]]);
    $this->link = MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->profile->id, 'source_type' => 'square_customer',
        'source_id' => $this->customer->square_customer_id, 'match_method' => 'owner_reviewed', 'source_meta' => $this->binding]);
    $this->source = app(SquareCustomerSource::class);
});

function cachedSquareHistory($test, int $count, string $kind = 'square_order'): array
{
    $ids = [];
    for ($index = 0; $index < $count; $index++) {
        $id = $kind.'-'.Str::uuid();
        $base = ['tenant_id' => $test->tenant->id, 'square_customer_id' => $test->customer->square_customer_id,
            'location_id' => 'original-recorded-location', 'synced_at' => now(), 'raw_payload' => ['_source_binding' => $test->binding,
                'line_items' => [['name' => '<b>Original fictional candle</b>', 'variation_name' => 'Recorded variant', 'quantity' => '2']]]];
        if ($kind === 'square_order') {
            SquareOrder::create($base + ['square_order_id' => $id, 'state' => 'COMPLETED', 'closed_at' => now()->subDays($index), 'total_money_amount' => 2500, 'total_money_currency' => 'USD']);
        } else {
            SquarePayment::create($base + ['square_payment_id' => $id, 'status' => 'COMPLETED', 'amount_money' => 2500, 'currency' => 'USD', 'created_at_source' => now()->subDays($index)]);
        }
        $ids[] = $id;
    }

    return array_reverse($ids);
}

function squareHistoryUrl($test, array $changes = []): string
{
    return route('customers.square.history', $changes + ['tenant' => $test->tenant->slug, 'profile' => $test->profile->id, 'kind' => 'square_order']);
}

function assertSquareHistoryPrivate($response)
{
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');

    return $response;
}

test('cached Square history pages every original ID once and separates order and payment coverage', function () {
    $expected = cachedSquareHistory($this, 121);
    cachedSquareHistory($this, 54, 'square_payment');
    $initial = $this->source->forStaff($this->profile, $this->tenant->id)['history'];
    expect($initial['orders'])->toHaveCount(50)->and($initial['payments'])->toHaveCount(50);
    $all = array_column($initial['orders'], 'id');
    $cursor = $initial['coverage']['square_order']['next_cursor'];
    expect($cursor)->toBeString()->not->toContain($this->customer->square_customer_id, $this->binding['merchant_id']);
    $pages = 1;
    while ($cursor) {
        $result = $this->source->historyPage($this->profile, $this->tenant->id, 'square_order', $cursor)['history'];
        expect($result['payments'])->toBe([])->and($result['coverage'])->toHaveKeys(['square_order']);
        $all = [...$all, ...array_column($result['orders'], 'id')];
        $cursor = $result['coverage']['square_order']['next_cursor'];
        $pages++;
    }
    expect($all)->toBe($expected)->and(array_unique($all))->toHaveCount(121)->and($pages)->toBe(3);
    $payments = $this->source->historyPage($this->profile, $this->tenant->id, 'square_payment', $initial['coverage']['square_payment']['next_cursor'])['history'];
    expect($payments['payments'])->toHaveCount(4)->and($payments['coverage']['square_payment']['has_more'])->toBeFalse();
    Http::assertNothingSent();
});

test('a cached-history cursor excludes later imports and stays stable when an older row refreshes', function () {
    $expected = cachedSquareHistory($this, 51);
    $first = $this->source->forStaff($this->profile, $this->tenant->id)['history'];
    $later = cachedSquareHistory($this, 1)[0];
    SquareOrder::where('tenant_id', $this->tenant->id)->where('square_order_id', $expected[50])->update(['synced_at' => now()->addDay()]);
    $next = $this->source->historyPage($this->profile, $this->tenant->id, 'square_order', $first['coverage']['square_order']['next_cursor'])['history'];
    expect(array_column($next['orders'], 'id'))->toBe([$expected[50]])->not->toContain($later)
        ->and($next['coverage']['square_order']['cached_count'])->toBe(51)->and($next['coverage']['square_order']['has_more'])->toBeFalse();
    expect($this->source->forStaff($this->profile, $this->tenant->id)['history']['coverage']['square_order']['cached_count'])->toBe(52);
});

test('withheld conflicting rows still advance an empty page to available older history', function () {
    $ids = cachedSquareHistory($this, 51);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    foreach (array_slice($ids, 0, 50) as $id) {
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id, 'source_type' => 'square_order', 'source_id' => $id]);
    }
    $first = $this->source->forStaff($this->profile, $this->tenant->id);
    expect($first['history']['orders'])->toBe([])->and($first['history']['coverage']['square_order']['withheld_count'])->toBe(50)
        ->and($first['history']['coverage']['square_order']['has_more'])->toBeTrue()->and($first['issues'])->toContain('square_purchase_identity_review_required');
    $next = $this->source->historyPage($this->profile, $this->tenant->id, 'square_order', $first['history']['coverage']['square_order']['next_cursor']);
    expect(array_column($next['history']['orders'], 'id'))->toBe([$ids[50]]);
});

test('cached history authorizes the current tenant owner with global member role and renders private escaped originals', function () {
    cachedSquareHistory($this, 51);
    CustomerExternalProfile::create(['tenant_id' => $this->tenant->id, 'provider' => 'square', 'integration' => 'square_customer', 'external_customer_id' => $this->customer->square_customer_id,
        'raw_metafields' => ['square' => ['source_binding' => $this->binding, 'cards' => [['id' => 'private-card-reference', 'customer_id' => $this->customer->square_customer_id, 'last_4' => '1234']]]]]);
    $this->actingAs($this->owner);
    $json = assertSquareHistoryPrivate($this->getJson(squareHistoryUrl($this)))->assertOk()->assertJsonPath('customer_id', $this->profile->id)
        ->assertJsonPath('history.coverage.square_order.provider_complete', false)->assertJsonPath('historical_import_earns_rewards', false)->json();
    expect($json)->not->toHaveKeys(['sources', 'legacy_consent_review'])->and(json_encode($json))->not->toContain('private-card-reference');
    assertSquareHistoryPrivate($this->get(squareHistoryUrl($this)))->assertOk()
        ->assertSee('&lt;b&gt;Original fictional candle&lt;/b&gt;', false)->assertDontSee('<b>Original fictional candle</b>', false)
        ->assertSee('original-recorded-location')->assertSee('More cached orders')->assertSee('Provider history completeness is unknown');
    expect(MarketingConsentEvent::count())->toBe(0)->and($this->profile->fresh()->accepts_email_marketing)->toBeFalse();
    Http::assertNothingSent();
});

test('forged expired other-kind and other-customer cursors cannot retrieve private continuation rows', function () {
    cachedSquareHistory($this, 51);
    $this->actingAs($this->owner);
    $first = $this->getJson(squareHistoryUrl($this))->assertOk()->json();
    $cursor = $first['history']['coverage']['square_order']['next_cursor'];
    $this->getJson(squareHistoryUrl($this, ['cursor' => 'forged-'.$cursor]))->assertUnprocessable()->assertJsonMissingPath('history');
    $this->getJson(squareHistoryUrl($this, ['cursor' => $cursor, 'kind' => 'square_payment']))->assertConflict()->assertJsonMissingPath('history');
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    $this->getJson(squareHistoryUrl($this, ['cursor' => $cursor, 'profile' => $other->id]))->assertConflict()->assertJsonMissingPath('history');
    $this->travel(31)->minutes();
    assertSquareHistoryPrivate($this->getJson(squareHistoryUrl($this, ['cursor' => $cursor])))->assertConflict()->assertJsonPath('message', 'This history page expired. Restart the cached history.');
    $this->get(squareHistoryUrl($this, ['cursor' => $cursor]))->assertConflict()->assertSee('Restart orders history');
});

test('a history cursor is bound to current canonical aliases and original source namespace', function (string $change) {
    cachedSquareHistory($this, 51);
    $this->actingAs($this->owner);
    $cursor = $this->getJson(squareHistoryUrl($this))->assertOk()->json('history.coverage.square_order.next_cursor');
    if ($change === 'alias') {
        MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->profile->id, 'archived_at' => now()]);
    } elseif ($change === 'namespace') {
        $this->link->update(['source_meta' => array_replace($this->binding, ['environment' => 'sandbox'])]);
    } else {
        $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
        $this->profile->update(['merged_into_profile_id' => $other->id, 'archived_at' => now()]);
    }
    $this->getJson(squareHistoryUrl($this, ['cursor' => $cursor]))->assertConflict()->assertJsonMissingPath('history');
})->with(['alias', 'namespace', 'merged']);

test('private cached history rechecks membership verification activity and workspace capability', function (string $change) {
    cachedSquareHistory($this, 1);
    if ($change === 'member') {
        $this->tenant->users()->updateExistingPivot($this->owner->id, ['role' => 'member']);
    } elseif ($change === 'removed') {
        $this->tenant->users()->updateExistingPivot($this->owner->id, ['membership_active' => false]);
    } elseif ($change === 'unverified') {
        $this->owner->forceFill(['email_verified_at' => null])->save();
    } elseif ($change === 'inactive') {
        $this->owner->update(['is_active' => false]);
    } elseif ($change === 'soccer') {
        TenantAccessProfile::where('tenant_id', $this->tenant->id)->update(['metadata' => ['workspace_focus' => 'soccer']]);
    } else {
        \App\Models\TenantModuleState::updateOrCreate(['tenant_id' => $this->tenant->id, 'module_key' => 'customers'], ['enabled_override' => false]);
    }
    Auth::forgetGuards();
    $this->actingAs($this->owner)->getJson(squareHistoryUrl($this))->assertForbidden()->assertJsonMissingPath('history');
})->with(['member', 'removed', 'unverified', 'inactive', 'soccer', 'customers_disabled']);

test('archived customers and signed-out requests never expose cached history', function () {
    cachedSquareHistory($this, 1);
    $this->getJson(squareHistoryUrl($this))->assertUnauthorized();
    $this->profile->update(['archived_at' => now()]);
    $this->actingAs($this->owner)->getJson(squareHistoryUrl($this))->assertNotFound();
});

test('another tenant cannot resolve the customer or reuse an original-tenant cursor', function () {
    cachedSquareHistory($this, 51);
    $this->actingAs($this->owner);
    $cursor = $this->getJson(squareHistoryUrl($this))->assertOk()->json('history.coverage.square_order.next_cursor');
    $otherTenant = Tenant::create(['name' => 'Other fictional history shop', 'slug' => 'other-history-'.Str::lower(Str::random(8))]);
    TenantAccessProfile::create(['tenant_id' => $otherTenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct']);
    $this->owner->tenants()->attach($otherTenant, ['role' => 'owner', 'membership_active' => true]);
    $otherProfile = MarketingProfile::create(['tenant_id' => $otherTenant->id]);
    Auth::forgetGuards();
    $this->actingAs($this->owner)->getJson(squareHistoryUrl($this, ['tenant' => $otherTenant->slug]))->assertNotFound();
    $this->getJson(squareHistoryUrl($this, ['tenant' => $otherTenant->slug, 'profile' => $otherProfile->id, 'cursor' => $cursor]))->assertConflict()->assertJsonMissingPath('history');
    Http::assertNothingSent();
});

test('invalid continuation offsets are rejected even when the envelope is authentic', function () {
    cachedSquareHistory($this, 51);
    $cursor = $this->source->forStaff($this->profile, $this->tenant->id)['history']['coverage']['square_order']['next_cursor'];
    $value = json_decode(Crypt::decryptString($cursor), true);
    $value['before'] = $value['ceiling'] + 1;
    $bad = Crypt::encryptString(json_encode($value));
    $this->actingAs($this->owner)->getJson(squareHistoryUrl($this, ['cursor' => $bad]))->assertUnprocessable()->assertJsonMissingPath('history');
});
