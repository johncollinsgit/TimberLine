<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Services\Onboarding\OnboardingJourneyDiagnosticsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class LandlordOnboardingJourneyDiagnosticsController extends Controller
{
    public function index(Request $request, OnboardingJourneyDiagnosticsService $diagnosticsService): Response
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
        ]);
    }
}

