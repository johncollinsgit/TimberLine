<?php

use App\Models\MarketingConsentEvent;
use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\SquareCustomerIdentityService;
use App\Services\Marketing\SquareCustomerSource;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/CustomerProfileJson.php';

beforeEach(function () {
    Http::preventStrayRequests();
    $this->tenant = Tenant::create(['name' => 'Fictional Square shop', 'slug' => 'square-review-'.Str::lower(Str::random(8))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $this->owner->tenants()->attach($this->tenant, ['role' => 'owner', 'membership_active' => true]);
    $this->source = app(SquareCustomerSource::class);
    $this->identities = app(SquareCustomerIdentityService::class);
    $this->customer = SquareCustomer::create(['tenant_id' => $this->tenant->id, 'square_customer_id' => 'customer-fixture',
        'given_name' => 'Fictional', 'family_name' => 'Buyer', 'email' => 'fictional@example.invalid', 'phone' => '+12025550123',
        'raw_payload' => ['id' => 'customer-fixture', 'given_name' => 'Fictional', 'family_name' => 'Buyer', 'email_address' => 'fictional@example.invalid', 'version' => 1, 'preferences' => ['email_unsubscribed' => false]]]);
});

function squareReviewIdentity(): array
{
    return ['first_name' => 'Fictional', 'last_name' => 'Buyer', 'raw_email' => 'fictional@example.invalid', 'raw_phone' => '+12025550123',
        'source_links' => [['source_type' => 'square_customer', 'source_id' => 'customer-fixture']], 'primary_source' => ['source_type' => 'square_customer', 'source_id' => 'customer-fixture']];
}

function squareReviewCommand($test, MarketingIdentityReview $review, array $changes = []): array
{
    return array_replace(['request_id' => (string) Str::uuid(), 'expected_version' => $test->identities->version($review), 'decision' => 'create_new',
        'identity_confirmed' => true, 'reason' => 'Owner independently reviewed this original source customer.'], $changes);
}

test('Square contact visibility and false unsubscribe indicators never provide affirmative permission', function () {
    $choice = $this->source->consent(['phone_number' => '+12025550123', 'preferences' => ['email_unsubscribed' => false, 'sms_unsubscribed' => false]]);
    expect($choice['accepts_email_marketing'])->toBeNull()->and($choice['accepts_sms_marketing'])->toBeNull();
    expect($this->source->observedPreferences([])['email']['state'])->toBe('unknown');
    expect($this->source->observedPreferences(['preferences' => ['email_unsubscribed' => false]])['email'])->toMatchArray(['state' => 'not_unsubscribed', 'affirmative_permission_proven' => false]);
    expect($this->source->consent(['preferences' => ['email_unsubscribed' => true]])['accepts_email_marketing'])->toBeFalse();
});

test('same email phone and name stage review without linking an existing account', function () {
    $existing = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'fictional@example.invalid', 'normalized_email' => 'fictional@example.invalid', 'first_name' => 'Fictional', 'last_name' => 'Buyer']);
    $result = $this->identities->sync(squareReviewIdentity(), $this->tenant->id, false);
    expect($result['status'])->toBe('review')->and(MarketingProfileLink::count())->toBe(0)->and(MarketingProfile::count())->toBe(1);
    $review = MarketingIdentityReview::sole();
    expect($review->payload['candidate_profile_ids'])->toContain($existing->id)->and(data_get($review->payload, 'source_context.tenant_id'))->toBe($this->tenant->id);
});

test('an existing explicit Square source link maintains its canonical alias without changing contact details', function () {
    $canonical = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'verified@example.invalid', 'normalized_email' => 'verified@example.invalid']);
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $canonical->id, 'archived_at' => now()]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $alias->id, 'source_type' => 'square_customer', 'source_id' => 'customer-fixture']);
    $result = $this->identities->sync(squareReviewIdentity(), $this->tenant->id, false);
    expect($result['profile_id'])->toBe($canonical->id)->and($canonical->fresh()->email)->toBe('verified@example.invalid')->and(MarketingIdentityReview::count())->toBe(0);
});

test('owner with global member role can review once without consent or historical rewards creation', function () {
    $this->identities->sync(squareReviewIdentity(), $this->tenant->id, false, ['merchant_id' => 'merchant-fixture', 'environment' => 'sandbox']);
    $order = SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'original-order', 'square_customer_id' => 'customer-fixture', 'location_id' => 'original-location', 'total_money_amount' => 5000, 'raw_payload' => ['_source_binding' => ['merchant_id' => 'merchant-fixture', 'environment' => 'sandbox']]]);
    $review = MarketingIdentityReview::sole();
    $command = squareReviewCommand($this, $review);
    $first = $this->identities->review($this->tenant->id, $this->owner, $review, $command);
    $second = $this->identities->review($this->tenant->id, $this->owner, $review, $command);
    expect(customerProfileJsonValue($second))->toBe(customerProfileJsonValue($first))->and(MarketingProfile::count())->toBe(1)->and(MarketingProfileLink::count())->toBe(2)
        ->and(MarketingProfile::sole()->accepts_email_marketing)->toBeFalse()->and(MarketingProfile::sole()->accepts_sms_marketing)->toBeFalse()
        ->and(MarketingConsentEvent::count())->toBe(0)->and($order->fresh()->total_money_amount)->toBe(5000)->and($first['rewards_earned'])->toBe(0);
    Http::assertNothingSent();
});

test('source changes reject stale review and foreign tenant cannot inspect or resolve it', function () {
    $this->identities->sync(squareReviewIdentity(), $this->tenant->id, false);
    $review = MarketingIdentityReview::sole();
    $command = squareReviewCommand($this, $review);
    $this->customer->update(['raw_payload' => ['id' => 'customer-fixture', 'version' => 2]]);
    expect(fn () => $this->identities->review($this->tenant->id, $this->owner, $review, $command))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    $foreign = Tenant::create(['name' => 'Other shop', 'slug' => 'square-other-'.Str::random(5)]);
    $this->owner->tenants()->attach($foreign, ['role' => 'owner', 'membership_active' => true]);
    expect(fn () => $this->identities->review($foreign->id, $this->owner, $review, $command))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(MarketingProfile::count())->toBe(0);
});

test('link review preserves verified contact identity and positive independent marketing history', function () {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'verified@example.invalid', 'normalized_email' => 'verified@example.invalid', 'accepts_email_marketing' => true, 'accepts_sms_marketing' => true]);
    $this->identities->sync(squareReviewIdentity(), $this->tenant->id, false);
    $review = MarketingIdentityReview::sole();
    $this->identities->review($this->tenant->id, $this->owner, $review, squareReviewCommand($this, $review, ['decision' => 'link_existing', 'profile_id' => $profile->id]));
    expect($profile->fresh()->email)->toBe('verified@example.invalid')->and($profile->fresh()->accepts_email_marketing)->toBeTrue()->and($profile->fresh()->accepts_sms_marketing)->toBeTrue();
});

test('card projection excludes sensitive fields and refuses foreign customer or merchant references', function () {
    $safe = $this->source->cards([['id' => 'opaque-reference', 'customer_id' => 'customer-fixture', 'merchant_id' => 'merchant-fixture', 'card_brand' => 'VISA', 'last_4' => '1234',
        'fingerprint' => 'provider-reference-only', 'pan' => 'never-store', 'cvv' => 'never-store', 'bin' => 'never-store', 'billing_address' => ['private' => 'never-store']]], 'customer-fixture', 'merchant-fixture');
    expect($safe[0])->toHaveKeys(['id', 'last_4', 'fingerprint'])->not->toHaveKeys(['pan', 'cvv', 'bin', 'billing_address']);
    expect($this->source->cards($safe, 'foreign-customer', 'merchant-fixture'))->toBe([])->and($this->source->cards($safe, 'customer-fixture', 'foreign-merchant'))->toBe([]);
});

test('legacy inferred Square permission is visible as a review signal without mass permission changes', function () {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'proof@example.invalid', 'accepts_email_marketing' => true, 'accepts_sms_marketing' => true]);
    MarketingConsentEvent::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'channel' => 'email', 'event_type' => 'imported', 'source_type' => 'square_customer_sync', 'occurred_at' => now()]);
    expect($this->source->legacyConsentRisk($profile)['email']['review_required'])->toBeTrue()->and($profile->fresh()->accepts_email_marketing)->toBeTrue();
    MarketingConsentEvent::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'channel' => 'email', 'event_type' => 'confirmed', 'source_type' => 'verified_customer_choice', 'details' => ['email' => 'proof@example.invalid'], 'occurred_at' => now()]);
    expect($this->source->legacyConsentRisk($profile)['email']['review_required'])->toBeFalse();
});

test('provider unsubscribe suppresses only its exact current normalized contact and preserves independently changed contacts', function () {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'new@example.invalid', 'phone' => '+12025550123', 'accepts_email_marketing' => true, 'accepts_sms_marketing' => true]);
    $this->source->applyConsent($profile, ['id' => 'customer-fixture', 'email_address' => 'old@example.invalid', 'phone_number' => '+1 (202) 555-0123', 'preferences' => ['email_unsubscribed' => true, 'sms_unsubscribed' => true]]);
    expect($profile->fresh()->accepts_email_marketing)->toBeTrue()->and($profile->fresh()->accepts_sms_marketing)->toBeFalse();
    $this->source->applyConsent($profile, ['id' => 'customer-fixture', 'email_address' => 'NEW@example.invalid', 'preferences' => ['email_unsubscribed' => true]]);
    expect($profile->fresh()->accepts_email_marketing)->toBeFalse();
});

test('legacy inferred grant after a revocation cannot borrow earlier affirmative evidence or another contact', function () {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'email' => 'current@example.invalid', 'accepts_email_marketing' => true]);
    MarketingConsentEvent::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'channel' => 'email', 'event_type' => 'confirmed', 'source_type' => 'verified_customer_choice', 'details' => ['email' => 'current@example.invalid'], 'occurred_at' => now()->subDays(3)]);
    MarketingConsentEvent::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'channel' => 'email', 'event_type' => 'revoked', 'source_type' => 'customer_choice', 'occurred_at' => now()->subDays(2)]);
    MarketingConsentEvent::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'channel' => 'email', 'event_type' => 'imported', 'source_type' => 'square_customer_sync', 'occurred_at' => now()->subDay()]);
    expect($this->source->legacyConsentRisk($profile)['email']['review_required'])->toBeTrue();
    MarketingConsentEvent::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'channel' => 'email', 'event_type' => 'confirmed', 'source_type' => 'verified_customer_choice', 'details' => ['email' => 'previous@example.invalid'], 'occurred_at' => now()]);
    expect($this->source->legacyConsentRisk($profile)['email']['review_required'])->toBeTrue()->and($profile->fresh()->accepts_email_marketing)->toBeTrue();
});

test('reviewed history withholds unproven merchant namespaces and conflicting customer claims', function () {
    $binding = ['merchant_id' => 'merchant-fixture', 'environment' => 'sandbox'];
    $this->identities->sync(squareReviewIdentity(), $this->tenant->id, false, $binding);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'FOREIGN', 'square_customer_id' => 'customer-fixture', 'raw_payload' => ['_source_binding' => ['merchant_id' => 'other-merchant', 'environment' => 'sandbox']]]);
    $review = MarketingIdentityReview::sole();
    $result = $this->identities->review($this->tenant->id, $this->owner, $review, squareReviewCommand($this, $review));
    expect($result['history_link_conflicts'])->toBe(1)->and(MarketingProfileLink::where('source_type', 'square_order')->count())->toBe(0);
    $profile = MarketingProfile::find($result['profile_id']);
    $order = SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'AMBIGUOUS', 'square_customer_id' => 'customer-fixture', 'raw_payload' => ['_source_binding' => $binding, 'line_items' => [['name' => 'Fictional candle', 'quantity' => '2']]]]);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id, 'source_type' => 'square_order', 'source_id' => $order->square_order_id]);
    $projection = $this->source->forStaff($profile, $this->tenant->id);
    expect($projection['history']['orders'])->toBe([])->and($projection['issues'])->toContain('square_purchase_identity_review_required');
});

test('recorded original item labels quantities and locations remain separate from inferred scents events and payment authority', function () {
    $profile = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => 'ORIGINAL']);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'ORIGINAL', 'location_id' => 'RECORDEDLOCATION', 'total_money_amount' => 2500, 'raw_payload' => ['line_items' => [['name' => '<b>Original candle</b>', 'quantity' => '2', 'variation_name' => 'Recorded variant']]]]);
    $projection = $this->source->forStaff($profile, $this->tenant->id);
    expect($projection['history']['orders'][0])->toMatchArray(['location_id' => 'RECORDEDLOCATION', 'location_is_event' => false, 'total_amount' => 2500])
        ->and($projection['history']['orders'][0]['items'][0])->toMatchArray(['label' => '<b>Original candle</b>', 'quantity' => '2', 'scent_inferred' => false]);
    $html = view('customers.square-source', ['squareSource' => $projection, 'tenant' => $this->tenant])->render();
    expect($html)->toContain('&lt;b&gt;Original candle&lt;/b&gt;', '2 ×', 'RECORDEDLOCATION')->not->toContain('<b>Original candle</b>');
});
