<?php

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    $this->owner = User::factory()->create(['email' => 'ai-owner@everbranch.invalid', 'is_active' => true, 'email_verified_at' => now()]);
    config()->set('retail_showroom.enabled', true);
    config()->set('retail_showroom.owner_email', $this->owner->email);
    config()->set('retail_showroom.ai_enabled', true);
    config()->set('retail_showroom.ai_model', 'gpt-6.1-sol');
    config()->set('services.openai.api_key', 'synthetic-test-credential');
    $this->url = 'http://'.parse_url(route('retail.showroom'), PHP_URL_HOST).'/retail/showroom/api/assistant';
    $this->input = ['mode' => 'email', 'business' => 'Sample Retail', 'question' => 'Draft a weekend collection announcement', 'history' => []];
    $this->answer = ['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['reply' => 'A draft for Sample Retail. Please review before sending.', 'destination' => null])]]]]];
    $this->actingAs($this->owner);
});

test('live OpenAI uses server credentials bounded sample context and no provider storage', function () {
    Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer)]);
    $response = $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('provider', 'openai')
        ->assertJsonPath('review_required', true)->assertJsonPath('destination', null);
    expect($response->getContent())->not->toContain('synthetic-test-credential');
    Http::assertSent(fn ($r) => $r->url() === 'https://api.openai.com/v1/responses' && $r['store'] === false
        && $r['max_output_tokens'] === 700 && ! isset($r['tools'])
        && str_contains($r['input'], 'fictional_retail_demonstration') && str_contains($r['input'], '29900'));
    Http::assertSentCount(1);
});

test('ordinary users cannot invoke the private demonstration provider', function () {
    Http::fake();
    $this->actingAs(User::factory()->create(['is_active' => true, 'email_verified_at' => now()]));
    $this->postJson($this->url, $this->input)->assertForbidden();
    Http::assertNothingSent();
});

test('missing configuration fails honestly instead of returning a synthetic AI answer', function () {
    config()->set('retail_showroom.ai_enabled', false);
    Http::fake();
    $this->postJson($this->url, $this->input)->assertStatus(503)->assertJsonPath('message', 'Live AI is not connected for this demo yet. The guided examples are still available.');
    Http::assertNothingSent();
});

test('foreign context and unsupported modes never reach the provider', function () {
    Http::fake();
    $this->postJson($this->url, [...$this->input, 'tenant_id' => 100])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'mode' => 'social'])->assertUnprocessable();
    $this->postJson($this->url, [...$this->input, 'merchant' => 'unknown-private-tenant'])->assertNotFound();
    Http::assertNothingSent();
});

test('the demo request cap prevents another provider call', function () {
    config()->set('retail_showroom.ai_requests_per_hour', 1);
    Http::fake(['api.openai.com/v1/responses' => Http::response($this->answer)]);
    $this->postJson($this->url, $this->input)->assertOk();
    $this->postJson($this->url, $this->input)->assertStatus(429);
    Http::assertSentCount(1);
});

test('untrusted destinations and provider failures cannot execute actions or expose provider errors', function () {
    $answer = $this->answer;
    $answer['output'][0]['content'][0]['text'] = json_encode(['reply' => 'Review this draft.', 'destination' => 'https://untrusted.invalid']);
    Http::fake(['api.openai.com/v1/responses' => Http::sequence()->push($answer)->push(['error' => 'private provider error'], 500)]);
    $this->postJson($this->url, $this->input)->assertOk()->assertJsonPath('destination', null);
    $r = $this->postJson($this->url, $this->input)->assertStatus(503);
    expect($r->getContent())->not->toContain('private provider error', 'synthetic-test-credential');
});
