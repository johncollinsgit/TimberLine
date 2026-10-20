<?php

use App\Models\TeamArea;
use App\Models\TeamProviderConnection;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use Illuminate\Support\Facades\Http;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['team_work.enabled' => false, 'team_work.premium_enabled' => false, 'team_operations.enabled' => false]);
});

test('launch preview does not grant access or create work areas', function () {
    [$tenant] = teamWorkspace();
    $this->artisan('team:launch', ['tenant' => $tenant->slug])->assertSuccessful();
    expect(TeamArea::count())->toBe(0)->and(TenantModuleEntitlement::count())->toBe(1);
    Http::assertNothingSent();
});

test('authorized launch is replayable and preserves plans and provider connections', function () {
    [$tenant] = teamWorkspace();
    $plan = TenantAccessProfile::where('tenant_id', $tenant->id)->value('plan_key');
    $options = ['tenant' => $tenant->slug, '--apply' => true, '--authorization' => 'approved-test-rollout'];
    $this->artisan('team:launch', $options)->assertSuccessful();
    $this->artisan('team:launch', $options)->assertSuccessful();
    expect(TeamArea::where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(TenantAccessProfile::where('tenant_id', $tenant->id)->value('plan_key'))->toBe($plan)
        ->and(TeamProviderConnection::count())->toBe(0)
        ->and(TenantModuleEntitlement::where('tenant_id', $tenant->id)->where('module_key', 'teams_wholesale_workspace')->value('enabled_status'))->toBe('enabled');
    Http::assertNothingSent();
});

test('launch refuses missing authorization and refuses local shared environment activation before changing access', function () {
    [$tenant] = teamWorkspace();
    $this->artisan('team:launch', ['tenant' => $tenant->slug, '--apply' => true])->assertFailed();
    $this->artisan('team:launch', ['tenant' => $tenant->slug, '--apply' => true, '--authorization' => 'approved-test-rollout', '--activate-config' => true])->assertFailed();
    expect(TeamArea::count())->toBe(0)->and(TenantModuleEntitlement::count())->toBe(1);
    Http::assertNothingSent();
});

test('launch accepts an existing verified tenant administrator without granting membership or owner privileges', function () {
    [$tenant, $administrator] = teamWorkspace();
    $tenant->users()->updateExistingPivot($administrator->id, ['role' => 'admin']);
    $this->artisan('team:launch', ['tenant' => $tenant->slug, '--apply' => true, '--authorization' => 'approved-admin-rollout'])->assertSuccessful();
    expect(TeamArea::where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and($tenant->users()->count())->toBe(1)
        ->and($tenant->users()->first()->pivot->role)->toBe('admin');
    Http::assertNothingSent();
});

test('launch refuses ordinary members and unverified administrators with a specific read-only prerequisite report', function () {
    [$tenant, $member] = teamWorkspace();
    $tenant->users()->updateExistingPivot($member->id, ['role' => 'member']);
    $options = ['tenant' => $tenant->slug, '--apply' => true, '--authorization' => 'approved-test-rollout'];
    $this->artisan('team:launch', $options)->expectsOutputToContain('"schema_ready":true,"eligible_administrators":0')->assertFailed();
    $tenant->users()->updateExistingPivot($member->id, ['role' => 'admin']);
    $member->forceFill(['email_verified_at' => null])->save();
    $this->artisan('team:launch', $options)->assertFailed();
    expect(TeamArea::count())->toBe(0)->and(TenantModuleEntitlement::count())->toBe(1);
    Http::assertNothingSent();
});
