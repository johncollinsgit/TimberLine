<?php

use App\Models\TenantModuleEntitlement;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_work.mobile_enabled' => true, 'team_operations.enabled' => true]);
});

test('native Teams discovery excludes other active companies without changing shared mobile discovery', function () {
    [$teams, $owner] = teamWorkspace();
    [$other] = teamWorkspace();
    $other->update(['name' => 'Alphabetical company']);
    $other->users()->attach($owner->id, ['role' => 'admin', 'membership_active' => true]);
    config(['team_work.tenant_allowlist' => [(string) $teams->id]]);
    $token = $owner->createToken('Fictional Teams', ['mobile:read'])->plainTextToken;
    $this->withToken($token)->getJson('/api/mobile/v1/teams/workspaces')->assertOk()
        ->assertJsonCount(1, 'workspaces')->assertJsonPath('workspaces.0.id', $teams->id)
        ->assertJsonPath('landlord_access', false)->assertHeader('Cache-Control', 'no-store, private');
    $this->withToken($token)->getJson('/api/mobile/v1/workspaces')->assertOk()->assertJsonCount(2, 'workspaces');
    Http::assertNothingSent();
});

test('Teams discovery rechecks membership entitlement and valid role', function (string $change) {
    [$tenant, $owner] = teamWorkspace();
    if ($change === 'membership') {
        $tenant->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    } elseif ($change === 'role') {
        $tenant->users()->updateExistingPivot($owner->id, ['role' => 'unknown-role']);
    } else {
        TenantModuleEntitlement::where('tenant_id', $tenant->id)->update(['enabled_status' => 'disabled']);
        app()->forgetInstance(TenantModuleAccessResolver::class);
    }
    $token = $owner->createToken('Fictional Teams', ['mobile:read'])->plainTextToken;
    $this->withToken($token)->getJson('/api/mobile/v1/teams/workspaces')->assertOk()->assertJsonCount(0, 'workspaces');
})->with(['membership', 'role', 'entitlement']);

test('Teams discovery requires an active verified user and native read token', function () {
    [, $owner] = teamWorkspace();
    $this->actingAs($owner)->getJson('/api/mobile/v1/teams/workspaces')->assertForbidden();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $token = $owner->createToken('Fictional Teams', ['mobile:read'])->plainTextToken;
    $owner->forceFill(['email_verified_at' => null])->save();
    $this->withToken($token)->getJson('/api/mobile/v1/teams/workspaces')->assertForbidden();
    $owner->forceFill(['email_verified_at' => now(), 'is_active' => false])->save();
    \Illuminate\Support\Facades\Auth::forgetGuards();
    $this->withToken($token)->getJson('/api/mobile/v1/teams/workspaces')->assertForbidden();
});

test('Teams discovery fails closed when native access is disabled', function () {
    [, $owner] = teamWorkspace();
    $token = $owner->createToken('Fictional Teams', ['mobile:read'])->plainTextToken;
    config(['team_work.mobile_enabled' => false]);
    $this->withToken($token)->getJson('/api/mobile/v1/teams/workspaces')->assertNotFound();
});
