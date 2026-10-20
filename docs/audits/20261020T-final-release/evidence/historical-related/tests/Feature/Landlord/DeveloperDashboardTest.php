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

test('integration health preserves real zero and nonzero observations', function (): void {
    Cache::forget(OperationalStatusService::LAST_ISSUES_SUCCESS_CACHE_KEY);
    $service = app(OperationalStatusService::class);
    $empty = $service->openIssues();
    expect($empty['known'])->toBeTrue()->and($empty['total'])->toBe(0)
        ->and($empty['error_code'])->toBeNull()->and($empty['recent'])->toHaveCount(0);
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($user)->get('http://'.developerLandlordHost().'/landlord/developer')->assertOk()
        ->assertSee('data-integration-health-state="known"', false)->assertSeeText('All clear');
    foreach (['error', 'warning'] as $severity) {
        \App\Models\IntegrationHealthEvent::query()->create([
            'provider' => 'quality', 'event_type' => 'fixture', 'severity' => $severity,
            'status' => 'open', 'dedupe_key' => 'quality:'.$severity, 'occurred_at' => now(),
        ]);
    }
    \App\Models\IntegrationHealthEvent::query()->create([
        'provider' => 'quality', 'event_type' => 'fixture', 'severity' => 'error',
        'status' => 'resolved', 'dedupe_key' => 'quality:resolved', 'occurred_at' => now(),
    ]);
    $observed = $service->openIssues();
    expect($observed['known'])->toBeTrue()->and($observed['total'])->toBe(2)
        ->and((int) $observed['by_severity']['error'])->toBe(1)
        ->and((int) $observed['by_severity']['warning'])->toBe(1)
        ->and($observed['recent'])->toHaveCount(2);
    $this->actingAs($user)->get('http://'.developerLandlordHost().'/landlord/developer')->assertOk()
        ->assertSee('data-integration-health-state="known"', false)->assertSeeText('2 open');
});

test('any failed integration health read is unknown with sanitized evidence and last successful time', function (int $failAt): void {
    \Illuminate\Support\Facades\Log::spy();
    $service = app(OperationalStatusService::class);
    $prior = $service->openIssues();
    $this->travel(5)->minutes();
    $queryNumber = 0;
    \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $query) use (&$queryNumber, $failAt): void {
        if (str_contains($query, 'integration_health_events') && ++$queryNumber >= $failAt) {
            throw new RuntimeException('DO_NOT_LEAK secret=fictional SQL details');
        }
    });
    $failed = $service->openIssues();
    expect($failed['known'])->toBeFalse()->and($failed['total'])->toBeNull()
        ->and($failed['by_severity'])->toBe([])->and($failed['recent'])->toHaveCount(0)
        ->and($failed['error_code'])->toBe('integration_health_read_failed')
        ->and($failed['last_success_at']->equalTo($prior['checked_at']->startOfSecond()))->toBeTrue();
    $user = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $this->actingAs($user)->get('http://'.developerLandlordHost().'/landlord/developer')->assertOk()
        ->assertSee('data-integration-health-state="unknown"', false)
        ->assertSeeText('Unknown')->assertSeeText('Integration health unavailable')
        ->assertDontSeeText('All clear')->assertDontSeeText('No open integration health events right now.')
        ->assertDontSeeText('DO_NOT_LEAK')->assertDontSeeText('fictional SQL details');
    \Illuminate\Support\Facades\Log::shouldHaveReceived('warning')->with('operations.integration_health.read_failed', [
        'error_code' => 'integration_health_read_failed', 'exception_type' => 'RuntimeException',
    ]);
})->with(['count failure' => [1], 'severity failure' => [2], 'recent list failure' => [3]]);

test('a failed initial health observation has no invented last successful time', function (): void {
    Cache::forget(OperationalStatusService::LAST_ISSUES_SUCCESS_CACHE_KEY);
    \Illuminate\Support\Facades\DB::connection()->beforeExecuting(function (string $query): void {
        if (str_contains($query, 'integration_health_events')) {
            throw new RuntimeException('fictional unavailable database');
        }
    });
    $failed = app(OperationalStatusService::class)->openIssues();
    expect($failed['known'])->toBeFalse()->and($failed['total'])->toBeNull()->and($failed['last_success_at'])->toBeNull();
});

test('health provenance cache failure does not invalidate a successful issue read', function (): void {
    Cache::partialMock()->shouldReceive('get')->with(OperationalStatusService::LAST_ISSUES_SUCCESS_CACHE_KEY)
        ->andThrow(new RuntimeException('fictional cache unavailable'));
    Cache::shouldReceive('put')->with(OperationalStatusService::LAST_ISSUES_SUCCESS_CACHE_KEY, \Mockery::type('string'), \Mockery::any())
        ->andThrow(new RuntimeException('fictional cache unavailable'));
    $observed = app(OperationalStatusService::class)->openIssues();
    expect($observed['known'])->toBeTrue()->and($observed['total'])->toBe(0)
        ->and($observed['error_code'])->toBeNull()->and($observed['last_success_at'])->not->toBeNull();
});
