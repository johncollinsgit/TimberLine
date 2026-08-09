<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Models\TenantWorkspaceChangeRequest;
use App\Services\Onboarding\TenantOnboardingCompletionService;
use App\Services\Onboarding\TenantSetupStatusService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantBlueprintModuleRecommendationService;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\TenantWorkspaceChangeRequestService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class CustomerStartHereController extends Controller
{
    public function show(
        TenantCommercialExperienceService $experienceService,
        TenantSetupStatusService $setupStatusService,
        TenantBlueprintModuleRecommendationService $blueprintModuleRecommendations,
        TenantOnboardingCompletionService $completionService,
        TenantBlueprintProfileService $blueprints,
        TenantWorkspaceChangeRequestService $workspaceChanges
    ): Response
    {
        /** @var Tenant|null $tenant */
        $tenant = request()->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $setupStatus = $setupStatusService->forTenant($tenant);
        $onboardingComplete = $completionService->isComplete($tenant);
        $showElectricianTutorial = (bool) config('features.customer_electrician_tutorial', false);

        return response()->view('onboarding.start-here', [
            'tenant' => $tenant,
            'journey' => $experienceService->merchantJourneyPayload((int) $tenant->id),
            'plans' => $experienceService->plansPayload((int) $tenant->id),
            'setupOptions' => $setupStatusService->options(),
            'setupStatus' => $setupStatusService->payload($tenant, $setupStatus),
            'blueprintModuleRecommendations' => $blueprintModuleRecommendations->forTenantModel($tenant),
            'onboardingComplete' => $onboardingComplete,
            'showElectricianTutorial' => $showElectricianTutorial,
            'completionRedirectUrl' => route('dashboard', absolute: false),
            'ownerCanManageWorkspace' => request()->user() instanceof \App\Models\User
                ? $workspaceChanges->canManageWorkspace(request()->user(), $tenant)
                : false,
            'workspaceChangeRequest' => $workspaceChanges->pendingForTenant($tenant),
            'workspaceDetailOptions' => [
                'templates' => $blueprints->templateOptions(),
                'work_management_intents' => $blueprints->formOptions()['work_management_intents'] ?? [],
            ],
        ]);
    }

    public function updateWorkspaceDetails(
        Request $request,
        TenantSetupStatusService $setupStatusService,
        TenantBlueprintProfileService $blueprints,
        TenantWorkspaceChangeRequestService $workspaceChanges
    ): RedirectResponse {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof \App\Models\User && $workspaceChanges->canManageWorkspace($user, $tenant), 403);

        $validated = $request->validate($blueprints->ownerDetailValidationRules());
        $profile = $tenant->accessProfile ?: $tenant->accessProfile()->create([
            'plan_key' => 'base',
            'operating_mode' => 'custom_or_unknown',
            'source' => 'tenant_workspace_details',
        ]);

        $blueprints->updateOwnerDetails($tenant, $profile, $setupStatusService->forTenant($tenant), $validated);

        return redirect()->route('app.start', ['tenant' => $tenant->slug])
            ->with('status', 'Workspace details updated. Your workspace access has not changed.');
    }

    public function requestWorkspaceChange(Request $request, TenantWorkspaceChangeRequestService $workspaceChanges): RedirectResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof \App\Models\User && $workspaceChanges->canManageWorkspace($user, $tenant), 403);

        $validated = $request->validate($workspaceChanges->requestValidationRules());

        try {
            $workspaceChanges->request($tenant, $user, $validated);
        } catch (\RuntimeException $exception) {
            return back()->withErrors(['workspace_change' => $exception->getMessage()])->withInput();
        }

        return redirect()->route('app.start', ['tenant' => $tenant->slug])
            ->with('status', 'Workspace change request sent to Everbranch for review. Your current workspace is unchanged.');
    }

    public function cancelWorkspaceChange(Request $request, TenantWorkspaceChangeRequest $workspaceChangeRequest, TenantWorkspaceChangeRequestService $workspaceChanges): RedirectResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        $user = $request->user();
        abort_unless($tenant instanceof Tenant && $user instanceof \App\Models\User, 403);

        try {
            $workspaceChanges->cancel($workspaceChangeRequest, $user, $tenant);
        } catch (\RuntimeException $exception) {
            abort(403, $exception->getMessage());
        }

        return redirect()->route('app.start', ['tenant' => $tenant->slug])
            ->with('status', 'Workspace change request cancelled.');
    }

    public function updateSetupStatus(Request $request, TenantSetupStatusService $setupStatusService): RedirectResponse
    {
        /** @var Tenant|null $tenant */
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $options = $setupStatusService->options();
        $validated = $request->validate([
            'business_profile_status' => ['required', 'string', Rule::in(array_keys((array) ($options['business_profile_statuses'] ?? [])))],
            'import_path' => ['required', 'string', Rule::in(array_keys((array) ($options['import_paths'] ?? [])))],
            'square_status' => ['required', 'string', Rule::in(array_keys((array) ($options['square_statuses'] ?? [])))],
            'csv_manual_status' => ['required', 'string', Rule::in(array_keys((array) ($options['csv_manual_statuses'] ?? [])))],
            'module_interests' => ['nullable', 'array'],
            'module_interests.*' => ['string', Rule::in(array_keys((array) ($options['module_interests'] ?? [])))],
            'mobile_interest' => ['required', 'string', Rule::in(array_keys((array) ($options['mobile_interests'] ?? [])))],
            'plan_interest' => ['nullable', 'string', Rule::in(array_keys((array) ($options['plan_interests'] ?? [])))],
            'billing_lane_interest' => ['nullable', 'string', Rule::in(array_keys((array) ($options['billing_lane_interests'] ?? [])))],
            'implementation_help_interest' => ['nullable', 'boolean'],
            'commercial_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $setupStatusService->updateTenantStatus($tenant, $validated);

        return redirect()
            ->route('app.start', ['tenant' => (string) $tenant->slug])
            ->with('status', 'Setup status saved. Everbranch will use this to guide your next steps.');
    }
}
