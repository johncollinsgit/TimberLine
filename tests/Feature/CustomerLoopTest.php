<?php

use App\Models\CustomerLoopAction;
use App\Models\CustomerLoopActivity;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Automation\V2\WorkflowNativeActionService;
use App\Services\Bud\TenantBudService;
use Illuminate\Support\Str;

/** @return array{Tenant,User} */
function customerLoopTenant(string $slug): array
{
    $tenant = Tenant::query()->create([
        'name' => Str::headline($slug),
        'slug' => $slug,
    ]);
    $user = User::factory()->create(['role' => 'admin', 'email_verified_at' => now()]);
    $user->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);

    return [$tenant, $user];
}

test('Customer Loop creates a tenant-scoped review-only draft and never sends it', function (): void {
    [$tenant, $user] = customerLoopTenant('customer-loop-'.Str::lower((string) Str::ulid()));
    $profile = MarketingProfile::factory()->create(['tenant_id' => $tenant->id]);

    $this->actingAs($user)
        ->withSession(['tenant_id' => $tenant->id])
        ->post(route('customer-loop.store'), [
            'template' => 'text_draft',
            'title' => 'Check in after a completed visit',
            'marketing_profile_id' => $profile->id,
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Customer Loop action created. It is a draft only until a person reviews it.');

    $action = CustomerLoopAction::query()->forAllTenants()->sole();
    expect($action->tenant_id)->toBe((int) $tenant->id)
        ->and($action->marketing_profile_id)->toBe((int) $profile->id)
        ->and($action->status)->toBe(CustomerLoopAction::STATUS_SUGGESTED)
        ->and($action->safe_context['draft_only'] ?? false)->toBeTrue()
        ->and(CustomerLoopActivity::query()->forAllTenants()->count())->toBe(1);
});

test('Workflow Studio can prepare one idempotent Customer Loop draft without dispatching a message', function (): void {
    [$tenant, $user] = customerLoopTenant('customer-loop-workflow-'.Str::lower((string) Str::ulid()));
    $service = app(WorkflowNativeActionService::class);
    $payload = [
        'template' => 'follow_up',
        'title' => 'Review the next step after this service visit',
        'summary' => 'Prepared by an if/then workflow.',
    ];

    $first = $service->prepareCustomerLoopDraft((int) $tenant->id, $user, $payload, 'customer-loop-workflow-key');
    $repeat = $service->prepareCustomerLoopDraft((int) $tenant->id, $user, $payload, 'customer-loop-workflow-key');
    $preview = $service->prepareCustomerLoopDraft((int) $tenant->id, $user, $payload, 'customer-loop-preview-key', true);

    expect($first['draft_only'])->toBeTrue()
        ->and($first['status'])->toBe(CustomerLoopAction::STATUS_SUGGESTED)
        ->and($repeat['customer_loop_action_id'])->toBe($first['customer_loop_action_id'])
        ->and($preview['dry_run'])->toBeTrue()
        ->and(CustomerLoopAction::query()->forAllTenants()->count())->toBe(1)
        ->and(CustomerLoopActivity::query()->forAllTenants()->count())->toBe(1);
});

test('Customer Loop actions are not accessible from another workspace', function (): void {
    [$tenant, $user] = customerLoopTenant('customer-loop-owner-'.Str::lower((string) Str::ulid()));
    [$otherTenant, $otherUser] = customerLoopTenant('customer-loop-other-'.Str::lower((string) Str::ulid()));
    $action = CustomerLoopAction::query()->forAllTenants()->create([
        'tenant_id' => $tenant->id,
        'action_type' => 'follow_up',
        'status' => CustomerLoopAction::STATUS_SUGGESTED,
        'title' => 'Private follow-up',
        'reason' => 'Tenant isolation test.',
        'safe_context' => ['draft_only' => true],
    ]);

    $this->actingAs($otherUser)
        ->withSession(['tenant_id' => $otherTenant->id])
        ->post(route('customer-loop.prepare', $action))
        ->assertNotFound();
});

test('Bud AI requires an explicit workspace request and an operator-set cap', function (): void {
    [$tenant, $user] = customerLoopTenant('bud-ai-'.Str::lower((string) Str::ulid()));
    $service = app(TenantBudService::class);

    $requested = $service->requestAi($tenant, $user);
    expect($requested->ai_status)->toBe('pending');

    $approved = $service->reviewAi($requested, $user, true, 2500, 'Paid pilot with a hard monthly cap.');

    expect($approved->ai_status)->toBe('approved')
        ->and($approved->ai_monthly_budget_cents)->toBe(2500)
        ->and($approved->ai_used_cents)->toBe(0)
        ->and($approved->ai_period_started_at)->not->toBeNull();
});
