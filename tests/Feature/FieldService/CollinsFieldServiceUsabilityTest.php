<?php

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\FieldServicePriceBookItem;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantMemberPreference;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\FieldService\FieldServiceJobLifecycleService;
use Laravel\Sanctum\Sanctum;

function usabilityWorkspace(): array
{
    $tenant = Tenant::query()->create(['name' => 'Collins Test Electric', 'slug' => 'collins-test-electric']);
    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => 'base',
        'operating_mode' => 'direct',
        'source' => 'test',
        'metadata' => ['tenant_blueprint' => ['business_template' => 'electrician', 'starter_modules' => ['customers', 'field_service']]],
    ]);
    foreach (['field_service', 'work_core', 'documents', 'estimator'] as $module) {
        TenantModuleEntitlement::query()->create([
            'tenant_id' => $tenant->id,
            'module_key' => $module,
            'availability_status' => 'available',
            'enabled_status' => 'enabled',
            'billing_status' => 'included_in_plan',
            'entitlement_source' => 'test',
            'price_source' => 'catalog',
        ]);
    }
    $owner = User::factory()->create(['role' => 'admin', 'is_active' => true, 'email_verified_at' => now()]);
    $member = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $other = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $owner->tenants()->attach($tenant->id, ['role' => 'owner']);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);
    $other->tenants()->attach($tenant->id, ['role' => 'member']);

    return [$tenant, $owner, $member, $other];
}

test('quickbooks lifecycle derives active quote complete and history without replacing manual status', function (): void {
    [$tenant] = usabilityWorkspace();
    $active = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Open invoice', 'status' => 'open', 'external_source' => 'quickbooks', 'external_id' => 'quickbooks:invoice:1']);
    FieldServiceFinancialDocument::query()->create(['tenant_id' => $tenant->id, 'field_service_job_id' => $active->id, 'source' => 'quickbooks', 'document_type' => 'invoice', 'external_id' => '1', 'status' => 'open', 'transaction_date' => now()->subDays(15), 'total_amount' => 1200, 'balance' => 600]);

    $quote = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Pending quote', 'status' => 'quoted', 'external_source' => 'quickbooks', 'external_id' => 'quickbooks:estimate:2']);
    FieldServiceFinancialDocument::query()->create(['tenant_id' => $tenant->id, 'field_service_job_id' => $quote->id, 'source' => 'quickbooks', 'document_type' => 'estimate', 'external_id' => '2', 'status' => 'pending', 'transaction_date' => now()->subDays(10), 'total_amount' => 800, 'balance' => 0]);

    $complete = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Paid work', 'status' => 'open', 'external_source' => 'quickbooks', 'external_id' => 'quickbooks:invoice:3']);
    FieldServiceFinancialDocument::query()->create(['tenant_id' => $tenant->id, 'field_service_job_id' => $complete->id, 'source' => 'quickbooks', 'document_type' => 'invoice', 'external_id' => '3', 'status' => 'paid', 'transaction_date' => now()->subDays(20), 'total_amount' => 500, 'balance' => 0]);

    $old = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Old work', 'status' => 'open', 'external_source' => 'quickbooks', 'external_id' => 'quickbooks:invoice:4']);
    FieldServiceFinancialDocument::query()->create(['tenant_id' => $tenant->id, 'field_service_job_id' => $old->id, 'source' => 'quickbooks', 'document_type' => 'invoice', 'external_id' => '4', 'status' => 'open', 'transaction_date' => now()->subYears(2), 'total_amount' => 400, 'balance' => 100]);

    $manual = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'title' => 'Manual hold', 'status' => 'open', 'operational_status' => 'blocked', 'status_source' => 'manual']);
    app(FieldServiceJobLifecycleService::class)->reconcileTenant($tenant);

    expect($active->fresh()->operational_status)->toBe('needs_details')
        ->and($quote->fresh()->operational_status)->toBe('quote')
        ->and($complete->fresh()->operational_status)->toBe('complete')
        ->and($old->fresh()->operational_status)->toBe('history')
        ->and($manual->fresh()->operational_status)->toBe('blocked')
        ->and($manual->fresh()->status_source)->toBe('manual');
});

test('field service mobile keeps members on participating jobs and lets owners see all financial detail', function (): void {
    [$tenant, $owner, $member, $other] = usabilityWorkspace();
    $assigned = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $member->id, 'title' => 'Assigned panel job',
        'status' => 'open', 'operational_status' => 'active', 'status_source' => 'manual', 'customer_name' => 'Ada',
    ]);
    $private = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $other->id, 'title' => 'Other crew job',
        'status' => 'open', 'operational_status' => 'active', 'status_source' => 'manual',
    ]);
    FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $assigned->id, 'source' => 'quickbooks',
        'document_type' => 'invoice', 'external_id' => 'member-visible-owner-only-finance', 'transaction_date' => now(),
        'status' => 'open', 'total_amount' => 900, 'balance' => 450, 'private_note' => 'Owner note',
    ]);

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service?view=list&filter=mine')
        ->assertOk()->assertJsonPath('jobs.0.id', $assigned->id)->assertJsonMissing(['id' => $private->id]);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$assigned->id)
        ->assertOk()->assertJsonCount(0, 'job.financials');
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$private->id)->assertNotFound();

    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$assigned->id.'/comments', [
        'body' => 'Panel is labeled and ready for inspection.',
        'mention_user_ids' => [$owner->id],
    ])->assertCreated();
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$assigned->id.'/comments', [
        'body' => 'Trying to close the job.',
        'status_update' => 'complete',
    ])->assertForbidden();
    $this->patchJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/preferences', [
        'phone' => '+18645550123',
        'operational_sms_enabled' => true,
    ])->assertUnprocessable();
    $this->patchJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/preferences', [
        'phone' => '+18645550123',
    ])->assertOk()->assertJsonPath('preferences.phone_verified', false);
    TenantMemberPreference::query()->forTenantId($tenant->id)->where('user_id', $member->id)->update(['phone_verified_at' => now()]);
    $this->patchJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/preferences', [
        'operational_sms_enabled' => true,
    ])->assertOk()->assertJsonPath('preferences.operational_sms_enabled', true);

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service?view=list&filter=active')
        ->assertOk()->assertJsonCount(2, 'jobs');
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$assigned->id)
        ->assertOk()->assertJsonPath('job.financials.0.balance', 450)->assertJsonPath('job.activity.0.body', 'Panel is labeled and ready for inspection.');
});

test('estimator is draft only and owner restricted', function (): void {
    [$tenant, $owner, $member] = usabilityWorkspace();
    $item = FieldServicePriceBookItem::query()->create([
        'tenant_id' => $tenant->id, 'source' => 'curated', 'external_id' => 'manual:test',
        'name' => 'Dedicated 20A circuit', 'item_type' => 'service', 'unit_price' => 425, 'active' => true,
    ]);

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/estimator')->assertForbidden();

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/estimator')
        ->assertOk()->assertJsonPath('catalog.0.id', $item->id);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/estimator/drafts', [
        'title' => 'Kitchen circuit estimate',
        'lines' => [['price_book_item_id' => $item->id, 'description' => 'Dedicated 20A circuit', 'quantity' => 2, 'unit_price' => 425]],
    ])->assertCreated()->assertJsonPath('draft.total', 850)->assertJsonPath('draft.status', 'draft');
});

test('field service mobile fails closed when the branch is disabled', function (): void {
    [$tenant, $owner] = usabilityWorkspace();
    TenantModuleEntitlement::query()->forTenantId($tenant->id)->where('module_key', 'field_service')->update(['enabled_status' => 'disabled']);

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service')->assertNotFound();
});
