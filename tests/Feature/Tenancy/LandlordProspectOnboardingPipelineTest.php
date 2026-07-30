<?php

use App\Models\LandlordProspect;
use App\Models\LandlordProspectDiscoveryRun;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST);
    $landlordHost = is_string($landlordHost) && $landlordHost !== '' ? strtolower($landlordHost) : 'app.theeverbranch.com';

    config()->set('tenancy.landlord.primary_host', $landlordHost);
    config()->set('tenancy.landlord.hosts', [$landlordHost]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
});

test('landlord onboarding sheet includes researched trade prospects and verified no website leads', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    expect(LandlordProspect::query()->count())->toBe(13)
        ->and(LandlordProspect::query()->where('website_status', 'missing_verified')->count())->toBe(3);

    $response = $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/onboarding");

    $response
        ->assertOk()
        ->assertSeeText('Find the right local businesses. Work every next step.')
        ->assertSeeText('8/10')
        ->assertSeeText('R&R Lawn LLC')
        ->assertSeeText('SC Wired')
        ->assertSeeText('Warmer Water & Plumbing')
        ->assertSeeText('Garcia Landscape LLC')
        ->assertSeeText('No website · reviewed')
        ->assertSeeText('Convert to customer');
});

test('landlord can run a bounded no website places search with deduplication and cost evidence', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    config()->set('services.google_places.api_key', 'places-test-key');
    config()->set('services.google_places.estimated_cost_per_request', 0.032);
    Http::fake([
        'https://places.googleapis.com/v1/places:searchText' => Http::response([
            'places' => [
                [
                    'id' => 'place-no-site-1',
                    'displayName' => ['text' => 'Upstate Air Test'],
                    'formattedAddress' => '10 Main St, Easley, SC 29640',
                    'addressComponents' => [
                        ['longText' => 'Easley', 'types' => ['locality']],
                        ['longText' => 'Pickens County', 'types' => ['administrative_area_level_2']],
                    ],
                    'nationalPhoneNumber' => '(864) 555-0100',
                    'googleMapsUri' => 'https://maps.google.com/?cid=100',
                    'rating' => 4.9,
                    'userRatingCount' => 28,
                    'businessStatus' => 'OPERATIONAL',
                ],
                [
                    'id' => 'place-with-site-1',
                    'displayName' => ['text' => 'Has A Website HVAC'],
                    'websiteUri' => 'https://example.com',
                    'googleMapsUri' => 'https://maps.google.com/?cid=101',
                ],
            ],
        ]),
    ]);

    $payload = [
        'trade' => 'HVAC',
        'search_region' => 'Easley, SC',
        'website_preference' => 'missing_only',
        'maximum_results' => 10,
        'confirm_cost' => '1',
    ];

    $this->actingAs($user)
        ->post("http://{$landlordHost}/landlord/onboarding/discovery", $payload)
        ->assertRedirect();
    $this->actingAs($user)
        ->post("http://{$landlordHost}/landlord/onboarding/discovery", $payload)
        ->assertRedirect();

    expect(LandlordProspect::query()->where('google_place_id', 'place-no-site-1')->count())->toBe(1)
        ->and(LandlordProspect::query()->where('google_place_id', 'place-with-site-1')->count())->toBe(0)
        ->and(LandlordProspectDiscoveryRun::query()->count())->toBe(2)
        ->and((float) LandlordProspectDiscoveryRun::query()->firstOrFail()->actual_api_cost)->toBe(0.032);

    $this->assertDatabaseHas('landlord_operator_actions', [
        'action_type' => 'landlord_prospect_discovery_completed',
        'target_type' => 'landlord_prospect_discovery_run',
    ]);
});

test('landlord can create a reviewed outreach draft and explicitly mark it sent', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $prospect = LandlordProspect::query()->where('email', 'ageehvac.llc@gmail.com')->firstOrFail();

    $this->actingAs($user)
        ->post("http://{$landlordHost}/landlord/onboarding/{$prospect->id}/drafts", [
            'template' => 'first_touch',
        ])
        ->assertRedirect();

    $draft = $prospect->communications()->latest('id')->firstOrFail();
    expect($draft->status)->toBe('draft')
        ->and($draft->body)->toContain('service calls')
        ->and($draft->body)->toContain('Everbranch')
        ->and($prospect->fresh()->status)->toBe('draft_ready');

    $this->actingAs($user)
        ->patch("http://{$landlordHost}/landlord/onboarding/{$prospect->id}/communications/{$draft->id}/sent")
        ->assertRedirect();

    expect($draft->fresh()->status)->toBe('sent')
        ->and($prospect->fresh()->status)->toBe('contacted')
        ->and($prospect->fresh()->last_contacted_at)->not->toBeNull()
        ->and($prospect->fresh()->next_follow_up_at)->not->toBeNull();
});

test('landlord can log an inbound email response and move the prospect to replied', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $prospect = LandlordProspect::query()->where('email', 'ryan@scwired.com')->firstOrFail();

    $this->actingAs($user)
        ->post("http://{$landlordHost}/landlord/onboarding/{$prospect->id}/communications", [
            'direction' => 'inbound',
            'channel' => 'email',
            'communication_status' => 'received',
            'subject' => 'Re: local software fit',
            'body' => 'Interested. Can we talk next Tuesday?',
            'from_address' => $prospect->email,
            'to_address' => 'john@evergrovesoftware.com',
        ])
        ->assertRedirect();

    $prospect->refresh();

    expect($prospect->status)->toBe('replied')
        ->and($prospect->responded_at)->not->toBeNull()
        ->and($prospect->communications()->count())->toBe(2)
        ->and($prospect->communications()->where('direction', 'inbound')->exists())->toBeTrue();

    $this->assertDatabaseHas('landlord_operator_actions', [
        'action_type' => 'landlord_prospect_communication_logged',
        'target_type' => 'landlord_prospect_communication',
    ]);
});

test('landlord can convert a researched prospect into a production tenant', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $prospect = LandlordProspect::query()->where('email', 'rrlawneasley@gmail.com')->firstOrFail();

    $response = $this->actingAs($user)
        ->post("http://{$landlordHost}/landlord/tenants", [
            'prospect_id' => $prospect->id,
            'name' => $prospect->business_name,
            'primary_contact_email' => $prospect->email,
            'tenant_type' => 'direct',
            'operating_mode' => 'direct',
            'account_mode' => 'production',
            'data_source_preference' => 'undecided',
            'business_template' => 'landscaping',
            'role' => 'manager',
            'status' => 'active',
        ]);

    $tenant = Tenant::query()->where('name', 'R&R Lawn LLC')->firstOrFail();

    $response->assertRedirect(route('landlord.tenants.show', [
        'tenant' => $tenant->id,
        'tab' => 'overview',
    ]));

    $prospect->refresh();

    expect($prospect->status)->toBe('converted')
        ->and($prospect->converted_tenant_id)->toBe($tenant->id)
        ->and($prospect->converted_at)->not->toBeNull()
        ->and($prospect->communications()->where('subject', 'Converted to Everbranch customer')->exists())->toBeTrue();
});

test('landlord prospect sheet exports the current filtered rows as csv', function (): void {
    $landlordHost = parse_url(route('landlord.dashboard'), PHP_URL_HOST) ?: 'app.theeverbranch.com';
    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get("http://{$landlordHost}/landlord/onboarding-export.csv?trade=HVAC")
        ->assertOk()
        ->assertHeader('content-type', 'text/csv; charset=UTF-8');

    $csv = $response->streamedContent();

    expect($csv)
        ->toContain('Agee HVAC LLC')
        ->toContain('Rhino HVAC')
        ->not->toContain('R&R Lawn LLC');
});
