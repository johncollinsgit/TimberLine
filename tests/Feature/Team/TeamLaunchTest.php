<?php

use App\Models\TeamArea;
use App\Models\TeamProviderConnection;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Support/TeamFixtures.php';

beforeEach(function () {
    Http::preventStrayRequests();
    config(['team_work.enabled' => false, 'team_work.premium_enabled' => false, 'team_operations.enabled' => false, 'team_work.mobile_enabled' => false]);
});

test('mobile launch preview is read only and mobile apply requires protected configuration activation', function () {
    [$tenant] = teamWorkspace();
    $this->artisan('team:launch', ['tenant' => $tenant->slug, '--enable-mobile' => true])->assertSuccessful();
    $this->artisan('team:launch', ['tenant' => $tenant->slug, '--apply' => true, '--enable-mobile' => true, '--authorization' => 'approved-mobile-rollout'])
        ->expectsOutputToContain('Mobile activation requires protected configuration activation')->assertFailed();
    expect(TeamArea::count())->toBe(0)->and(config('team_work.mobile_enabled'))->toBeFalse();
    Http::assertNothingSent();
});

test('protected mobile activation preserves unrelated flags and restores the environment if caching fails', function (bool $cacheSucceeds) {
    [$tenant] = teamWorkspace();
    $directory = sys_get_temp_dir().'/teams-launch-'.Str::uuid();
    File::makeDirectory($directory, 0700);
    $path = $directory.'/.env';
    $original = "APP_KEY=fictional-test-key\nPOS_LIVE_NATIVE_CLIENT_ENABLED=false\nTEAM_OPERATIONS_SEND_ENABLED=false\nTEAM_WORK_MOBILE_ENABLED=false\nUNRELATED_SETTING=retained\n";
    file_put_contents($path, $original);
    chmod($path, 0600);
    $previousEnvironment = app()->environment();
    $previousEnvironmentPath = app()->environmentPath();
    $previousEnvironmentFile = app()->environmentFile();
    $previousRelease = getenv('RELEASE_ID');
    app()->instance('env', 'production');
    app()->useEnvironmentPath($directory)->loadEnvironmentFrom('.env');
    config(['app.release_id' => str_repeat('b', 40), 'team_work.tenant_allowlist' => ['existing-tenant']]);
    putenv('RELEASE_ID='.str_repeat('b', 40));
    $kernel = Artisan::getFacadeRoot();
    Artisan::shouldReceive('call')->with('config:cache')->times($cacheSucceeds ? 1 : 2)->andReturn(...($cacheSucceeds ? [0] : [1, 0]));
    Artisan::shouldReceive('call')->with('team:launch', \Mockery::type('array'), \Mockery::any())
        ->andReturnUsing(fn ($name, $arguments, $output) => $kernel->call($name, $arguments, $output));
    try {
        $command = $this->artisan('team:launch', ['tenant' => $tenant->slug, '--apply' => true, '--force-production' => true, '--activate-config' => true, '--enable-mobile' => true, '--authorization' => 'approved-mobile-rollout']);
        if ($cacheSucceeds) {
            $command->assertSuccessful()->run();
            expect(file_get_contents($path))->toContain('TEAM_WORK_MOBILE_ENABLED=true', 'TEAM_WORK_TENANT_ALLOWLIST=existing-tenant,'.$tenant->id, 'POS_LIVE_NATIVE_CLIENT_ENABLED=false', 'TEAM_OPERATIONS_SEND_ENABLED=false', 'UNRELATED_SETTING=retained');
        } else {
            try {
                $command->run();
                $this->fail('Configuration failure must be reported.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $exception) {
                expect($exception->getStatusCode())->toBe(503);
            }
            expect(file_get_contents($path))->toBe($original);
        }
        expect(file_get_contents($path.'.teams-backup-approved-mobile-rollout'))->toBe($original)
            ->and(fileperms($path) & 0777)->toBe(0600);
        Http::assertNothingSent();
    } finally {
        app()->instance('env', $previousEnvironment);
        app()->useEnvironmentPath($previousEnvironmentPath)->loadEnvironmentFrom($previousEnvironmentFile);
        putenv($previousRelease === false ? 'RELEASE_ID' : 'RELEASE_ID='.$previousRelease);
        File::deleteDirectory($directory);
    }
})->with([true, false]);

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

test('production activation refuses missing release context before changing tenant access', function () {
    [$tenant] = teamWorkspace();
    $previousEnvironment = app()->environment();
    $previousRelease = getenv('RELEASE_ID');
    app()->instance('env', 'production');
    config(['app.release_id' => str_repeat('a', 40)]);
    putenv('RELEASE_ID');
    try {
        $this->artisan('team:launch', ['tenant' => $tenant->slug, '--apply' => true, '--authorization' => 'approved-test-rollout', '--force-production' => true, '--activate-config' => true])
            ->expectsOutputToContain('requires the deployed RELEASE_ID')->assertFailed();
        expect(TeamArea::count())->toBe(0)->and(TenantModuleEntitlement::count())->toBe(1);
        Http::assertNothingSent();
    } finally {
        app()->instance('env', $previousEnvironment);
        putenv($previousRelease === false ? 'RELEASE_ID' : 'RELEASE_ID='.$previousRelease);
    }
});
