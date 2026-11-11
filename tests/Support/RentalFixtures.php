<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use App\Services\Rentals\RentalRepository;
use Illuminate\Support\Str;

function rentalFixture(): array
{
    $tenant = Tenant::query()->create(['name' => 'Fictional shared home', 'slug' => 'rental-'.Str::lower(Str::random(10))]);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'property_rentals', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    config(['rentals.enabled' => true, 'rentals.tenant_allowlist' => [...config('rentals.tenant_allowlist', []), (string) $tenant->id]]);
    $admin = rentalMember($tenant, 'owner');
    $property = app(RentalRepository::class)->create($tenant, $admin, ['id' => (string) Str::uuid(), 'name' => 'Fictional villa', 'unit' => 'Unit A', 'location' => 'Example Coast', 'address' => '', 'timezone' => 'America/New_York']);

    return [$tenant, $admin, $property];
}

function rentalMember(Tenant $tenant, string $role = 'member'): User
{
    $user = User::factory()->create(['role' => 'member', 'is_active' => true, 'email_verified_at' => now()]);
    $tenant->users()->attach($user->id, ['role' => $role, 'membership_active' => true]);

    return $user;
}

function rentalChange(Tenant $tenant, User $user, array $property, string $action, array $fields = []): array
{
    return app(RentalRepository::class)->execute($tenant, $user, $property['property']['id'], $fields + ['action' => $action, 'version' => $property['version'], 'requestKey' => (string) Str::uuid()]);
}

function rentalHousehold(Tenant $tenant, User $admin, array $property, User $member, int $share): array
{
    return rentalChange($tenant, $admin, $property, 'household', ['name' => 'Household '.$share, 'userIds' => [(string) $member->id], 'color' => '#157f69', 'shares' => [$share], 'address' => '']);
}
