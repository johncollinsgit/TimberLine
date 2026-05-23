<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantSetupStatus;
use App\Models\User;

beforeEach(function (): void {
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['platform_admin', 'admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com']);
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com', 'theeverbranch.com']);
});

function everbranchSeedTestTenant(string $slug, string $name, string $accountMode = 'production'): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => $name,
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => (int) $tenant->id,
        'plan_key' => $accountMode === 'production' ? 'custom' : 'starter',
        'operating_mode' => $slug === 'modern-forestry' ? 'shopify' : 'direct',
        'source' => 'test',
        'metadata' => ['account_mode' => $accountMode],
    ]);

    TenantSetupStatus::query()->create([
        'tenant_id' => (int) $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => $slug === 'modern-forestry' ? 'shopify' : 'manual',
        'shopify_connection_status' => 'not_connected',
        'mobile_interest' => 'undecided',
        'landlord_review_status' => 'reviewed',
        'billing_lane_interest' => 'free_internal_demo',
    ]);

    return $tenant;
}

test('seed access surfaces dry run does not create records', function (): void {
    $this->artisan('everbranch:seed-access-surfaces', ['--dry-run' => true])
        ->assertExitCode(0);

    expect(Tenant::query()->whereIn('slug', ['modern-forestry', 'everbranch-demo', 'sandbox-test-client'])->exists())->toBeFalse()
        ->and(User::query()->whereIn('email', [
            'everbranch.operator@example.invalid',
            'modern.forestry.admin@example.invalid',
            'everbranch.demo@example.invalid',
            'sandbox.test@example.invalid',
        ])->exists())->toBeFalse();
});

test('seed access surfaces is idempotent and creates expected lanes', function (): void {
    $this->artisan('everbranch:seed-access-surfaces', ['--password' => 'local-only-test-password'])
        ->assertExitCode(0);
    $this->artisan('everbranch:seed-access-surfaces', ['--password' => 'local-only-test-password'])
        ->assertExitCode(0);

    expect(Tenant::query()->whereIn('slug', ['modern-forestry', 'everbranch-demo', 'sandbox-test-client'])->count())->toBe(3)
        ->and(User::query()->whereIn('email', [
            'everbranch.operator@example.invalid',
            'modern.forestry.admin@example.invalid',
            'everbranch.demo@example.invalid',
            'sandbox.test@example.invalid',
        ])->count())->toBe(4);

    $modern = Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
    $demo = Tenant::query()->where('slug', 'everbranch-demo')->firstOrFail();
    $sandbox = Tenant::query()->where('slug', 'sandbox-test-client')->firstOrFail();

    expect(data_get($modern->accessProfile?->metadata, 'account_mode'))->toBe('production')
        ->and(data_get($demo->accessProfile?->metadata, 'account_mode'))->toBe('demo')
        ->and(data_get($sandbox->accessProfile?->metadata, 'account_mode'))->toBe('sandbox')
        ->and($modern->users()->where('email', 'modern.forestry.admin@example.invalid')->exists())->toBeTrue()
        ->and($demo->users()->where('email', 'everbranch.demo@example.invalid')->exists())->toBeTrue()
        ->and($sandbox->users()->where('email', 'sandbox.test@example.invalid')->exists())->toBeTrue();
});

test('seed access surfaces refuses production without explicit force flag', function (): void {
    $this->app->detectEnvironment(fn () => 'production');

    try {
        $this->artisan('everbranch:seed-access-surfaces')
            ->assertExitCode(1);
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
});

test('landlord can view test access panel and tenant admins cannot', function (): void {
    $tenant = everbranchSeedTestTenant('everbranch-demo', 'Everbranch Demo', 'demo');
    $platformAdmin = User::factory()->platformAdmin()->create();
    $demoUser = User::factory()->demoUser()->create([
        'name' => 'Demo Operator',
        'email' => 'demo.operator@example.invalid',
    ]);
    $demoUser->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $tenantAdmin = User::factory()->tenantAdmin()->create();
    $tenantAdmin->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($platformAdmin)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}")
        ->assertOk()
        ->assertSee('Test Access')
        ->assertSee('Everbranch demo tenant')
        ->assertSee('Demo tenant: use for walkthroughs')
        ->assertSee('demo.operator@example.invalid')
        ->assertSee('No direct login bypass is active here')
        ->assertSee('Direct impersonation is not active yet');

    $this->actingAs($tenantAdmin)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}")
        ->assertForbidden();
});

test('seeded Modern Forestry demo and sandbox users keep their distinct routing and banners', function (): void {
    $this->artisan('everbranch:seed-access-surfaces', ['--password' => 'local-only-test-password'])
        ->assertExitCode(0);

    $this->get('http://modern-forestry.theeverbranch.com/login')->assertOk();
    $this->post('http://modern-forestry.theeverbranch.com/login', [
        'email' => 'modern.forestry.admin@example.invalid',
        'password' => 'local-only-test-password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get('http://modern-forestry.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertDontSee('data-access-lane-banner="demo"', false)
        ->assertDontSee('data-access-lane-banner="sandbox"', false);

    $this->post('http://modern-forestry.theeverbranch.com/logout');

    $this->get('http://everbranch-demo.theeverbranch.com/login')->assertOk();
    $this->post('http://everbranch-demo.theeverbranch.com/login', [
        'email' => 'everbranch.demo@example.invalid',
        'password' => 'local-only-test-password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get('http://everbranch-demo.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSee('Viewing Demo Tenant')
        ->assertSee('data-access-lane-banner="demo"', false);

    $this->post('http://everbranch-demo.theeverbranch.com/logout');

    $this->get('http://sandbox-test-client.theeverbranch.com/login')->assertOk();
    $this->post('http://sandbox-test-client.theeverbranch.com/login', [
        'email' => 'sandbox.test@example.invalid',
        'password' => 'local-only-test-password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('dashboard', absolute: false));

    $this->get('http://sandbox-test-client.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSee('Viewing Sandbox Test Tenant')
        ->assertSee('data-access-lane-banner="sandbox"', false);
});
