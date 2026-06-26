<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Tenancy\TenantBlueprintModuleRecommendationService;

beforeEach(function (): void {
    $this->withoutVite();

    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['platform_admin', 'admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com']);
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com', 'theeverbranch.com']);
});

function pr25BlueprintPost(array $overrides = []): array
{
    return array_merge([
        'name' => 'Blueprint Module Client',
        'slug' => 'blueprint-module-client',
        'primary_contact_email' => 'owner@example.invalid',
        'account_mode' => 'production',
        'business_template' => 'generic',
        'operating_mode' => 'direct',
        'data_source_preference' => 'manual',
        'primary_outcome' => '',
        'customer_label' => '',
        'work_label' => '',
        'money_label' => '',
        'material_label' => '',
        'stage_label' => '',
        'project_label' => '',
        'task_label' => '',
        'assignee_label' => '',
        'communication_label' => '',
        'upload_label' => '',
        'starter_modules' => [],
        'work_management_notes' => '',
        'setup_notes' => '',
        'onboarding_next_action' => '',
        'role' => 'admin',
        'status' => 'active',
    ], $overrides);
}

function pr25CreateTenant($testCase, array $overrides = []): Tenant
{
    $operator = User::factory()->platformAdmin()->create();
    $payload = pr25BlueprintPost($overrides);

    $testCase->actingAs($operator)
        ->post('http://app.theeverbranch.com/landlord/tenants', $payload)
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $tenant = Tenant::query()->where('slug', (string) $payload['slug'])->firstOrFail();

    TenantSetupStatus::query()->updateOrCreate(
        ['tenant_id' => (int) $tenant->id],
        [
            'business_profile_status' => 'ready',
            'import_path' => 'manual',
            'shopify_connection_status' => 'not_applicable',
            'mobile_interest' => 'undecided',
            'landlord_review_status' => 'reviewed',
        ]
    );

    return $tenant->refresh();
}

function pr25TenantUser(Tenant $tenant): User
{
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    return $user;
}

test('blueprint templates produce display-only module recommendation families', function (): void {
    $service = app(TenantBlueprintModuleRecommendationService::class);

    $landscaping = pr25CreateTenant($this, [
        'name' => 'Landscaping Modules',
        'slug' => 'landscaping-modules',
        'business_template' => 'landscaping',
        'operating_mode' => 'manual',
        'data_source_preference' => 'manual',
    ]);
    $electrician = pr25CreateTenant($this, [
        'name' => 'Electrician Modules',
        'slug' => 'electrician-modules',
        'business_template' => 'electrician',
        'operating_mode' => 'manual',
        'data_source_preference' => 'manual',
    ]);
    $law = pr25CreateTenant($this, [
        'name' => 'Law Modules',
        'slug' => 'law-modules',
        'business_template' => 'law',
        'operating_mode' => 'manual',
        'data_source_preference' => 'manual',
    ]);
    $maker = pr25CreateTenant($this, [
        'name' => 'Maker Modules',
        'slug' => 'maker-modules',
        'business_template' => 'candle_or_maker',
        'operating_mode' => 'manual',
        'data_source_preference' => 'manual',
    ]);

    $landscapingRows = collect($service->forTenantModel($landscaping)['rows'] ?? []);
    $electricianRows = collect($service->forTenantModel($electrician)['rows'] ?? []);
    $lawRows = collect($service->forTenantModel($law)['rows'] ?? []);
    $makerRows = collect($service->forTenantModel($maker)['rows'] ?? []);

    expect($landscapingRows->pluck('key')->all())->toContain('jobs', 'tasks', 'assignments', 'photos', 'materials', 'job_costing', 'reports')
        ->and($landscapingRows->pluck('label')->all())->toContain('Jobs', 'Tasks', 'Assignments', 'Job Photos', 'Materials', 'Job Costing', 'Reports')
        ->and($electricianRows->pluck('key')->all())->toContain('jobs', 'tasks', 'assignments', 'estimates', 'parts', 'invoices', 'photos', 'reports')
        ->and($lawRows->pluck('key')->all())->toContain('clients', 'matters', 'tasks', 'documents', 'time_invoices', 'reports')
        ->and($makerRows->pluck('key')->all())->toContain('orders', 'products', 'batches', 'tasks', 'materials', 'inventory', 'photos', 'reports')
        ->and($makerRows->pluck('label')->all())->toContain('Production Tasks', 'Raw Materials', 'Product / Batch Photos');
});

test('Shopify blueprint gets ecommerce recommendations while manual tenants avoid Shopify-only assumptions', function (): void {
    $service = app(TenantBlueprintModuleRecommendationService::class);

    $shopifyTenant = pr25CreateTenant($this, [
        'name' => 'Shopify Blueprint Modules',
        'slug' => 'shopify-blueprint-modules',
        'business_template' => 'apparel',
        'operating_mode' => 'shopify',
        'data_source_preference' => 'shopify',
    ]);
    $manualTenant = pr25CreateTenant($this, [
        'name' => 'Manual Blueprint Modules',
        'slug' => 'manual-blueprint-modules',
        'business_template' => 'landscaping',
        'operating_mode' => 'manual',
        'data_source_preference' => 'manual',
    ]);

    $shopifyKeys = collect($service->forTenantModel($shopifyTenant)['rows'] ?? [])->pluck('key')->all();
    $manualKeys = collect($service->forTenantModel($manualTenant)['rows'] ?? [])->pluck('key')->all();

    expect($shopifyKeys)->toContain('customers', 'orders', 'products', 'campaigns', 'reports', 'rewards', 'wishlist')
        ->and($manualKeys)->toContain('jobs', 'tasks', 'materials')
        ->and($manualKeys)->not->toContain('rewards')
        ->and($manualKeys)->not->toContain('wishlist');
});

test('work management intent influences requested and not active yet display states without activation', function (): void {
    $tenant = pr25CreateTenant($this, [
        'name' => 'Field Intent Modules',
        'slug' => 'field-intent-modules',
        'business_template' => 'custom',
        'operating_mode' => 'manual',
        'data_source_preference' => 'manual',
        'wants_project_workspace' => '1',
        'wants_task_management' => '1',
        'wants_user_assignments' => '1',
        'wants_team_communication' => '1',
        'wants_photo_uploads' => '1',
        'wants_file_uploads' => '1',
        'wants_mobile_field_capture' => '1',
    ]);

    $rows = collect(app(TenantBlueprintModuleRecommendationService::class)->forTenantModel($tenant)['rows'] ?? []);

    expect($rows->firstWhere('key', 'projects')['display_state'])->toBe('not_active_yet')
        ->and($rows->firstWhere('key', 'tasks')['display_state'])->toBe('not_active_yet')
        ->and($rows->firstWhere('key', 'photos')['display_state'])->toBe('not_active_yet')
        ->and($rows->firstWhere('key', 'mobile_field_capture')['display_state'])->toBe('not_active_yet')
        ->and(TenantModuleState::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0)
        ->and(TenantModuleEntitlement::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0)
        ->and(TenantBillingFulfillment::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0);
});

test('tenant Module Store Start Here and landlord detail show blueprint module states safely', function (): void {
    $tenant = pr25CreateTenant($this, [
        'name' => 'Catalog Alignment Co',
        'slug' => 'catalog-alignment',
        'business_template' => 'landscaping',
        'operating_mode' => 'manual',
        'data_source_preference' => 'csv',
    ]);
    $user = pr25TenantUser($tenant);
    $operator = User::factory()->platformAdmin()->create();

    $this->actingAs($user)
        ->get('http://catalog-alignment.theeverbranch.com/marketing/modules')
        ->assertOk()
        ->assertSeeText('Recommended for your setup')
        ->assertSeeText('Job Photos')
        ->assertSeeText('Not active yet')
        ->assertSeeText('Recommended by the Landscaping setup profile')
        ->assertDontSeeText('Pay now');

    $this->actingAs($user)
        ->get('http://catalog-alignment.theeverbranch.com/start')
        ->assertOk()
        ->assertSeeText('Setup feature guidance')
        ->assertSeeText('recommended')
        ->assertSeeText('requested')
        ->assertSeeText('planned/future')
        ->assertSeeText('These states are display-only')
        ->assertDontSeeText('Upload photo')
        ->assertDontSeeText('Create task');

    $this->actingAs($operator)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}")
        ->assertOk()
        ->assertSeeText('Setup-driven module recommendations')
        ->assertSeeText('Setup recommendations do not activate modules or billing.')
        ->assertSeeText('Job Photos');
});

test('demo sandbox and Modern Forestry recommendation contexts remain distinct', function (): void {
    $demo = pr25CreateTenant($this, [
        'name' => 'Everbranch Demo Modules',
        'slug' => 'everbranch-demo-modules',
        'account_mode' => 'demo',
        'business_template' => 'generic',
        'operating_mode' => 'demo',
        'data_source_preference' => 'manual',
    ]);
    $sandbox = pr25CreateTenant($this, [
        'name' => 'Sandbox Modules',
        'slug' => 'sandbox-modules',
        'account_mode' => 'sandbox',
        'business_template' => 'generic',
        'operating_mode' => 'sandbox',
        'data_source_preference' => 'manual',
    ]);
    $modern = pr25CreateTenant($this, [
        'name' => 'Modern Forestry',
        'slug' => 'modern-forestry',
        'account_mode' => 'production',
        'business_template' => 'candle_or_maker',
        'operating_mode' => 'shopify',
        'data_source_preference' => 'shopify',
    ]);

    $service = app(TenantBlueprintModuleRecommendationService::class);

    expect(data_get($service->forTenantModel($demo), 'context.is_demo'))->toBeTrue()
        ->and(data_get($service->forTenantModel($sandbox), 'context.is_sandbox'))->toBeTrue()
        ->and(data_get($service->forTenantModel($modern), 'context.is_flagship_tenant'))->toBeTrue();

    $demoUser = pr25TenantUser($demo);
    $this->actingAs($demoUser)
        ->get('http://everbranch-demo-modules.theeverbranch.com/marketing/modules')
        ->assertOk()
        ->assertSeeText('Demo tenant context');
});
