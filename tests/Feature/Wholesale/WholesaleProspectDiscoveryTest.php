<?php

require_once dirname(__DIR__).'/ShopifyEmbeddedTestHelpers.php';

use App\Jobs\RunWholesaleProspectDiscovery;
use App\Models\Tenant;
use App\Models\User;
use App\Models\WholesaleProspect;
use App\Models\WholesaleProspectDiscoveryRun;
use App\Models\WholesaleProspectEvidence;
use App\Services\Wholesale\GooglePlacesProspectClient;
use App\Services\Wholesale\WholesaleProspectIngestService;
use App\Services\Wholesale\WholesaleProspectWebsiteEnricher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

beforeEach(function (): void {
    $this->withoutVite();
    $this->tenant = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    configureEmbeddedWholesaleStore((int) $this->tenant->id);
    config()->set('services.google_places.api_key', 'places-test-key');
    config()->set('services.google_places.estimated_cost_per_request', 0.032);
    config()->set('services.google_places.large_search_threshold', 40);
    $this->actor = User::factory()->create(['email' => 'prospecting@example.com', 'role' => 'admin', 'is_active' => true]);
    $this->actor->tenants()->attach($this->tenant->id, ['role' => 'admin']);
});

test('authorized operators can queue tenant scoped discovery with a recorded cost estimate', function (): void {
    Queue::fake();

    $response = $this->withHeaders([
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ])->post(route('shopify.app.wholesale.prospects.run'), [
        'search_region' => 'Greenville, SC',
        'search_phrases' => "gift shop\nhome goods store",
        'maximum_results' => 40,
        'campaign_name' => 'Regional review',
    ]);

    $response->assertAccepted()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('estimated_api_cost', 0.064);

    $run = WholesaleProspectDiscoveryRun::query()->forAllTenants()->firstOrFail();
    expect((int) $run->tenant_id)->toBe((int) $this->tenant->id)
        ->and($run->search_phrases)->toBe(['gift shop', 'home goods store'])
        ->and((float) $run->estimated_api_cost)->toBe(0.064);

    Queue::assertPushed(RunWholesaleProspectDiscovery::class, fn ($job): bool => $job->tenantId === (int) $this->tenant->id);
});

test('large searches require explicit confirmation and instagram enrichment stays disabled', function (): void {
    Queue::fake();
    $headers = [
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => $this->actor->email]),
        'Accept' => 'application/json',
    ];

    $this->withHeaders($headers)->post(route('shopify.app.wholesale.prospects.run'), [
        'search_region' => 'South Carolina',
        'search_phrases' => 'gift shop',
        'maximum_results' => 80,
    ])->assertUnprocessable()->assertJsonPath('ok', false);

    $this->withHeaders($headers)->post(route('shopify.app.wholesale.prospects.run'), [
        'search_region' => 'South Carolina',
        'search_phrases' => 'gift shop',
        'maximum_results' => 20,
        'instagram_enrichment' => true,
    ])->assertUnprocessable()->assertJsonPath('ok', false);

    expect(WholesaleProspectDiscoveryRun::query()->forAllTenants()->count())->toBe(0);
    Queue::assertNothingPushed();
});

test('google places discovery stores explainable evidence and suppresses exact place duplicates', function (): void {
    Http::fake([
        'https://places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [[
                'id' => 'place-123',
                'displayName' => ['text' => 'Forest Gift House'],
                'primaryType' => 'gift_shop',
                'types' => ['gift_shop', 'store'],
                'formattedAddress' => '12 Main St, Greenville, SC 29601',
                'addressComponents' => [
                    ['longText' => 'Greenville', 'shortText' => 'Greenville', 'types' => ['locality']],
                    ['longText' => 'South Carolina', 'shortText' => 'SC', 'types' => ['administrative_area_level_1']],
                    ['longText' => '29601', 'shortText' => '29601', 'types' => ['postal_code']],
                ],
                'location' => ['latitude' => 34.85, 'longitude' => -82.39],
                'nationalPhoneNumber' => '(864) 555-1212',
                'websiteUri' => 'https://forest-gift.example',
                'googleMapsUri' => 'https://maps.google.com/?cid=123',
                'rating' => 4.8,
                'userRatingCount' => 100,
                'businessStatus' => 'OPERATIONAL',
            ]],
        ]),
    ]);

    $makeRun = function (): WholesaleProspectDiscoveryRun {
        return WholesaleProspectDiscoveryRun::query()->create([
            'tenant_id' => $this->tenant->id,
            'public_id' => (string) Str::uuid(),
            'status' => 'queued',
            'search_region' => 'Greenville, SC',
            'search_phrases' => ['gift shop'],
            'maximum_results' => 20,
            'estimated_api_cost' => 0.032,
            'requested_by_user_id' => $this->actor->id,
        ]);
    };

    $first = $makeRun();
    (new RunWholesaleProspectDiscovery($first->id, $this->tenant->id))->handle(
        app(GooglePlacesProspectClient::class), app(WholesaleProspectIngestService::class)
    );
    $second = $makeRun();
    (new RunWholesaleProspectDiscovery($second->id, $this->tenant->id))->handle(
        app(GooglePlacesProspectClient::class), app(WholesaleProspectIngestService::class)
    );

    $prospect = WholesaleProspect::query()->forAllTenants()->with('evidence')->firstOrFail();
    expect(WholesaleProspect::query()->forAllTenants()->count())->toBe(1)
        ->and($prospect->fit_score)->toBeGreaterThan(0)
        ->and($prospect->fit_explanation['positive_signals'])->not->toBeEmpty()
        ->and($prospect->fit_explanation['evaluated_at'])->not->toBeEmpty()
        ->and($prospect->evidence)->toHaveCount(1)
        ->and($second->fresh()->duplicates_suppressed)->toBe(1)
        ->and((float) $first->fresh()->actual_api_cost)->toBe(0.032);

    Http::assertSent(fn ($request): bool => $request->hasHeader('X-Goog-FieldMask') && $request->hasHeader('X-Goog-Api-Key', 'places-test-key'));
});

test('controlled website enrichment stores concise public evidence and updates explained fit', function (): void {
    Http::fake([
        'https://example.com/robots.txt' => Http::response("User-agent: *\nDisallow:", 200),
        'https://example.com' => Http::response('<html><body>Shop locally made candles, gifts, and independent brands online. Visit our locations. <a href="mailto:buyer@example.com">Email</a><a href="/contact">Contact</a></body></html>', 200),
    ]);
    $prospect = WholesaleProspect::query()->create([
        'tenant_id' => $this->tenant->id,
        'public_id' => (string) Str::uuid(),
        'business_name' => 'Enriched Gift Shop',
        'status' => 'newly_discovered',
        'website' => 'https://example.com',
        'discovery_source' => 'google_places',
        'fit_score' => 40,
        'fit_confidence' => 40,
        'fit_explanation' => ['score' => 40, 'confidence' => 40, 'positive_signals' => [], 'negative_signals' => [], 'missing_information' => [], 'evaluated_at' => now()->toIso8601String()],
    ]);

    $result = app(WholesaleProspectWebsiteEnricher::class)->enrich($prospect);
    $prospect->refresh();

    expect($result['enriched'])->toBeTrue()
        ->and($prospect->public_business_email)->toBe('buyer@example.com')
        ->and($prospect->contact_form_url)->toBe('https://example.com/contact')
        ->and($prospect->fit_score)->toBeGreaterThan(40)
        ->and($prospect->fit_explanation['positive_signals'])->not->toBeEmpty()
        ->and(WholesaleProspectEvidence::query()->forAllTenants()->where('wholesale_prospect_id', $prospect->id)->value('summary'))->toContain('Public website references');
});

test('unauthorized shopify admins cannot queue prospect discovery', function (): void {
    Queue::fake();

    $this->withHeaders([
        'Authorization' => 'Bearer '.wholesaleShopifySessionToken(['email' => 'unknown@example.com']),
        'Accept' => 'application/json',
    ])->post(route('shopify.app.wholesale.prospects.run'), [
        'search_region' => 'Greenville, SC',
        'search_phrases' => 'gift shop',
        'maximum_results' => 20,
    ])->assertForbidden();

    Queue::assertNothingPushed();
});
