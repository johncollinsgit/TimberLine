<?php

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\MarketingSegment;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\CustomerEventValueService;
use App\Services\Marketing\CustomerLocationDistance;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\Marketing\MarketingSegmentEvaluator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';
require_once __DIR__.'/../../Support/PayMirrorAttributionFixture.php';

function leanEventOrder($tenant, MarketingProfile $profile, string $id): void
{
    SquareOrder::create(['tenant_id' => $tenant->id, 'square_order_id' => $id, 'state' => 'COMPLETED', 'closed_at' => now()->subHour(),
        'location_id' => 'lean-location', 'total_money_amount' => 1080, 'total_money_currency' => 'USD',
        'raw_payload' => ['total_tax_money' => ['amount' => 80, 'currency' => 'USD'], 'line_items' => [['catalog_object_id' => 'lean-candle', 'quantity' => '1']]]]);
    SquarePayment::create(['tenant_id' => $tenant->id, 'square_payment_id' => 'payment-'.$id, 'square_order_id' => $id, 'status' => 'COMPLETED',
        'amount_money' => 1080, 'currency' => 'USD', 'location_id' => 'lean-location', 'source_type' => 'CARD', 'created_at_source' => now()->subHour(), 'raw_payload' => []]);
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $profile->id, 'source_type' => 'square_order', 'source_id' => $id]);
}

function leanEventReview($tenant, $owner, int $id): void
{
    app(StockLedger::class)->rows('event_links', $tenant->id)->insert(['tenant_id' => $tenant->id, 'event_id' => $id, 'provider' => 'manual',
        'external_id' => 'lean-reviewed-'.$id, 'metadata' => json_encode(['name' => 'Reviewed show '.$id, 'timezone' => 'UTC']), 'reviewed_by' => $owner->id]);
}

function leanEventRecord($tenant, $owner, $area, int $eventId, array $ids): TeamOperationRecord
{
    return TeamOperationRecord::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
        'kind' => 'event', 'title' => 'Reviewed show '.$eventId, 'source_key' => hash('sha256', Str::uuid()), 'status' => 'accepted', 'version' => 1,
        'data' => ['_teams_enrichment' => ['commerce_event' => ['id' => $eventId], 'square_orders' => ['ids' => $ids,
            'reviewed_by' => $owner->id, 'reviewed_at' => now()->toIso8601String()]]]]);
}

function leanEventCapture(MarketingProfile $profile, string $id, mixed $date): MarketingProfileLink
{
    return MarketingProfileLink::create(['tenant_id' => $profile->tenant_id, 'marketing_profile_id' => $profile->id, 'source_type' => 'pos_event_capture', 'source_id' => $id,
        'source_meta' => ['event_id' => 123, 'captured_at' => $date, 'capture_evidence_version' => 1, 'record_origin' => 'selected']]);
}

beforeEach(function (): void {
    $this->freezeTime();
    $this->withoutVite();
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_operations.enabled' => true, 'pos_live.runtime_id' => 'lean_event_fixture']);
    [$this->tenant, $this->owner] = teamWorkspace();
    $this->area = studioArea($this->tenant);
    leanEventReview($this->tenant, $this->owner, 123);
    $this->buyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Explicit buyer', 'email' => Str::uuid().'@example.invalid', 'accepts_email_marketing' => true]);
});

it('bounds typed event proof to selected originals across many buyers and unrelated linked histories without loading the catalog rewards or behavior', function (): void {
    $buyers = collect([$this->buyer]);
    for ($i = 1; $i < 16; $i++) {
        $buyers->push(MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Buyer '.$i]));
    }
    $selected = [];
    foreach ($buyers as $buyer) {
        $id = 'selected-'.$buyer->id;
        $selected[] = $id;
        leanEventOrder($this->tenant, $buyer, $id);
        for ($i = 0; $i < 8; $i++) {
            leanEventOrder($this->tenant, $buyer, 'unrelated-'.$buyer->id.'-'.$i);
        }
    }
    leanEventRecord($this->tenant, $this->owner, $this->area, 123, $selected);
    DB::enableQueryLog();
    DB::flushQueryLog();
    $this->actingAs($this->owner)->postJson('/marketing/event-audiences/preview?tenant='.$this->tenant->slug,
        ['kind' => 'event_buyers', 'event_id' => 123, 'preview_id' => (string) Str::uuid()])->assertOk()->assertJsonPath('matched', 16)->assertJsonPath('complete', true);
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();
    $originalReads = $queries->filter(fn ($q) => str_contains(str_replace(['"', '`'], '', $q['query']), 'from square_orders'));
    foreach ($originalReads as $query) {
        expect(collect($query['bindings'])->contains(fn ($binding) => is_string($binding) && str_starts_with($binding, 'unrelated-')))->toBeFalse();
        expect(str_replace(['"', '`'], '', $query['query']))->not->toContain('order by id asc limit 5000');
    }
    // One shared event order read plus one selected-source read per buyer;
    // the eight unrelated linked originals per buyer never enter these reads.
    expect($originalReads)->toHaveCount(17)->and($queries->count())->toBeLessThan(850)
        ->and($queries->contains(fn ($q) => preg_match('/from ["`]*(candle_cash|order_lines|website_order_lines)/', $q['query']) === 1))->toBeFalse();
    // The full original history is still authoritative and unchanged, including unknown refunds.
    $full = app(CustomerCommerceSnapshotService::class)->forReportingProfile($this->buyer, $this->tenant->id);
    expect($full['purchases'])->toHaveCount(9)->and(collect($full['purchases'])->every(fn ($p) => $p['confirmed'] && $p['refunded_minor'] === null))->toBeTrue();
    $segment = new MarketingSegment(['tenant_id' => $this->tenant->id, 'rules_json' => ['logic' => 'and', 'conditions' => [['field' => 'purchased_event_ids', 'operator' => 'contains_exact', 'value' => '123']]]]);
    foreach ($buyers as $buyer) {
        expect(app(MarketingSegmentEvaluator::class)->evaluateProfile($segment, $buyer)['matched'])->toBeTrue();
    }
    Http::assertNothingSent();
});

it('retains original allocation priority ambiguity reviewer revocation and unknown refunds in the selected shared relationship proof', function (string $change): void {
    leanEventOrder($this->tenant, $this->buyer, 'original-selected');
    $record = leanEventRecord($this->tenant, $this->owner, $this->area, 123, ['original-selected']);
    $link = MarketingProfileLink::forTenantId($this->tenant->id)->where('source_type', 'square_order')->firstOrFail();
    $link->update(['source_meta' => ['event_attribution' => ['event_id' => 123, 'reviewed_by' => $this->owner->id,
        'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => 'Explicit original source review']]]);
    $reader = app(CustomerEventValueService::class);
    $sources = [['type' => 'square_order', 'id' => 'original-selected']];
    expect($reader->eventSourceRelations($this->buyer, $this->tenant->id, 123, $sources)['confirmed_source_keys'])->toBe(['square_order:original-selected']);
    $selected = app(CustomerCommerceSnapshotService::class)->forSelectedSources($this->buyer, $this->tenant->id, $sources);
    expect($selected['purchases'][0]['refunded_minor'])->toBeNull()->and($selected['behavior'])->toBeNull()->and($selected['rewards'])->toBeNull();
    if ($change === 'priority' || $change === 'ambiguity') {
        leanEventReview($this->tenant, $this->owner, 124);
        leanEventRecord($this->tenant, $this->owner, $this->area, 124, ['original-selected']);
        if ($change === 'priority') {
            $record->update(['status' => 'withdrawn']);
        }
    } elseif ($change === 'revoked_reviewer') {
        DB::table('tenant_user')->where(['tenant_id' => $this->tenant->id, 'user_id' => $this->owner->id])->update(['membership_active' => false]);
    } else {
        $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Conflicting source owner']);
        MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id, 'source_type' => 'square_payment', 'source_id' => 'payment-original-selected']);
    }
    $this->travel(6)->seconds();
    $proof = $reader->eventSourceRelations($this->buyer, $this->tenant->id, 123, $sources);
    $full = $reader->forProfile($this->buyer, $this->tenant->id, true);
    expect($proof['confirmed_source_keys'])->toBe([])
        ->and(collect($full['purchase_journey'])->filter(fn ($p) => ($p['purchase_event']['event']['id'] ?? null) === 123 && $p['confirmed_purchase']))->toBeEmpty();
    Http::assertNothingSent();
})->with(['priority', 'ambiguity', 'revoked_reviewer', 'conflicting_source']);

it('reproves the current selected originals on the next preview batch instead of trusting previously saved candidate IDs', function (): void {
    $ids = [];
    for ($i = 0; $i < 101; $i++) {
        $buyer = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Paged buyer '.$i]);
        $ids[] = $id = 'paged-selected-'.$i;
        leanEventOrder($this->tenant, $buyer, $id);
    }
    leanEventRecord($this->tenant, $this->owner, $this->area, 123, $ids);
    $input = ['kind' => 'event_buyers', 'event_id' => 123, 'preview_id' => (string) Str::uuid()];
    $url = '/marketing/event-audiences/preview?tenant='.$this->tenant->slug;
    $first = $this->actingAs($this->owner)->postJson($url, $input)->assertOk()->assertJsonPath('matched', 100)
        ->assertJsonPath('candidates', 101)->assertJsonPath('complete', false);
    SquarePayment::forTenantId($this->tenant->id)->where('square_payment_id', 'payment-paged-selected-100')->update(['status' => 'FAILED']);
    $this->withCredentials()->withCookie(config('session.cookie'), $first->getCookie(config('session.cookie'))->getValue())
        ->postJson($url, $input)->assertOk()->assertJsonPath('matched', 100)->assertJsonPath('candidates', 101)->assertJsonPath('offset', 101)->assertJsonPath('complete', true);
    Http::assertNothingSent();
});

it('validates capture dates and canonical aliases and keeps selected tender priority identical to full history', function (): void {
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->buyer->id, 'archived_at' => now()]);
    leanEventCapture($alias, 'valid-capture', now()->subHour()->toIso8601String());
    $invalid = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Invalid capture']);
    leanEventCapture($invalid, 'future-capture', now()->addHour()->toIso8601String());
    leanEventCapture($invalid, 'undated-capture', null);
    $url = '/marketing/event-audiences/preview?tenant='.$this->tenant->slug;
    $response = $this->actingAs($this->owner)->postJson($url, ['kind' => 'captured_contacts', 'event_id' => 123, 'preview_id' => (string) Str::uuid()])
        ->assertOk()->assertJsonPath('matched', 1)->assertJsonPath('samples.0.id', $this->buyer->id);
    expect($response->json('samples.0.reasons'))->toBe(['Contact captured at Reviewed show 123']);
    leanEventOrder($this->tenant, $alias, 'linked-order');
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $alias->id, 'source_type' => 'square_payment', 'source_id' => 'payment-linked-order',
        'source_meta' => ['event_attribution' => ['event_id' => 123, 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => 'Explicit tender-only review']]]);
    $history = app(CustomerCommerceSnapshotService::class)->forSelectedSources($this->buyer, $this->tenant->id, [['type' => 'square_payment', 'id' => 'payment-linked-order']]);
    expect($history['purchases'])->toBe([])->and(app(CustomerCommerceSnapshotService::class)->forReportingProfile($this->buyer, $this->tenant->id)['purchases'][0]['source'])->toBe('square_order');
    $reader = app(CustomerEventValueService::class);
    $sources = [['type' => 'square_payment', 'id' => 'payment-linked-order']];
    expect($reader->eventSourceRelations($this->buyer, $this->tenant->id, 123, $sources)['confirmed_source_keys'])->toBe([])
        ->and($reader->forProfile($this->buyer, $this->tenant->id, true)['purchase_journey'][0]['purchase_event'])->toBeNull();
    MarketingProfileLink::forTenantId($this->tenant->id)->where('source_type', 'square_order')->delete();
    expect($reader->eventSourceRelations($this->buyer, $this->tenant->id, 123, $sources)['confirmed_source_keys'])->toBe(['square_payment:payment-linked-order'])
        ->and($reader->forProfile($this->buyer, $this->tenant->id, true)['purchase_journey'][0]['purchase_event']['event']['id'])->toBe(123);
    [$foreign] = teamWorkspace();
    try {
        app(CustomerEventValueService::class)->eventSourceRelations($this->buyer, $foreign->id, 123, []);
        test()->fail('A foreign tenant must not admit the source.');
    } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
        expect($error->getStatusCode())->toBe(404);
    }
    Http::assertNothingSent();
});

it('keeps known guest pending and differently selected Pay mirrors outside lean event membership', function (string $state): void {
    $fixture = payMirrorAttributionFixture('square_order', true, $state === 'pending' ? 'unknown' : 'succeeded', $state === 'different_customer');
    extract($fixture);
    leanEventReview($tenant, $actor, 123);
    MarketingProfileLink::forTenantId($tenant->id)->where('source_type', 'square_order')->update(['source_meta' => json_encode(['event_attribution' => [
        'event_id' => 123, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => 'Original review']])]);
    if ($state === 'different_customer') {
        $different = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Explicit original customer']);
        $snapshot['customer'] = ['id' => $different->id];
        $store->rows('sales', $tenant->id)->where('id', $saleId)->update(['snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot)]);
    }
    expect(app(EventCustomerCohort::class)->read($tenant->id, 123, selectedEvidence: true)['profile_ids'])->toBe([]);
    Http::assertNothingSent();
})->with(['guest', 'pending', 'different_customer']);

it('admits a raw native receipt through its runtime-prefixed retained alias link and preserves native priority over its Square mirror', function (): void {
    $fixture = payMirrorAttributionFixture('square_order', selected: true);
    extract($fixture);
    leanEventReview($tenant, $actor, 123);
    $pool = app(StockLedger::class)->createPool($tenant->id, 'lean-native-pool', $actor->id, 'Original show pool', 'show', 'event:123')['pool_id'];
    $snapshot['terms']['stock_pool_id'] = $pool;
    $store->rows('sales', $tenant->id)->where('id', $saleId)->update(['snapshot' => $store->encrypt($snapshot), 'snapshot_hash' => \App\Services\PaymentsCore\Contract::hash($snapshot)]);
    $alias = MarketingProfile::create(['tenant_id' => $tenant->id, 'merged_into_profile_id' => $profile->id, 'archived_at' => now()]);
    MarketingProfileLink::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $alias->id, 'source_type' => 'pos_sale', 'source_id' => $store->runtime().':'.$saleId]);
    $sources = [['type' => 'pos_sale', 'id' => $saleId], ['type' => 'square_order', 'id' => $orderId]];
    $selected = app(CustomerCommerceSnapshotService::class)->forSelectedSources($profile, $tenant->id, $sources);
    $full = app(CustomerCommerceSnapshotService::class)->forReportingProfile($profile, $tenant->id);
    expect(array_column($selected['purchases'], 'id'))->toBe(['pos_sale:'.$saleId])->toBe(array_column($full['purchases'], 'id'));
    expect(app(CustomerEventValueService::class)->eventSourceRelations($profile, $tenant->id, 123, $sources)['confirmed_source_keys'])->toBe(['pos_sale:'.$saleId]);
    expect(app(EventCustomerCohort::class)->read($tenant->id, 123, selectedEvidence: true)['profile_ids'])->toBe([$profile->id]);
    Http::assertNothingSent();
});

it('retains global Website customer conflicts even when only an explicitly reviewed order is selected', function (): void {
    $site = \App\Models\TenantSite::create(['tenant_id' => $this->tenant->id, 'subdomain' => 'lean-'.Str::uuid()]);
    $customer = \App\Models\WebsiteCustomer::create(['tenant_id' => $this->tenant->id, 'email' => 'website-'.Str::uuid().'@example.invalid']);
    $order = \App\Models\WebsiteOrder::create(['tenant_id' => $this->tenant->id, 'tenant_site_id' => $site->id, 'website_customer_id' => $customer->id,
        'source' => 'website', 'number' => 'Original Website order', 'lookup_token' => (string) Str::uuid(), 'payment_status' => 'paid', 'paid_at' => now()->subHour(), 'total_cents' => 1900, 'currency' => 'USD']);
    MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $this->buyer->id, 'source_type' => 'website_order', 'source_id' => (string) $order->id,
        'source_meta' => ['event_attribution' => ['event_id' => 123, 'reviewed_by' => $this->owner->id, 'reviewed_at' => now()->toIso8601String(), 'evidence_reference' => 'Original order owner review']]]);
    $sources = [['type' => 'website_order', 'id' => (string) $order->id]];
    $reader = app(CustomerEventValueService::class);
    expect($reader->eventSourceRelations($this->buyer, $this->tenant->id, 123, $sources)['confirmed_source_keys'])->toBe(['website_order:'.$order->id]);
    $other = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'first_name' => 'Different Website customer owner']);
    $customerLink = MarketingProfileLink::create(['tenant_id' => $this->tenant->id, 'marketing_profile_id' => $other->id, 'source_type' => 'website_customer', 'source_id' => (string) $customer->id]);
    expect($reader->eventSourceRelations($this->buyer, $this->tenant->id, 123, $sources)['confirmed_source_keys'])->toBe([])
        ->and(app(CustomerCommerceSnapshotService::class)->forReportingProfile($this->buyer, $this->tenant->id)['purchases'])->toBe([]);
    $alias = MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->buyer->id, 'archived_at' => now()]);
    $customerLink->update(['marketing_profile_id' => $alias->id]);
    expect($reader->eventSourceRelations($this->buyer, $this->tenant->id, 123, $sources)['confirmed_source_keys'])->toBe(['website_order:'.$order->id]);
    $order->update(['source' => 'pos_simulation']);
    expect($reader->eventSourceRelations($this->buyer, $this->tenant->id, 123, $sources)['confirmed_source_keys'])->toBe([]);
    Http::assertNothingSent();
});

it('counts current location gaps in one exact grouped read without changing country ZIP or tenant rules', function (): void {
    $rows = [['US', '29201'], ['us', '29201-1234'], [' USA ', ' 29201 '], ['ŪS', '29201'], ['US', '29201 '], ['US', '29201-abc'], ['CA', '29201'], [null, '29201'], ['US', null], ['US', '00000']];
    foreach ($rows as [$country, $postal]) {
        MarketingProfile::create(['tenant_id' => $this->tenant->id, 'country' => $country, 'postal_code' => $postal]);
    }
    for ($i = 0; $i < 1100; $i++) {
        MarketingProfile::create(['tenant_id' => $this->tenant->id, 'country' => 'US', 'postal_code' => '29201']);
    }
    MarketingProfile::create(['tenant_id' => $this->tenant->id, 'country' => null, 'archived_at' => now()]);
    MarketingProfile::create(['tenant_id' => $this->tenant->id, 'merged_into_profile_id' => $this->buyer->id]);
    [$foreign] = teamWorkspace();
    MarketingProfile::create(['tenant_id' => $foreign->id]);
    $distance = app(CustomerLocationDistance::class);
    $expected = MarketingProfile::forTenantId($this->tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id')->get()
        ->filter(fn ($p) => $distance->point($p->postal_code, $p->country) === null)->count();
    DB::enableQueryLog();
    DB::flushQueryLog();
    expect($distance->missingProfileCount($this->tenant->id))->toBe($expected)->toBe(7);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();
    expect($queries)->toHaveCount(1)->and($queries[0]['bindings'])->toBe([$this->tenant->id]);
    Http::assertNothingSent();
});
