<?php

use App\Livewire\Admin\Users\UsersIndex;
use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Onboarding\CustomerAccessApprovalService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->withoutVite();
});

function teamAccessTenant(string $name, string $slug): Tenant
{
    return Tenant::query()->create(['name' => $name, 'slug' => $slug]);
}

function teamAccessAdmin(Tenant $tenant): User
{
    $admin = User::factory()->create(['role' => 'admin', 'is_active' => true]);
    $admin->tenants()->attach($tenant->id, ['role' => 'admin']);
    app(TenantContext::class)->set((int) $tenant->id);

    return $admin;
}

test('team access shows only current workspace members and platform requests', function (): void {
    $collins = teamAccessTenant('Collins Upstate Electric', 'collins-upstate-electric');
    $wholesaleTenant = teamAccessTenant('Modern Forestry', 'modern-forestry');
    $admin = teamAccessAdmin($collins);

    $collinsMember = User::factory()->create(['name' => 'Collins Technician', 'email' => 'tech@collins.example']);
    $collinsMember->tenants()->attach($collins->id, ['role' => 'member']);

    $wholesaleMember = User::factory()->create(['name' => 'Wholesale Buyer', 'email' => 'buyer@wholesale.example']);
    $wholesaleMember->tenants()->attach($wholesaleTenant->id, ['role' => 'manager']);

    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_PLATFORM_ACCESS,
        'status' => 'pending',
        'name' => 'Collins Applicant',
        'email' => 'applicant@collins.example',
        'tenant_id' => $collins->id,
        'requested_tenant_slug' => $collins->slug,
    ]);
    CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_WHOLESALE_APPLICATION,
        'status' => 'pending',
        'name' => 'Wholesale Applicant',
        'email' => 'applicant@wholesale.example',
        'tenant_id' => $collins->id,
        'requested_tenant_slug' => $collins->slug,
    ]);

    Livewire::actingAs($admin)
        ->withQueryParams([])
        ->test(UsersIndex::class)
        ->assertSee('Collins Upstate Electric')
        ->assertSee('Collins Technician')
        ->assertSee('Collins Applicant')
        ->assertDontSee('Wholesale Buyer')
        ->assertDontSee('Wholesale Applicant')
        ->assertDontSee('Pouring');
});

test('rejecting a platform request removes its approve action from the queue immediately', function (): void {
    $tenant = teamAccessTenant('Collins Upstate Electric', 'collins-upstate-electric');
    $admin = teamAccessAdmin($tenant);

    $request = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_PLATFORM_ACCESS,
        'status' => 'pending',
        'name' => 'Pending Person',
        'email' => 'pending@collins.example',
        'tenant_id' => $tenant->id,
        'requested_tenant_slug' => $tenant->slug,
    ]);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->assertSee('Pending Person')
        ->call('rejectRequest', (int) $request->id)
        ->assertDontSee('Pending Person');

    expect($request->fresh()->status)->toBe('rejected');
});

test('a workspace administrator can approve access without a global admin role', function (): void {
    Notification::fake();
    $tenant = teamAccessTenant('Collins Upstate Electric', 'collins-upstate-electric');
    $workspaceAdmin = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $workspaceAdmin->tenants()->attach($tenant->id, ['role' => 'admin']);
    app(TenantContext::class)->set((int) $tenant->id);

    $request = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_PLATFORM_ACCESS,
        'status' => 'pending',
        'name' => 'Field Technician',
        'email' => 'field-tech@collins.example',
        'tenant_id' => $tenant->id,
        'requested_tenant_slug' => $tenant->slug,
    ]);

    Livewire::actingAs($workspaceAdmin)
        ->test(UsersIndex::class)
        ->call('approveRequest', (int) $request->id)
        ->assertSee('Field Technician');

    $approvedUser = User::query()->where('email', 'field-tech@collins.example')->firstOrFail();
    expect($request->fresh()->status)->toBe('approved')
        ->and($approvedUser->tenants()->whereKey($tenant->id)->exists())->toBeTrue();
});

test('role changes and removals affect only the current workspace membership', function (): void {
    $collins = teamAccessTenant('Collins Upstate Electric', 'collins-upstate-electric');
    $otherTenant = teamAccessTenant('Other Workspace', 'other-workspace');
    $admin = teamAccessAdmin($collins);
    $member = User::factory()->create(['name' => 'Shared User']);
    $member->tenants()->attach($collins->id, ['role' => 'member']);
    $member->tenants()->attach($otherTenant->id, ['role' => 'manager']);

    Livewire::actingAs($admin)
        ->test(UsersIndex::class)
        ->call('updateMemberRole', (int) $member->id, 'manager')
        ->call('removeAccess', (int) $member->id);

    expect($member->tenants()->whereKey($collins->id)->exists())->toBeFalse()
        ->and($member->tenants()->whereKey($otherTenant->id)->exists())->toBeTrue()
        ->and(User::query()->whereKey($member->id)->exists())->toBeTrue();
});

test('platform approval never invokes wholesale Shopify synchronization', function (): void {
    Notification::fake();
    Http::fake(fn () => throw new RuntimeException('Platform approval must not call Shopify.'));

    $tenant = teamAccessTenant('Collins Upstate Electric', 'collins-upstate-electric');
    $admin = teamAccessAdmin($tenant);
    $request = CustomerAccessRequest::query()->create([
        'intent' => 'production',
        'application_kind' => CustomerAccessRequest::KIND_PLATFORM_ACCESS,
        'status' => 'pending',
        'name' => 'Collins Applicant',
        'email' => 'platform-only@collins.example',
        'tenant_id' => $tenant->id,
        'requested_tenant_slug' => $tenant->slug,
    ]);

    $service = app(CustomerAccessApprovalService::class);
    $approved = $service->approve((int) $request->id, (int) $admin->id);
    $user = User::query()->where('email', 'platform-only@collins.example')->firstOrFail();

    Http::assertNothingSent();
    expect($request->fresh()->status)->toBe('approved')
        ->and(fn () => $service->syncShopifyWholesaleCustomer($user, $approved, (int) $admin->id, 'test'))
        ->toThrow(DomainException::class);
});

test('wholesale inbox is unavailable from a non-wholesale workspace', function (): void {
    $collins = teamAccessTenant('Collins Upstate Electric', 'collins-upstate-electric');
    teamAccessTenant('Modern Forestry', 'modern-forestry');
    $admin = teamAccessAdmin($collins);

    $this->actingAs($admin)
        ->withSession(['tenant_id' => $collins->id])
        ->get(route('admin.wholesale.applications'))
        ->assertNotFound();
});
