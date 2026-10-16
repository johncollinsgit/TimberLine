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
