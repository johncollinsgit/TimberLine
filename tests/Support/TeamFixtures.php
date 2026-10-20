<?php

use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\TenantModuleEntitlement;
use App\Models\User;
use Illuminate\Support\Str;

function teamWorkspace(): array
{
    $tenant = Tenant::query()->create(['name' => 'Fictional Team', 'slug' => 'fixture-'.Str::lower(Str::random(8))]);
    TenantAccessProfile::query()->create(['tenant_id' => $tenant->id, 'plan_key' => 'base', 'operating_mode' => 'direct', 'source' => 'test']);
    TenantModuleEntitlement::query()->create(['tenant_id' => $tenant->id, 'module_key' => 'team_work', 'enabled_status' => 'enabled', 'availability_status' => 'available', 'entitlement_source' => 'test']);
    config(['team_work.tenant_allowlist' => array_merge(config('team_work.tenant_allowlist', []), [(string) $tenant->id])]);
    $owner = teamMember($tenant, 'owner');

    return [$tenant, $owner];
}
function teamMember(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['role' => 'member', 'is_active' => true]);
    $tenant->users()->attach($user->id, ['role' => $role, 'membership_active' => true]);

    return $user;
}
function teamPost(Tenant $tenant, User $user, array $command)
{
    $command['id'] ??= (string) Str::uuid();
    $command['idempotency_key'] ??= (string) Str::uuid();

    return test()->actingAs($user)->postJson(route('team.commands', ['tenant' => $tenant->slug]), $command);
}
function teamProject(Tenant $tenant, User $user, string $privacy = 'private'): array
{
    return teamPost($tenant, $user, ['kind' => 'project.create', 'name' => 'Fictional project', 'privacy' => $privacy])->assertOk()->json('result');
}
function teamTask(Tenant $tenant, User $user, ?array $project = null): array
{
    return teamPost($tenant, $user, ['kind' => 'task.create', 'title' => 'Fictional inspection', 'description' => 'Native context', 'memberships' => $project ? [$project['id'] => []] : []])->assertOk()->json('result');
}
