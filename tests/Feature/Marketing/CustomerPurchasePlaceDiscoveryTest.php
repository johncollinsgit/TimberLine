<?php

use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CustomerPurchasePlaceMap;
use App\Services\Marketing\EventAudienceDefinition;
use App\Services\Marketing\MarketingSegmentEvaluator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['pos_live.enabled' => false, 'pos_live.runtime_id' => 'purchase_place_fixture']);
    $this->tenant = Tenant::create(['name' => 'Fictional place owner', 'slug' => 'purchase-place-'.Str::lower(Str::random(8))]);
    $this->owner = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $this->tenant->users()->attach($this->owner->id, ['role' => 'owner', 'membership_active' => true]);
    $this->places = app(CustomerPurchasePlaceMap::class);
    $this->buyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Distant buyer', 'country' => 'US', 'postal_code' => '75201',
        'email' => 'place-buyer@example.invalid', 'normalized_email' => 'place-buyer@example.invalid', 'accepts_email_marketing' => false, 'accepts_sms_marketing' => false]);
});

function placeMapConfigure($test, array $labels): array
{
    return $test->places->configure($test->tenant->id, $test->owner, $test->places->status($test->tenant->id)['version'], $labels);
}

function placePurchase($test, array $labels, string $status = 'COMPLETED', string $key = 'place'): void
{
    SquareOrder::create(['tenant_id' => $test->tenant->id, 'square_order_id' => $key.'-order', 'location_id' => 'FictionalLocation', 'state' => 'COMPLETED',
        'closed_at' => now()->subYear(), 'total_money_amount' => 1080, 'total_money_currency' => 'USD', 'raw_tax_names' => $labels,
        'raw_payload' => ['total_tax_money' => ['amount' => 80, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $test->tenant->id, 'square_payment_id' => $key.'-payment', 'square_order_id' => $key.'-order', 'location_id' => 'FictionalLocation',
        'status' => $status, 'amount_money' => 1080, 'currency' => 'USD', 'created_at_source' => now()->subYear(), 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    MarketingProfileLink::create(['tenant_id' => $test->tenant->id, 'marketing_profile_id' => $test->buyer->id, 'source_type' => 'square_payment', 'source_id' => $key.'-payment']);
}

function placeSegment($test): MarketingSegment
{
    return new MarketingSegment(['tenant_id' => $test->tenant->id, 'rules_json' => app(EventAudienceDefinition::class)->rules($test->tenant->id,
        ['kind' => 'area_buyers_nearby', 'postal_code' => '29201', 'radius_miles' => 50])]);
}

it('retains a versioned tenant-owned place map without changing homes permissions or original tax names', function (): void {
    $mapping = placeMapConfigure($this, ['Columbia, SC, Richland Co, 8%' => ['29201']]);
    expect($this->places->places($this->tenant->id, ['COLUMBIA, SC, RICHLAND CO, 8%'])['postal_codes'])->toBe(['29201'])
        ->and($this->places->places($this->tenant->id, ['8%']))->toBe([])
        ->and($this->buyer->fresh()->postal_code)->toBe('75201')->and($this->buyer->fresh()->accepts_email_marketing)->toBeFalse();
    $runs = MarketingImportRun::where('type', 'customer_purchase_place_mapping')->count();
    $this->places->configure($this->tenant->id, $this->owner, $mapping['version'], ['Columbia, SC, Richland Co, 8%' => ['29201']]);
    expect(MarketingImportRun::where('type', 'customer_purchase_place_mapping')->count())->toBe($runs);
    expect(fn () => $this->places->configure($this->tenant->id, $this->owner, str_repeat('0', 64), ['Columbia' => ['29201']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    expect(fn () => $this->places->configure($this->tenant->id, $this->owner, $mapping['version'], ['Columbia, SC, Richland Co, 8%' => ['NOTZIP']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
    Http::assertNothingSent();
});

it('saves reviewed places through Customers and rejects stale versions or non-owner writes', function (): void {
    $url = '/workspace/customers/reconciliation/purchase-place?tenant='.$this->tenant->slug;
    $input = ['expected_version' => $this->places->status($this->tenant->id)['version'], 'label' => 'Columbia original named tax', 'postal_codes' => '29201'];
    $this->actingAs($this->owner)->post($url, $input)->assertRedirect();
    $this->postJson($url, $input)->assertConflict();
    $current = $this->places->status($this->tenant->id);
    $this->post($url, ['expected_version' => $current['version'], 'label' => 'COLUMBIA ORIGINAL NAMED TAX', 'postal_codes' => '29201, 29169'])->assertRedirect();
    expect($this->places->status($this->tenant->id)['labels'])->toHaveCount(1)
        ->and($this->places->places($this->tenant->id, ['Columbia original named tax'])['postal_codes'])->toBe(['29169', '29201']);
    $this->get('/workspace/customers/reconciliation?tenant='.$this->tenant->slug)->assertOk()->assertSee('COLUMBIA ORIGINAL NAMED TAX');
    $viewer = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $this->tenant->users()->attach($viewer->id, ['role' => 'member', 'membership_active' => true]);
    $this->actingAs($viewer)->postJson($url, $input)->assertForbidden();
    expect($this->buyer->fresh()->postal_code)->toBe('75201')->and($this->buyer->fresh()->accepts_sms_marketing)->toBeFalse();
    Http::assertNothingSent();
});

it('uses a confirmed original payment order pointer for an area audience without claiming a home move or show attendance', function (): void {
    placeMapConfigure($this, ['Columbia, SC' => ['29201']]);
    placePurchase($this, ['Columbia, SC']);
    $evaluated = app(MarketingSegmentEvaluator::class)->evaluateProfile(placeSegment($this), $this->buyer);
    expect($evaluated['matched'])->toBeTrue()->and($evaluated['metrics']['purchase_location_groups'][0]['source_id'])->toBe('place-order')
        ->and($evaluated['metrics']['purchased_event_ids'])->toBe([])->and($this->buyer->fresh()->postal_code)->toBe('75201')
        ->and($this->buyer->fresh()->accepts_sms_marketing)->toBeFalse()->and(\App\Models\MarketingCampaign::forTenantId($this->tenant->id)->count())->toBe(0);
    expect(implode(' ', $evaluated['reasons']))->toContain('recorded tax place');
    $input = ['kind' => 'area_buyers_nearby', 'postal_code' => '29201', 'radius_miles' => 50];
    expect(app(EventAudienceDefinition::class)->candidateIds($this->tenant->id, $input))->toContain($this->buyer->id);
    $this->actingAs($this->owner)->postJson('/marketing/event-audiences/preview?tenant='.$this->tenant->slug, [...$input, 'preview_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('email_eligible', 0)->assertJsonPath('sms_eligible', 0);
    Http::assertNothingSent();
});

it('keeps incomplete payment and unresolved mixed tax locations out of geographic purchase evidence', function (string $status, array $labels): void {
    placeMapConfigure($this, ['Columbia' => ['29201'], 'Macon' => ['31201']]);
    placePurchase($this, $labels, $status);
    expect(app(MarketingSegmentEvaluator::class)->evaluateProfile(placeSegment($this), $this->buyer)['matched'])->toBeFalse();
})->with([
    'unconfirmed payment' => ['APPROVED', ['Columbia']],
    'unknown second location' => ['COMPLETED', ['Columbia', 'Unknown tax place']],
    'two far-away named places' => ['COMPLETED', ['Columbia', 'Macon']],
    'percentage alone' => ['COMPLETED', ['8%']],
]);

it('does not publish a conflicting customer purchase or another tenant source as marketing location truth', function (): void {
    placeMapConfigure($this, ['Columbia' => ['29201']]);
    placePurchase($this, ['Columbia']);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id, 'source_type' => 'square_order', 'source_id' => 'place-order']);
    expect(app(MarketingSegmentEvaluator::class)->evaluateProfile(placeSegment($this), $this->buyer)['matched'])->toBeFalse();
    $foreign = Tenant::create(['name' => 'Foreign place workspace', 'slug' => 'foreign-place-'.Str::lower(Str::random(8))]);
    expect($this->places->groupsForConfirmedSources($foreign->id, ['square_order:place-order']))->toBe([]);
    expect(fn () => $this->places->configure($foreign->id, $this->owner, $this->places->status($foreign->id)['version'], ['Columbia' => ['29201']]))->toThrow(\Symfony\Component\HttpKernel\Exception\HttpException::class);
});
