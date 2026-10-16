<?php

use App\Models\AiBranchAccount;
use App\Models\CustomerLoopAction;
use App\Models\CustomerLoopActivity;
use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTaskEvent;
use App\Models\IntegrationConnection;
use App\Models\MarketingProfile;
use App\Models\ScheduledClass;
use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
use App\Models\TenantBillingSubscription;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\AiBranch\AiAttentionBriefing;
use App\Services\AiBranch\AiWorkspaceContext;
use App\Services\AiBranch\AiWorkspacePage;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;

function workspaceFixture(string $slug = 'workspace-pilot'): array
{
    $tenant = Tenant::create(['name' => 'Fictional '.ucfirst($slug), 'slug' => $slug]);
    $user = User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'owner', 'membership_active' => true]);
    TenantAccessProfile::create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test',
        'metadata' => ['tenant_blueprint' => ['business_template' => 'field_service'], 'ai_branch' => ['timezone' => 'America/New_York']]]);
    TenantAccessAddon::create(['tenant_id' => $tenant->id, 'addon_key' => 'ai_branch_growth', 'enabled' => true, 'source' => 'stripe_fulfillment']);
    TenantBillingSubscription::create(['tenant_id' => $tenant->id, 'provider' => 'stripe', 'provider_subscription_reference' => 'sub_'.$slug,
        'purchase_key' => 'addon.ai_branch_growth', 'status' => 'active', 'current_period_ends_at' => now()->addMonths(2)]);
    AiBranchAccount::create(['tenant_id' => $tenant->id, 'enabled' => true, 'budget_cents' => 1500]);
    foreach (['class_scheduling', 'integrations', 'quickbooks'] as $module) {
        TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => $module, 'enabled_status' => 'enabled', 'billing_status' => 'included']);
    }
    config()->set('ai_branch.tenant_ids', [...config('ai_branch.tenant_ids', []), $tenant->id]);
    config()->set('ai_branch.workspace_context_tenant_ids', [...config('ai_branch.workspace_context_tenant_ids', []), $tenant->id]);

    return [$tenant, $user];
}

beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set(['ai_branch.enabled' => true, 'ai_branch.workspace_context_enabled' => true,
        'ai_branch.api_key' => 'fake', 'ai_branch.model' => 'test-model', 'ai_branch.input_micros_per_million' => 1000000,
        'ai_branch.output_micros_per_million' => 4000000]);
});

test('workspace context identifies the current workspace without serializing protected fields', function () {
    [$tenant, $user] = workspaceFixture();
    [$other] = workspaceFixture('other');
    $customer = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional', 'last_name' => 'Customer', 'email' => 'PRIVATE-CUSTOMER@example.test', 'phone' => 'PRIVATE-PHONE']);
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'title' => 'Replace entry light', 'status' => 'blocked', 'lock_box_code' => 'LOCK-SECRET', 'description' => 'PRIVATE-DESCRIPTION']);
    FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'Order fixture', 'status' => 'open', 'due_at' => now()->subDay(), 'description' => 'PRIVATE-TASK']);
    FieldServiceJob::create(['tenant_id' => $other->id, 'title' => 'OTHER-TENANT', 'status' => 'open']);
    CustomerLoopAction::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'title' => 'Call back', 'action_type' => 'follow_up', 'reason' => 'Fictional follow-up', 'status' => 'suggested', 'draft_body' => 'PRIVATE-MESSAGE']);
    IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'status' => 'error', 'access_token' => 'PROVIDER-SECRET', 'last_error_message' => 'PRIVATE-ERROR']);
    $snapshot = app(AiWorkspaceContext::class)->capture($tenant, $user, [], 'briefing');
    expect($snapshot['version'])->toBe(2)->and($snapshot['workspace']['name'])->toBe($tenant->name)->and($snapshot['workspace']['role'])->toBe('owner')
        ->and($snapshot['workspace']['timezone'])->toBe('America/New_York')->and(collect($snapshot['sources'])->pluck('type')->all())->toContain('job', 'task', 'followup', 'customer', 'connection');
    $json = json_encode($snapshot);
    foreach (['PRIVATE-CUSTOMER', 'PRIVATE-PHONE', 'LOCK-SECRET', 'PRIVATE-DESCRIPTION', 'PRIVATE-TASK', 'OTHER-TENANT', 'PRIVATE-MESSAGE', 'PROVIDER-SECRET', 'PRIVATE-ERROR'] as $secret) {
        expect($json)->not->toContain($secret);
    }
    Http::assertNothingSent();
});

test('page descriptors reject arbitrary details and other tenant records', function () {
    [$tenant, $user] = workspaceFixture();
    [$other] = workspaceFixture('other');
    $job = FieldServiceJob::create(['tenant_id' => $other->id, 'title' => 'Other', 'status' => 'open']);
    $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
    app(AiWorkspacePage::class)->resolve($tenant, $user, ['kind' => 'job', 'id' => $job->id]);
});

test('origin validation never treats a URL or title as a record identity', function () {
    expect(fn () => app(AiWorkspacePage::class)->normalize(['kind' => 'job', 'id' => 1, 'url' => 'https://example.test', 'title' => 'forged']))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
    expect(fn () => app(AiWorkspacePage::class)->normalize(['kind' => 'website_order', 'id' => 1]))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

test('archived recycled completed and snoozed records do not enter the sample', function () {
    [$tenant, $user] = workspaceFixture();
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'ARCHIVED', 'status' => 'open', 'archived_at' => now()]);
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'RECYCLED', 'status' => 'open', 'metadata' => ['recycled_at' => now()->toIso8601String()]]);
    CustomerLoopAction::create(['tenant_id' => $tenant->id, 'title' => 'COMPLETED', 'status' => 'completed', 'action_type' => 'follow_up', 'reason' => 'Fictional follow-up']);
    CustomerLoopAction::create(['tenant_id' => $tenant->id, 'title' => 'SNOOZED', 'status' => 'snoozed', 'action_type' => 'follow_up', 'reason' => 'Fictional follow-up', 'snoozed_until' => now()->addDay()]);
    $snapshot = app(AiWorkspaceContext::class)->capture($tenant, $user);
    expect($snapshot['coverage']['jobs']['status'])->toBe('empty')->and($snapshot['coverage']['followups']['status'])->toBe('empty');
    foreach (['ARCHIVED', 'RECYCLED', 'COMPLETED', 'SNOOZED'] as $text) {
        expect(json_encode($snapshot))->not->toContain($text);
    }
});

test('record limits and selected page evidence are enforced before generation', function () {
    [$tenant, $user] = workspaceFixture();
    for ($i = 0; $i < 9; $i++) {
        $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Job '.$i, 'status' => 'blocked']);
        FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'Task '.$i, 'status' => 'open', 'due_at' => now()->subDay()]);
        CustomerLoopAction::create(['tenant_id' => $tenant->id, 'title' => 'Follow up '.$i, 'status' => 'suggested', 'action_type' => 'follow_up', 'reason' => 'Fictional follow-up', 'due_at' => now()->subDay()]);
    }
    $snapshot = app(AiWorkspaceContext::class)->capture($tenant, $user, ['kind' => 'job', 'id' => $job->id], 'briefing');
    expect(count($snapshot['sources']))->toBeLessThanOrEqual(20)->and($snapshot['sources'][0]['ref'])->toBe('job:'.$job->id);
    foreach (collect($snapshot['sources'])->groupBy('type') as $group) {
        expect($group->count())->toBeLessThanOrEqual(5);
    }
    expect($snapshot['coverage']['jobs']['truncated'])->toBeTrue();
});

test('attention ordering honors workspace midnight and seven day bounds', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-10-16T01:00:00Z'));
    $source = fn ($type, $id, $status, $due) => ['type' => $type, 'id' => $id, 'ref' => $type.':'.$id, 'status' => $status, 'due_at' => $due];
    $rows = [
        $source('connection', 1, 'error', null), $source('job', 2, 'scheduled', '2026-10-17T12:00:00Z'),
        $source('class', 3, 'published', '2026-10-16T02:00:00Z'), $source('job', 4, 'blocked', null),
        $source('followup', 5, 'suggested', '2026-10-14T10:00:00Z'), $source('task', 6, 'open', '2026-10-15T10:00:00Z'),
        $source('class', 7, 'published', '2026-11-20T10:00:00Z'),
    ];
    $result = app(AiAttentionBriefing::class)->candidates($rows, 'America/New_York');
    expect(array_column($result, 'id'))->toBe([5, 6, 4, 3, 2, 1]);
});

test('the context flag and tenant allowlist are independent of paid AI access', function () {
    [$tenant, $user] = workspaceFixture();
    config()->set('ai_branch.workspace_context_tenant_ids', []);
    expect(fn () => app(AiWorkspaceContext::class)->capture($tenant, $user))->toThrow(HttpException::class);
    config()->set('ai_branch.workspace_context_tenant_ids', [$tenant->id]);
    config()->set('ai_branch.workspace_context_enabled', false);
    expect(fn () => app(AiWorkspaceContext::class)->capture($tenant, $user))->toThrow(HttpException::class);
});

