<?php

use App\Support\Auth\MobileAuthorizationClients;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamStudioFixtures.php';

beforeEach(function () {
    $this->withoutVite();
    config(['team_work.enabled' => true, 'team_work.premium_enabled' => true, 'team_work.mobile_enabled' => true, 'team_operations.enabled' => true]);
});

test('the Teams native callback is its own exact gated authorization pair', function () {
    MobileAuthorizationClients::assertAllowed('everbranch-teams', 'everbranchteams://auth/callback');
    expect(fn () => MobileAuthorizationClients::assertAllowed('everbranch-teams', 'everbranch://auth/callback'))->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => MobileAuthorizationClients::assertAllowed('everbranch-mobile', 'everbranchteams://auth/callback'))->toThrow(\Illuminate\Validation\ValidationException::class);
    config(['team_work.mobile_enabled' => false]);
    expect(fn () => MobileAuthorizationClients::assertAllowed('everbranch-teams', 'everbranchteams://auth/callback'))->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('native offline leases bind verified account workspace device and exact device session for at most 24 hours', function () {
    [$t, $owner] = teamWorkspace();
    $this->actingAs($owner)->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/offline-lease?device_id='.Str::uuid())->assertForbidden();
    Auth::forgetGuards();
    $token = $owner->createToken('Teams fixture', ['mobile:read', 'mobile:write'], now()->addHours(5));
    $this->withToken($token->plainTextToken);
    $device = (string) Str::uuid();
    $payload = $this->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/offline-lease?device_id='.$device)->assertOk()->assertJsonPath('owner.tenant_id', (string) $t->id)->assertJsonPath('owner.user_id', (string) $owner->id)->assertJsonPath('owner.device_id', $device)->json();
    expect($payload['expires_at'] - $payload['issued_at'])->toBeLessThanOrEqual(18000);
    $sealed = Crypt::decrypt($payload['lease']);
    expect($sealed['session_id'])->toBe($payload['owner']['session_id']);
    $token->accessToken->delete();
    Auth::forgetGuards();
    $this->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/offline-lease?device_id='.$device)->assertUnauthorized();
});

test('native definitions share web storage and require exact command headers and current tenant access', function () {
    [$t, $owner] = teamWorkspace();
    $area = studioArea($t);
    Auth::forgetGuards();
    $token = $owner->createToken('Teams fixture', ['mobile:read', 'mobile:write']);
    $this->withToken($token->plainTextToken);
    $input = ['id' => (string) Str::uuid(), 'request_id' => (string) Str::uuid(), 'expected_version' => 0, 'kind' => 'cost_policy', 'publish' => false, 'definition' => studioPolicy()];
    $url = '/api/mobile/v1/workspaces/'.$t->slug.'/team-work/studio/definition?area='.$area->id;
    $this->postJson($url, $input)->assertUnprocessable();
    $this->withHeader('Idempotency-Key', $input['request_id'])->postJson($url, $input)->assertOk();
    $this->getJson('/api/mobile/v1/workspaces/'.$t->slug.'/team-work/studio?area='.$area->id)->assertOk()->assertJsonPath('definitions.0.id', $input['id']);
    $t->users()->updateExistingPivot($owner->id, ['membership_active' => false]);
    Auth::forgetGuards();
    $this->postJson($url, $input)->assertNotFound();
});
