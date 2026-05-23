<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingFulfillment;
use App\Models\TenantModuleEntitlement;
use App\Models\TenantModuleState;
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

function everbranchReviewBlueprintCreate(array $overrides = []): array
{
    return array_merge([
        'name' => 'Review Blueprint Co',
        'slug' => 'review-blueprint-co',
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
        'setup_notes' => 'Initial blueprint notes.',
        'onboarding_next_action' => '',
        'role' => 'admin',
        'status' => 'active',
    ], $overrides);
}

function everbranchReviewBlueprintUpdate(array $overrides = []): array
{
    return array_merge([
        'account_mode' => 'production',
        'business_template' => 'landscaping',
        'operating_mode' => 'manual',
        'data_source_preference' => 'manual',
        'primary_outcome' => 'Track customers, jobs, costs, photos, and stages.',
        'customer_label' => 'Customer',
        'work_label' => 'Job',
        'money_label' => 'Revenue / Cost',
        'material_label' => 'Materials',
        'stage_label' => 'Job Stage',
        'project_label' => 'Job',
        'task_label' => 'Task',
        'assignee_label' => 'Crew Member',
        'communication_label' => 'Job Updates',
        'upload_label' => 'Job Photos',
        'wants_project_workspace' => '1',
        'wants_task_management' => '1',
        'wants_user_assignments' => '1',
        'wants_team_communication' => '1',
        'wants_client_communication' => '0',
        'wants_photo_uploads' => '1',
        'wants_file_uploads' => '0',
        'wants_mobile_field_capture' => '1',
        'starter_modules' => ['customers', 'jobs', 'tasks', 'assignments', 'photos', 'reports'],
        'work_management_notes' => 'Plan field photo capture after module activation.',
        'setup_notes' => 'Review jobs and material flow.',
        'onboarding_next_action' => 'Tenant should confirm first job workflow with Everbranch.',
        'blueprint_review_status' => 'needs_follow_up',
        'blueprint_internal_notes' => 'Landlord-only: verify service area and crew roles.',
        'blueprint_next_action' => 'Schedule blueprint review call.',
    ], $overrides);
}

function everbranchCreateReviewedTenant(User $operator, array $overrides = []): Tenant
{
    test()->actingAs($operator)
        ->post('http://app.theeverbranch.com/landlord/tenants', everbranchReviewBlueprintCreate($overrides))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    return Tenant::query()->where('slug', (string) ($overrides['slug'] ?? 'review-blueprint-co'))->firstOrFail();
}

test('landlord can access blueprint edit page while tenant demo and sandbox users are blocked', function (): void {
    $operator = User::factory()->platformAdmin()->create();
    $tenant = everbranchCreateReviewedTenant($operator);

    $this->actingAs($operator)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}/blueprint/edit")
        ->assertOk()
        ->assertSee('Edit Tenant Setup Plan')
        ->assertSee('Landlord setup review')
        ->assertSee('Work management needs');

    $tenantAdmin = User::factory()->tenantAdmin()->create();
    $tenantAdmin->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($tenantAdmin)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}/blueprint/edit")
        ->assertForbidden();

    $this->artisan('everbranch:seed-access-surfaces', ['--password' => 'local-only-test-password'])
        ->assertExitCode(0);

    $demoTenant = Tenant::query()->where('slug', 'everbranch-demo')->firstOrFail();
    $demoUser = User::query()->where('email', 'everbranch.demo@example.invalid')->firstOrFail();
    $sandboxTenant = Tenant::query()->where('slug', 'sandbox-test-client')->firstOrFail();
    $sandboxUser = User::query()->where('email', 'sandbox.test@example.invalid')->firstOrFail();

    $this->actingAs($demoUser)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$demoTenant->id}/blueprint/edit")
        ->assertForbidden();

    $this->actingAs($sandboxUser)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$sandboxTenant->id}/blueprint/edit")
        ->assertForbidden();
});

test('landlord can update and review blueprint fields without activating billing modules imports or oauth', function (): void {
    $operator = User::factory()->platformAdmin()->create(['name' => 'Blueprint Reviewer']);
    $tenant = everbranchCreateReviewedTenant($operator, [
        'name' => 'Service Blueprint Co',
        'slug' => 'service-blueprint-co',
        'data_source_preference' => 'csv',
    ]);

    $this->actingAs($operator)
        ->patch("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}/blueprint", everbranchReviewBlueprintUpdate([
            'business_template' => 'law',
            'operating_mode' => 'manual',
            'data_source_preference' => 'manual',
            'customer_label' => 'Client',
            'work_label' => 'Matter',
            'money_label' => 'Fees / Invoices',
            'material_label' => 'Documents / Time',
            'stage_label' => 'Matter Stage',
            'project_label' => 'Matter',
            'assignee_label' => 'Responsible User',
            'upload_label' => 'Documents',
            'starter_modules' => ['clients', 'matters', 'tasks', 'documents', 'time_invoices', 'reports'],
            'wants_photo_uploads' => '0',
            'wants_file_uploads' => '1',
            'wants_mobile_field_capture' => '0',
            'work_management_notes' => 'Needs document organization later.',
            'onboarding_next_action' => 'Tenant should confirm matter stages with Everbranch.',
            'blueprint_review_status' => 'reviewed',
            'blueprint_internal_notes' => 'Landlord-only: verify document retention assumptions.',
            'blueprint_next_action' => 'Internal follow-up complete.',
        ]))
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $profile = TenantAccessProfile::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();
    $setup = TenantSetupStatus::query()->where('tenant_id', (int) $tenant->id)->firstOrFail();

    expect((string) $profile->operating_mode)->toBe('manual')
        ->and(data_get($profile->metadata, 'account_mode'))->toBe('production')
        ->and(data_get($profile->metadata, 'tenant_blueprint.business_template'))->toBe('law')
        ->and(data_get($profile->metadata, 'tenant_blueprint.customer_label'))->toBe('Client')
        ->and(data_get($profile->metadata, 'tenant_blueprint.project_label'))->toBe('Matter')
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_management_intent.wants_file_uploads'))->toBeTrue()
        ->and(data_get($profile->metadata, 'tenant_blueprint.work_management_intent.wants_photo_uploads'))->toBeFalse()
        ->and(data_get($profile->metadata, 'tenant_blueprint.blueprint_review_status'))->toBe('reviewed')
        ->and(data_get($profile->metadata, 'tenant_blueprint.blueprint_reviewed_by'))->toBe((int) $operator->id)
        ->and(data_get($profile->metadata, 'tenant_blueprint.blueprint_reviewed_at'))->not->toBeNull()
        ->and(data_get($profile->metadata, 'tenant_blueprint.blueprint_internal_notes'))->toContain('Landlord-only')
        ->and((string) $setup->import_path)->toBe('manual')
        ->and((string) $setup->next_recommended_action)->toBe('Tenant should confirm matter stages with Everbranch.');

    expect(TenantModuleState::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0)
        ->and(TenantModuleEntitlement::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0)
        ->and(TenantBillingFulfillment::query()->where('tenant_id', (int) $tenant->id)->count())->toBe(0);
});

test('review status can move to needs follow up and clears stale reviewed metadata', function (): void {
    $operator = User::factory()->platformAdmin()->create();
    $tenant = everbranchCreateReviewedTenant($operator, ['slug' => 'follow-up-blueprint']);

    $this->actingAs($operator)
        ->patch("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}/blueprint", everbranchReviewBlueprintUpdate([
            'blueprint_review_status' => 'reviewed',
        ]))
        ->assertSessionHasNoErrors();

    expect(data_get($tenant->refresh()->accessProfile?->metadata, 'tenant_blueprint.blueprint_reviewed_by'))->toBe((int) $operator->id);

    $this->actingAs($operator)
        ->patch("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}/blueprint", everbranchReviewBlueprintUpdate([
            'blueprint_review_status' => 'needs_follow_up',
            'blueprint_next_action' => 'Collect missing workflow details.',
        ]))
        ->assertSessionHasNoErrors();

    $metadata = (array) $tenant->refresh()->accessProfile?->metadata;

    expect(data_get($metadata, 'tenant_blueprint.blueprint_review_status'))->toBe('needs_follow_up')
        ->and(data_get($metadata, 'tenant_blueprint.blueprint_reviewed_by'))->toBeNull()
        ->and(data_get($metadata, 'tenant_blueprint.blueprint_reviewed_at'))->toBeNull()
        ->and(data_get($metadata, 'tenant_blueprint.blueprint_next_action'))->toBe('Collect missing workflow details.');
});

test('tenant detail shows review context and Start Here reflects only tenant-facing blueprint updates', function (): void {
    $operator = User::factory()->platformAdmin()->create();
    $tenant = everbranchCreateReviewedTenant($operator, [
        'name' => 'Tenant Facing Blueprint Co',
        'slug' => 'tenant-facing-blueprint',
    ]);

    $this->actingAs($operator)
        ->patch("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}/blueprint", everbranchReviewBlueprintUpdate([
            'business_template' => 'electrician',
            'operating_mode' => 'direct',
            'data_source_preference' => 'csv',
            'customer_label' => 'Customer',
            'work_label' => 'Service Job',
            'material_label' => 'Parts / Labor',
            'project_label' => 'Job',
            'assignee_label' => 'Technician',
            'upload_label' => 'Job Photos / Receipts',
            'onboarding_next_action' => 'Please confirm sample job and parts data.',
            'blueprint_review_status' => 'needs_follow_up',
            'blueprint_internal_notes' => 'Landlord-only: confirm license coverage.',
            'blueprint_next_action' => 'Internal review still needed.',
        ]))
        ->assertSessionHasNoErrors();

    $this->actingAs($operator)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$tenant->id}")
        ->assertOk()
        ->assertSee('Setup review')
        ->assertSee('Needs follow-up')
        ->assertSee('Edit setup plan')
        ->assertSee('Landlord-only: confirm license coverage.')
        ->assertSee('Internal review still needed.');

    $tenantUser = User::factory()->tenantAdmin()->create();
    $tenantUser->tenants()->attach((int) $tenant->id, ['role' => 'admin']);

    $this->actingAs($tenantUser)
        ->get('http://tenant-facing-blueprint.theeverbranch.com/start')
        ->assertOk()
        ->assertSee('Electrician setup profile')
        ->assertSee('Service Job')
        ->assertSee('Technician')
        ->assertSee('Job Photos / Receipts')
        ->assertSee('Please confirm sample job and parts data.')
        ->assertDontSee('Landlord-only: confirm license coverage.')
        ->assertDontSee('Internal review still needed.');
});

test('Modern Forestry demo and sandbox account contexts are preserved during blueprint review edits', function (): void {
    $this->artisan('everbranch:seed-access-surfaces', ['--password' => 'local-only-test-password'])
        ->assertExitCode(0);

    $operator = User::query()->where('email', 'everbranch.operator@example.invalid')->firstOrFail();
    $modern = Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
    $demo = Tenant::query()->where('slug', 'everbranch-demo')->firstOrFail();
    $sandbox = Tenant::query()->where('slug', 'sandbox-test-client')->firstOrFail();

    $this->actingAs($operator)
        ->patch("http://app.theeverbranch.com/landlord/tenants/{$modern->id}/blueprint", everbranchReviewBlueprintUpdate([
            'business_template' => 'candle_or_maker',
            'operating_mode' => 'shopify',
            'data_source_preference' => 'shopify',
            'blueprint_review_status' => 'reviewed',
        ]))
        ->assertSessionHasNoErrors();

    expect(data_get($modern->refresh()->accessProfile?->metadata, 'account_mode'))->toBe('production')
        ->and(data_get($demo->refresh()->accessProfile?->metadata, 'account_mode'))->toBe('demo')
        ->and(data_get($sandbox->refresh()->accessProfile?->metadata, 'account_mode'))->toBe('sandbox');

    $this->actingAs($operator)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$demo->id}/blueprint/edit")
        ->assertOk()
        ->assertSee('Everbranch demo tenant');

    $this->actingAs($operator)
        ->get("http://app.theeverbranch.com/landlord/tenants/{$sandbox->id}/blueprint/edit")
        ->assertOk()
        ->assertSee('Sandbox/test tenant');

    $modernUser = User::query()->where('email', 'modern.forestry.admin@example.invalid')->firstOrFail();
    $this->actingAs($modernUser)
        ->get('http://modern-forestry.theeverbranch.com/dashboard')
        ->assertOk();
});
