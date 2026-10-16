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

test('weekly snapshots reconcile more than chat sample limits and remain encrypted and idempotent', function () {
    [$tenant, $user] = workspaceFixture('fictional-weekly');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id], 'ai_branch.assistant_reports_enabled' => true]);
    \Illuminate\Support\Facades\Storage::fake('local');
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Fictional weekly work', 'status' => 'open']);
    $task = FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'Fictional task', 'status' => 'open']);
    for ($index = 0; $index < 30; $index++) {
        $event = FieldServiceTaskEvent::create(['tenant_id' => $tenant->id, 'field_service_task_id' => $task->id, 'event_type' => 'updated', 'note' => 'PRIVATE NOTE']);
        $event->forceFill(['created_at' => \Carbon\CarbonImmutable::parse('2026-11-02 12:00:00', 'America/New_York')->utc()])->save();
    }
    $uuid = (string) \Illuminate\Support\Str::uuid();
    $reports = app(\App\Services\AiBranch\AiWeeklyReport::class);
    $report = $reports->capture($tenant, $user, '2026-11-01', 'executive', [], $uuid);
    expect($report['count'])->toBe(30)->and($report['coverage']['task_events']['count'])->toBe(30)
        ->and($report['coverage_complete'])->toBeFalse()->and(json_encode($report))->not->toContain('PRIVATE NOTE');
    expect(\Carbon\CarbonImmutable::parse($report['starts_at'])->diffInHours(\Carbon\CarbonImmutable::parse($report['ends_before'])))->toBe(169.0);
    expect($reports->capture($tenant, $user, '2026-11-01', 'executive', [], $uuid))->toBe($report);
    $stored = \Illuminate\Support\Facades\Storage::disk('local')->get('ai-weekly-reports/'.$tenant->id.'/'.$user->id.'/'.$uuid.'.enc');
    expect($stored)->not->toContain('Fictional task');
    $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']);
    expect(fn () => $reports->read($tenant, $user, $uuid))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

test('team reports exclude private-note existence and recheck changed source visibility', function () {
    [$tenant, $user] = workspaceFixture('fictional-team-weekly');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id], 'ai_branch.assistant_reports_enabled' => true]);
    \Illuminate\Support\Facades\Storage::fake('local');
    $member = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $member->tenants()->attach($tenant->id, ['role' => 'member', 'membership_active' => true]);
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Fictional shared work', 'status' => 'open']);
    \App\Models\FieldServiceJobNote::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'body' => 'OWNER SECRET', 'metadata' => ['visibility' => 'owner']]);
    $shared = \App\Models\FieldServiceJobNote::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'body' => 'Shared note']);
    $week = \Carbon\CarbonImmutable::now('America/New_York')->startOfWeek()->toDateString();
    $uuid = (string) \Illuminate\Support\Str::uuid();
    $reports = app(\App\Services\AiBranch\AiWeeklyReport::class);
    $report = $reports->capture($tenant, $user, $week, 'team', [$member->id], $uuid);
    expect($report['count'])->toBe(1)->and($report['coverage']['job_notes']['count'])->toBe(1)
        ->and(json_encode($report))->not->toContain('OWNER SECRET', 'Shared note');
    $shared->update(['metadata' => ['visibility' => 'owner']]);
    expect(fn () => $reports->read($tenant, $user, $uuid))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

test('native assistant origin binds the selected permitted job and rejects another workspace record', function () {
    [$tenant, $user] = workspaceFixture('fictional-origin');
    [$other] = workspaceFixture('fictional-other-origin');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id]]);
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Fictional selected job', 'status' => 'open']);
    $foreign = FieldServiceJob::create(['tenant_id' => $other->id, 'title' => 'OTHER TENANT JOB', 'status' => 'open']);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant';
    $this->getJson($base.'?'.http_build_query(['origin' => ['kind' => 'job', 'id' => $job->id]]))->assertOk()->assertJsonPath('context.page.id', $job->id)->assertJsonPath('context.page.title', 'Fictional selected job');
    $this->getJson($base.'?'.http_build_query(['origin' => ['kind' => 'job', 'id' => $foreign->id]]))->assertNotFound();
    Http::assertNothingSent();
});

test('weekly report retention deletes only expired verified private snapshots even with runtime disabled', function () {
    [$tenant, $user] = workspaceFixture('fictional-report-retention');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id], 'ai_branch.assistant_reports_enabled' => true]);
    \Illuminate\Support\Facades\Storage::fake('local');
    $reports = app(\App\Services\AiBranch\AiWeeklyReport::class);
    $uuid = (string) \Illuminate\Support\Str::uuid();
    $reports->capture($tenant, $user, now()->startOfWeek()->toDateString(), 'executive', [], $uuid);
    $this->travel(31)->days();
    config()->set('ai_branch.assistant_enabled', false);
    expect($reports->prune(false)['expired'])->toBe(1);
    expect(\Illuminate\Support\Facades\Storage::disk('local')->allFiles('ai-weekly-reports'))->toHaveCount(1);
    expect($reports->prune(true)['expired'])->toBe(1);
    expect(\Illuminate\Support\Facades\Storage::disk('local')->allFiles('ai-weekly-reports'))->toHaveCount(0);
});

test('weekly JSON and print exports recheck ownership scope and changed audience', function () {
    [$tenant, $user] = workspaceFixture('fictional-report-routes');
    [$foreign, $foreignUser] = workspaceFixture('fictional-foreign-report');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id, $foreign->id], 'ai_branch.assistant_reports_enabled' => true]);
    \Illuminate\Support\Facades\Storage::fake('local');
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => '<script>private report</script>', 'status' => 'open']);
    \App\Models\FieldServiceJobNote::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'body' => 'Private note body']);
    $uuid = (string) \Illuminate\Support\Str::uuid();
    $this->actingAs($user)->postJson(route('ai-branch.assistant.reports.store', ['tenant' => $tenant->slug]), ['request_id' => $uuid, 'audience' => 'executive'])->assertCreated()->assertJsonPath('count', 1);
    $url = route('ai-branch.assistant.reports.print', ['tenant' => $tenant->slug, 'report' => $uuid]);
    $this->get($url)->assertOk()->assertSee('&lt;script&gt;private report&lt;/script&gt;', false)->assertDontSee('<script>private report</script>', false)->assertDontSee('Private note body');
    $this->actingAs($foreignUser)->get(route('ai-branch.assistant.reports.print', ['tenant' => $foreign->slug, 'report' => $uuid]))->assertNotFound();
    $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']);
    $this->actingAs($user)->get($url)->assertForbidden();
    Http::assertNothingSent();
});

function sharedAttentionFixture(): array
{
    [$tenant, $user] = workspaceFixture('fictional-attention');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id], 'ai_branch.assistant_attention_enabled' => true]);
    $action = CustomerLoopAction::create(['tenant_id' => $tenant->id, 'title' => 'Fictional follow-up', 'action_type' => 'follow_up', 'reason' => 'Review', 'draft_body' => 'PRIVATE DRAFT', 'status' => 'suggested']);

    return [$tenant, $user, $action, app(\App\Services\AiBranch\AiSharedAttention::class)];
}

test('shared attention claims use exact versions and one encrypted receipt without sending a draft', function () {
    [$tenant, $user, $action, $service] = sharedAttentionFixture();
    $row = $service->listing($tenant, $user, 'open')['items'][0];
    expect(json_encode($row))->not->toContain('PRIVATE DRAFT');
    $uuid = (string) \Illuminate\Support\Str::uuid();
    $claim = $service->transition($tenant, $user, $action->id, 'claim', $row['version'], $uuid);
    expect($claim['item']['claim']['user_id'])->toBe($user->id)
        ->and($service->transition($tenant, $user, $action->id, 'claim', $row['version'], $uuid)['receipt_id'])->toBe($claim['receipt_id']);
    expect(fn () => $service->transition($tenant, $user, $action->id, 'resolve', $row['version'], (string) \Illuminate\Support\Str::uuid(), ['resolution' => 'Finished']))->toThrow(HttpException::class);
    $resolved = $service->transition($tenant, $user, $action->id, 'resolve', $claim['item']['version'], (string) \Illuminate\Support\Str::uuid(), ['resolution' => 'PRIVATE RESOLUTION']);
    expect($resolved['item']['status'])->toBe('completed')->and($service->listing($tenant, $user, 'open')['items'])->toBe([])
        ->and($service->listing($tenant, $user, 'resolved')['items'])->toHaveCount(1);
    expect(\Illuminate\Support\Facades\DB::table('customer_loop_actions')->value('safe_context'))->not->toContain('PRIVATE RESOLUTION');
    expect(\Illuminate\Support\Facades\DB::table('ai_branch_actions')->pluck('payload')->implode(' '))->not->toContain('PRIVATE RESOLUTION');
    Http::assertNothingSent();
});

test('concurrent attention owners cannot overwrite another claim and expired leases require a fresh claim', function () {
    [$tenant, $user, $action, $service] = sharedAttentionFixture();
    $other = User::factory()->tenantAdmin()->create();
    $other->tenants()->attach($tenant->id, ['role' => 'admin', 'membership_active' => true]);
    $version = $service->listing($tenant, $user, 'open')['items'][0]['version'];
    $claim = $service->transition($tenant, $user, $action->id, 'claim', $version, (string) \Illuminate\Support\Str::uuid());
    expect(fn () => $service->transition($tenant, $other, $action->id, 'claim', $version, (string) \Illuminate\Support\Str::uuid()))->toThrow(HttpException::class);
    expect(fn () => $service->transition($tenant, $other, $action->id, 'claim', $claim['item']['version'], (string) \Illuminate\Support\Str::uuid()))->toThrow(HttpException::class);
    $this->travel(6)->minutes();
    expect(fn () => $service->transition($tenant, $user, $action->id, 'resolve', $claim['item']['version'], (string) \Illuminate\Support\Str::uuid(), ['resolution' => 'Late']))->toThrow(HttpException::class);
    $new = $service->transition($tenant, $other, $action->id, 'claim', $claim['item']['version'], (string) \Illuminate\Support\Str::uuid());
    expect($new['item']['claim']['user_id'])->toBe($other->id);
});

test('attention assignment snooze reopen and legacy changes retain authoritative state', function () {
    [$tenant, $user, $action, $service] = sharedAttentionFixture();
    $operate = fn ($operation, $options = []) => $service->transition($tenant, $user, $action->id, $operation, $service->version($action->fresh()), (string) \Illuminate\Support\Str::uuid(), $options);
    $operate('claim');
    $operate('assign', ['assignee_id' => $user->id]);
    expect($action->fresh()->assigned_to_user_id)->toBe($user->id);
    $operate('claim');
    $operate('snooze', ['until' => now()->addDay()->toIso8601String()]);
    expect($service->listing($tenant, $user, 'open')['items'])->toBe([])->and($service->listing($tenant, $user, 'snoozed')['items'])->toHaveCount(1);
    $operate('claim');
    $operate('resolve', ['resolution' => 'Fictional review done']);
    $operate('claim');
    $operate('reopen');
    expect($action->fresh()->status)->toBe('suggested')->and($action->fresh()->completed_at)->toBeNull();
    $claim = $operate('claim');
    $action->fresh()->update(['title' => 'Changed from the existing Customer Loop screen']);
    expect(fn () => $service->transition($tenant, $user, $action->id, 'resolve', $claim['item']['version'], (string) \Illuminate\Support\Str::uuid(), ['resolution' => 'Stale']))->toThrow(HttpException::class);
    Http::assertNothingSent();
});

test('attention API rejects missing grants foreign sources and uncertain repeat identity', function () {
    [$tenant, $user, $action, $service] = sharedAttentionFixture();
    [$foreign] = workspaceFixture('foreign-attention');
    $otherAction = CustomerLoopAction::create(['tenant_id' => $foreign->id, 'title' => 'FOREIGN FOLLOWUP', 'action_type' => 'follow_up', 'reason' => 'Review', 'status' => 'suggested']);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read', 'mobile:write']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant/attention';
    $row = $this->getJson($base)->assertOk()->assertDontSee('PRIVATE DRAFT')->assertDontSee('FOREIGN FOLLOWUP')->json('items.0');
    $payload = ['operation' => 'claim', 'version' => $row['version'], 'request_id' => (string) \Illuminate\Support\Str::uuid(), 'confirmed' => true];
    $this->postJson($base.'/'.$otherAction->id, $payload)->assertNotFound();
    $this->postJson($base.'/'.$action->id, $payload)->assertOk();
    $this->postJson($base.'/'.$action->id, [...$payload, 'operation' => 'release'])->assertConflict();
    $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'member']);
    $this->getJson($base)->assertForbidden();
    $this->postJson($base.'/'.$action->id, $payload)->assertForbidden();
    Http::assertNothingSent();
});

test('phone practice is fictional scoped and unavailable in production regardless of its flag', function (string $scenario) {
    [$tenant, $user] = workspaceFixture('fictional-phone');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id], 'ai_branch.assistant_phone_practice_enabled' => true]);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read']);
    $url = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant/phone/practice';
    $this->postJson($url, ['scenario' => $scenario, 'request_id' => (string) \Illuminate\Support\Str::uuid()])->assertOk()->assertJsonPath('simulated', true)
        ->assertJsonPath('call.tenant_id', $tenant->id)->assertJsonPath('call.simulated', true)->assertJsonPath('provider_capabilities.live_dispatch', false);
    expect(\App\Models\TenantAiUsageEvent::count())->toBe(0)->and(\App\Models\AiBranchAction::count())->toBe(0);
    $this->app->detectEnvironment(fn () => 'production');
    try {
        $this->postJson($url, ['scenario' => 'open_hours', 'request_id' => (string) \Illuminate\Support\Str::uuid()])->assertNotFound();
    } finally {
        $this->app->detectEnvironment(fn () => 'testing');
    }
    Http::assertNothingSent();
})->with(\App\Services\Voice\PhonePractice::SCENARIOS);

test('ordinary assistant reads do not consume the separate phone practice limiter', function () {
    [$tenant, $user] = workspaceFixture('fictional-phone-limit');
    config()->set(['ai_branch.assistant_enabled' => true, 'ai_branch.assistant_tenant_ids' => [$tenant->id], 'ai_branch.assistant_phone_practice_enabled' => true]);
    \Laravel\Sanctum\Sanctum::actingAs($user, ['mobile:read']);
    $base = '/api/mobile/v1/workspaces/'.$tenant->slug.'/ai-assistant';
    foreach (range(1, 12) as $index) {
        $this->getJson($base)->assertOk();
    }
    $this->postJson($base.'/phone/practice', ['scenario' => 'provider_outage', 'request_id' => (string) \Illuminate\Support\Str::uuid()])->assertOk()->assertJsonPath('outcome', 'unconfirmed');
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

function workspaceResponse(string $text = 'Review the permitted sample.'): array
{
    return ['id' => 'fake_response', 'status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => $text]]]], 'usage' => ['input_tokens' => 100, 'output_tokens' => 30]];
}

test('briefings create one encrypted private conversation and reuse its usage reservation', function () {
    [$tenant, $user] = workspaceFixture();
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Blocked fixture', 'status' => 'blocked']);
    Http::fake(['api.openai.com/*' => Http::response(workspaceResponse())]);
    $uuid = (string) Illuminate\Support\Str::uuid();
    $chat = app(App\Services\AiBranch\AiBranchChat::class);
    $first = $chat->briefing($tenant, $user, $uuid);
    expect($chat->briefing($tenant, $user, $uuid))->toBe($first)
        ->and($first['workspace_context']['attention'][0]['title'])->toBe('Blocked fixture')
        ->and(App\Models\AiBranchConversation::count())->toBe(1)->and(App\Models\TenantAiUsageEvent::count())->toBe(1);
    $event = App\Models\TenantAiUsageEvent::sole();
    expect($event->buyer_charge_micros)->toBe(0)->and($event->metadata['context_version'])->toBe(2)->and($event->metadata['purpose'])->toBe('briefing')
        ->and(Illuminate\Support\Facades\DB::table('ai_branch_conversations')->value('messages'))->not->toContain('Blocked fixture');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => str_contains($r['instructions'], 'exact server order') && ! isset($r['tools']) && $r['store'] === false);
});

test('chat and briefing share quotas and rejected reservations create no conversation', function () {
    [$tenant, $user] = workspaceFixture();
    config()->set('module_catalog.addons.ai_branch_growth.ai_limits.responses', 1);
    Http::fake(['api.openai.com/*' => Http::response(workspaceResponse())]);
    $chat = app(App\Services\AiBranch\AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $chat->ask($tenant, $user, $conversation->id, 'Hello', (string) Illuminate\Support\Str::uuid());
    expect(fn () => $chat->briefing($tenant, $user, (string) Illuminate\Support\Str::uuid()))->toThrow(HttpException::class);
    expect(App\Models\AiBranchConversation::count())->toBe(1)->and($chat->usage($tenant)['responses'])->toBe(1);
    Http::assertSentCount(1);
});

test('briefing spending caps and input ceilings reject before dispatch atomically', function (string $limit) {
    [$tenant, $user] = workspaceFixture();
    if ($limit === 'cap') {
        AiBranchAccount::where('tenant_id', $tenant->id)->update(['budget_cents' => 0]);
    } else {
        config()->set('ai_branch.max_input_bytes', 1000);
    }
    expect(fn () => app(App\Services\AiBranch\AiBranchChat::class)->briefing($tenant, $user, (string) Illuminate\Support\Str::uuid()))->toThrow(HttpException::class);
    expect(App\Models\AiBranchConversation::count())->toBe(0)->and(App\Models\TenantAiUsageEvent::count())->toBe(0);
    Http::assertNothingSent();
})->with(['cap', 'input']);

test('uncertain briefing failures retain one reservation and never retry provider dispatch', function () {
    [$tenant, $user] = workspaceFixture();
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('Simulated timeout'));
    $chat = app(App\Services\AiBranch\AiBranchChat::class);
    $uuid = (string) Illuminate\Support\Str::uuid();
    expect(fn () => $chat->briefing($tenant, $user, $uuid))->toThrow(HttpException::class);
    $event = App\Models\TenantAiUsageEvent::sole();
    expect($event->status)->toBe('uncertain')->and($event->provider_cost_micros)->toBeGreaterThan(0);
    expect(fn () => $chat->briefing($tenant, $user, $uuid))->toThrow(HttpException::class);
    expect(App\Models\AiBranchConversation::count())->toBe(1)->and(App\Models\TenantAiUsageEvent::count())->toBe(1);
});

test('forged cross tenant and disallowed page descriptors fail before provider or reservation', function () {
    [$tenant, $user] = workspaceFixture();
    [$other] = workspaceFixture('other');
    $job = FieldServiceJob::create(['tenant_id' => $other->id, 'title' => 'Other', 'status' => 'open']);
    $this->actingAs($user)->postJson(route('ai-branch.briefings', ['tenant' => $tenant->slug]), ['request_id' => (string) Illuminate\Support\Str::uuid(), 'origin' => ['kind' => 'job', 'id' => $job->id]])->assertNotFound();
    $this->postJson(route('ai-branch.briefings', ['tenant' => $tenant->slug]), ['request_id' => (string) Illuminate\Support\Str::uuid(), 'origin' => ['kind' => 'job', 'id' => $job->id, 'tenant_id' => $other->id]])->assertUnprocessable();
    expect(App\Models\AiBranchConversation::count())->toBe(0)->and(App\Models\TenantAiUsageEvent::count())->toBe(0);
    Http::assertNothingSent();
});

test('workspace switching isolates identity capabilities records and personal memory', function () {
    [$tenant, $user] = workspaceFixture();
    [$other] = workspaceFixture('other');
    $user->tenants()->attach($other->id, ['role' => 'admin', 'membership_active' => true]);
    TenantModuleEntitlement::create(['tenant_id' => $other->id, 'module_key' => 'field_service', 'enabled_status' => 'disabled', 'billing_status' => 'included']);
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'FIRST-ONLY', 'status' => 'blocked']);
    App\Models\AiBranchMemory::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'body' => 'FIRST-MEMORY', 'expires_at' => now()->addDay()]);
    App\Models\AiBranchMemory::create(['tenant_id' => $other->id, 'user_id' => $user->id, 'body' => 'SECOND-MEMORY', 'expires_at' => now()->addDay()]);
    Http::fake(['api.openai.com/*' => Http::response(workspaceResponse())]);
    $chat = app(App\Services\AiBranch\AiBranchChat::class);
    $first = $chat->briefing($tenant, $user, (string) Illuminate\Support\Str::uuid());
    $second = $chat->briefing($other, $user, (string) Illuminate\Support\Str::uuid());
    expect($second['workspace_context']['workspace']['name'])->toBe($other->name)
        ->and($second['workspace_context']['workspace']['role'])->toBe('admin')
        ->and($second['workspace_context']['coverage']['jobs']['status'])->toBe('restricted')
        ->and($second['workspace_context']['workspace']['capabilities'])->not->toContain('field_service.jobs');
    $sent = Http::recorded()->map(fn ($pair) => json_encode($pair[0]->data()));
    expect($sent[0])->toContain('FIRST-MEMORY', 'FIRST-ONLY')->not->toContain('SECOND-MEMORY');
    expect($sent[1])->toContain('SECOND-MEMORY')->not->toContain('FIRST-MEMORY', 'FIRST-ONLY');
    expect(fn () => $chat->conversation($other, $user, $first['conversation_id']))->toThrow(Illuminate\Database\Eloquent\ModelNotFoundException::class);
});

test('workspace briefings block staff managers and inactive members', function (string $role, bool $active) {
    [$tenant, $user] = workspaceFixture();
    $user->tenants()->updateExistingPivot($tenant->id, ['role' => $role, 'membership_active' => $active]);
    $this->actingAs($user)->postJson(route('ai-branch.briefings', ['tenant' => $tenant->slug]), ['request_id' => (string) Illuminate\Support\Str::uuid()])->assertForbidden();
    expect(fn () => app(App\Services\AiBranch\AiWorkspaceSources::class)->collect($tenant, $user, ['page' => ['kind' => 'workspace'], 'record' => null], 'briefing'))->toThrow(HttpException::class);
    expect(fn () => app(AiWorkspacePage::class)->resolve($tenant, $user, []))->toThrow(HttpException::class);
    Http::assertNothingSent();
})->with([['staff', true], ['manager', true], ['owner', false]]);

test('permission or record revocation prevents returning an in flight answer', function (string $change) {
    [$tenant, $user] = workspaceFixture();
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Permitted then revoked', 'status' => 'blocked']);
    Http::fake(function () use ($change, $tenant, $user, $job) {
        match ($change) {
            'module' => TenantModuleEntitlement::create(['tenant_id' => $tenant->id, 'module_key' => 'field_service', 'enabled_status' => 'disabled', 'billing_status' => 'included']),
            'membership' => $user->tenants()->updateExistingPivot($tenant->id, ['role' => 'staff']),
            'inactive' => User::whereKey($user->id)->update(['is_active' => false]),
            'record' => $job->update(['archived_at' => now()]),
        };

        return Http::response(workspaceResponse('REVOKED-ANSWER'));
    });
    expect(fn () => app(App\Services\AiBranch\AiBranchChat::class)->briefing($tenant, $user, (string) Illuminate\Support\Str::uuid()))->toThrow(HttpException::class);
    expect(App\Models\TenantAiUsageEvent::sole()->status)->toBe('settled');
    Http::assertSentCount(1);
})->with(['module', 'membership', 'inactive', 'record']);

test('incompatible chats show restart instructions and retain deletion without showing old text', function () {
    [$tenant, $user] = workspaceFixture();
    $conversation = App\Models\AiBranchConversation::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'context_hash' => str_repeat('0', 64), 'messages' => [['role' => 'assistant', 'text' => 'OLD-PRIVATE-TEXT']], 'expires_at' => now()->addDay()]);
    $this->actingAs($user)->get(route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $conversation->id]))->assertOk()->assertSee('Start a new chat')->assertSee('Delete chat '.$conversation->id)->assertDontSee('OLD-PRIVATE-TEXT');
    $this->delete(route('ai-branch.conversations.delete', ['tenant' => $tenant->slug, 'conversation' => $conversation->id]))->assertRedirect();
    expect(App\Models\AiBranchConversation::count())->toBe(0);
    Http::assertNothingSent();
});

test('timezone settings validate preserve profile metadata and default to UTC', function () {
    [$tenant, $user] = workspaceFixture();
    $profile = TenantAccessProfile::where('tenant_id', $tenant->id)->sole();
    $profile->update(['metadata' => ['unrelated' => ['keep' => true]]]);
    expect(app(AiWorkspaceContext::class)->timezone($tenant))->toBe('UTC');
    $payload = ['enabled' => 1, 'budget_cents' => 1500, 'confirmed' => 1, 'timezone' => 'America/Los_Angeles'];
    $this->actingAs($user)->post(route('ai-branch.settings', ['tenant' => $tenant->slug]), $payload)->assertRedirect();
    expect($profile->fresh()->metadata['unrelated'])->toBe(['keep' => true])->and(app(AiWorkspaceContext::class)->timezone($tenant))->toBe('America/Los_Angeles');
    $this->postJson(route('ai-branch.settings', ['tenant' => $tenant->slug]), [...$payload, 'timezone' => 'not/a/zone'])->assertUnprocessable();
    Http::assertNothingSent();
});

test('briefing UI escapes generated text links evidence and GET context never calls provider', function () {
    [$tenant, $user] = workspaceFixture();
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Fixture attention job', 'status' => 'blocked']);
    $url = route('ai-branch.index', ['tenant' => $tenant->slug, 'origin' => ['kind' => 'job', 'id' => $job->id]]);
    $this->actingAs($user)->get($url)->assertOk()->assertSee('Context used')->assertSee('What needs attention?')->assertSee('Opened from: Fixture attention job');
    Http::assertNothingSent();
    Http::fake(['api.openai.com/*' => Http::response(workspaceResponse('<script>alert("unsafe")</script>'))]);
    $uuid = (string) Illuminate\Support\Str::uuid();
    $payload = ['request_id' => $uuid, 'origin' => ['kind' => 'job', 'id' => $job->id]];
    $result = $this->postJson(route('ai-branch.briefings', ['tenant' => $tenant->slug]), $payload)->assertOk()->json();
    $this->post(route('ai-branch.briefings', ['tenant' => $tenant->slug]), $payload)->assertRedirect(route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $result['conversation_id'], 'origin' => $payload['origin']]));
    $page = $this->get(route('ai-branch.index', ['tenant' => $tenant->slug, 'conversation' => $result['conversation_id']]))->assertOk();
    $page->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert', false)->assertSee('America/New_York')->assertSee('Attention evidence, in priority order')->assertSee(route('field-service.jobs.show', ['tenant' => $tenant->slug, 'job' => $job->id], false));
    $this->get(route('field-service.jobs.show', ['tenant' => $tenant->slug, 'job' => $job->id]))->assertOk();
    Http::assertSentCount(1);
});

test('refreshing context uses updated records and memory changes invalidate history', function () {
    [$tenant, $user] = workspaceFixture();
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Before update', 'status' => 'blocked']);
    Http::fake(['api.openai.com/*' => Http::response(workspaceResponse())]);
    $chat = app(App\Services\AiBranch\AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    $chat->ask($tenant, $user, $conversation->id, 'Hello', (string) Illuminate\Support\Str::uuid());
    $job->update(['title' => 'After update']);
    $answer = $chat->ask($tenant, $user, $conversation->id, 'Again', (string) Illuminate\Support\Str::uuid());
    expect($answer['sources'][0]['title'])->toBe('After update');
    App\Models\AiBranchMemory::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'body' => 'New preference', 'expires_at' => now()->addDay()]);
    expect(fn () => $chat->conversation($tenant, $user, $conversation->id))->toThrow(HttpException::class);
    Http::assertSentCount(2);
});

test('Collins restriction applies before every operational adapter', function () {
    [$tenant, $user] = workspaceFixture('collins-electric');
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'RESTRICTED-JOB', 'status' => 'blocked']);
    $context = app(AiWorkspaceContext::class)->capture($tenant, $user);
    expect($context['sources'])->toBe([])->and($context['coverage']['jobs']['status'])->toBe('restricted');
    expect(fn () => app(App\Services\AiBranch\AiWorkspaceSources::class)->jobsQuery($tenant, $user))->toThrow(HttpException::class);
});

test('empty samples and stale missing unsupported sources remain distinguishable', function () {
    [$tenant, $user] = workspaceFixture();
    IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'status' => 'connected', 'last_synced_at' => now()->subDays(2)]);
    $snapshot = app(AiWorkspaceContext::class)->capture($tenant, $user, [], 'briefing');
    expect($snapshot['attention'])->toBe([])->and($snapshot['coverage']['jobs']['status'])->toBe('empty')
        ->and($snapshot['coverage']['connections']['status'])->toBe('stale')->and($snapshot['coverage']['external_calendar']['status'])->toBe('unsupported');
    Illuminate\Support\Facades\Schema::drop('scheduled_classes');
    expect(app(AiWorkspaceContext::class)->capture($tenant, $user)['coverage']['scheduling']['status'])->toBe('missing');
});

test('classes activities and connection evidence never load private provider or record fields into a prompt', function () {
    [$tenant, $user] = workspaceFixture();
    $customer = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Fictional client', 'email' => 'SECRET-CONTACT@example.test']);
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Safe job label', 'status' => 'blocked']);
    $task = FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'Safe task label', 'status' => 'open']);
    FieldServiceTaskEvent::create(['tenant_id' => $tenant->id, 'field_service_task_id' => $task->id, 'event_type' => 'note', 'note' => 'SECRET-NOTE', 'metadata' => ['private' => 'SECRET-METADATA']]);
    CustomerLoopActivity::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'source_type' => 'manual', 'event_key' => 'private', 'title' => 'SECRET-EVENT', 'summary' => 'SECRET-SUMMARY', 'occurred_at' => now()]);
    ScheduledClass::create(['tenant_id' => $tenant->id, 'title' => 'Fictional workshop', 'slug' => 'fictional-class', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2), 'status' => 'published', 'price' => '9887.67', 'location' => 'SECRET-ADDRESS', 'capacity' => 10, 'description' => 'SECRET-CLASS']);
    IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'quickbooks', 'status' => 'error', 'access_token' => 'SECRET-CREDENTIAL', 'last_error_message' => 'SECRET-PROVIDER-ERROR']);
    Http::fake(['api.openai.com/*' => Http::response(workspaceResponse())]);
    $result = app(App\Services\AiBranch\AiBranchChat::class)->briefing($tenant, $user, (string) Illuminate\Support\Str::uuid());
    expect(collect($result['sources'])->pluck('type')->all())->toContain('class', 'task_activity', 'customer_activity');
    Http::assertSent(function ($request) {
        $json = json_encode($request->data());

        return str_contains($json, 'Fictional workshop') && ! str_contains($json, 'SECRET-') && ! str_contains($json, '9887.67');
    });
});

test('metrics record request counts latency usage and outcome without record content', function () {
    [$tenant, $user] = workspaceFixture();
    FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'CONTENT-NOT-FOR-LOGS', 'status' => 'blocked']);
    Http::fake(['api.openai.com/*' => Http::response(workspaceResponse('ANSWER-NOT-FOR-LOGS'))]);
    Illuminate\Support\Facades\Log::spy();
    $uuid = (string) Illuminate\Support\Str::uuid();
    app(App\Services\AiBranch\AiBranchChat::class)->briefing($tenant, $user, $uuid);
    Illuminate\Support\Facades\Log::shouldHaveReceived('info')->withArgs(function ($name, $data) use ($uuid) {
        return $name === 'ai_branch.request' && $data['request_id'] === $uuid && $data['context_version'] === 2
            && $data['adapter_counts']['jobs'] === 1 && $data['latency_ms'] >= 0 && $data['input_tokens'] === 100
            && $data['provider_cost_micros'] === 220 && $data['outcome'] === 'settled'
            && ! str_contains(json_encode($data), 'NOT-FOR-LOGS');
    })->once();
});

test('shared launcher carries only an allowlisted page identity and works in Website sales', function () {
    [$tenant, $user] = workspaceFixture();
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Origin job', 'status' => 'open']);
    $response = $this->actingAs($user)->get(route('field-service.jobs.show', ['tenant' => $tenant->slug, 'job' => $job->id]))->assertOk();
    $response->assertSee('origin%5Bkind%5D=job', false)->assertSee('origin%5Bid%5D='.$job->id, false);
    $profile = TenantAccessProfile::where('tenant_id', $tenant->id)->sole();
    $metadata = $profile->metadata;
    $metadata['workspace_focus'] = 'website_sales';
    $profile->update(['metadata' => $metadata]);
    $request = Illuminate\Http\Request::create('/dashboard');
    $request->attributes->set('current_tenant', $tenant);
    $request->setUserResolver(fn () => $user);
    $navigation = app(App\Services\Navigation\UnifiedAppNavigationService::class)->build($request, $user);
    expect(collect($navigation['items'])->pluck('key')->all())->toContain('ai-branch');
    config()->set('ai_branch.workspace_context_enabled', false);
    expect(app(AiWorkspacePage::class)->launcherUrl($request, $tenant))->not->toContain('origin');
    $this->postJson(route('ai-branch.briefings', ['tenant' => $tenant->slug]), ['request_id' => (string) Illuminate\Support\Str::uuid()])->assertNotFound();
    Http::assertNothingSent();
});

test('the message endpoint accepts authorized page context and rejects a changed UUID replay', function () {
    [$tenant, $user] = workspaceFixture();
    $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'title' => 'Selected job', 'status' => 'open']);
    $chat = app(App\Services\AiBranch\AiBranchChat::class);
    $conversation = $chat->start($tenant, $user);
    Http::fake(['api.openai.com/*' => Http::response(workspaceResponse())]);
    $uuid = (string) Illuminate\Support\Str::uuid();
    $url = route('ai-branch.ask', ['tenant' => $tenant->slug, 'conversation' => $conversation->id]);
    $this->actingAs($user)->postJson($url, ['request_id' => $uuid, 'question' => 'Describe this work', 'origin' => ['kind' => 'job', 'id' => $job->id]])->assertOk()->assertJsonPath('workspace_context.page.title', 'Selected job');
    $this->postJson($url, ['request_id' => $uuid, 'question' => 'Describe this work', 'origin' => ['kind' => 'workspace']])->assertConflict();
    Http::assertSentCount(1);
});

test('combined adapter families obey the twenty record ceiling including activity and classes', function () {
    [$tenant, $user] = workspaceFixture();
    for ($i = 0; $i < 7; $i++) {
        $customer = MarketingProfile::create(['tenant_id' => $tenant->id, 'first_name' => 'Sample '.$i]);
        $job = FieldServiceJob::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'title' => 'Job '.$i, 'status' => 'blocked']);
        $task = FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'title' => 'Task '.$i, 'status' => 'open', 'due_at' => now()->subDay()]);
        FieldServiceTaskEvent::create(['tenant_id' => $tenant->id, 'field_service_task_id' => $task->id, 'event_type' => 'created']);
        CustomerLoopActivity::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'source_type' => 'manual', 'event_key' => 'created:'.$i, 'title' => 'Private raw activity', 'occurred_at' => now()]);
        CustomerLoopAction::create(['tenant_id' => $tenant->id, 'marketing_profile_id' => $customer->id, 'title' => 'Follow up '.$i, 'status' => 'suggested', 'action_type' => 'follow_up', 'reason' => 'Fixture', 'due_at' => now()->subDay()]);
        ScheduledClass::create(['tenant_id' => $tenant->id, 'title' => 'Class '.$i, 'slug' => 'class-'.$i, 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2), 'capacity' => 10, 'status' => 'published']);
    }
    $context = app(AiWorkspaceContext::class)->capture($tenant, $user, [], 'briefing');
    expect($context['sources'])->toHaveCount(20)->and(array_sum(array_column($context['coverage'], 'returned')))->toBe(20);
    foreach ($context['coverage'] as $family) {
        expect($family['returned'])->toBeLessThanOrEqual(5);
    }
    expect(collect($context['coverage'])->contains(fn ($family) => $family['truncated'] ?? false))->toBeTrue();
});

test('workspace daylight savings boundaries do not change UTC billing windows', function () {
    $this->travelTo(\Carbon\CarbonImmutable::parse('2026-11-01T05:30:00Z'));
    [$tenant, $user] = workspaceFixture();
    $class = ScheduledClass::create(['tenant_id' => $tenant->id, 'title' => 'Late local class', 'slug' => 'late-local', 'starts_at' => '2026-11-02 04:30:00', 'ends_at' => '2026-11-02 04:50:00', 'capacity' => 10, 'status' => 'published']);
    $snapshot = app(AiWorkspaceContext::class)->capture($tenant, $user, [], 'briefing');
    expect(collect($snapshot['attention'])->pluck('ref')->all())->toContain('class:'.$class->id)
        ->and(app(AiAttentionBriefing::class)->rank($snapshot['attention'][0], 'America/New_York'))->toBe(2);
    foreach (['2026-10-31 23:59:59', '2026-11-01 00:00:00'] as $time) {
        App\Models\TenantAiUsageEvent::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => (string) Illuminate\Support\Str::uuid(), 'feature' => 'ai_branch_chat', 'context' => 'workspace', 'model' => 'fake', 'status' => 'settled', 'duration_seconds' => 0, 'provider_cost_micros' => 50, 'buyer_charge_micros' => 0, 'occurred_at' => $time]);
    }
    expect(app(App\Services\AiBranch\AiBranchChat::class)->usage($tenant))->toBe(['responses' => 1, 'provider_micros' => 50]);
});
