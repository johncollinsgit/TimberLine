<?php

use App\Livewire\Admin\Users\UsersIndex;
use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use App\Services\Onboarding\CustomerAccessApprovalService;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('app.url', 'https://app.grovebud.com');
    config()->set('tenancy.landlord.primary_host', 'app.grovebud.com');
});

test('non-admin users cannot perform approve/reject/resend actions', function (): void {
    Notification::fake();

    $manager = User::factory()->create(['role' => 'manager', 'is_active' => true]);
    $pending = User::factory()->create([
        'email' => 'ops-unauth@example.com',
        'role' => 'manager',
        'is_active' => false,
        'requested_via' => 'customer_production',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => $pending->name,
        'email' => $pending->email,
        'requested_tenant_slug' => 'acme',
        'user_id' => (int) $pending->id,
    ]);

    Livewire::actingAs($manager)->test(UsersIndex::class)->call('approve', (int) $pending->id);

    $pending->refresh();
    expect((bool) $pending->is_active)->toBeFalse();
    Notification::assertNothingSent();
});

test('approve action is idempotent and does not duplicate membership or emails', function (): void {
    Notification::fake();
    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Acme Ops',
        'email' => 'ops-approve@example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
    ]);

    $service = app(CustomerAccessApprovalService::class);

    $service->approve((int) $accessRequest->id, (int) $approver->id);
    $service->approve((int) $accessRequest->id, (int) $approver->id);

    $tenant = Tenant::query()->where('slug', 'acme')->first();
    expect($tenant)->not->toBeNull();

    $user = User::query()->where('email', 'ops-approve@example.com')->first();
    expect($user)->not->toBeNull();

    expect($user->tenants()->where('tenants.id', (int) $tenant->id)->count())->toBe(1);

    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);
});

test('reject action blocks later activation', function (): void {
    Notification::fake();
    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Acme Ops',
        'email' => 'ops-reject@example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
    ]);

    $service = app(CustomerAccessApprovalService::class);
    $service->reject((int) $accessRequest->id, (int) $approver->id, 'Not a fit.');

    expect(fn () => $service->approve((int) $accessRequest->id, (int) $approver->id))
        ->toThrow(DomainException::class);

    Notification::assertNothingSent();
});

test('resend activation uses tenant host and is throttled for repeated clicks', function (): void {
    Notification::fake();
    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Acme Ops',
        'email' => 'ops-resend@example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
    ]);

    $service = app(CustomerAccessApprovalService::class);
    $service->approve((int) $accessRequest->id, (int) $approver->id);

    $user = User::query()->where('email', 'ops-resend@example.com')->firstOrFail();
    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);

    $service->resendActivation((int) $accessRequest->id, (int) $approver->id);
    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 1);

    CustomerAccessRequest::query()->whereKey((int) $accessRequest->id)->update([
        'activation_email_last_sent_at' => now()->subMinutes(5),
    ]);

    $service->resendActivation((int) $accessRequest->id, (int) $approver->id);
    Notification::assertSentToTimes($user, ApprovalPasswordSetupNotification::class, 2);

    Notification::assertSentTo($user, ApprovalPasswordSetupNotification::class, function (ApprovalPasswordSetupNotification $notification) use ($user): bool {
        $mail = $notification->toMail($user);
        expect((string) $mail->actionUrl)->toContain('://acme.grovebud.com/');

        return true;
    });
});

test('admin surface routes through Livewire component for approval actions', function (): void {
    Notification::fake();

    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $this->actingAs($admin)->get(route('admin.users'))->assertOk();

    $user = User::factory()->create([
        'email' => 'ops-livewire@example.com',
        'role' => 'manager',
        'is_active' => false,
        'requested_via' => 'customer_production',
    ]);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => $user->name,
        'email' => $user->email,
        'requested_tenant_slug' => 'acme',
        'user_id' => (int) $user->id,
    ]);

    Livewire::actingAs($admin)->test(UsersIndex::class)
        ->call('approve', (int) $user->id);

    $user->refresh();
    expect((bool) $user->is_active)->toBeTrue();
});
