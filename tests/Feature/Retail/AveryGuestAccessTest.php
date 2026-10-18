<?php

use App\Models\User;
use App\Services\Retail\RetailShowroomService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    $this->withoutVite();
    $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class);
    Http::preventStrayRequests();
    $this->originalEnvironment = app()->environment();
    $this->owner = User::factory()->create(['email' => 'presenter@example.invalid', 'role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->visitor = User::factory()->create(['email' => 'avery-demo-visitor@everbranch-demo.invalid', 'role' => 'pouring', 'is_active' => true, 'email_verified_at' => now()]);
    config(['retail_showroom.enabled' => true, 'retail_showroom.owner_email' => $this->owner->email]);
    app(RetailShowroomService::class)->seed($this->owner);
    $this->host = parse_url(route('retail.showroom'), PHP_URL_HOST);
    $this->base = 'http://'.$this->host;
    config(['retail_showroom.presenter_host' => $this->host, 'retail_showroom.avery_pin_enabled' => true, 'retail_showroom.avery_pin_hash' => Hash::make('1234')]);
    app()->instance('env', 'staging');
});

afterEach(function () {
    app()->instance('env', $this->originalEnvironment);
});

test('Avery code opens only Avery without authenticating a platform user', function () {
    $this->get($this->base.'/avery')->assertOk()->assertSee('Access code');
    $this->post($this->base.'/avery', ['code' => '1234'])->assertRedirect('/retail/showroom/concepts/avery-knifeworks');
    $this->assertGuest();
    $this->get($this->base.'/retail/showroom/concepts/avery-knifeworks')->assertOk()->assertSee('data-avery-only="true"', false);
    $response = $this->getJson($this->base.'/retail/showroom/api/bootstrap')->assertOk()->assertJsonPath('restricted', true)->assertJsonPath('owner', false)->assertJsonPath('staff', false)->assertJsonPath('sawyer_url', null);
    expect(array_column($response->json('vendors'), 'slug'))->toBe(['avery-knifeworks']);
    expect($response->getContent())->not->toContain('private_notes', 'fit_score', 'provider_cost', 'multiplier');
    $this->getJson($this->base.'/retail/showroom/api/session')->assertOk()->assertJsonStructure(['csrf_token']);
    $this->assertGuest();
    expect($this->visitor->tenants()->count())->toBe(0)->and($this->visitor->isAdmin())->toBeFalse();
});

test('wrong code does not grant access and fresh visitors return to the code form', function () {
    $this->post($this->base.'/avery', ['code' => '0000'])->assertSessionHasErrors('code');
    $this->get($this->base.'/retail/showroom/concepts/avery-knifeworks')->assertRedirect('/avery');
    $this->getJson($this->base.'/retail/showroom/api/session')->assertUnauthorized();
    $this->assertGuest();
});

test('Avery guest cannot access another workspace or invoke platform and sending actions', function () {
    $this->post($this->base.'/avery', ['code' => '1234']);
    foreach (['/retail/showroom/concepts/mcneills-pottery', '/studio/', '/landlord/tenants', '/retail/showroom/outreach', '/retail/showroom/api/research', '/retail/showroom/api/mcneills-pottery/staff', '/retail/showroom/api/bootstrap?merchant=sawyer-naturals', '/retail/showroom/api/bootstrap?tenant_id=1'] as $path) {
        $this->getJson($this->base.$path)->assertNotFound();
    }
    foreach (['/retail/showroom/api/demo/delivery', '/retail/showroom/api/signup', '/retail/showroom/api/avery-knifeworks/share', '/retail/showroom/api/avery-knifeworks/checkout', '/api/studio/customers'] as $path) {
        $this->postJson($this->base.$path, [])->assertNotFound();
    }
    $this->postJson($this->base.'/retail/showroom/api/assistant', ['merchant' => 'sawyer-naturals'])->assertNotFound();
    $this->postJson($this->base.'/retail/showroom/api/voice/session', ['merchant' => 'sawyer-naturals'])->assertNotFound();
    Http::assertNothingSent();
    $this->assertGuest();
});

test('presenter can leave Avery mode and retains the existing full login', function () {
    $this->actingAs($this->owner);
    $this->post($this->base.'/avery', ['code' => '1234']);
    $this->getJson($this->base.'/retail/showroom/api/bootstrap')->assertJsonCount(1, 'vendors');
    $this->get($this->base.'/avery/presenter')->assertRedirect('/retail/showroom');
    $this->getJson($this->base.'/retail/showroom/api/bootstrap')->assertOk()->assertJsonPath('owner', true)->assertJsonCount(21, 'vendors');
    $this->assertAuthenticatedAs($this->owner);
});

test('presenter link never grants a visitor full access', function () {
    $this->post($this->base.'/avery', ['code' => '1234']);
    $this->get($this->base.'/avery/presenter')->assertRedirect('/login');
    $this->get($this->base.'/retail/showroom')->assertRedirect('/avery');
    $this->getJson($this->base.'/retail/showroom/api/bootstrap')->assertForbidden();
    $this->assertGuest();
});

test('expired grants and changed access codes stop working', function () {
    $this->post($this->base.'/avery', ['code' => '1234']);
    $this->withSession(['avery_guest.expires' => time() - 1])->getJson($this->base.'/retail/showroom/api/session')->assertUnauthorized();
    $this->post($this->base.'/avery', ['code' => '1234']);
    config(['retail_showroom.avery_pin_hash' => Hash::make('different-code')]);
    $this->getJson($this->base.'/retail/showroom/api/session')->assertUnauthorized();
});

test('PIN entry is disabled outside the explicitly enabled staging host', function () {
    config(['retail_showroom.presenter_host' => 'other.invalid']);
    $this->get($this->base.'/avery')->assertNotFound();
    config(['retail_showroom.presenter_host' => $this->host]);
    app()->instance('env', 'production');
    $this->get($this->base.'/avery')->assertNotFound();
    app()->instance('env', 'staging');
    config(['retail_showroom.avery_pin_enabled' => false]);
    $this->post($this->base.'/avery', ['code' => '1234'])->assertNotFound();
});

test('Avery guest can use live AI with only fictional Avery context', function () {
    config(['retail_showroom.ai_enabled' => true, 'retail_showroom.ai_model' => 'gpt-6.1-sol', 'services.openai.api_key' => 'synthetic-guest-test-key']);
    $answer = ['status' => 'completed', 'output' => [
        ['type' => 'message', 'content' => [
            ['type' => 'output_text', 'text' => json_encode(['reply' => 'Bob’s favorite is in his fictional customer record.', 'destination' => null])],
        ]],
    ]];
    Http::fake(['api.openai.com/v1/responses' => Http::response($answer)]);
    $this->post($this->base.'/avery', ['code' => '1234']);
    $this->postJson($this->base.'/retail/showroom/api/assistant', ['merchant' => 'avery-knifeworks', 'business' => 'Avery Knifeworks', 'mode' => 'admin', 'question' => 'Tell me about Bob Ross'])->assertOk()->assertJsonPath('provider', 'openai');
    Http::assertSent(fn ($r) => $r['store'] === false && str_contains($r['input'], 'Bob Ross') && ! str_contains($r['input'], 'private_notes'));
    $this->assertGuest();
});

test('missing or deactivated visitor identity fails closed', function () {
    $this->post($this->base.'/avery', ['code' => '1234']);
    $this->visitor->update(['is_active' => false]);
    $this->getJson($this->base.'/retail/showroom/api/bootstrap')->assertStatus(503);
    $this->assertGuest();
});
