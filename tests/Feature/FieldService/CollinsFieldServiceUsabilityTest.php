<?php

use App\Models\FieldServiceFinancialDocument;
use App\Models\FieldServiceJob;
use App\Models\FieldServicePriceBookItem;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTaskEvent;
use App\Models\IntegrationConnection;
use App\Models\QuickBooksReportingSnapshot;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantMemberPreference;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\FieldService\FieldServiceJobLifecycleService;
use App\Services\FieldService\FieldServiceJobReadinessService;
use App\Services\Mobile\TenantMobileModuleRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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
    expect(collect(app(TenantMobileModuleRegistry::class)->manifest((int) $tenant->id, $member, '2.2.0'))->pluck('module_key'))
        ->not->toContain('estimator');
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/estimator')->assertForbidden();

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    expect(collect(app(TenantMobileModuleRegistry::class)->manifest((int) $tenant->id, $owner, '2.2.0'))->pluck('module_key'))
        ->toContain('estimator');
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

test('work 2 readiness and field transitions preserve manager and participant boundaries', function (): void {
    [$tenant, $owner, $member, $other] = usabilityWorkspace();
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $member->id, 'title' => 'Ready panel change',
        'status' => 'open', 'operational_status' => 'scheduled', 'status_source' => 'system',
        'customer_name' => 'Pat Customer', 'customer_phone' => '555-1010', 'description' => 'Replace the main panel.',
        'service_address_line_1' => '100 Main Street', 'scheduled_for' => now()->addDay(),
    ]);
    expect(app(FieldServiceJobReadinessService::class)->forJob($job)['ready'])->toBeTrue();

    Sanctum::actingAs($other, ['mobile:read', 'mobile:write']);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/transitions', ['action' => 'start'])->assertForbidden();

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/transitions', ['action' => 'block'])->assertUnprocessable();
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/transitions', ['action' => 'start'])
        ->assertOk()->assertJsonPath('status', 'active');
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/transitions', ['action' => 'block', 'reason' => 'Waiting on utility disconnect'])
        ->assertOk()->assertJsonPath('status', 'blocked');
    expect($job->fresh()->blocked_reason)->toBe('Waiting on utility disconnect');

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/transitions', ['action' => 'cancel'])->assertOk()->assertJsonPath('status', 'canceled');
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/transitions', ['action' => 'reopen'])->assertOk()->assertJsonPath('status', 'scheduled');
});

test('work 2 my day and task APIs are role aware and tenant scoped', function (): void {
    [$tenant, $owner, $member, $other] = usabilityWorkspace();
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $member->id, 'title' => 'Today service call',
        'status' => 'open', 'operational_status' => 'scheduled', 'customer_phone' => '555-2020',
        'description' => 'Repair a failed receptacle.', 'service_address_line_1' => '20 Oak Street', 'scheduled_for' => now()->startOfDay()->addHours(10),
    ]);

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/my-day')
        ->assertOk()->assertJsonPath('contract_version', 5)->assertJsonPath('today_jobs.0.id', $job->id)
        ->assertJsonPath('viewer.capabilities.manage_jobs', false);
    $taskId = $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/tasks', [
        'title' => 'Verify voltage', 'assigned_user_id' => $member->id, 'priority' => 'high',
    ])->assertCreated()->json('task_id');
    $this->patchJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/tasks/'.$taskId, ['status' => 'done'])->assertOk();
    expect(FieldServiceTask::query()->find($taskId)?->completed_by_user_id)->toBe($member->id);

    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/tasks', [
        'title' => 'Assign someone else', 'assigned_user_id' => $other->id,
    ])->assertForbidden();

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/my-day')
        ->assertOk()->assertJsonPath('viewer.capabilities.manage_jobs', true)->assertJsonCount(2, 'owner_shortcuts');
});

test('work 2 notifications remain tenant and user scoped', function (): void {
    [$tenant, $owner, $member] = usabilityWorkspace();
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $member->id, 'title' => 'Notification job',
        'status' => 'open', 'operational_status' => 'active', 'status_source' => 'manual',
    ]);
    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/comments', ['body' => 'Meet at the south entrance.'])->assertCreated();

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $notificationId = $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/notifications')
        ->assertOk()->assertJsonPath('unread', 1)->json('notifications.0.id');
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/notifications/'.$notificationId.'/read')->assertOk();
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/notifications')->assertJsonPath('unread', 0);

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/notifications/'.$notificationId.'/read')->assertNotFound();
});

test('field service v7 supports multiple assignees assigned task feeds and idempotent handoffs', function (): void {
    [$tenant, $owner, $member, $other] = usabilityWorkspace();
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $member->id,
        'title' => 'Shared panel replacement',
        'status' => 'open',
        'operational_status' => 'active',
    ]);
    $job->participants()->attach($other->id, ['tenant_id' => $tenant->id, 'role' => 'member', 'following' => true]);

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $taskId = $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/tasks', [
        'title' => 'Finish and verify panel',
        'assignee_ids' => [$member->id, $other->id],
        'priority' => 'urgent',
    ])->assertCreated()
        ->assertJsonCount(2, 'task.assignees')
        ->json('task_id');

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/tasks?scope=assigned_to_me&status=open')
        ->assertOk()
        ->assertJsonPath('contract_version', 7)
        ->assertJsonPath('tasks.0.id', $taskId)
        ->assertJsonCount(2, 'tasks.0.assignees');
    $handoffUrl = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/tasks/'.$taskId.'/handoff';
    $this->withHeader('Idempotency-Key', 'handoff-'.$taskId)
        ->postJson($handoffUrl, ['assignee_ids' => [$other->id], 'note' => 'Waiting on final torque check.'])
        ->assertOk()->assertJsonPath('replayed', false)->assertJsonPath('task.status', 'waiting')->assertJsonCount(1, 'task.assignees');
    $this->withHeader('Idempotency-Key', 'handoff-'.$taskId)
        ->postJson($handoffUrl, ['assignee_ids' => [$other->id], 'note' => 'Waiting on final torque check.'])
        ->assertOk()->assertJsonPath('replayed', true);

    expect(FieldServiceTaskEvent::query()->forTenantId($tenant->id)->where('field_service_task_id', $taskId)->count())->toBe(1)
        ->and(FieldServiceTask::query()->findOrFail($taskId)->assigned_user_id)->toBe($other->id);

    Sanctum::actingAs($other, ['mobile:read', 'mobile:write']);
    $officeUrl = '/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/tasks/'.$taskId.'/send-to-office';
    $this->withHeader('Idempotency-Key', 'office-'.$taskId)
        ->postJson($officeUrl, ['office_user_id' => $owner->id, 'note' => 'Please order the inspection.'])
        ->assertOk()->assertJsonPath('replayed', false)->assertJsonPath('task.status', 'waiting');
    $this->withHeader('Idempotency-Key', 'office-'.$taskId)
        ->postJson($officeUrl, ['office_user_id' => $owner->id, 'note' => 'Please order the inspection.'])
        ->assertOk()->assertJsonPath('replayed', true);
    expect(FieldServiceTask::query()->findOrFail($taskId)->assigned_user_id)->toBe($owner->id);
});

test('team visible PDF drawings are tenant scoped audited and readable by every authorized Collins employee', function (): void {
    Storage::fake('local');
    [$tenant, $owner, $member, $other] = usabilityWorkspace();
    TenantModuleEntitlement::query()->forTenantId($tenant->id)->where('module_key', 'field_service')->update(['metadata' => ['member_job_visibility' => 'all_operational']]);
    $job = FieldServiceJob::query()->create(['tenant_id' => $tenant->id, 'assigned_user_id' => $other->id, 'title' => 'Drawing review', 'status' => 'open', 'operational_status' => 'active']);

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->post('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/files', [
        'files' => [UploadedFile::fake()->create('panel-drawing.pdf', 1024, 'application/pdf')],
    ])->assertCreated()->assertJsonPath('files.0.mime_type', 'application/pdf');
    $asset = \App\Models\WorkspaceAsset::query()->forTenantId($tenant->id)->sole();
    expect($asset->visibility)->toBe('team')->and($asset->jobs()->whereKey($job->id)->exists())->toBeTrue()
        ->and(\App\Models\WorkspaceAssetEvent::query()->forTenantId($tenant->id)->where('workspace_asset_id', $asset->id)->where('action', 'uploaded')->exists())->toBeTrue();

    Sanctum::actingAs($other, ['mobile:read']);
    $this->get('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/assets/'.$asset->id)->assertOk();

    $foreign = Tenant::query()->create(['name' => 'Foreign Electric', 'slug' => 'foreign-electric']);
    $other->tenants()->attach($foreign->id, ['role' => 'member']);
    Sanctum::actingAs($other, ['mobile:read']);
    $this->get('/api/mobile/v1/workspaces/'.$foreign->slug.'/field-service/assets/'.$asset->id)->assertNotFound();
});

test('field operations v7 lets configured members browse every job without broadening mutations or financial payloads', function (): void {
    [$tenant, $owner, $member, $other] = usabilityWorkspace();
    TenantModuleEntitlement::query()->forTenantId($tenant->id)->where('module_key', 'field_service')->update([
        'metadata' => ['member_job_visibility' => 'all_operational', 'field_service_contract_version' => 7],
    ]);
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $other->id, 'title' => 'Visible crew job',
        'status' => 'open', 'operational_status' => 'active', 'customer_name' => 'Casey Customer',
        'service_address_line_1' => '14 Trade Street', 'service_city' => 'Greenville', 'service_state' => 'SC',
        'project_manager_name' => 'Pat Builder', 'project_manager_company' => 'Upstate GC', 'project_manager_phone' => '+18645550199',
    ]);
    FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'source' => 'quickbooks',
        'document_type' => 'invoice', 'external_id' => 'all-jobs-finance', 'status' => 'open',
        'total_amount' => 2400, 'balance' => 900, 'private_note' => 'Never expose this note',
    ]);

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $list = $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service?view=list&filter=active')
        ->assertOk()->assertJsonPath('contract_version', 7)->assertJsonPath('jobs.0.id', $job->id)
        ->assertJsonPath('jobs.0.address.formatted', '14 Trade Street, Greenville, SC')
        ->assertJsonPath('jobs.0.project_manager.phone', '+18645550199');
    expect(json_encode($list->json()))->not->toContain('2400', '900', 'Never expose this note', 'financial_total', 'financial_balance');
    $this->patchJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id, ['title' => 'Unauthorized rename'])->assertForbidden();
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id.'/transitions', ['action' => 'complete'])->assertForbidden();

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/jobs/'.$job->id)
        ->assertOk()->assertJsonPath('job.financials.0.balance', 900);
});

test('member and manager mobile surfaces never serialize QuickBooks financial evidence', function (string $role): void {
    [$tenant, $owner, $member] = usabilityWorkspace();
    $viewer = $role === 'member' ? $member : User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    if ($role === 'manager') {
        $viewer->tenants()->attach($tenant->id, ['role' => 'manager']);
    }
    TenantModuleEntitlement::query()->forTenantId($tenant->id)->where('module_key', 'field_service')->update([
        'metadata' => ['member_job_visibility' => 'all_operational', 'field_service_contract_version' => 7],
    ]);
    TenantModuleEntitlement::query()->create([
        'tenant_id' => $tenant->id, 'module_key' => 'reporting', 'availability_status' => 'available',
        'enabled_status' => 'enabled', 'billing_status' => 'included_in_plan', 'entitlement_source' => 'test', 'price_source' => 'catalog',
    ]);
    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id, 'assigned_user_id' => $member->id, 'title' => 'Safe operational title',
        'customer_name' => 'Safe Customer', 'status' => 'open', 'operational_status' => 'active',
    ]);
    FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'source' => 'quickbooks',
        'document_type' => 'invoice', 'external_id' => 'finance-contract-secret', 'document_number' => 'INV-SECRET-7788',
        'status' => 'open', 'transaction_date' => now(), 'total_amount' => 9876.54, 'balance' => 8765.43,
        'private_note' => 'FINANCIAL PRIVATE NOTE MUST NEVER LEAK',
    ]);

    Sanctum::actingAs($viewer, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug;
    $responses = [
        $this->getJson($base.'/bootstrap')->assertOk(),
        $this->getJson($base.'/work')->assertOk(),
        $this->getJson($base.'/work/jobs/'.$job->id)->assertOk(),
        $this->getJson($base.'/field-service?view=list&filter=active')->assertOk(),
        $this->getJson($base.'/field-service/jobs/'.$job->id)->assertOk(),
        $this->getJson($base.'/search?q=FINANCIAL%20PRIVATE')->assertOk(),
        $this->getJson($base.'/modules/reporting')->assertOk(),
        $this->getJson($base.'/modules/documents')->assertOk(),
    ];
    $this->getJson($base.'/field-service/job-drafts')->assertForbidden();
    $this->getJson($base.'/field-service/work-candidates')->assertForbidden();

    foreach ($responses as $response) {
        $encoded = json_encode($response->json(), JSON_THROW_ON_ERROR);
        expect($encoded)->not->toContain(
            'INV-SECRET-7788',
            'FINANCIAL PRIVATE NOTE MUST NEVER LEAK',
            'finance-contract-secret',
            '9876.54',
            '8765.43',
            '$9,876.54',
            '$8,765.43',
            'receivable',
        );
    }
})->with(['member', 'manager']);

test('mobile bootstrap uses the canonical Collins light and dark brand presentation', function (): void {
    [$tenant, $owner] = usabilityWorkspace();
    $tenant->forceFill(['name' => 'Collins Upstate Electric', 'slug' => 'collins-electric'])->save();

    Sanctum::actingAs($owner, ['mobile:read']);
    $this->getJson('/api/mobile/v1/workspaces/collins-electric/bootstrap')
        ->assertOk()
        ->assertJsonPath('branding.display_name', 'Collins Upstate Electric')
        ->assertJsonPath('branding.tagline', 'Residential · Commercial · Reliable Power')
        ->assertJsonPath('branding.theme_key', 'collins-upstate-electric')
        ->assertJsonPath('branding.primary_color', '#061D42')
        ->assertJsonPath('branding.accent_color', '#1464E8')
        ->assertJson(fn ($json) => $json
            ->whereType('branding.light_logo_url', 'string')
            ->whereType('branding.dark_logo_url', 'string')
            ->where('branding.can_manage', true)
            ->etc());
});

test('job drafts are owner only editable archivable restorable and publish without accounting fields', function (): void {
    [$tenant, $owner, $member] = usabilityWorkspace();
    $customer = \App\Models\MarketingProfile::query()->create([
        'tenant_id' => $tenant->id, 'first_name' => 'Jordan', 'last_name' => 'Customer',
        'email' => 'jordan@example.com', 'phone' => '864-555-0101', 'address_line_1' => '8 Billing Lane', 'city' => 'Greenville', 'state' => 'SC',
    ]);
    FieldServiceFinancialDocument::query()->create([
        'tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'source' => 'quickbooks',
        'document_type' => 'invoice', 'external_id' => 'draft-1', 'document_number' => '981', 'status' => 'open',
        'total_amount' => 3000, 'balance' => 1000, 'private_note' => 'Accounting only', 'customer_memo' => 'Install service disconnect.',
        'metadata' => ['quickbooks' => ['service_address' => ['line_1' => '22 Job Site Road', 'line_2' => null, 'city' => 'Greer', 'state' => 'SC', 'postal_code' => '29650', 'country' => 'US']]],
    ]);

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/job-drafts')->assertForbidden();

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $response = $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/job-drafts')
        ->assertOk()->assertJsonPath('contract_version', 7)->assertJsonPath('job_drafts.0.address.line_1', '22 Job Site Road');
    expect(json_encode($response->json()))->not->toContain('invoice', '981', '3000', '1000', 'Accounting only', 'source_type');
    $draftId = (int) $response->json('job_drafts.0.id');
    $this->patchJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/job-drafts/'.$draftId, [
        'title' => 'Main service upgrade', 'project_manager_name' => 'Alex General', 'project_manager_phone' => '+18645550222',
        'assigned_user_id' => $member->id, 'participant_user_ids' => [$member->id],
    ])->assertOk()->assertJsonPath('job_draft.title', 'Main service upgrade');
    $this->deleteJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/job-drafts/'.$draftId)->assertOk()->assertJsonPath('status', 'archived');
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/job-drafts?status=archived')->assertOk()->assertJsonPath('job_drafts.0.id', $draftId);
    $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/job-drafts/'.$draftId.'/restore')->assertOk();
    $jobId = $this->postJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/job-drafts/'.$draftId.'/publish')
        ->assertCreated()->json('destination.id');
    $job = FieldServiceJob::query()->findOrFail($jobId);
    expect($job->title)->toBe('Main service upgrade')->and($job->service_address_line_1)->toBe('22 Job Site Road')
        ->and($job->project_manager_name)->toBe('Alex General')->and($job->participants()->whereKey($member->id)->exists())->toBeTrue();
});

test('my day exposes cash home metrics only to financial owners and keeps all assigned tasks available', function (): void {
    [$tenant, $owner, $member] = usabilityWorkspace();
    $now = now();
    $connection = IntegrationConnection::query()->create([
        'tenant_id' => $tenant->id,
        'provider' => 'quickbooks',
        'external_account_id' => 'home-metrics-fixture',
        'status' => IntegrationConnection::STATUS_DISCONNECTED,
    ]);
    QuickBooksReportingSnapshot::query()->create([
        'tenant_id' => $tenant->id,
        'integration_connection_id' => $connection->id,
        'range_key' => 'home:cash:month',
        'period_start' => $now->copy()->startOfMonth()->toDateString(),
        'period_end' => $now->toDateString(),
        'metrics' => ['accounting_method' => 'Cash', 'total_income' => 12500.25, 'total_expenses' => 4300.75],
        'observed_at' => now(),
    ]);
    FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $member->id,
        'title' => 'Completed service call',
        'status' => 'complete',
        'operational_status' => 'complete',
        'completed_at' => now(),
    ]);

    Sanctum::actingAs($owner, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/my-day?period=month')
        ->assertOk()
        ->assertJsonPath('owner_metrics.money_in', 12500.25)
        ->assertJsonPath('owner_metrics.money_spent', 4300.75)
        ->assertJsonPath('owner_metrics.finished_jobs', 1);

    Sanctum::actingAs($member, ['mobile:read', 'mobile:write']);
    $this->getJson('/api/mobile/v1/workspaces/'.$tenant->slug.'/field-service/my-day?period=month')
        ->assertOk()->assertJsonPath('owner_metrics', null);
});
