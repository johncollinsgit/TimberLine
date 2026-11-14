<?php

use App\Models\CustomerExternalProfile;
use App\Models\MarketingConsentEvent;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\SquareCustomerCandidateResolution;
use App\Services\Marketing\SquareCustomerIdentityService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional automatic source shop', 'slug' => 'auto-square-'.Str::lower(Str::random(8))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->binding = ['merchant_id' => 'fictional-merchant', 'environment' => 'sandbox'];
    $this->context = $this->binding + ['create_unmatched' => true, 'actor_id' => $this->owner->id, 'source_label' => 'square_customer_sync', 'request_id' => (string) Str::uuid()];
    $this->identity = ['raw_phone' => '+12025550123', 'source_links' => [['source_type' => 'square_customer', 'source_id' => 'fictional-square']],
        'primary_source' => ['source_type' => 'square_customer', 'source_id' => 'fictional-square']];
    $this->customer = SquareCustomer::create(['tenant_id' => $this->tenant->id, 'square_customer_id' => 'fictional-square', 'phone' => '+12025550123',
        'raw_payload' => ['id' => 'fictional-square', 'phone_number' => '+12025550123', 'version' => 1, '_source_binding' => $this->binding]]);
    $this->canonical = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Buyer',
        'phone' => '+12025550123', 'normalized_phone' => '2025550123', 'email' => 'fictional@example.invalid', 'normalized_email' => 'fictional@example.invalid',
        'accepts_sms_marketing' => false, 'accepts_email_marketing' => false]);
    $this->duplicate = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'phone' => '+1 (202) 555-0123', 'normalized_phone' => '2025550123']);
    $this->sync = fn (bool $dry = false, array $context = []) => app(SquareCustomerIdentityService::class)->sync($this->identity, $this->tenant->id, $dry, array_replace($this->context, $context));
});

function automaticSquareShopifyProof($test, string $olderKey = 'retail:12345'): CustomerExternalProfile
{
    foreach ([$test->canonical, $test->duplicate] as $index => $profile) {
        MarketingProfileLink::create(['tenant_id' => $index === 1 ? null : $test->tenant->id, 'marketing_profile_id' => $profile->id,
            'source_type' => 'shopify_customer', 'source_id' => $index === 1 ? $olderKey : 'retail:12345', 'match_method' => $index === 1 ? 'exact_phone' : 'shopify_customer_webhook']);
    }

    return CustomerExternalProfile::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $test->canonical->id,
        'provider' => 'shopify', 'integration' => 'shopify_customer', 'store_key' => 'retail', 'external_customer_id' => '12345',
        'external_customer_gid' => 'gid://shopify/Customer/12345', 'phone' => '+12025550123', 'synced_at' => now()]);
}

test('enrolled source refresh collapses existing aliases and retains an audited source association once', function () {
    $this->duplicate->update(['merged_into_profile_id' => $this->canonical->id, 'archived_at' => now()]);
    $before = $this->canonical->fresh()->getAttributes();
    $first = ($this->sync)();
    $second = ($this->sync)();
    expect($first)->toMatchArray(['status' => 'updated', 'reason' => 'automatic_existing_canonical_aliases', 'profile_id' => $this->canonical->id, 'reviews_created' => 0]);
    expect($second['profile_id'])->toBe($this->canonical->id)->and(MarketingProfile::count())->toBe(2)
        ->and(MarketingProfileLink::where('source_type', 'square_customer')->count())->toBe(1)
        ->and(MarketingIdentityReview::count())->toBe(1)->and(MarketingIdentityReview::sole()->status)->toBe('resolved')
        ->and(data_get(MarketingIdentityReview::sole()->payload, 'automatic_resolution.proof.basis'))->toBe('existing_canonical_aliases')
        ->and($this->canonical->fresh()->getAttributes())->toBe($before)->and(MarketingConsentEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('same original Shopify customer selects the current external mapping and keeps duplicate rows separate', function () {
    $anchor = automaticSquareShopifyProof($this, 'retail:gid://shopify/Customer/12345');
    $result = ($this->sync)();
    $link = MarketingProfileLink::where('source_type', 'square_customer')->sole();
    expect($result['reason'])->toBe('automatic_same_original_shopify_customer')->and($result['profile_id'])->toBe($this->canonical->id)
        ->and($link->marketing_profile_id)->toBe($this->canonical->id)
        ->and(data_get($link->source_meta, 'automatic_resolution.proof.external_profile_ids'))->toBe([$anchor->id])
        ->and($this->duplicate->fresh()->merged_into_profile_id)->toBeNull()
        ->and($this->canonical->fresh()->accepts_sms_marketing)->toBeFalse()->and(MarketingConsentEvent::count())->toBe(0);
});

test('an existing contact-candidate review is resolved with its original evidence retained', function () {
    ($this->sync)(false, ['create_unmatched' => false]);
    $review = MarketingIdentityReview::sole();
    $original = $review->payload;
    automaticSquareShopifyProof($this);
    ($this->sync)();
    expect(MarketingIdentityReview::count())->toBe(1)->and($review->fresh()->status)->toBe('resolved')
        ->and($review->fresh()->payload['candidate_profile_ids'])->toBe($original['candidate_profile_ids'])
        ->and(data_get($review->fresh()->payload, 'automatic_resolution.marketing_permission_granted'))->toBeFalse();
});

test('preview reports an eligible automatic match without writing links reviews or customer fields', function () {
    automaticSquareShopifyProof($this);
    $before = $this->canonical->fresh()->getAttributes();
    expect(($this->sync)(true)['profile_id'])->toBe($this->canonical->id)
        ->and(MarketingProfileLink::where('source_type', 'square_customer')->count())->toBe(0)
        ->and(MarketingIdentityReview::count())->toBe(0)->and($this->canonical->fresh()->getAttributes())->toBe($before);
});

test('ordinary phone matches and matching names alone remain in review', function () {
    expect(($this->sync)()['status'])->toBe('review');
    $this->identity['raw_phone'] = null;
    $this->identity['first_name'] = 'Fictional';
    $this->identity['last_name'] = 'Buyer';
    $this->duplicate->update(['first_name' => 'Fictional', 'last_name' => 'Buyer']);
    automaticSquareShopifyProof($this);
    expect(($this->sync)()['status'])->toBe('review')->and(MarketingProfileLink::where('source_type', 'square_customer')->count())->toBe(0);
});

test('a single matching contact without duplicate source evidence remains in review', function () {
    $this->duplicate->delete();
    expect(($this->sync)()['status'])->toBe('review');
});

test('different original Shopify IDs and stores remain in review', function (string $key) {
    automaticSquareShopifyProof($this, $key);
    expect(($this->sync)()['status'])->toBe('review')->and(MarketingProfileLink::where('source_type', 'square_customer')->count())->toBe(0);
})->with(['different ID' => 'retail:54321', 'different store' => 'wholesale:12345', 'unqualified ID' => '12345']);

test('conflicting saved contact or person fields keep same-source duplicates in review', function (array $fields) {
    automaticSquareShopifyProof($this);
    $this->duplicate->update($fields);
    expect(($this->sync)()['status'])->toBe('review');
})->with([
    'email conflict' => [['email' => 'another@example.invalid', 'normalized_email' => 'another@example.invalid']],
    'person conflict' => [['first_name' => 'Different']],
]);

test('an incoming email conflicting with the surviving alias does not auto-associate', function () {
    $this->duplicate->update(['merged_into_profile_id' => $this->canonical->id]);
    $this->identity['raw_email'] = 'other@example.invalid';
    expect(($this->sync)()['status'])->toBe('review');
});

test('missing stale malformed or foreign external mappings cannot select a same-source duplicate', function (string $change) {
    $anchor = automaticSquareShopifyProof($this);
    match ($change) {
        'missing' => $anchor->delete(),
        'unsynced' => $anchor->update(['synced_at' => null]),
        'mismatched_gid' => $anchor->update(['external_customer_gid' => 'gid://shopify/Customer/999']),
        'foreign' => (function () use ($anchor) {
            $anchor->delete();
            $foreign = Tenant::create(['name' => 'Foreign', 'slug' => 'foreign-'.Str::random(7)]);
            $profile = MarketingProfile::create(['tenant_id' => $foreign->id]);
            CustomerExternalProfile::create(['tenant_id' => $foreign->id, 'marketing_profile_id' => $profile->id,
                'provider' => 'shopify', 'integration' => 'shopify_customer', 'store_key' => 'retail', 'external_customer_id' => '12345', 'synced_at' => now()]);
        })(),
    };
    expect(($this->sync)()['status'])->toBe('review');
})->with(['missing', 'unsynced', 'mismatched_gid', 'foreign']);

test('multiple current external destinations do not select by recency or row ID', function () {
    automaticSquareShopifyProof($this);
    CustomerExternalProfile::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->duplicate->id,
        'provider' => 'shopify', 'integration' => 'shopify_customer', 'store_key' => 'retail', 'external_customer_id' => 'gid://shopify/Customer/12345', 'synced_at' => now()]);
    expect(($this->sync)()['status'])->toBe('review');
});

test('the current external source contact must agree rather than borrowing a stale profile phone', function () {
    $anchor = automaticSquareShopifyProof($this);
    $anchor->update(['phone' => '+12025550999', 'normalized_phone' => '2025550999']);
    expect(($this->sync)()['status'])->toBe('review');
});

test('a newer cached Square contact cannot be associated using an older incoming payload', function () {
    $this->duplicate->update(['merged_into_profile_id' => $this->canonical->id]);
    $this->customer->update(['phone' => '+12025550999']);
    expect(($this->sync)()['status'])->toBe('review');
});

test('explicit conflicting original Square claims are retained for review even when aliases agree', function () {
    $this->duplicate->update(['merged_into_profile_id' => $this->canonical->id]);
    $foreign = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    MarketingProfileLink::create(['tenant_id' => null, 'marketing_profile_id' => $foreign->id, 'source_type' => 'square_customer', 'source_id' => 'fictional-square']);
    expect(($this->sync)()['status'])->toBe('review')->and(MarketingProfileLink::where('tenant_id', $this->tenant->id)->where('source_type', 'square_customer')->count())->toBe(0);
});

test('card collisions cannot supply the independent evidence for automatic association', function () {
    $this->duplicate->update(['merged_into_profile_id' => $this->canonical->id]);
    foreach (['fictional-square', 'another-square'] as $id) {
        CustomerExternalProfile::create(['tenant_id' => $this->tenant->id, 'provider' => 'square', 'integration' => 'square_customer', 'external_customer_id' => $id,
            'raw_metafields' => ['square' => ['source_binding' => $this->binding, 'cards' => [['id' => 'opaque-shared-card', 'fingerprint' => 'fictional-reference']]]]]);
    }
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->canonical->id, 'source_type' => 'square_customer', 'source_id' => 'another-square']);
    expect(($this->sync)()['status'])->toBe('review');
});

test('broken archived and cross-tenant aliases never become automatic destinations', function (string $change) {
    $target = match ($change) {
        'foreign' => MarketingProfile::create(['tenant_id' => Tenant::create(['name' => 'Other tenant', 'slug' => 'other-'.Str::random(7)])->id]),
        default => $this->canonical,
    };
    $this->duplicate->update(['merged_into_profile_id' => $target->id]);
    if ($change === 'cycle') {
        $this->canonical->update(['merged_into_profile_id' => $this->duplicate->id]);
    }
    if ($change === 'archived') {
        $this->canonical->update(['archived_at' => now()]);
    }
    expect(($this->sync)()['status'])->toBe('review');
})->with(['foreign', 'cycle', 'archived']);

test('automatic matching requires owner enrollment and current actor authorization', function () {
    automaticSquareShopifyProof($this);
    expect(($this->sync)(false, ['create_unmatched' => false])['status'])->toBe('review');
    $this->owner->tenants()->updateExistingPivot($this->tenant->id, ['membership_active' => false]);
    expect(fn () => ($this->sync)())->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(MarketingProfileLink::where('source_type', 'square_customer')->count())->toBe(0);
});

test('different merchant snapshots and previously staged source conflicts remain held', function (string $change) {
    automaticSquareShopifyProof($this);
    if ($change === 'merchant') {
        $this->customer->update(['raw_payload' => ['_source_binding' => ['merchant_id' => 'other', 'environment' => 'sandbox']]]);
    } else {
        MarketingIdentityReview::create(['status' => 'pending', 'source_type' => 'square_customer', 'source_id' => 'fictional-square',
            'conflict_reasons' => ['square_source_link_conflict'], 'payload' => ['source_context' => ['tenant_id' => $this->tenant->id] + $this->binding]]);
    }
    expect(($this->sync)()['status'])->toBe('review')->and(MarketingProfileLink::where('source_type', 'square_customer')->count())->toBe(0);
})->with(['merchant', 'recorded conflict']);

test('a contact collision beyond the bounded lookup cannot be treated as complete alias agreement', function () {
    $this->duplicate->update(['merged_into_profile_id' => $this->canonical->id]);
    foreach (range(1, 25) as $i) {
        MarketingProfile::create(['tenant_id' => $this->tenant->id, 'phone' => '+12025550123', 'normalized_phone' => '2025550123', 'merged_into_profile_id' => $this->canonical->id]);
    }
    expect(($this->sync)()['status'])->toBe('review');
    expect(app(SquareCustomerCandidateResolution::class)->resolve($this->tenant->id, $this->identity, range(1, 26)))->toBeNull();
});

test('scheduled and verified webhook reads use the same enrolled automatic resolution and audit policy', function (string $kind) {
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => null, 'marketing.square.enabled' => false]);
    $this->duplicate->update(['merged_into_profile_id' => $this->canonical->id]);
    \App\Models\TenantMarketingSetting::create(['tenant_id' => $this->tenant->id, 'key' => 'square_config', 'value' => [
        'connection_id' => 'automatic-reader', 'generation' => 1, 'merchant_id' => 'fictional-merchant', 'environment' => 'sandbox',
        'location_ids' => ['FICTIONALLOCATION'], 'status' => 'active', 'access_token' => 'fictional-auto-key']]);
    Http::fake(function ($request) {
        $payload = ['id' => 'fictional-square', 'phone_number' => '+12025550123', 'version' => 2];

        return Http::response(match (parse_url($request->url(), PHP_URL_PATH)) {
            '/v2/merchants/fictional-merchant' => ['merchant' => ['id' => 'fictional-merchant']],
            '/v2/locations/FICTIONALLOCATION' => ['location' => ['id' => 'FICTIONALLOCATION', 'merchant_id' => 'fictional-merchant', 'status' => 'ACTIVE']],
            '/v2/customers/search', '/v2/customers' => ['customers' => [$payload]],
            '/v2/customers/fictional-square' => ['customer' => $payload],
            '/v2/orders/search' => ['orders' => []],
            '/v2/payments' => ['payments' => []],
            default => throw new LogicException('Unexpected source endpoint '.$request->url()),
        });
    });
    $refresh = app(\App\Services\Marketing\SquareSourceRefresh::class);
    $policy = $refresh->configure($this->tenant->id, $this->owner, ['enabled' => true, 'expected_version' => $refresh->status($this->tenant->id)['version'],
        'connection_id' => 'automatic-reader', 'lookback_days' => 14, 'create_unmatched' => true]);
    $result = $kind === 'scheduled' ? $refresh->runTenant($this->tenant->id, 100)['customers']
        : $refresh->refreshObject($this->tenant->id, $policy['binding'] + ['policy_version' => $policy['version']], 'customer', 'fictional-square', (string) Str::uuid());
    expect($result['status'])->toBe('completed')->and($result['summary']['automatic_associations'])->toBe(1)
        ->and(MarketingProfileLink::where('source_type', 'square_customer')->sole()->marketing_profile_id)->toBe($this->canonical->id)
        ->and(data_get(MarketingIdentityReview::sole()->payload, 'automatic_resolution.source_refresh_policy_version'))->toBe($policy['version'])
        ->and(\App\Models\CandleCashTransaction::count())->toBe(0)->and(\App\Models\MarketingCampaign::forTenantId($this->tenant->id)->count())->toBe(0);
})->with(['scheduled', 'webhook']);
