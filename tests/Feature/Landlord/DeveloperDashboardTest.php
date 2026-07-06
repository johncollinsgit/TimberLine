<?php

use App\Models\User;
use App\Services\Operations\OperationalStatusService;
use Database\Seeders\DeveloperDashboardSeeder;
use Illuminate\Support\Facades\Cache;

function developerLandlordHost(): string
{
    return 'app.theeverbranch.com';
}

beforeEach(function (): void {
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', developerLandlordHost());
    config()->set('tenancy.domains.legacy.base_domains', []);
    config()->set('tenancy.domains.legacy.public_hosts', []);
    config()->set('tenancy.domains.legacy.landlord_hosts', []);
    config()->set('tenancy.landlord.primary_host', developerLandlordHost());
    config()->set('tenancy.landlord.hosts', [developerLandlordHost()]);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.auth.flagship_hosts', [developerLandlordHost(), 'theeverbranch.com']);
    config()->set('tenancy.auth.host_map', []);
});

test('landlord operator sees the developer control center with seeded content', function (): void {
    $this->seed(DeveloperDashboardSeeder::class);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('http://'.developerLandlordHost().'/landlord/developer')
        ->assertOk()
        ->assertSeeText('Developer Control Center')
        ->assertSeeText('Production readiness')
        ->assertSeeText('Recent changes')
        ->assertSeeText('Vision board')
        ->assertSeeText('CI now gates every deploy')          // seeded agentic change
        ->assertSeeText('Automated daily database backups')   // seeded checklist item
        ->assertSeeText('Add Sentry error tracking + alerting'); // seeded vision idea
});

test('non-operator users are forbidden from the developer control center', function (): void {
    $user = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('http://'.developerLandlordHost().'/landlord/developer')
        ->assertForbidden();
});

test('completed vision ideas drop off the board', function (): void {
    $this->seed(DeveloperDashboardSeeder::class);

    \App\Models\VisionIdea::query()->where('slug', 'sentry-error-tracking')->update(['status' => 'done']);

    $user = User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($user)
        ->get('http://'.developerLandlordHost().'/landlord/developer')
        ->assertOk()
        ->assertDontSeeText('Add Sentry error tracking + alerting');
});

test('ops:record-backup stamps the last-backup timestamp for the dashboard', function (): void {
    Cache::forget(OperationalStatusService::LAST_BACKUP_CACHE_KEY);

    $this->artisan('ops:record-backup')->assertSuccessful();

    expect(Cache::get(OperationalStatusService::LAST_BACKUP_CACHE_KEY))->not->toBeNull();
    expect(app(OperationalStatusService::class)->backupStatus()['reported'])->toBeTrue();
});
