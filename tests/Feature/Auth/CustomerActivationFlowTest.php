<?php

use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ApprovalPasswordSetupNotification;
use App\Services\Onboarding\CustomerAccessApprovalService;
use Illuminate\Support\Facades\Notification;

beforeEach(function (): void {
    $this->withoutVite();
    config()->set('app.url', 'https://app.backstage.local');
    config()->set('tenancy.landlord.primary_host', 'app.backstage.local');
    config()->set('tenancy.auth.flagship_hosts', ['app.backstage.local']);
});

test('approving a production access request sends a tenant-host password setup link', function (): void {
    Notification::fake();
    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $user = User::factory()->create([
        'name' => 'Acme Ops',
        'email' => 'ops@acme.example.com',
        'role' => 'manager',
        'requested_via' => 'customer_production',
        'is_active' => false,
    ]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => $user->name,
        'email' => $user->email,
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
        'message' => 'Approve our production workspace.',
        'metadata' => ['requested_via' => 'test'],
        'user_id' => (int) $user->id,
    ]);

    app(CustomerAccessApprovalService::class)->approve((int) $accessRequest->id, (int) $approver->id);

    $tenant = Tenant::query()->where('slug', 'acme')->first();
    expect($tenant)->not->toBeNull();

    $user->refresh();
    expect((bool) $user->is_active)->toBeTrue();
    expect($user->tenants()->where('tenants.id', (int) $tenant->id)->exists())->toBeTrue();

    $accessRequest->refresh();
    expect((string) $accessRequest->status)->toBe('approved')
        ->and((int) $accessRequest->tenant_id)->toBe((int) $tenant->id);

    Notification::assertSentTo(
        $user,
        ApprovalPasswordSetupNotification::class,
        function (ApprovalPasswordSetupNotification $notification) use ($user): bool {
            $mail = $notification->toMail($user);
            expect((string) $mail->actionUrl)->toContain('://acme.backstage.local/')
                ->and((string) $mail->actionUrl)->toContain('/reset-password/');

            return true;
        }
    );
});

test('customer users redirect to tenant-aware start here after login', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'requested_via' => 'customer_production',
        'is_active' => true,
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    $this->get('http://acme.backstage.local/login')->assertOk();

    $this->post('http://acme.backstage.local/login', [
        'email' => $user->email,
        'password' => 'password',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.start', absolute: false))
        ->assertSessionHas('tenant_id', (int) $tenant->id);
});

test('start here page renders for tenant members', function (): void {
    $tenant = Tenant::query()->create([
        'name' => 'Acme Candle Co',
        'slug' => 'acme',
    ]);

    $user = User::factory()->create([
        'role' => 'manager',
        'requested_via' => 'customer_production',
        'is_active' => true,
    ]);
    $user->tenants()->attach($tenant->id, ['role' => 'manager']);

    $this->actingAs($user)
        ->get('http://acme.backstage.local/start')
        ->assertOk()
        ->assertSeeText('Start Here')
        ->assertSeeText('Available Now')
        ->assertSeeText('Acme Candle Co');
});

test('password setup stays on tenant host and first login lands on start here', function (): void {
    Notification::fake();

    $approver = User::factory()->create(['role' => 'admin', 'is_active' => true]);

    $accessRequest = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'status' => 'pending',
        'name' => 'Acme Ops',
        'email' => 'ops-activate@example.com',
        'company' => 'Acme Candle Co',
        'requested_tenant_slug' => 'acme',
    ]);

    app(CustomerAccessApprovalService::class)->approve((int) $accessRequest->id, (int) $approver->id);

    $user = User::query()->where('email', 'ops-activate@example.com')->firstOrFail();

    $notification = Notification::sent($user, ApprovalPasswordSetupNotification::class)->first();
    expect($notification)->not->toBeNull();

    $mail = $notification->toMail($user);
    $resetUrl = (string) $mail->actionUrl;
    expect($resetUrl)->toContain('://acme.backstage.local/');

    $this->get($resetUrl)
        ->assertOk()
        ->assertSeeText('Reset password');

    $path = (string) (parse_url($resetUrl, PHP_URL_PATH) ?: '');
    $token = trim((string) basename($path));
    expect($token)->not->toBe('');

    $this->post('http://acme.backstage.local/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'new-password-123',
        'password_confirmation' => 'new-password-123',
    ])->assertRedirect('/login');

    $this->post('http://acme.backstage.local/login', [
        'email' => $user->email,
        'password' => 'new-password-123',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.start', absolute: false));
});
