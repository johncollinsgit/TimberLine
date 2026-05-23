<?php

use App\Models\CustomerAccessRequest;
use App\Models\ShopifyStore;
use App\Models\Tenant;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Onboarding\TenantSetupStatusService;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

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
});

function intakeAdmin(): User
{
    return User::factory()->create([
        'role' => 'admin',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);
}

function intakeTenant(string $slug, array $status = []): Tenant
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);

    TenantSetupStatus::query()->create(array_merge([
        'tenant_id' => (int) $tenant->id,
        'business_profile_status' => 'ready',
        'import_path' => 'undecided',
        'shopify_connection_status' => 'not_connected',
        'square_status' => 'not_requested',
        'csv_manual_status' => 'not_started',
        'module_interests' => [],
        'mobile_interest' => 'undecided',
        'landlord_review_status' => 'pending_review',
        'next_recommended_action' => 'Review '.$slug.' setup.',
    ], $status));

    return $tenant;
}

test('landlord admin can view intake queue rows and source context', function (): void {
    $tenant = intakeTenant('acme-square', [
        'import_path' => 'square',
        'square_status' => 'requested',
        'mobile_interest' => 'both',
        'landlord_review_status' => 'waiting_on_everbranch',
        'next_recommended_action' => 'Confirm Square import shape.',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'approved',
        'name' => 'Acme Ops',
        'email' => 'ops@acme.example.com',
        'company' => 'Acme',
        'requested_tenant_slug' => 'acme-square',
        'tenant_id' => (int) $tenant->id,
    ]);

    $this->actingAs(intakeAdmin())
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake')
        ->assertOk()
        ->assertSeeText('Intake Queue')
        ->assertSeeText('Acme Square')
        ->assertSeeText('Square')
        ->assertSeeText('Android and iOS')
        ->assertSeeText('Waiting on Everbranch')
        ->assertSeeText('Confirm Square import shape.')
        ->assertSeeText('Seeded from access request #');
});

test('non landlord users cannot view intake queue', function (): void {
    $manager = User::factory()->create([
        'role' => 'manager',
        'is_active' => true,
        'email_verified_at' => now(),
    ]);

    $this->actingAs($manager)
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake')
        ->assertForbidden();
});

test('intake queue filters waiting on everbranch review', function (): void {
    intakeTenant('needs-review', ['landlord_review_status' => 'waiting_on_everbranch']);
    intakeTenant('done-review', ['landlord_review_status' => 'reviewed']);

    $this->actingAs(intakeAdmin())
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake?filter=waiting_on_everbranch_review')
        ->assertOk()
        ->assertSeeText('Needs Review')
        ->assertDontSeeText('Done Review');
});

test('intake queue filters shopify selected but not connected and derives connection status', function (): void {
    $notConnected = intakeTenant('shopify-pending', [
        'import_path' => 'shopify',
        'shopify_connection_status' => 'connected',
    ]);
    $connected = intakeTenant('shopify-connected', [
        'import_path' => 'shopify',
        'shopify_connection_status' => 'not_connected',
    ]);

    ShopifyStore::query()->create([
        'tenant_id' => (int) $connected->id,
        'store_key' => 'retail',
        'shop_domain' => 'connected.myshopify.com',
        'access_token' => 'test-token',
        'installed_at' => now(),
    ]);

    app(TenantSetupStatusService::class)->forTenant($notConnected);
    app(TenantSetupStatusService::class)->forTenant($connected);

    expect($notConnected->setupStatus()->first()->shopify_connection_status)->toBe('not_connected')
        ->and($connected->setupStatus()->first()->shopify_connection_status)->toBe('connected');

    $this->actingAs(intakeAdmin())
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake?filter=shopify_selected_not_connected')
        ->assertOk()
        ->assertSeeText('Shopify Pending')
        ->assertSeeText('Shopify follow-up')
        ->assertDontSeeText('Shopify Connected');
});

test('intake queue filters square csv manual and undecided import paths', function (string $filter, string $visible, array $hidden): void {
    intakeTenant('square-tenant', ['import_path' => 'square']);
    intakeTenant('csv-tenant', ['import_path' => 'csv', 'csv_manual_status' => 'requested']);
    intakeTenant('manual-tenant', ['import_path' => 'manual', 'csv_manual_status' => 'requested']);
    intakeTenant('undecided-tenant', ['import_path' => 'undecided']);

    $response = $this->actingAs(intakeAdmin())
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake?filter='.$filter)
        ->assertOk()
        ->assertSeeText($visible);

    foreach ($hidden as $name) {
        $response->assertDontSeeText($name);
    }
})->with([
    ['square_selected', 'Square Tenant', ['Csv Tenant', 'Manual Tenant', 'Undecided Tenant']],
    ['csv_selected', 'Csv Tenant', ['Square Tenant', 'Manual Tenant', 'Undecided Tenant']],
    ['manual_selected', 'Manual Tenant', ['Square Tenant', 'Csv Tenant', 'Undecided Tenant']],
    ['undecided_import_path', 'Undecided Tenant', ['Square Tenant', 'Csv Tenant', 'Manual Tenant']],
]);

test('intake queue filters mobile interest', function (): void {
    intakeTenant('mobile-ready', ['mobile_interest' => 'ios']);
    intakeTenant('mobile-none', ['mobile_interest' => 'none']);
    intakeTenant('mobile-undecided', ['mobile_interest' => 'undecided']);

    $this->actingAs(intakeAdmin())
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake?filter=mobile_interest')
        ->assertOk()
        ->assertSeeText('Mobile Ready')
        ->assertSeeText('Mobile review')
        ->assertDontSeeText('Mobile None')
        ->assertDontSeeText('Mobile Undecided');
});

test('reviewed filter includes reviewed statuses while other review queue excludes them', function (): void {
    intakeTenant('reviewed-tenant', ['landlord_review_status' => 'reviewed']);
    intakeTenant('pending-tenant', ['landlord_review_status' => 'pending_review']);

    $this->actingAs(intakeAdmin())
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake?filter=reviewed')
        ->assertOk()
        ->assertSeeText('Reviewed Tenant')
        ->assertDontSeeText('Pending Tenant');

    $this->actingAs(intakeAdmin())
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake?filter=waiting_on_everbranch_review')
        ->assertOk()
        ->assertSeeText('Pending Tenant')
        ->assertDontSeeText('Reviewed Tenant');
});

test('intake queue exposes only review actions and keeps billing checkout controls absent', function (): void {
    intakeTenant('billing-safe', ['import_path' => 'manual']);

    $this->actingAs(intakeAdmin())
        ->get('http://app.theeverbranch.com/landlord/onboarding/intake')
        ->assertOk()
        ->assertSeeText('Save review')
        ->assertSee('landlord_review_status', false)
        ->assertDontSee('/commercial/billing/stripe', false)
        ->assertDontSeeText('Checkout');

    expect(config('commercial.billing_readiness.checkout_active'))->toBeFalse()
        ->and(config('commercial.billing_readiness.lifecycle_mutations_enabled'))->toBeFalse();
});

test('Modern Forestry mobile API remains unaffected by intake queue', function (): void {
    expect(Route::has('mobile.modern-forestry.products'))->toBeTrue()
        ->and(Route::has('mobile.everbranch.products'))->toBeFalse();

    $this->getJson('/api/mobile/v1/modern-forestry/products?limit=1')
        ->assertStatus(503)
        ->assertJsonPath('meta.tenant', 'modern-forestry');
});
