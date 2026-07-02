<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Onboarding\TenantOnboardingCompletionService;
use App\Services\Onboarding\TenantSetupStatusService;
use App\Services\Tenancy\TenantCommercialExperienceService;
use App\Services\Tenancy\TenantBlueprintModuleRecommendationService;
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
        TenantOnboardingCompletionService $completionService
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
            'showOnboardingModal' => $showElectricianTutorial && ! $onboardingComplete,
            'completionRedirectUrl' => route('dashboard', absolute: false),
        ]);
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
