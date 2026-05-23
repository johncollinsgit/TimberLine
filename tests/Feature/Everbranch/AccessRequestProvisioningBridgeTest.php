<?php

use App\Models\CustomerAccessRequest;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Onboarding\CustomerAccessApprovalService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('app.url', 'https://app.theeverbranch.com');
    config()->set('tenancy.domains.canonical.scheme', 'https');
    config()->set('tenancy.domains.canonical.base_domain', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.public_host', 'theeverbranch.com');
    config()->set('tenancy.domains.canonical.landlord_host', 'app.theeverbranch.com');
    config()->set('tenancy.domains.legacy.base_domains', []);
    config()->set('tenancy.domains.legacy.public_hosts', []);
    config()->set('tenancy.domains.legacy.landlord_hosts', []);
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('commercial.billing_readiness.checkout_active', false);
    config()->set('commercial.billing_readiness.lifecycle_mutations_enabled', false);

    Notification::fake();
});

function bridgeAdmin(): User
{
    return User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

function bridgeAccessRequest(array $overrides = []): CustomerAccessRequest
{
    return CustomerAccessRequest::query()->create(array_merge([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Acme Ops',
        'email' => 'ops@acme.example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
        'message' => 'We want to import customers and review mobile options.',
        'metadata' => [
            'business_type' => 'retail',
            'team_size' => '6_20',
            'timeline' => '30_days',
            'import_path' => 'square',
            'mobile_interest' => 'both',
            'addons_interest' => ['rewards', 'sms'],
        ],
    ], $overrides));
}

test('approving an access request seeds tenant setup status with intake metadata', function (): void {
    $admin = bridgeAdmin();
    $request = bridgeAccessRequest();

    app(CustomerAccessApprovalService::class)->approve((int) $request->id, (int) $admin->id);

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
    $status = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect((string) $status->import_path)->toBe('square')
        ->and((string) $status->mobile_interest)->toBe('both')
        ->and((array) $status->module_interests)->toBe(['rewards', 'sms'])
        ->and((string) $status->business_profile_status)->toBe('in_progress')
        ->and((string) $status->landlord_review_status)->toBe('waiting_on_everbranch')
        ->and((string) $status->shopify_connection_status)->toBe('not_connected')
        ->and((string) $status->internal_notes)->toContain('Access request #'.$request->id)
        ->and((string) $status->internal_notes)->toContain('Import path: square')
        ->and((string) $status->next_recommended_action)->toContain('Review seeded access request details');

    $request->refresh();
    expect((int) $request->tenant_id)->toBe((int) $tenant->id);
});

test('missing intake metadata defaults safely to undecided values', function (): void {
    $admin = bridgeAdmin();
    $request = bridgeAccessRequest([
        'email' => 'defaults@example.com',
        'requested_tenant_slug' => 'defaults',
        'metadata' => ['requested_via' => 'test'],
        'message' => null,
        'company' => null,
    ]);

    app(CustomerAccessApprovalService::class)->approve((int) $request->id, (int) $admin->id);

    $tenant = Tenant::query()->where('slug', 'defaults')->firstOrFail();
    $status = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect((string) $status->import_path)->toBe('undecided')
        ->and((string) $status->mobile_interest)->toBe('undecided')
        ->and((array) $status->module_interests)->toBe([])
        ->and((string) $status->business_profile_status)->toBe('not_started');
});

test('provisioning bridge is idempotent and does not duplicate setup status rows', function (): void {
    $admin = bridgeAdmin();
    $request = bridgeAccessRequest();
    $service = app(CustomerAccessApprovalService::class);

    $service->approve((int) $request->id, (int) $admin->id);
    $service->approve((int) $request->id, (int) $admin->id);

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();

    expect(TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(1);

    $status = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();
    expect(substr_count((string) $status->internal_notes, 'Access request #'.$request->id))->toBe(1);
});

test('existing tenant setup edits are preserved during access request seeding', function (): void {
    $admin = bridgeAdmin();
    $tenant = Tenant::query()->create([
        'name' => 'Acme Existing',
        'slug' => 'acme',
    ]);

    TenantSetupStatus::query()->create([
        'tenant_id' => (int) $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'csv',
        'shopify_connection_status' => 'not_connected',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'requested',
        'module_interests' => ['rewards'],
        'mobile_interest' => 'ios',
        'landlord_review_status' => 'reviewed',
        'next_recommended_action' => 'Preserve this operator note.',
        'internal_notes' => 'Operator already reviewed intake.',
    ]);

    $request = bridgeAccessRequest([
        'tenant_id' => (int) $tenant->id,
        'metadata' => [
            'import_path' => 'square',
            'mobile_interest' => 'android',
            'addons_interest' => ['sms'],
        ],
    ]);

    app(CustomerAccessApprovalService::class)->approve((int) $request->id, (int) $admin->id);

    $status = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect((string) $status->import_path)->toBe('csv')
        ->and((string) $status->mobile_interest)->toBe('ios')
        ->and((array) $status->module_interests)->toBe(['rewards'])
        ->and((string) $status->business_profile_status)->toBe('ready')
        ->and((string) $status->landlord_review_status)->toBe('reviewed')
        ->and((string) $status->next_recommended_action)->toBe('Preserve this operator note.')
        ->and((string) $status->internal_notes)->toContain('Operator already reviewed intake.')
        ->and((string) $status->internal_notes)->toContain('Access request #'.$request->id);
});

test('shopify setup status remains derived from store connection records', function (): void {
    $admin = bridgeAdmin();
    $request = bridgeAccessRequest([
        'metadata' => [
            'import_path' => 'shopify',
            'mobile_interest' => 'none',
            'shopify_connection_status' => 'connected',
        ],
    ]);

    app(CustomerAccessApprovalService::class)->approve((int) $request->id, (int) $admin->id);

    $tenant = Tenant::query()->where('slug', 'acme')->firstOrFail();
    $status = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();
    expect((string) $status->shopify_connection_status)->toBe('not_connected');

    ShopifyStore::query()->create([
        'tenant_id' => (int) $tenant->id,
        'store_key' => 'retail',
        'shop_domain' => 'acme.myshopify.com',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ]);

    $this->actingAs(User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]));

    app(App\Services\Onboarding\TenantSetupStatusService::class)->forTenant($tenant);

    expect((string) $status->refresh()->shopify_connection_status)->toBe('connected');
});

test('non-admin users cannot trigger approval bridge', function (): void {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
    $request = bridgeAccessRequest();

    expect(fn () => app(CustomerAccessApprovalService::class)->approve((int) $request->id, (int) $manager->id))
        ->toThrow(DomainException::class);

    expect(TenantSetupStatus::query()->count())->toBe(0);
});

test('landlord diagnostics shows bridged access request context', function (): void {
    $admin = bridgeAdmin();
    $request = bridgeAccessRequest();

    app(CustomerAccessApprovalService::class)->approve((int) $request->id, (int) $admin->id);

    $this->actingAs($admin)
        ->get('http://app.theeverbranch.com/landlord/onboarding/journey')
        ->assertOk()
        ->assertSeeText('Seeded from access request #'.$request->id)
        ->assertSeeText('Square')
        ->assertSeeText('Android and iOS')
        ->assertSeeText('Waiting on Everbranch');
});

test('manual landlord tenant creation can seed setup status from a matching access request', function (): void {
    $admin = bridgeAdmin();
    $request = bridgeAccessRequest([
        'email' => 'manual@example.com',
        'requested_tenant_slug' => 'manual-acme',
        'metadata' => [
            'import_path' => 'csv',
            'mobile_interest' => 'ios',
        ],
    ]);

    $this->actingAs($admin)
        ->post('http://app.theeverbranch.com/landlord/tenants', [
            'name' => 'Manual Acme',
            'slug' => 'manual-acme',
            'primary_contact_email' => 'manual@example.com',
            'tenant_type' => 'direct',
            'role' => 'manager',
            'status' => 'active',
        ])
        ->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'manual-acme')->firstOrFail();
    $status = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect((string) $status->import_path)->toBe('csv')
        ->and((string) $status->mobile_interest)->toBe('ios')
        ->and((string) $status->internal_notes)->toContain('Access request #'.$request->id)
        ->and((int) $request->refresh()->tenant_id)->toBe((int) $tenant->id);
});

test('billing and checkout remain disabled while provisioning bridge runs', function (): void {
    $admin = bridgeAdmin();
    $request = bridgeAccessRequest();

    app(CustomerAccessApprovalService::class)->approve((int) $request->id, (int) $admin->id);

    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse();
});
