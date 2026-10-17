<?php

use App\Models\User;
use App\Services\Retail\EvergroveOffer;
use App\Services\Retail\RetailShowroomService;
use App\Services\Retail\SandboxCarrierRateProvider;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    $this->owner = User::factory()->create(['email' => 'review@everbranch.invalid', 'is_active' => true, 'email_verified_at' => now(), 'approved_at' => now()]);
    config()->set('retail_showroom.enabled', true);
    config()->set('retail_showroom.owner_email', $this->owner->email);
    $this->service = app(RetailShowroomService::class);
    $this->service->seed($this->owner);
    $this->actingAs($this->owner);
    $this->host = parse_url(route('retail.showroom'), PHP_URL_HOST);
    $this->origin = ['street1' => '123 Test Way', 'city' => 'Test City', 'state' => 'NC', 'zip' => '28604', 'country' => 'US'];
});
test('test USPS rates aggregate mixed packages using integer cents and stay labeled', function () {
    config()->set('retail_showroom.sandbox_key', 'EZTK_synthetic_test_only');
    config()->set('retail_showroom.sandbox_origins.highland-croft-candle-company', $this->origin);
    Http::fake(['*/shipments' => Http::sequence()->push(['mode' => 'test', 'rates' => [['mode' => 'test', 'currency' => 'USD', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '8.09']]])->push(['mode' => 'test', 'rates' => [['mode' => 'test', 'currency' => 'USD', 'carrier' => 'USPS', 'service' => 'GroundAdvantage', 'rate' => '5.01']]])]);
    $q = $this->service->quote('highland-croft-candle-company', [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1], ['product_id' => 'p3', 'variant_id' => 'standard', 'quantity' => 1]], ['zip' => '28604', 'state' => 'NC']);
    expect($q['kind'])->toBe('test')->and($q['options'][1]['amount_cents'])->toBe(1310 + $this->service->merchant('highland-croft-candle-company')['shipping']['handling_cents'])->and($q['options'][1]['label'])->toContain('USPS', 'TEST');
    Http::assertSentCount(2);
    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/shipments') && $r['from_address']['street1'] === '123 Test Way' && $r['parcel']['weight'] > 0 && ! isset($r['shipment']));
});
test('live keys and foreign origins cannot enable test quoting and farm shipping never calls carriers', function () {
    config()->set('retail_showroom.sandbox_key', 'EZAK_live_key_forbidden');
    config()->set('retail_showroom.sandbox_origins.joy-fudge-company', $this->origin);
    expect(app(SandboxCarrierRateProvider::class)->configured($this->service->merchant('joy-fudge-company')))->toBeFalse();
    config()->set('retail_showroom.sandbox_key', 'EZTK_synthetic');
    expect(app(SandboxCarrierRateProvider::class)->configured($this->service->merchant('leather-link-studio')))->toBeFalse();
    config()->set('retail_showroom.sandbox_origins.old-beech-mountain-farms', $this->origin);
    $q = $this->service->quote('old-beech-mountain-farms', [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1]], ['zip' => '90210', 'state' => 'CA']);
    expect(array_column($q['options'], 'id'))->toBe(['pickup']);
    Http::assertNothingSent();
});
test('a production-mode carrier response fails closed', function () {
    config()->set('retail_showroom.sandbox_key', 'EZTK_synthetic');
    config()->set('retail_showroom.sandbox_origins.joy-fudge-company', $this->origin);
    Http::fake(['*/shipments' => Http::response(['mode' => 'production', 'rates' => []])]);
    $this->postJson('http://'.$this->host.'/retail/showroom/api/joy-fudge-company/quote', ['lines' => [['product_id' => 'p1', 'variant_id' => 'standard', 'quantity' => 1]], 'destination' => ['zip' => '28604', 'state' => 'NC']])->assertStatus(502);
});
test('Studio uses its existing engine through an owner-only fixed-origin proxy', function () {
    $page = $this->get('http://'.$this->host.'/studio/')->assertOk();
    expect($page->headers->get('Cache-Control'))->toContain('no-store', 'private');
    Http::fake(['https://levelfoundations.org/api/studio/login' => Http::response(['token' => 'synthetic'], 200)]);
    $this->postJson('http://'.$this->host.'/api/studio/login', ['book' => 'renfrow-studio-demo', 'password' => 'synthetic-only'])->assertOk()->assertJsonPath('token', 'synthetic');
    $this->postJson('http://'.$this->host.'/api/studio/not-an-api', [])->assertNotFound();
    $this->actingAs(User::factory()->create(['is_active' => true, 'email_verified_at' => now()]))->get('http://'.$this->host.'/studio/')->assertForbidden();
    Http::assertSentCount(1);
});
test('the Retail launch offer retains the weekly strategic partnership', function () {
    $offer = app(EvergroveOffer::class)->presentation();
    expect($offer['monthly_cents'])->toBe(8900)->and($offer['ongoing_monthly_cents'])->toBe(14900)->and($offer['introductory_months'])->toBe(6)->and($offer['setup_cents'])->toBe(49900)->and($offer['partnership'][0])->toBe('One-hour video call every week');
    $this->get('http://'.$this->host.'/retail/showroom/pricing')->assertOk();
    $this->getJson('http://'.$this->host.'/retail/showroom/api/bootstrap?merchant=joy-fudge-company')->assertJsonCount(21, 'vendors')->assertJsonPath('offer.setup_cents', 49900);
});
