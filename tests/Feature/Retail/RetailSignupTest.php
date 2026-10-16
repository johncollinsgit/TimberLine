<?php

use App\Mail\RetailSignupConfirmationMail;
use App\Models\CustomerAccessRequest;
use App\Models\LandlordProspect;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Retail\EvergroveOffer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    Mail::fake();
    \Illuminate\Support\Facades\Cache::flush();
    $this->owner = User::factory()->create(['email' => 'retail-owner@everbranch.invalid', 'is_active' => true, 'email_verified_at' => now()]);
    config()->set('retail_showroom.enabled', true);
    config()->set('retail_showroom.owner_email', $this->owner->email);
    config()->set('retail_showroom.signup_confirmation_enabled', false);
    $this->url = 'http://'.parse_url(route('retail.showroom'), PHP_URL_HOST).'/retail/showroom/api/signup';
    $this->input = ['business_name' => 'Sample Retail', 'contact_name' => 'Sample Owner', 'email' => 'sample@retail.invalid',
        'phone' => '+1 555 010 2345', 'subscription' => 'retail_launch_partner', 'pricing_accepted' => true,
        'quote_version' => app(EvergroveOffer::class)->presentation()['quote_version']];
    $this->actingAs($this->owner);
});

test('the retail offer has explicit intro expiry and leaves managed website pricing unchanged', function () {
    $offer = app(EvergroveOffer::class)->presentation();
    expect($offer['introductory_monthly_cents'])->toBe(8900)->and($offer['introductory_months'])->toBe(6)
        ->and($offer['ongoing_monthly_cents'])->toBe(14900)->and($offer['setup_cents'])->toBe(49900)
        ->and($offer['qualifying_business_limit'])->toBe(10)->and($offer['activation'])->toBe('request_only')
        ->and(config('module_catalog.addons.managed_website.pricing.recurring_price_cents'))->toBe(9900);
});

test('signup uses authorized onboarding and CRM without creating a tenant or membership', function () {
    $tenants = Tenant::count();
    $memberships = DB::table('tenant_user')->count();
    $response = $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('status', 'pending_review')
        ->assertJsonPath('subscription_active', false)->assertJsonPath('slot_reserved', false)
        ->assertJsonPath('confirmation_status', 'pending');
    expect($response->headers->get('Cache-Control'))->toContain('no-store', 'private');
    $request = CustomerAccessRequest::sole();
    expect($request->intent)->toBe('production')->and($request->status)->toBe('pending')
        ->and($request->requested_tenant_slug)->toBeNull()->and($request->tenant_id)->toBeNull()
        ->and($request->user->is_active)->toBeFalse()->and(Tenant::count())->toBe($tenants)
        ->and(DB::table('tenant_user')->count())->toBe($memberships)
        ->and(data_get(LandlordProspect::where('business_name', 'Sample Retail')->sole()->source_snapshot, 'retail_signup.request_id'))->toBe($request->id);
    Mail::assertNothingSent();
    Http::assertNothingSent();
});

test('duplicate submissions reuse the access request and preserve existing prospect research', function () {
    $prospect = LandlordProspect::create(['business_name' => 'Sample Retail', 'trade' => 'Retail', 'county' => 'Research pending', 'status' => 'draft_ready', 'source' => 'existing-research', 'notes' => 'Preserve this', 'source_snapshot' => ['research' => ['verified' => true]]]);
    $first = $this->postJson($this->url, $this->input)->assertOk()->json('reference');
    $this->postJson($this->url, [...$this->input, 'email' => 'SAMPLE@RETAIL.INVALID'])->assertOk()->assertJsonPath('reference', $first);
    expect(CustomerAccessRequest::count())->toBe(1)->and(LandlordProspect::where('business_name', 'Sample Retail')->count())->toBe(1)
        ->and($prospect->fresh()->notes)->toBe('Preserve this')->and($prospect->fresh()->source)->toBe('existing-research')
        ->and(data_get($prospect->fresh()->source_snapshot, 'research.verified'))->toBeTrue();
});

test('restricted users and disabled showrooms cannot register customers', function () {
    $this->actingAs(User::factory()->create(['is_active' => true, 'email_verified_at' => now()]));
    $this->postJson($this->url, $this->input)->assertForbidden();
    $this->actingAs($this->owner);
    config()->set('retail_showroom.enabled', false);
    $this->postJson($this->url, $this->input)->assertForbidden();
    expect(CustomerAccessRequest::count())->toBe(0);
});

test('signup rejects stale pricing missing consent and tenant or card injection', function () {
    $this->postJson($this->url, [...$this->input, 'quote_version' => 'old'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'pricing_accepted' => false])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'tenant_id' => 1])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'requested_tenant_slug' => 'demo'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'card_number' => 'synthetic-no-card'])->assertUnprocessable();
    expect(CustomerAccessRequest::count())->toBe(0);
});

test('confirmation is sent once only through an enabled real mail transport', function () {
    config()->set('retail_showroom.signup_confirmation_enabled', true);
    config()->set('mail.default', 'smtp');
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('confirmation_status', 'submitted');
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('confirmation_status', 'submitted');
    Mail::assertSent(RetailSignupConfirmationMail::class, 1);
    Mail::assertSent(RetailSignupConfirmationMail::class, fn ($mail) => $mail->hasTo($this->input['email']) && $mail->offer['ongoing_monthly_cents'] === 14900);
});

test('an array mailer never falsely reports a confirmation sent', function () {
    config()->set('retail_showroom.signup_confirmation_enabled', true);
    config()->set('mail.default', 'array');
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('confirmation_status', 'pending');
    Mail::assertNothingSent();
});

test('a different business cannot overwrite an existing registration for the same email', function () {
    $this->postJson($this->url, $this->input)->assertOk();
    $this->postJson($this->url, [...$this->input, 'business_name' => 'Different Business'])->assertUnprocessable();
    expect(CustomerAccessRequest::sole()->company)->toBe('Sample Retail');
});

test('a changed offer requires review rather than overwriting previously accepted terms', function () {
    $this->postJson($this->url, $this->input)->assertOk();
    config()->set('module_catalog.launch_offers.retail_launch_partner.ongoing_monthly_cents', 15000);
    $updated = app(EvergroveOffer::class)->presentation();
    $this->postJson($this->url, [...$this->input, 'quote_version' => $updated['quote_version']])->assertUnprocessable();
    expect(data_get(CustomerAccessRequest::sole()->metadata, 'retail_signup.offer.ongoing_monthly_cents'))->toBe(14900);
});
