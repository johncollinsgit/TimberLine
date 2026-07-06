<?php

use App\Models\FieldServiceJob;
use App\Models\FieldServiceTask;
use App\Models\MarketingProfile;
use App\Models\MobileLoginChallenge;
use App\Models\MobilePushDevice;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Models\WorkActivityEvent;
use App\Models\WorkItemComment;
use App\Models\WorkItemWatcher;
use App\Models\WorkNotification;
use App\Models\WorkNotificationDelivery;
use App\Models\WorkNotificationPreference;
use App\Models\WorkPushDevice;
use App\Notifications\EverbranchWorkItemNotification;
use App\Notifications\EverbranchWorkMagicLinkNotification;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    Notification::fake();
});

test('magic link creates a mobile session and auto selects a single tenant', function (): void {
    [$tenant, $user] = everbranchWorkMobileTenantAndUser();

    $token = requestEverbranchWorkMobileToken($user->email);
    Notification::assertSentTo($user, EverbranchWorkMagicLinkNotification::class);

    $response = $this->postJson(route('mobile.work.auth.accept-link'), [
        'token' => $token,
        'device_id' => 'iphone-1',
        'device_name' => 'John iPhone',
        'app_version' => '1.0.0',
    ])
        ->assertOk()
        ->assertJsonPath('ok', true)
        ->assertJsonPath('bootstrap.selected_tenant.id', $tenant->id)
        ->assertJsonPath('bootstrap.requires_tenant_selection', false);

    expect($response->json('access_token'))->toBeString()->not->toBeEmpty()
        ->and(collect($response->json('bootstrap.tabs'))->pluck('key')->all())
        ->toBe(['home', 'jobs', 'team'])
        ->and($response->json('bootstrap.permissions.can_create_jobs'))
        ->toBeTrue()
        ->and($response->json('bootstrap.permissions.can_invite_team'))
        ->toBeTrue()
        ->and(collect($response->json('bootstrap.tabs'))->pluck('key')->all())
        ->not->toContain('customers', 'work', 'tasks', 'settings')
        ->not->toContain('messages');
});

test('expired and consumed magic links are rejected', function (): void {
    [, $user] = everbranchWorkMobileTenantAndUser();

    MobileLoginChallenge::query()->create([
        'user_id' => $user->id,
        'email' => $user->email,
        'token_hash' => hash('sha256', 'expired-token'),
        'expires_at' => now()->subMinute(),
    ]);

    $this->postJson(route('mobile.work.auth.accept-link'), ['token' => 'expired-token'])
        ->assertUnprocessable();

    $token = requestEverbranchWorkMobileToken($user->email);
    $this->postJson(route('mobile.work.auth.accept-link'), ['token' => $token])->assertOk();
    $this->postJson(route('mobile.work.auth.accept-link'), ['token' => $token])->assertUnprocessable();
});

test('multi tenant users receive a tenant picker and cannot select unavailable tenants', function (): void {
    [$firstTenant, $user] = everbranchWorkMobileTenantAndUser('Bright Wire Electric', 'bright-wire-electric');
    [$secondTenant] = everbranchWorkMobileTenantAndUser('Clear Path Plumbing', 'clear-path-plumbing', $user);
    [$otherTenant] = everbranchWorkMobileTenantAndUser('Outside Tenant', 'outside-tenant');

    $token = requestEverbranchWorkMobileToken($user->email);
    $accepted = $this->postJson(route('mobile.work.auth.accept-link'), ['token' => $token])
        ->assertOk()
        ->assertJsonPath('bootstrap.selected_tenant', null)
        ->assertJsonPath('bootstrap.requires_tenant_selection', true);

    $accessToken = (string) $accepted->json('access_token');
    $this->withToken($accessToken)
        ->postJson(route('mobile.work.tenants.select'), ['tenant' => (string) $otherTenant->id])
        ->assertForbidden();

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.tenants.select'), ['tenant' => $secondTenant->slug])
        ->assertOk()
        ->assertJsonPath('bootstrap.selected_tenant.id', $secondTenant->id)
        ->assertJsonPath('bootstrap.requires_tenant_selection', false);

    expect($firstTenant->id)->not->toBe($secondTenant->id);
});

test('field service tabs and endpoints are gated by tenant modules', function (): void {
    [$tenant, $user] = everbranchWorkMobileTenantAndUser('Shopify Starter', 'shopify-starter', null, 'starter', 'shopify');

    $accessToken = acceptEverbranchWorkMobileLogin($user->email);

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.bootstrap'))
        ->assertOk()
        ->assertJsonMissing(['key' => 'work'])
        ->assertJsonMissing(['key' => 'tasks'])
        ->assertJsonPath('permissions.can_manage_jobs', false);

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.jobs'))
        ->assertForbidden();

    expect($tenant->slug)->toBe('shopify-starter');
});

test('work home returns only launch critical assigned due blocked unread and activity data', function (): void {
    [$tenant, $user] = everbranchWorkMobileTenantAndUser();
    $otherUser = User::factory()->tenantAdmin()->create(['email' => 'crew@example.com']);
    $otherUser->tenants()->attach($tenant->id, ['role' => 'member']);

    $mine = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $user->id,
        'title' => 'Wire garage',
        'status' => 'open',
        'scheduled_for' => now()->addDay(),
    ]);
    FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $otherUser->id,
        'title' => 'Not mine',
        'status' => 'open',
    ]);
    FieldServiceTask::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $mine->id,
        'assigned_user_id' => $user->id,
        'title' => 'Pull wire',
        'status' => 'open',
        'due_at' => now()->addDay(),
    ]);
    FieldServiceTask::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $mine->id,
        'assigned_user_id' => $user->id,
        'title' => 'Waiting on panel',
        'status' => 'blocked',
    ]);
    WorkNotification::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'category' => 'direct_notify',
        'title' => 'Check job',
    ]);
    WorkActivityEvent::query()->create([
        'tenant_id' => $tenant->id,
        'item_type' => 'field_service_job',
        'item_id' => $mine->id,
        'event_type' => 'created',
        'title' => 'Job created',
    ]);

    $accessToken = acceptEverbranchWorkMobileLogin($user->email);

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.home'))
        ->assertOk()
        ->assertJsonPath('summary.assigned_jobs', 1)
        ->assertJsonPath('summary.due_soon_tasks', 1)
        ->assertJsonPath('summary.blocked_tasks', 1)
        ->assertJsonPath('summary.unread_notifications', 1)
        ->assertJsonFragment(['title' => 'Wire garage'])
        ->assertJsonFragment(['title' => 'Pull wire'])
        ->assertJsonFragment(['title' => 'Waiting on panel'])
        ->assertJsonFragment(['title' => 'Check job'])
        ->assertJsonFragment(['event_type' => 'created'])
        ->assertJsonMissing(['title' => 'Not mine']);
});

test('customers jobs tasks and team are tenant scoped', function (): void {
    [$tenant, $user] = everbranchWorkMobileTenantAndUser();
    [$otherTenant, $otherUser] = everbranchWorkMobileTenantAndUser('Other Tenant', 'other-tenant');

    MarketingProfile::query()->create([
        'tenant_id' => $otherTenant->id,
        'first_name' => 'Other',
        'last_name' => 'Customer',
        'email' => 'other@example.com',
        'normalized_email' => 'other@example.com',
    ]);

    $accessToken = acceptEverbranchWorkMobileLogin($user->email);

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.jobs.store'), [
            'customer_name' => 'Pat Electric',
            'customer_email' => 'pat@example.com',
            'title' => 'Kitchen outlet repair',
            'assigned_user_id' => $otherUser->id,
            'first_task' => 'Check GFCI',
            'first_material' => '20A breaker',
        ])
        ->assertCreated()
        ->assertJsonPath('job.customer.email', 'pat@example.com')
        ->assertJsonPath('job.assigned_user', null);

    $job = FieldServiceJob::query()->where('tenant_id', $tenant->id)->where('title', 'Kitchen outlet repair')->firstOrFail();
    $task = FieldServiceTask::query()->where('tenant_id', $tenant->id)->where('field_service_job_id', $job->id)->firstOrFail();

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.customers'))
        ->assertOk()
        ->assertJsonFragment(['email' => 'pat@example.com'])
        ->assertJsonMissing(['email' => 'other@example.com']);

    $this->withToken($accessToken)
        ->patchJson(route('mobile.work.tasks.update', ['task' => $task->id]), ['status' => 'done'])
        ->assertOk()
        ->assertJsonPath('task.status', 'done');

    $otherTask = FieldServiceTask::query()->create([
        'tenant_id' => $otherTenant->id,
        'field_service_job_id' => FieldServiceJob::query()->create([
            'tenant_id' => $otherTenant->id,
            'title' => 'Other job',
            'status' => 'open',
        ])->id,
        'title' => 'Other task',
        'status' => 'open',
    ]);

    $this->withToken($accessToken)
        ->patchJson(route('mobile.work.tasks.update', ['task' => $otherTask->id]), ['status' => 'done'])
        ->assertNotFound();

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.team'))
        ->assertOk()
        ->assertJsonFragment(['email' => $user->email])
        ->assertJsonFragment(['endpoint' => '/api/mobile/work/v1/team/'.$user->id.'/notify'])
        ->assertJsonMissing(['email' => $otherUser->email]);
});

test('job filters and v1 role permissions keep creation assignment and invites admin only', function (): void {
    [$tenant, $admin] = everbranchWorkMobileTenantAndUser();
    $member = User::factory()->tenantAdmin()->create(['email' => 'member@example.com']);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);

    $adminToken = acceptEverbranchWorkMobileLogin($admin->email);
    $memberToken = acceptEverbranchWorkMobileLogin($member->email);

    $mine = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $member->id,
        'title' => 'Panel service',
        'customer_name' => 'Avery Home',
        'status' => 'open',
        'scheduled_for' => now()->addDay(),
    ]);
    FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'assigned_user_id' => $admin->id,
        'title' => 'Office lighting',
        'customer_name' => 'Office',
        'status' => 'open',
        'scheduled_for' => now()->addWeek(),
    ]);
    $task = FieldServiceTask::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $mine->id,
        'assigned_user_id' => $member->id,
        'title' => 'Replace panel',
        'status' => 'open',
        'due_at' => now()->addDay(),
    ]);

    $this->withToken($memberToken)
        ->getJson(route('mobile.work.bootstrap'))
        ->assertOk()
        ->assertJsonPath('permissions.can_create_jobs', false)
        ->assertJsonPath('permissions.can_assign_users', false)
        ->assertJsonPath('permissions.can_invite_team', false)
        ->assertJsonPath('permissions.can_update_task_status', true);

    $this->withToken($memberToken)
        ->postJson(route('mobile.work.jobs.store'), [
            'customer_name' => 'Blocked Customer',
            'title' => 'Blocked job',
        ])
        ->assertForbidden();

    $this->withToken($memberToken)
        ->patchJson(route('mobile.work.tasks.update', ['task' => $task->id]), ['status' => 'in_progress'])
        ->assertOk()
        ->assertJsonPath('task.status', 'in_progress');

    $this->withToken($memberToken)
        ->patchJson(route('mobile.work.tasks.update', ['task' => $task->id]), ['assigned_user_id' => $admin->id])
        ->assertForbidden();

    $this->withToken($memberToken)
        ->getJson(route('mobile.work.jobs', ['assigned' => 'me', 'q' => 'panel', 'due' => 'soon']))
        ->assertOk()
        ->assertJsonFragment(['title' => 'Panel service'])
        ->assertJsonMissing(['title' => 'Office lighting']);

    $this->withToken($adminToken)
        ->patchJson(route('mobile.work.jobs.update', ['job' => $mine->id]), [
            'assigned_user_id' => $admin->id,
            'status' => 'scheduled',
        ])
        ->assertOk()
        ->assertJsonPath('job.assigned_user.id', $admin->id)
        ->assertJsonPath('job.status', 'scheduled');
});

test('tenant admins can invite team members with a scoped magic link', function (): void {
    [$tenant, $admin] = everbranchWorkMobileTenantAndUser();
    $member = User::factory()->tenantAdmin()->create(['email' => 'not-admin@example.com']);
    $member->tenants()->attach($tenant->id, ['role' => 'member']);

    $adminToken = acceptEverbranchWorkMobileLogin($admin->email);
    $memberToken = acceptEverbranchWorkMobileLogin($member->email);

    $this->withToken($memberToken)
        ->postJson(route('mobile.work.team.invite'), [
            'email' => 'newtech@example.com',
            'name' => 'New Tech',
        ])
        ->assertForbidden();

    $response = $this->withToken($adminToken)
        ->postJson(route('mobile.work.team.invite'), [
            'email' => 'newtech@example.com',
            'name' => 'New Tech',
            'role' => 'member',
        ])
        ->assertCreated()
        ->assertJsonPath('status', 'invited')
        ->assertJsonPath('user.email', 'newtech@example.com');

    $invited = User::query()->where('email', 'newtech@example.com')->firstOrFail();
    Notification::assertSentTo($invited, EverbranchWorkMagicLinkNotification::class);
    expect($invited->tenants()->whereKey($tenant->id)->exists())->toBeTrue();

    $this->postJson(route('mobile.work.auth.accept-link'), [
        'token' => $response->json('debug.token'),
    ])
        ->assertOk()
        ->assertJsonPath('bootstrap.selected_tenant.id', $tenant->id);
});

test('expo push delivery is attempted for registered work devices', function (): void {
    Http::fake([
        'https://exp.host/*' => Http::response(['data' => ['status' => 'ok', 'id' => 'ticket-1']], 200),
    ]);

    [$tenant, $actor] = everbranchWorkMobileTenantAndUser();
    $target = User::factory()->tenantAdmin()->create(['email' => 'push-target@example.com']);
    $target->tenants()->attach($tenant->id, ['role' => 'member']);
    WorkPushDevice::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $target->id,
        'platform' => 'ios',
        'device_token' => 'ExponentPushToken[abc123]',
        'push_enabled' => true,
    ]);

    $accessToken = acceptEverbranchWorkMobileLogin($actor->email);

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.team.notify', ['target' => $target->id]), [
            'body' => 'Heads up',
        ])
        ->assertCreated();

    Http::assertSent(fn ($request): bool => $request->url() === 'https://exp.host/--/api/v2/push/send'
        && $request['to'] === 'ExponentPushToken[abc123]'
        && $request['body'] === 'Heads up');

    expect(WorkNotificationDelivery::query()
        ->where('tenant_id', $tenant->id)
        ->where('user_id', $target->id)
        ->where('channel', 'push')
        ->where('status', 'sent')
        ->exists())->toBeTrue();
});

test('team notify creates in app email audited notification for tenant users only', function (): void {
    [$tenant, $actor] = everbranchWorkMobileTenantAndUser();
    $target = User::factory()->tenantAdmin()->create(['email' => 'target@example.com']);
    $target->tenants()->attach($tenant->id, ['role' => 'manager']);
    [, $outsideUser] = everbranchWorkMobileTenantAndUser('Outside Tenant', 'outside-tenant');

    $accessToken = acceptEverbranchWorkMobileLogin($actor->email);

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.team.notify', ['target' => $target->id]), [
            'body' => 'Can you look at this today?',
        ])
        ->assertCreated()
        ->assertJsonPath('notification.category', 'direct_notify')
        ->assertJsonPath('notification.actor.id', $actor->id);

    Notification::assertSentTo($target, EverbranchWorkItemNotification::class);
    expect(WorkNotification::query()->where('tenant_id', $tenant->id)->where('user_id', $target->id)->where('category', 'direct_notify')->count())->toBe(1)
        ->and(WorkNotificationDelivery::query()->where('tenant_id', $tenant->id)->where('user_id', $target->id)->where('channel', 'email')->where('status', 'sent')->count())->toBe(1);

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.team.notify', ['target' => $outsideUser->id]), [
            'body' => 'This should not cross tenants.',
        ])
        ->assertNotFound();
});

test('notification preferences default on and push registration is user based', function (): void {
    [$tenant, $user] = everbranchWorkMobileTenantAndUser();
    $accessToken = acceptEverbranchWorkMobileLogin($user->email);

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.notification-preferences'))
        ->assertOk()
        ->assertJsonFragment([
            'category' => 'assignment',
            'email_enabled' => true,
            'in_app_enabled' => true,
            'push_enabled' => true,
        ]);

    $this->withToken($accessToken)
        ->patchJson(route('mobile.work.notification-preferences.update'), [
            'preferences' => [
                [
                    'category' => 'comment',
                    'email_enabled' => false,
                    'push_enabled' => false,
                ],
            ],
        ])
        ->assertOk()
        ->assertJsonFragment([
            'category' => 'comment',
            'email_enabled' => false,
            'push_enabled' => false,
        ]);

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.notifications.push.register'), [
            'platform' => 'ios',
            'device_token' => 'work-device-token',
            'authorization_status' => 'authorized',
            'device_name' => 'Work iPhone',
        ])
        ->assertOk()
        ->assertJsonPath('device.platform', 'ios');

    expect(WorkNotificationPreference::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->count())->toBeGreaterThan(0)
        ->and(WorkPushDevice::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('device_token', 'work-device-token')->exists())->toBeTrue()
        ->and(MobilePushDevice::query()->where('device_token', 'work-device-token')->exists())->toBeFalse();
});

test('notifications can be listed and marked read only by owning user', function (): void {
    [$tenant, $user] = everbranchWorkMobileTenantAndUser();
    $otherUser = User::factory()->tenantAdmin()->create();
    $otherUser->tenants()->attach($tenant->id, ['role' => 'admin']);

    $mine = WorkNotification::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $user->id,
        'category' => 'direct_notify',
        'title' => 'Mine',
    ]);
    $theirs = WorkNotification::query()->create([
        'tenant_id' => $tenant->id,
        'user_id' => $otherUser->id,
        'category' => 'direct_notify',
        'title' => 'Theirs',
    ]);

    $accessToken = acceptEverbranchWorkMobileLogin($user->email);

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.notifications'))
        ->assertOk()
        ->assertJsonFragment(['title' => 'Mine'])
        ->assertJsonMissing(['title' => 'Theirs']);

    $this->withToken($accessToken)
        ->patchJson(route('mobile.work.notifications.update', ['notification' => $theirs->id]), ['read' => true])
        ->assertNotFound();

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.notifications.read'), ['ids' => [$mine->id, $theirs->id]])
        ->assertOk()
        ->assertJsonPath('updated', 1);

    expect($mine->fresh()->read_at)->not->toBeNull()
        ->and($theirs->fresh()->read_at)->toBeNull();
});

test('comments mentions watchers and activity are tenant scoped', function (): void {
    [$tenant, $actor] = everbranchWorkMobileTenantAndUser();
    $watcher = User::factory()->tenantAdmin()->create(['email' => 'watcher@example.com']);
    $watcher->tenants()->attach($tenant->id, ['role' => 'manager']);
    $mentioned = User::factory()->tenantAdmin()->create(['email' => 'mentioned@example.com']);
    $mentioned->tenants()->attach($tenant->id, ['role' => 'manager']);
    [$otherTenant, $outsideUser] = everbranchWorkMobileTenantAndUser('Outside Tenant', 'outside-tenant');

    $job = FieldServiceJob::query()->create([
        'tenant_id' => $tenant->id,
        'title' => 'Panel upgrade',
        'status' => 'open',
    ]);
    $task = FieldServiceTask::query()->create([
        'tenant_id' => $tenant->id,
        'field_service_job_id' => $job->id,
        'assigned_user_id' => $watcher->id,
        'title' => 'Pull permit',
        'status' => 'open',
    ]);
    $otherJob = FieldServiceJob::query()->create([
        'tenant_id' => $otherTenant->id,
        'title' => 'Other job',
        'status' => 'open',
    ]);

    $accessToken = acceptEverbranchWorkMobileLogin($actor->email);

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.jobs.watchers.store', ['job' => $job->id]), ['user_id' => $watcher->id])
        ->assertCreated()
        ->assertJsonPath('watcher.id', $watcher->id);

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.jobs.watchers.store', ['job' => $job->id]), ['user_id' => $outsideUser->id])
        ->assertNotFound();

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.jobs.comments.store', ['job' => $job->id]), [
            'body' => 'Please review this with the customer.',
            'mentioned_user_ids' => [$mentioned->id, $outsideUser->id],
        ])
        ->assertCreated()
        ->assertJsonPath('comment.mentioned_user_ids.0', $mentioned->id);

    Notification::assertSentTo($watcher, EverbranchWorkItemNotification::class);
    Notification::assertSentTo($mentioned, EverbranchWorkItemNotification::class);

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.jobs.comments', ['job' => $job->id]))
        ->assertOk()
        ->assertJsonFragment(['body' => 'Please review this with the customer.']);

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.jobs.activity', ['job' => $job->id]))
        ->assertOk()
        ->assertJsonFragment(['event_type' => 'commented'])
        ->assertJsonFragment(['event_type' => 'watcher_added']);

    $this->withToken($accessToken)
        ->postJson(route('mobile.work.tasks.comments.store', ['task' => $task->id]), [
            'body' => 'Task note.',
            'mentioned_user_ids' => [$watcher->id],
        ])
        ->assertCreated();

    $this->withToken($accessToken)
        ->getJson(route('mobile.work.jobs.comments', ['job' => $otherJob->id]))
        ->assertNotFound();

    expect(WorkItemWatcher::query()->where('tenant_id', $tenant->id)->where('user_id', $watcher->id)->exists())->toBeTrue()
        ->and(WorkItemComment::query()->where('tenant_id', $tenant->id)->count())->toBe(2)
        ->and(WorkActivityEvent::query()->where('tenant_id', $tenant->id)->where('event_type', 'commented')->count())->toBe(2)
        ->and(WorkNotification::query()->where('tenant_id', $tenant->id)->where('user_id', $outsideUser->id)->exists())->toBeFalse();
});

test('assignment and task updates create watchers activity and notifications', function (): void {
    [$tenant, $actor] = everbranchWorkMobileTenantAndUser();
    $assignee = User::factory()->tenantAdmin()->create(['email' => 'assignee@example.com']);
    $assignee->tenants()->attach($tenant->id, ['role' => 'manager']);

    $accessToken = acceptEverbranchWorkMobileLogin($actor->email);

    $jobResponse = $this->withToken($accessToken)
        ->postJson(route('mobile.work.jobs.store'), [
            'customer_name' => 'Rae Customer',
            'title' => 'Install EV charger',
            'assigned_user_id' => $assignee->id,
        ])
        ->assertCreated()
        ->assertJsonPath('job.assigned_user.id', $assignee->id);

    $jobId = (int) $jobResponse->json('job.id');
    expect(WorkItemWatcher::query()->where('tenant_id', $tenant->id)->where('item_type', 'field_service_job')->where('item_id', $jobId)->where('user_id', $assignee->id)->exists())->toBeTrue()
        ->and(WorkNotification::query()->where('tenant_id', $tenant->id)->where('user_id', $assignee->id)->where('category', 'assignment')->exists())->toBeTrue();

    $taskResponse = $this->withToken($accessToken)
        ->postJson(route('mobile.work.jobs.tasks.store', ['job' => $jobId]), [
            'title' => 'Schedule inspection',
            'assigned_user_id' => $assignee->id,
        ])
        ->assertCreated();

    $taskId = (int) $taskResponse->json('task.id');
    $this->withToken($accessToken)
        ->patchJson(route('mobile.work.tasks.update', ['task' => $taskId]), [
            'status' => 'blocked',
            'due_at' => now()->addDay()->toDateTimeString(),
        ])
        ->assertOk()
        ->assertJsonPath('task.status', 'blocked');

    expect(WorkActivityEvent::query()->where('tenant_id', $tenant->id)->where('item_type', 'field_service_task')->where('item_id', $taskId)->where('event_type', 'status_changed')->exists())->toBeTrue()
        ->and(WorkActivityEvent::query()->where('tenant_id', $tenant->id)->where('item_type', 'field_service_task')->where('item_id', $taskId)->where('event_type', 'due_date_changed')->exists())->toBeTrue()
        ->and(WorkNotification::query()->where('tenant_id', $tenant->id)->where('user_id', $assignee->id)->where('category', 'status_change')->exists())->toBeTrue()
        ->and(WorkNotification::query()->where('tenant_id', $tenant->id)->where('user_id', $assignee->id)->where('category', 'due_date')->exists())->toBeTrue();
});

/**
 * @return array{0:Tenant,1:User}
 */
function everbranchWorkMobileTenantAndUser(
    string $name = 'Bright Wire Electric',
    string $slug = 'bright-wire-electric',
    ?User $user = null,
    string $plan = 'base',
    string $mode = 'direct'
): array {
    $tenant = Tenant::query()->create([
        'name' => $name,
        'slug' => $slug,
    ]);

    TenantAccessProfile::query()->create([
        'tenant_id' => $tenant->id,
        'plan_key' => $plan,
        'operating_mode' => $mode,
        'source' => 'test',
    ]);

    $user ??= User::factory()->tenantAdmin()->create();
    $user->tenants()->attach($tenant->id, ['role' => 'admin']);

    return [$tenant, $user];
}

function requestEverbranchWorkMobileToken(string $email): string
{
    $response = test()->postJson(route('mobile.work.auth.request-link'), [
        'email' => $email,
    ])->assertOk();

    $token = $response->json('debug.token');
    expect($token)->toBeString()->not->toBeEmpty();

    return (string) $token;
}

function acceptEverbranchWorkMobileLogin(string $email): string
{
    $token = requestEverbranchWorkMobileToken($email);
    $response = test()->postJson(route('mobile.work.auth.accept-link'), [
        'token' => $token,
    ])->assertOk();

    $accessToken = $response->json('access_token');
    expect($accessToken)->toBeString()->not->toBeEmpty();

    return (string) $accessToken;
}
