<?php

namespace App\Services\Onboarding;

use App\Models\FieldServiceJob;
use App\Models\MarketingProfile;
use App\Models\Tenant;
use App\Models\TenantSite;
use App\Models\User;
use App\Models\WebsiteProduct;
use App\Services\FieldService\CollinsClientAccessService;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\ManagedWebsite\ManagedWebsiteService;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\WorkspaceSettingsService;
use Illuminate\Support\Facades\Log;
use Throwable;

/** Read-only projection: records prove completion; opening a link never does. */
class WorkspaceSetupGuide
{
    public function goals(Tenant $tenant, User $user): array
    {
        app(WorkspaceSettingsService::class)->authorize($tenant, $user);
        $access = app(TenantModuleAccessResolver::class);
        $enabled = fn (string $key): bool => $access->canAccess($tenant->id, $key);
        $marketing = $user->canAccessMarketing() && app(CollinsClientAccessService::class)->allows($user, $tenant);
        $goals = [];
        if ($enabled('customers') && $marketing) {
            $goals['customer'] = ['label' => 'Add your first customer', 'description' => 'Keep one customer’s details in a place you can return to.', 'route' => 'marketing.customers.create', 'destination' => 'marketing.customers', 'model' => MarketingProfile::class];
        }
        if ($enabled('field_service') && app(FieldServiceAccessService::class)->canCreateJobs($user, $tenant)
            && app(CollinsClientAccessService::class)->allows($user, $tenant)
            && in_array($user->role, ['admin', 'manager', 'marketing_manager', 'member'], true)) {
            $goals['job'] = ['label' => 'Create your first job', 'description' => 'Start with a real piece of work. Add scheduling and crew details when you need them.', 'route' => 'field-service.index', 'destination' => 'field-service.index', 'model' => FieldServiceJob::class];
        }
        if ($enabled('class_scheduling') && $marketing) {
            $goals['class'] = ['label' => 'Create your first class', 'description' => 'Prepare a class or activity before inviting people to join.', 'route' => 'class-scheduling.index', 'destination' => 'class-scheduling.index', 'model' => \App\Models\ScheduledClass::class];
        }
        if ($enabled('managed_website') && $marketing && app(ManagedWebsiteService::class)->editorEnabledFor($tenant)
            && TenantSite::query()->where('tenant_id', $tenant->id)->exists()) {
            $goals['product'] = ['label' => 'Prepare your first draft product', 'description' => 'Add a product to your own website catalog. Review it before publishing.', 'route' => 'managed-website.products.create', 'destination' => 'managed-website.products.index', 'model' => WebsiteProduct::class];
        }

        return $goals;
    }

    public function forTenant(Tenant $tenant, User $user): array
    {
        $settings = app(WorkspaceSettingsService::class);
        $settings->authorize($tenant, $user);
        $context = app(TenantBlueprintProfileService::class)->companyContext($tenant);
        $owner = $settings->canManage($tenant, $user);
        $steps = [];
        if ($owner) {
            $steps[] = ['id' => 'business', 'label' => 'Confirm your business profile', 'description' => 'Tell us what you do so the next steps fit your organization.',
                'state' => filled($context['confirmed_at'] ?? null) ? 'complete' : ($context === [] ? 'not_started' : 'in_progress'),
                'url' => $tenant->accessProfile ? route('workspace-settings.business', ['tenant' => $tenant->slug], false) : null,
                'required' => true, 'note' => $tenant->accessProfile ? null : 'Ask Everbranch to finish configuring this workspace.'];
        }
        try {
            $goals = $this->goals($tenant, $user);
            $preferred = ($context['goal'] ?? null) ?: match ($context['category'] ?? '') {
                'field_service' => 'job', 'education' => 'class', 'retail' => 'product', default => 'customer',
            };
            if (isset($goals[$preferred])) {
                $goals = [$preferred => $goals[$preferred], ...$goals];
            }
            foreach ($goals as $id => $goal) {
                try {
                    $complete = $goal['model']::query()->where('tenant_id', $tenant->id)->exists();
                    $state = $complete ? 'complete' : 'not_started';
                } catch (Throwable $exception) {
                    $state = 'unknown';
                    $complete = false;
                    Log::warning('workspace.setup.read_unavailable', ['exception_type' => class_basename($exception)]);
                }
                $steps[] = ['id' => $id, 'label' => $goal['label'], 'description' => $goal['description'], 'state' => $state,
                    'url' => route($complete ? $goal['destination'] : $goal['route'], ['tenant' => $tenant->slug, ...($complete && $id === 'product' ? ['status' => 'all'] : [])], false),
                    'required' => $id === array_key_first($goals), 'note' => $state === 'unknown' ? 'We could not verify existing records. Check your workspace before adding another.' : null];
            }
        } catch (Throwable $exception) {
            $steps[] = ['id' => 'availability', 'label' => 'Check available next steps', 'description' => 'We could not confirm which actions are available right now.', 'state' => 'unknown', 'required' => true, 'url' => null, 'note' => 'Try again. Existing work has not changed.'];
            Log::warning('workspace.setup.availability_unavailable', ['exception_type' => class_basename($exception)]);
        }
        try {
            $catalog = $settings->catalog($tenant, $user);
        } catch (Throwable $exception) {
            $catalog = [];
            Log::warning('workspace.setup.settings_unavailable', ['exception_type' => class_basename($exception)]);
        }
        foreach ($catalog as $setting) {
            if (in_array($setting['id'], ['brand', 'connections'], true)) {
                $steps[] = ['id' => $setting['id'], 'label' => $setting['label'], 'description' => $setting['description'],
                    'state' => $setting['id'] === 'connections' ? 'verification_pending' : 'optional', 'url' => $setting['url'], 'required' => false,
                    'note' => $setting['id'] === 'connections' ? 'Use the connection screen for its latest check and any required action. Configuration alone does not prove a healthy connection.' : null];
            }
        }
        $preferences = (array) data_get($user->onboarding_guide_answers, 'workspace_guidance.'.$tenant->id, []);
        foreach ($steps as &$step) {
            $step['dismissed'] = ! $step['required'] && in_array($step['id'], (array) ($preferences['dismissed_steps'] ?? []), true);
        }
        unset($step);
        $required = array_filter($steps, fn (array $step): bool => $step['required']);
        $next = collect($steps)->first(fn (array $step): bool => ! $step['dismissed'] && $step['state'] !== 'complete' && $step['url']);

        return ['steps' => $steps, 'required_count' => count($required), 'required_complete' => count(array_filter($required, fn (array $step): bool => $step['state'] === 'complete')),
            'next' => $next, 'context' => array_diff_key($context, ['description' => true]), 'guidance_hidden' => (bool) ($preferences['hidden'] ?? false), 'owner' => $owner];
    }
}
