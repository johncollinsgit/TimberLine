<?php

use App\Models\LandlordOperatorAction;
use App\Models\LandlordProspect;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Retail\EvergroveOffer;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    Http::preventStrayRequests();
    Mail::fake();
    config()->set('retail_showroom.enabled', true);
    $this->owner = User::factory()->create(['email' => 'owner@example.invalid', 'is_active' => true, 'email_verified_at' => now()]);
    config()->set('retail_showroom.owner_email', $this->owner->email);
    $this->prospect = LandlordProspect::create(['business_name' => 'Avery Knifeworks', 'trade' => 'Knife maker', 'county' => 'Avery', 'status' => 'draft_ready', 'source' => 'showroom',
        'source_snapshot' => ['showroom' => ['slug' => 'avery-knifeworks'], 'research' => ['preserved' => true]]]);
    $this->url = 'http://'.parse_url(route('retail.showroom'), PHP_URL_HOST).'/retail/showroom/api/avery/services';
    $this->input = ['services' => ['sms', 'email', 'memory', 'phone_voice'], 'offer_version' => app(EvergroveOffer::class)->presentation()['quote_version']];
    $this->actingAs($this->owner);
});

test('feature choices save an audited canonical quote without changing billing or activating services', function () {
    $tenants = Tenant::count();
    $response = $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('status', 'pending_launch_review')
        ->assertJsonPath('activated', false)->assertJsonPath('quote.subtotal_cents', 79800)
        ->assertJsonPath('quote.preload_cents', 0)->assertJsonPath('quote.feature_access_cents.memory', 0);
    $this->postJson($this->url, $this->input)->assertOk();
    expect(data_get($this->prospect->fresh()->source_snapshot, 'research.preserved'))->toBeTrue()
        ->and(data_get($this->prospect->fresh()->source_snapshot, 'standard_feature_selection.quote.hash'))->toBe($response->json('quote.hash'))
        ->and(LandlordOperatorAction::where('action_type', 'retail_standard_features_selected')->count())->toBe(1)
        ->and(Tenant::count())->toBe($tenants);
    Http::assertNothingSent();
    Mail::assertNothingSent();
});

test('stale prices arbitrary charges and unknown features are rejected', function () {
    foreach ([['offer_version' => 'stale'], ['services' => ['unlimited']], ['amount_cents' => 1], ['preload_cents' => 5000], ['activate' => true], ['tenant_id' => 15]] as $override) {
        $this->postJson($this->url, [...$this->input, ...$override])->assertUnprocessable();
    }
    expect(data_get($this->prospect->fresh()->source_snapshot, 'standard_feature_selection'))->toBeNull();
});

test('only the presenter can persist preferences and an empty selection is allowed', function () {
    $this->postJson($this->url, [...$this->input, 'services' => []])->assertOk()->assertJsonPath('quote.selected_services', []);
    $this->actingAs(User::factory()->create(['is_active' => true, 'email_verified_at' => now()]));
    $this->postJson($this->url, $this->input)->assertForbidden();
});
