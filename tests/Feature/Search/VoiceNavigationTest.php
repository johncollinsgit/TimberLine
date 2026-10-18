<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;

beforeEach(function (): void {
    $this->voiceTenant = Tenant::query()->create(['name' => 'Fictional Voice Workspace', 'slug' => 'fictional-voice']);
    TenantAccessProfile::query()->create(['tenant_id' => $this->voiceTenant->id, 'plan_key' => 'starter', 'operating_mode' => 'direct', 'source' => 'test']);
    $this->voiceUser = User::factory()->create(['role' => 'admin']);
    $this->voiceUser->tenants()->attach($this->voiceTenant->id, ['role' => 'owner', 'membership_active' => true]);
});

test('spoken navigation resolves an authorized page and excludes executable action suggestions', function (): void {
    $response = $this->actingAs($this->voiceUser)->getJson(route('app.search', ['q' => 'Can you open the home page?', 'intent' => 'navigate']));
    $response->assertOk()->assertJsonPath('query', 'home')->assertJsonPath('navigation.status', 'matched');
    expect($response->json('navigation.destination.type'))->toBe('navigation')
        ->and(collect($response->json('results'))->pluck('type'))->not->toContain('action');
});

test('navigation controls return no record data and destructive requests are not actions', function (): void {
    $this->actingAs($this->voiceUser)->getJson(route('app.search', ['q' => 'Go back', 'intent' => 'navigate']))
        ->assertOk()->assertJsonPath('navigation.control', 'back')->assertJsonPath('results', []);
    $this->getJson(route('app.search', ['q' => 'Delete the invoice', 'intent' => 'navigate']))
        ->assertOk()->assertJsonPath('navigation.status', 'no_match')->assertJsonPath('results', []);
});

test('body-based web navigation preserves legacy search and tenant authorization', function (): void {
    $this->actingAs($this->voiceUser)->postJson(route('app.search'), ['q' => 'Home', 'intent' => 'navigate'])
        ->assertOk()->assertJsonPath('navigation.status', 'matched');
    $other = Tenant::query()->create(['name' => 'Other Body Workspace', 'slug' => 'other-body']);
    $this->postJson(route('app.search', ['tenant' => $other->id]), ['q' => 'Home', 'intent' => 'navigate'])->assertForbidden();
    $this->getJson(route('app.search', ['q' => 'Home']))->assertOk()->assertJsonMissingPath('navigation');
});

test('body-based native navigation requires read ability and current workspace membership', function (): void {
    \Laravel\Sanctum\Sanctum::actingAs($this->voiceUser, ['mobile:read']);
    $path = '/api/mobile/v1/workspaces/'.$this->voiceTenant->slug.'/search/navigation';
    $this->postJson($path, ['q' => 'Go back', 'intent' => 'execute'])
        ->assertOk()->assertJsonPath('navigation.control', 'back')->assertJsonPath('results', []);
    $this->getJson('/api/mobile/v1/workspaces/'.$this->voiceTenant->slug.'/search?q=Go+back&intent=navigate')
        ->assertOk()->assertJsonPath('navigation.control', 'back');
    \Laravel\Sanctum\Sanctum::actingAs($this->voiceUser, ['mobile:write']);
    $this->postJson($path, ['q' => 'Home'])->assertForbidden();
    \Laravel\Sanctum\Sanctum::actingAs($this->voiceUser, ['mobile:read']);
    $this->voiceUser->tenants()->detach($this->voiceTenant->id);
    $this->postJson($path, ['q' => 'Home'])->assertNotFound();
});

test('voice page finding cannot switch into another tenant or accept an execution intent', function (): void {
    $other = Tenant::query()->create(['name' => 'Other Voice Workspace', 'slug' => 'other-voice']);
    $this->actingAs($this->voiceUser)->getJson(route('app.search', ['q' => 'Home', 'intent' => 'navigate', 'tenant' => $other->id]))->assertForbidden();
    $this->getJson(route('app.search', ['q' => 'Home', 'intent' => 'execute']))->assertUnprocessable();
});

test('a result limit cannot turn two exact page aliases into a unique match', function (): void {
    $this->mock(\App\Services\Navigation\UnifiedAppNavigationService::class)->shouldReceive('build')->andReturn([
        'items' => [
            ['key' => 'a', 'label' => 'First calendar', 'aliases' => ['schedule'], 'href' => '/first'],
            ['key' => 'b', 'label' => 'Second calendar', 'aliases' => ['schedule'], 'href' => '/second'],
        ],
    ]);
    $this->actingAs($this->voiceUser)->getJson(route('app.search', ['q' => 'schedule', 'intent' => 'navigate', 'limit' => 1]))
        ->assertOk()->assertJsonPath('navigation.status', 'choose')->assertJsonCount(1, 'results');
});

test('navigation provider failures do not log a recognized query embedded in an exception', function (string $scope): void {
    $provider = $scope === 'tenant' ? \App\Services\Search\Providers\NavigationSearchProvider::class : \App\Services\Search\Providers\LandlordNavigationSearchProvider::class;
    $coordinator = $scope === 'tenant' ? \App\Services\Search\GlobalSearchCoordinator::class : \App\Services\Search\LandlordSearchCoordinator::class;
    $event = $scope === 'tenant' ? 'search.provider_failed_closed' : 'landlord_search.provider_failed_closed';
    $this->mock($provider)->shouldReceive('search')->andThrow(new RuntimeException('SQL failure containing PRIVATE RECOGNIZED QUERY'));
    \Illuminate\Support\Facades\Log::spy();
    $service = app($coordinator);
    $context = ['tenant_id' => $this->voiceTenant->id, 'user' => $this->voiceUser, 'navigation_only' => true];
    $service->search('PRIVATE RECOGNIZED QUERY', $context);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($name, $data) => $name === $event && ! str_contains(json_encode($data), 'PRIVATE RECOGNIZED QUERY'))->once();
    $service->search('Typed ordinary search', [...$context, 'navigation_only' => false]);
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->withArgs(fn ($name, $data) => $name === $event && str_contains($data['message'], 'PRIVATE RECOGNIZED QUERY'))->once();
})->with(['tenant', 'landlord']);
