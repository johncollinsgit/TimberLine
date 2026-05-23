<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
use App\Models\TenantSetupStatus;
use App\Models\User;
use App\Services\Tenancy\TenantBlueprintProfileService;

beforeEach(function (): void {
    config()->set('tenancy.landlord.primary_host', 'app.theeverbranch.com');
    config()->set('tenancy.landlord.hosts', ['app.theeverbranch.com']);
    config()->set('tenancy.landlord.operator_roles', ['platform_admin', 'admin']);
    config()->set('tenancy.landlord.operator_emails', []);
    config()->set('tenancy.auth.flagship_tenant_slug', 'modern-forestry');
    config()->set('tenancy.domains.tenant_base_domains', ['theeverbranch.com']);
    config()->set('tenancy.auth.flagship_hosts', ['app.theeverbranch.com', 'theeverbranch.com']);
});

function everbranchBlueprintPost(array $overrides = []): array
{
    return array_merge([
        'name' => 'Blueprint Client',
        'slug' => 'blueprint-client',
        'primary_contact_email' => 'owner@example.invalid',
        'account_mode' => 'production',
        'business_template' => 'generic',
        'operating_mode' => 'direct',
        'data_source_preference' => 'manual',
        'primary_outcome' => 'Understand customers, work, money, resources, and stages.',
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
        'setup_notes' => 'Operator blueprint notes.',
        'onboarding_next_action' => '',
        'role' => 'admin',
        'status' => 'active',
    ], $overrides);
}

test('landlord can create a non Shopify tenant blueprint without activating modules or billing', function (): void {
    $operator = User::factory()->platformAdmin()->create();

    $this->actingAs($operator)
        ->get('http://app.theeverbranch.com/landlord/tenants/create')
        ->assertOk()
        ->assertSee('Create tenant setup plan')
        ->assertSee('CSV / Spreadsheet')
        ->assertSee('Square pending');

    $this->actingAs($operator)
        ->post('http://app.theeverbranch.com/landlord/tenants', everbranchBlueprintPost([
            'name' => 'Green Yard Co',
            'slug' => 'green-yard',
            'business_template' => 'landscaping',
            'operating_mode' => 'direct',
            'data_source_preference' => 'csv',
            'starter_modules' => ['customers', 'jobs', 'materials', 'job_costing', 'photos', 'reports'],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'green-yard')->firstOrFail();
    $profile = TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();
    $setup = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect((string) $profile->operating_mode)->toBe('direct')
        ->and(data_get($profile->metadata, 'account_mode'))->toBe('production')
        ->and(data_get($profile->metadata, 'tenant_blueprint.business_template'))->toBe('landscaping')
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_label'))->toBe('Job')
        ->and(data_get($profile->metadata, 'tenant_blueprint.material_label'))->toBe('Materials')
        ->and(data_get($profile->metadata, 'tenant_blueprint.project_label'))->toBe('Job')
        ->and(data_get($profile->metadata, 'tenant_blueprint.task_label'))->toBe('Task')
        ->and(data_get($profile->metadata, 'tenant_blueprint.assignee_label'))->toBe('Crew Member')
        ->and(data_get($profile->metadata, 'tenant_blueprint.upload_label'))->toBe('Job Photos')
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_management_intent.wants_project_workspace'))->toBeTrue()
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_management_intent.wants_photo_uploads'))->toBeTrue()
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_management_intent.wants_mobile_field_capture'))->toBeTrue()
        ->and(data_get($profile->metadata, 'tenant_blueprint.data_source_preference'))->toBe('csv')
        ->and((array) data_get($profile->metadata, 'tenant_blueprint.starter_modules'))->toContain('jobs', 'job_costing', 'tasks', 'assignments')
        ->and((string) $setup->import_path)->toBe('csv')
        ->and((string) $setup->landlord_review_status)->toBe('waiting_on_everbranch')
        ->and((string) $setup->next_recommended_action)->toContain('CSV');

    expect(TenantModuleState::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0)
        ->and(TenantModuleEntitlement::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0)
        ->and(TenantBillingFulfillment::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0);

    $this->actingAs($operator)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}")
        ->assertOk()
        ->assertSee('Tenant setup plan')
        ->assertSee('Landscaping operating profile')
        ->assertSee('CSV / Spreadsheet')
        ->assertSee('Job')
        ->assertSee('Materials')
        ->assertSee('Job Costing')
        ->assertSee('Work management setup plan')
        ->assertSee('Crew Member')
        ->assertSee('Photo uploads')
        ->assertSee('Mobile field capture')
        ->assertSee('Requested/planned context only');
});

test('tenant admins cannot access landlord tenant blueprint creation', function (): void {
    $tenant = Tenant::query()->create(['name' => 'Tenant User Co', 'slug' => 'tenant-user-co']);
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($user)
        ->get('http://app.theeverbranch.com/landlord/tenants/create')
        ->assertForbidden();

    $this->actingAs($user)
        ->post('http://app.theeverbranch.com/landlord/tenants', everbranchBlueprintPost())
        ->assertForbidden();
});

test('Shopify tenant blueprint remains supported without changing OAuth or checkout', function (): void {
    $operator = User::factory()->platformAdmin()->create();

    $this->actingAs($operator)
        ->post('http://app.theeverbranch.com/landlord/tenants', everbranchBlueprintPost([
            'name' => 'Maker Shopify Co',
            'slug' => 'maker-shopify',
            'business_template' => 'candle_or_maker',
            'operating_mode' => 'shopify',
            'data_source_preference' => 'shopify',
            'starter_modules' => ['customers', 'orders', 'products', 'materials', 'reports', 'campaigns'],
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'maker-shopify')->firstOrFail();
    $profile = TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();
    $setup = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect((string) $profile->operating_mode)->toBe('shopify')
        ->and(data_get($profile->metadata, 'tenant_blueprint.business_template'))->toBe('candle_maker')
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_label'))->toBe('Order or Batch')
        ->and(data_get($profile->metadata, 'tenant_blueprint.material_label'))->toBe('Raw Materials')
        ->and((string) $setup->import_path)->toBe('shopify')
        ->and((string) $setup->shopify_connection_status)->toBe('not_connected');

    expect(TenantBillingFulfillment::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0);
});

test('Start Here reflects direct manual and CSV blueprint guidance for tenant users', function (): void {
    $operator = User::factory()->platformAdmin()->create();

    $this->actingAs($operator)
        ->post('http://app.theeverbranch.com/landlord/tenants', everbranchBlueprintPost([
            'name' => 'Law Office',
            'slug' => 'law-office',
            'business_template' => 'law',
            'operating_mode' => 'manual',
            'data_source_preference' => 'manual',
            'starter_modules' => ['customers', 'matters', 'time_invoices', 'documents', 'reports'],
        ]))
        ->assertSessionHasNoErrors();

    $tenant = Tenant::query()->where('slug', 'law-office')->firstOrFail();
    $tenantUser = User::factory()->tenantAdmin()->create();
    $tenantUser->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($tenantUser)
        ->get('http://law-office.theeverbranch.com/start')
        ->assertOk()
        ->assertSee('Law setup profile')
        ->assertSee('Client')
        ->assertSee('Matter')
        ->assertSee('Documents / Time')
        ->assertSee('Manual setup')
        ->assertSee('Time / Invoices')
        ->assertSee('Recommendations are planning guidance only')
        ->assertDontSee('Install module')
        ->assertDontSee('Checkout');
});

test('templates change labels and recommendations without creating separate route systems', function (): void {
    $operator = User::factory()->platformAdmin()->create();

    $this->actingAs($operator)
        ->post('http://app.theeverbranch.com/landlord/tenants', everbranchBlueprintPost([
            'name' => 'Wire Works',
            'slug' => 'wire-works',
            'business_template' => 'electrician',
            'operating_mode' => 'custom_or_unknown',
            'data_source_preference' => 'undecided',
        ]))
        ->assertSessionHasNoErrors();

    $tenant = Tenant::query()->where('slug', 'wire-works')->firstOrFail();
    $profile = TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect(data_get($profile->metadata, 'tenant_blueprint.work_label'))->toBe('Job')
        ->and(data_get($profile->metadata, 'tenant_blueprint.stage_label'))->toBe('Job Stage')
        ->and(data_get($profile->metadata, 'tenant_blueprint.assignee_label'))->toBe('Technician')
        ->and(data_get($profile->metadata, 'tenant_blueprint.material_label'))->toBe('Parts / Labor')
        ->and((array) data_get($profile->metadata, 'tenant_blueprint.starter_modules'))->toContain('photos', 'estimates', 'parts', 'invoices')
        ->and(route('dashboard', absolute: false))->toBe('/dashboard')
        ->and(route('app.start', absolute: false))->toBe('/start');
});

test('template defaults define work management labels and recommendations without route forks', function (): void {
    $service = app(TenantBlueprintProfileService::class);

    $landscaping = $service->blueprintFromInput(['business_template' => 'landscaping']);
    $electrician = $service->blueprintFromInput(['business_template' => 'electrician']);
    $law = $service->blueprintFromInput(['business_template' => 'law']);
    $maker = $service->blueprintFromInput(['business_template' => 'candle_or_maker']);

    expect($landscaping['project_label'])->toBe('Job')
        ->and($landscaping['assignee_label'])->toBe('Crew Member')
        ->and($landscaping['upload_label'])->toBe('Job Photos')
        ->and($landscaping['work_management_intent']['wants_mobile_field_capture'])->toBeTrue()
        ->and($electrician['assignee_label'])->toBe('Technician')
        ->and($electrician['material_label'])->toBe('Parts / Labor')
        ->and($electrician['upload_label'])->toBe('Job Photos / Receipts')
        ->and($law['customer_label'])->toBe('Client')
        ->and($law['project_label'])->toBe('Matter')
        ->and($law['upload_label'])->toBe('Documents')
        ->and($maker['project_label'])->toBe('Batch / Production Run')
        ->and($maker['task_label'])->toBe('Production Task')
        ->and($maker['upload_label'])->toBe('Product / Batch Photos')
        ->and(route('app.start', absolute: false))->toBe('/start');
});

test('landlord can save explicit work management intent without activating systems', function (): void {
    $operator = User::factory()->platformAdmin()->create();

    $this->actingAs($operator)
        ->post('http://app.theeverbranch.com/landlord/tenants', everbranchBlueprintPost([
            'name' => 'Field Ops Co',
            'slug' => 'field-ops',
            'business_template' => 'custom',
            'operating_mode' => 'manual',
            'data_source_preference' => 'manual',
            'project_label' => 'Engagement',
            'task_label' => 'Action Item',
            'assignee_label' => 'Owner',
            'communication_label' => 'Client Updates',
            'upload_label' => 'Evidence Files',
            'wants_project_workspace' => '1',
            'wants_task_management' => '1',
            'wants_user_assignments' => '1',
            'wants_team_communication' => '1',
            'wants_client_communication' => '1',
            'wants_photo_uploads' => '1',
            'wants_file_uploads' => '1',
            'wants_mobile_field_capture' => '1',
            'work_management_notes' => 'Needs field photos and client-visible progress later.',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $tenant = Tenant::query()->where('slug', 'field-ops')->firstOrFail();
    $profile = TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect(data_get($profile->metadata, 'tenant_blueprint.project_label'))->toBe('Engagement')
        ->and(data_get($profile->metadata, 'tenant_blueprint.task_label'))->toBe('Action Item')
        ->and(data_get($profile->metadata, 'tenant_blueprint.assignee_label'))->toBe('Owner')
        ->and(data_get($profile->metadata, 'tenant_blueprint.communication_label'))->toBe('Client Updates')
        ->and(data_get($profile->metadata, 'tenant_blueprint.upload_label'))->toBe('Evidence Files')
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_management_intent.wants_client_communication'))->toBeTrue()
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_management_intent.wants_file_uploads'))->toBeTrue()
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_management_notes'))->toBe('Needs field photos and client-visible progress later.')
        ->and(TenantModuleState::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0)
        ->and(TenantModuleEntitlement::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0)
        ->and(TenantBillingFulfillment::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0);
});

test('Start Here displays work management setup as planned not active', function (): void {
    $operator = User::factory()->platformAdmin()->create();

    $this->actingAs($operator)
        ->post('http://app.theeverbranch.com/landlord/tenants', everbranchBlueprintPost([
            'name' => 'Photo Jobs Co',
            'slug' => 'photo-jobs',
            'business_template' => 'landscaping',
            'operating_mode' => 'manual',
            'data_source_preference' => 'manual',
        ]))
        ->assertSessionHasNoErrors();

    $tenant = Tenant::query()->where('slug', 'photo-jobs')->firstOrFail();
    $tenantUser = User::factory()->tenantAdmin()->create();
    $tenantUser->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($tenantUser)
        ->get('http://photo-jobs.theeverbranch.com/start')
        ->assertOk()
        ->assertSee('Work Management Setup')
        ->assertSee('Job')
        ->assertSee('Crew Member')
        ->assertSee('Job Photos')
        ->assertSee('Project/work tracking requested')
        ->assertSee('Task management requested')
        ->assertSee('Photo uploads requested')
        ->assertSee('Mobile field capture requested')
        ->assertSee('Not active yet: projects, tasks, assignments, comments, messaging, photo/file uploads, mobile capture, storage uploads, and notifications.')
        ->assertDontSee('Upload photo')
        ->assertDontSee('Create task');
});

test('Modern Forestry and demo sandbox lanes remain stable with blueprint foundation present', function (): void {
    $this->artisan('everbranch:seed-access-surfaces', ['--password' => 'local-only-test-password'])
        ->assertExitCode(0);

    $modern = Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
    $demo = Tenant::query()->where('slug', 'everbranch-demo')->firstOrFail();
    $sandbox = Tenant::query()->where('slug', 'sandbox-test-client')->firstOrFail();

    expect(data_get($modern->accessProfile?->metadata, 'account_mode'))->toBe('production')
        ->and(data_get($demo->accessProfile?->metadata, 'account_mode'))->toBe('demo')
        ->and(data_get($sandbox->accessProfile?->metadata, 'account_mode'))->toBe('sandbox');

    $this->get('http://everbranch-demo.theeverbranch.com/login')->assertOk();
    $this->post('http://everbranch-demo.theeverbranch.com/login', [
        'email' => 'everbranch.demo@example.invalid',
        'password' => 'local-only-test-password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->get('http://everbranch-demo.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSee('Viewing Demo Tenant');

    $this->post('http://everbranch-demo.theeverbranch.com/logout');

    $this->get('http://sandbox-test-client.theeverbranch.com/login')->assertOk();
    $this->post('http://sandbox-test-client.theeverbranch.com/login', [
        'email' => 'sandbox.test@example.invalid',
        'password' => 'local-only-test-password',
    ])->assertRedirect(route('dashboard', absolute: false));

    $this->get('http://sandbox-test-client.theeverbranch.com/dashboard')
        ->assertOk()
        ->assertSee('Viewing Sandbox Test Tenant');
});
