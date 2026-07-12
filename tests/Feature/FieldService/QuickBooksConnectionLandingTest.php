<?php

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\User;

test('quickbooks integration landing requires an authenticated verified operator', function (): void {
    $this->get('http://app.theeverbranch.com/integrations/quickbooks')
        ->assertRedirect(route('login'));
});

test('quickbooks integration landing lists only the users workspaces and their connection status', function (): void {
    $connected = Tenant::query()->create(['name' => 'Collins Electric', 'slug' => 'collins-electric']);
    $available = Tenant::query()->create(['name' => 'Modern Forestry', 'slug' => 'modern-forestry']);
    $unrelated = Tenant::query()->create(['name' => 'Unrelated', 'slug' => 'unrelated']);
    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user->tenants()->attach([$connected->id => ['role' => 'admin'], $available->id => ['role' => 'admin']]);

    IntegrationConnection::query()->create([
        'tenant_id' => $connected->id,
        'provider' => 'quickbooks',
        'external_account_id' => 'realm-123',
        'status' => 'connected',
    ]);

    $this->actingAs($user)
        ->get('http://app.theeverbranch.com/integrations/quickbooks')
        ->assertOk()
        ->assertSee('Collins Electric')
        ->assertSee('Modern Forestry')
        ->assertDontSee('Unrelated')
        ->assertSee('Reconnect')
        ->assertSee('Connect');

    expect($unrelated->id)->not->toBeIn($user->accessibleTenantIds());
});

test('quickbooks disconnect destination is public and explains retention and deletion', function (): void {
    $this->get('http://app.theeverbranch.com/integrations/quickbooks/disconnected')
        ->assertOk()
        ->assertSee('QuickBooks is disconnected')
        ->assertSee('request deletion')
        ->assertSee('Sign in to Everbranch');
});
