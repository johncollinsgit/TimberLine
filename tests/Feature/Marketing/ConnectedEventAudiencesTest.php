<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\EventAudienceDefinition;
use App\Services\Marketing\MarketingSegmentEvaluator;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function (): void {
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'pos_live.runtime_id' => 'audience_fixture']);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    $stock = app(StockLedger::class);
    $this->pool = $stock->createPool($this->tenant->id, 'audience-pool', $this->owner->id, 'Reviewed show', 'show', 'event:123')['pool_id'];
    $stock->rows('event_links', $this->tenant->id)->insert(['tenant_id' => $this->tenant->id, 'event_id' => 123, 'provider' => 'manual', 'external_id' => 'audience-event',
        'metadata' => json_encode(['name' => 'Reviewed show', 'timezone' => 'UTC', 'postal_code' => '29201', 'start_on' => '2026-11-13']), 'reviewed_by' => $this->owner->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->event = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'kind' => 'event', 'title' => 'Reviewed show',
        'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => ['_teams_enrichment' => ['commerce_event' => ['id' => 123],
            'square_orders' => ['ids' => ['audience-order'], 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String()]]]]);
    SquareOrder::create(['tenant_id' => $this->tenant->id, 'square_order_id' => 'audience-order', 'state' => 'COMPLETED', 'closed_at' => now()->subHour(), 'location_id' => 'audience-location',
        'total_money_amount' => 1080, 'total_money_currency' => 'USD', 'raw_payload' => ['total_tax_money' => ['amount' => 80, 'currency' => 'USD']]]);
    SquarePayment::create(['tenant_id' => $this->tenant->id, 'square_payment_id' => 'audience-payment', 'square_order_id' => 'audience-order', 'status' => 'COMPLETED', 'amount_money' => 1080, 'source_type' => 'CARD',
        'currency' => 'USD', 'location_id' => 'audience-location', 'raw_payload' => ['refunded_money' => ['amount' => 0, 'currency' => 'USD']]]);
    $this->buyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Buyer', 'email' => 'buyer@example.invalid', 'normalized_email' => 'buyer@example.invalid',
        'accepts_email_marketing' => true, 'country' => 'US', 'postal_code' => '75201']);
});

it('saves an event audience without sending and picks up a later explicit customer attribution', function (): void {
    $url = '/marketing/event-audiences?tenant='.$this->tenant->slug;
    $input = ['kind' => 'event_buyers', 'event_id' => 123, 'name' => 'Show buyers', 'request_id' => (string) Str::uuid()];
    $this->actingAs($this->owner)->get($url)->assertOk()->assertSee('Membership &amp; permissions', false);
    $campaignsBefore = \App\Models\MarketingCampaign::query()->count();
    $this->post($url, $input)->assertRedirect();
    $segment = MarketingSegment::forTenantId($this->tenant->id)->firstOrFail();
    expect(app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $this->buyer)['matched'])->toBeFalse();
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->buyer->id, 'source_type' => 'square_order', 'source_id' => 'audience-order']);
    $this->travel(6)->seconds();
    $preview = $this->postJson('/marketing/event-audiences/preview?tenant='.$this->tenant->slug, [...$input, 'preview_id' => (string) Str::uuid()])->assertOk();
    $preview->assertJsonPath('matched', 1)->assertJsonPath('email_eligible', 1)->assertJsonPath('sms_eligible', 0)->assertJsonPath('complete', true);
    expect($preview->json('samples.0.reasons.0'))->toBe('Confirmed purchase at Reviewed show');
    $this->post($url, $input)->assertRedirect();
    expect(MarketingSegment::forTenantId($this->tenant->id)->count())->toBe(1);
    expect(\App\Models\MarketingCampaign::query()->count())->toBe($campaignsBefore);
    Http::assertNothingSent();
});

it('combines previous series buyers outside the radius with nearby saved US locations and enforces tenant access', function (): void {
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->buyer->id, 'source_type' => 'square_order', 'source_id' => 'audience-order']);
    $series = TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'kind' => 'series', 'title' => 'Recurring show', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => []]);
    TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'area_id' => $this->area->id, 'kind' => 'event_link', 'title' => 'Reviewed series link', 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1, 'data' => ['series_id' => $series->id, 'occurrence_id' => $this->event->id]]);
    $near = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Nearby', 'country' => 'US', 'postal_code' => '29201']);
    MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Unknown location']);
    $input = ['kind' => 'previous_show_nearby', 'series_id' => $series->id, 'postal_code' => '29201', 'radius_miles' => 50];
    $this->actingAs($this->owner)->postJson('/marketing/event-audiences/preview?tenant='.$this->tenant->slug, [...$input, 'preview_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('matched', 2)->assertJsonPath('workspace_missing_locations', 1)->assertJsonPath('email_eligible', 1);
    [$other, $owner] = teamWorkspace();
    $this->postJson('/marketing/event-audiences/preview?tenant='.$this->tenant->slug, [...$input, 'series_id' => (string) Str::uuid(), 'preview_id' => (string) Str::uuid()])->assertUnprocessable();
    $this->actingAs($owner)->postJson('/marketing/event-audiences/preview?tenant='.$this->tenant->slug, [...$input, 'preview_id' => (string) Str::uuid()])->assertForbidden();
});

it('includes original Square product identities without inferring scents or including another tenants catalog', function (): void {
    $order = SquareOrder::forTenantId($this->tenant->id)->firstOrFail();
    $order->update(['raw_payload' => ['line_items' => [
        ['catalog_object_id' => 'square-candle', 'name' => 'Mountain candle', 'variation_name' => 'Forest', 'quantity' => '2.000'],
        ['name' => 'Unknown identity', 'quantity' => '1'],
        ['catalog_object_id' => 'fractional', 'name' => 'Recorded fractional quantity', 'quantity' => '1.5'],
    ]]]);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->buyer->id, 'source_type' => 'square_order', 'source_id' => 'audience-order']);
    [$other] = teamWorkspace();
    SquareOrder::create(['tenant_id' => $other->id, 'square_order_id' => 'foreign-order', 'state' => 'COMPLETED',
        'raw_payload' => ['line_items' => [['catalog_object_id' => 'foreign-product', 'name' => 'Foreign product', 'quantity' => '1']]]]);
    $options = app(EventAudienceDefinition::class)->options($this->tenant->id);
    expect(array_column($options['products'], 'id'))->toContain('square_catalog:square-candle')->not->toContain('square_catalog:foreign-product');
    $input = ['kind' => 'product_buyers', 'item_key' => 'square_catalog:square-candle'];
    $rules = app(EventAudienceDefinition::class)->rules($this->tenant->id, $input);
    $evaluated = app(MarketingSegmentEvaluator::class)->evaluateProfile(new MarketingSegment(['tenant_id' => $this->tenant->id, 'rules_json' => $rules]), $this->buyer);
    expect($evaluated['matched'])->toBeTrue();
    $behavior = app(\App\Services\Marketing\CustomerEventValueService::class)->forProfile($this->buyer, $this->tenant->id, true)['behavior'] ?? null;
    expect($behavior['purchased_scent_keys'])->toBe([]);
    expect($behavior['most_purchased_products'][0]['units'])->toBe(2);
});

it('matches stable event identifiers exactly and excludes suppressed contacts from eligible audience counts', function (): void {
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->buyer->id, 'source_type' => 'square_order', 'source_id' => 'audience-order']);
    $segment = new MarketingSegment(['tenant_id' => $this->tenant->id, 'rules_json' => ['logic' => 'and', 'conditions' => [['field' => 'purchased_event_ids', 'operator' => 'contains_exact', 'value' => '12']]]]);
    expect(app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $this->buyer)['matched'])->toBeFalse();
    $segment->rules_json = ['logic' => 'and', 'conditions' => [['field' => 'purchased_event_ids', 'operator' => 'contains_exact', 'value' => '123']]];
    expect(app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $this->buyer)['matched'])->toBeTrue();
    app(\App\Services\Marketing\MessagingContactChannelStateService::class)->markEmailStatus($this->tenant->id, $this->buyer, $this->buyer->email, 'suppressed', 'fictional_source_review');
    $this->actingAs($this->owner)->postJson('/marketing/event-audiences/preview?tenant='.$this->tenant->slug, ['kind' => 'event_buyers', 'event_id' => 123, 'preview_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('email_eligible', 0)->assertJsonPath('missing_email_permission', 1);
    Http::assertNothingSent();
});

it('keeps unknown net spend out of numeric low-spend segments when original refund evidence is incomplete', function (): void {
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->buyer->id, 'source_type' => 'square_order', 'source_id' => 'audience-order']);
    SquarePayment::forTenantId($this->tenant->id)->sole()->update(['raw_payload' => []]);
    foreach (['lt', 'lte', 'gt', 'gte'] as $operator) {
        $segment = new MarketingSegment(['tenant_id' => $this->tenant->id, 'rules_json' => ['logic' => 'and', 'conditions' => [['field' => 'total_spent', 'operator' => $operator, 'value' => 1]]]]);
        $result = app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $this->buyer);
        expect($result['matched'])->toBeFalse()->and($result['metrics']['total_spent'])->toBeNull()->and($result['metrics']['total_orders'])->toBe(1);
    }
});
