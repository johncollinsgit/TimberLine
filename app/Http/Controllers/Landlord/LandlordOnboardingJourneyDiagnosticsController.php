<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Onboarding\OnboardingJourneyDiagnosticsService;
use App\Services\Onboarding\TenantSetupStatusService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;

class LandlordOnboardingJourneyDiagnosticsController extends Controller
{
    public function intake(Request $request, TenantSetupStatusService $setupStatusService): Response
    {
        $filterOptions = $setupStatusService->intakeFilterOptions();
        $validated = $request->validate([
            'filter' => ['nullable', 'string', Rule::in(array_keys($filterOptions))],
        ]);

        $queue = $setupStatusService->intakeQueue((string) ($validated['filter'] ?? 'all'));

        return response()->view('landlord/onboarding/intake', [
            'activeFilter' => (string) $queue['active_filter'],
            'filterOptions' => (array) $queue['filter_options'],
            'summary' => (array) $queue['summary'],
            'rows' => (array) $queue['rows'],
            'setupOptions' => $setupStatusService->options(),
        ]);
    }

    public function commercialIntent(TenantSetupStatusService $setupStatusService): Response
    {
        $gate = $setupStatusService->commercialIntentGate();

        return response()->view('landlord/commercial-intent/index', [
            'summary' => (array) ($gate['summary'] ?? []),
            'planCounts' => (array) ($gate['plan_counts'] ?? []),
            'billingLaneCounts' => (array) ($gate['billing_lane_counts'] ?? []),
            'rows' => (array) ($gate['rows'] ?? []),
            'setupOptions' => $setupStatusService->options(),
        ]);
    }

    public function index(
        Request $request,
        OnboardingJourneyDiagnosticsService $diagnosticsService,
        TenantSetupStatusService $setupStatusService
    ): Response
    {
        $validated = $request->validate([
            'tenant_id' => ['nullable', 'integer', 'min:1'],
            'final_blueprint_id' => ['nullable', 'integer', 'min:1'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'stuck_point' => ['nullable', 'string', 'in:waiting_for_first_open,waiting_for_import,waiting_for_activation,progressing,completed_first_value'],
            'phase' => ['nullable', 'string', 'in:handoff,first_session,ongoing_setup'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $result = $diagnosticsService->summarize($validated);

        return response()->view('landlord/onboarding/journey', [
            'filters' => $validated,
            'rows' => (array) ($result['rows'] ?? []),
            'meta' => (array) ($result['meta'] ?? []),
            'setupRows' => $setupStatusService->landlordRows(),
            'setupOptions' => $setupStatusService->options(),
        ]);
    }

    public function updateSetupStatus(
        Request $request,
        Tenant $tenant,
        TenantSetupStatusService $setupStatusService
    ): RedirectResponse {
        $options = $setupStatusService->options();
        $validated = $request->validate([
            'landlord_review_status' => ['required', 'string', Rule::in(array_keys((array) ($options['landlord_review_statuses'] ?? [])))],
            'next_recommended_action' => ['nullable', 'string', 'max:500'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
            'commercial_review_status' => ['nullable', 'string', Rule::in(array_keys((array) ($options['commercial_review_statuses'] ?? [])))],
            'commercial_next_action' => ['nullable', 'string', 'max:500'],
        ]);

        $setupStatusService->updateLandlordStatus($tenant, $validated, $request->user());

        return redirect()
            ->route('landlord.onboarding.journey')
            ->with('status', 'Setup status updated for '.$tenant->name.'.');
    }

    public function updateCommercialIntent(
        Request $request,
        Tenant $tenant,
        TenantSetupStatusService $setupStatusService
    ): RedirectResponse {
        $options = $setupStatusService->options();
        $validated = $request->validate([
            'commercial_review_status' => ['required', 'string', Rule::in(array_keys((array) ($options['commercial_review_statuses'] ?? [])))],
            'commercial_next_action' => ['nullable', 'string', 'max:500'],
            'commercial_notes' => ['nullable', 'string', 'max:5000'],
        ]);

        $status = $setupStatusService->forTenant($tenant);
        $setupStatusService->updateLandlordStatus($tenant, [
            'landlord_review_status' => (string) ($status->landlord_review_status ?: 'pending_review'),
            'next_recommended_action' => (string) ($status->next_recommended_action ?: ''),
            'internal_notes' => (string) ($status->internal_notes ?: ''),
            'commercial_review_status' => (string) $validated['commercial_review_status'],
            'commercial_next_action' => (string) ($validated['commercial_next_action'] ?? ''),
        ], $request->user());

        if (array_key_exists('commercial_notes', $validated)) {
            $status = $setupStatusService->forTenant($tenant);
            $status->forceFill([
                'commercial_notes' => trim((string) $validated['commercial_notes']) !== ''
                    ? str((string) $validated['commercial_notes'])->limit(5000, '')->toString()
                    : null,
            ])->save();
        }

        return redirect()
            ->route('landlord.commercial-intent.index')
            ->with('status', 'Commercial intent updated for '.$tenant->name.'.');
    }
}
