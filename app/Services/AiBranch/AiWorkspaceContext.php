<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchMemory;
use App\Models\Tenant;
use App\Models\TenantAccessProfile;
use App\Models\User;
use App\Services\FieldService\CollinsClientAccessService;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use DateTimeZone;

class AiWorkspaceContext
{
    public const VERSION = 2;

    public function enabled(Tenant $tenant): bool
    {
        return (bool) config('ai_branch.workspace_context_enabled')
            && in_array((int) $tenant->id, config('ai_branch.workspace_context_tenant_ids', []), true);
    }

    public function authorize(Tenant $tenant, User $user, bool $optedIn = true): void
    {
        abort_unless($this->enabled($tenant), 404);
        app(AiBranchAccess::class)->authorize($tenant, $user, $optedIn);
    }

    public function modules(Tenant $tenant): array
    {
        return app(TenantModuleAccessResolver::class)->resolveForTenant($tenant->id)['modules'];
    }

    public function permissions(Tenant $tenant, User $user): array
    {
        $modules = $this->modules($tenant);
        $module = fn (string $key): bool => (bool) ($modules[$key]['enabled'] ?? false);
        $client = app(CollinsClientAccessService::class)->allows($user, $tenant);
        $jobs = $client && $module('field_service') && app(FieldServiceAccessService::class)->canManageJobs($user, $tenant);
        $customers = $client && $module('customers') && $user->canAccessMarketing();

        return [
            'jobs' => $jobs, 'tasks' => $jobs, 'followups' => $customers, 'customers' => $customers,
            'scheduling' => $client && $module('class_scheduling') && in_array($user->role, ['admin', 'manager', 'marketing_manager'], true),
            'activity' => $jobs || $customers, 'connections' => $client && $module('integrations') && ($user->canAccessMarketing() || $user->isManager()),
        ];
    }

    public function timezone(Tenant $tenant): string
    {
        $metadata = TenantAccessProfile::query()->where('tenant_id', $tenant->id)->value('metadata');
        $zone = data_get($metadata, 'ai_branch.timezone', 'UTC');

        return is_string($zone) && in_array($zone, DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true) ? $zone : 'UTC';
    }

    public function stamp(Tenant $tenant, User $user): string
    {
        $modules = $this->modules($tenant);
        $access = [];
        foreach ($modules as $key => $module) {
            $access[$key] = [(bool) ($module['enabled'] ?? false), (array) ($module['capabilities'] ?? [])];
        }
        ksort($access);

        return hash('sha256', json_encode([
            self::VERSION, $this->enabled($tenant), $this->permissions($tenant, $user), $access,
            app(FieldServiceAccessService::class)->role($user, $tenant), $user->role, $this->timezone($tenant),
            AiBranchMemory::query()->where('tenant_id', $tenant->id)->where('user_id', $user->id)->where('expires_at', '>', now())
                ->orderBy('id')->get(['id', 'body'])->map(fn ($memory) => [$memory->id, hash('sha256', $memory->body)])->all(),
        ], JSON_THROW_ON_ERROR));
    }

    public function profile(Tenant $tenant, User $user): array
    {
        $this->authorize($tenant, $user, false);
        $tenant = Tenant::query()->findOrFail($tenant->id);
        $profile = app(TenantExperienceProfileService::class)->forTenant($tenant->id, $user, $tenant);
        $blueprint = app(TenantBlueprintProfileService::class)->payloadForTenant($tenant);
        $configured = data_get($tenant->accessProfile?->metadata, 'tenant_blueprint.business_template');
        $enabled = [];
        $capabilities = [];
        $permissions = $this->permissions($tenant, $user);
        foreach ($this->modules($tenant) as $key => $module) {
            if (! ($module['enabled'] ?? false)) {
                continue;
            }
            $enabled[] = ['key' => $key, 'name' => $module['label'] ?? $key];
            // Module availability never implies permission to retrieve its data.
            if (($key === 'field_service' && $permissions['jobs']) || ($key === 'customers' && $permissions['customers'])
                || ($key === 'class_scheduling' && $permissions['scheduling']) || $key === 'ai_branch') {
                $capabilities = [...$capabilities, ...(array) ($module['capabilities'] ?? [])];
            }
        }

        return [
            'name' => mb_substr($tenant->name, 0, 180),
            'business_template' => $configured ? mb_substr((string) ($blueprint['business_template_label'] ?? $blueprint['business_template'] ?? ''), 0, 80) : 'Not configured',
            'operating_mode' => $tenant->accessProfile?->operating_mode ? (string) $profile['operating_mode'] : 'Not configured',
            'role' => app(FieldServiceAccessService::class)->role($user, $tenant),
            'timezone' => $this->timezone($tenant), 'branches' => $enabled,
            'capabilities' => array_values(array_unique($capabilities)),
        ];
    }

    public function capture(Tenant $tenant, User $user, array $origin = [], string $purpose = 'chat'): array
    {
        $this->authorize($tenant, $user);
        $profile = $this->profile($tenant, $user);
        $page = app(AiWorkspacePage::class)->resolve($tenant, $user, $origin);
        $result = app(AiWorkspaceSources::class)->collect($tenant, $user, $page, $purpose);

        return [
            'version' => self::VERSION, 'workspace' => $profile, 'page' => $page['page'],
            'generated_at' => now('UTC')->toIso8601String(), 'sources' => $result['sources'],
            'connections' => $result['connections'], 'coverage' => $result['coverage'],
            'attention' => $purpose === 'briefing' ? app(AiAttentionBriefing::class)->candidates($result['sources'], $profile['timezone']) : [],
        ];
    }
}
