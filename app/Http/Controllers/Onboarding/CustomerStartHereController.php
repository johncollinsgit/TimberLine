<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Http\Response;

class CustomerStartHereController extends Controller
{
    public function show(TenantCommercialExperienceService $experienceService): Response
    {
        /** @var Tenant|null $tenant */
        $tenant = request()->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return response()->view('onboarding.start-here', [
            'tenant' => $tenant,
            'journey' => $experienceService->merchantJourneyPayload((int) $tenant->id),
            'plans' => $experienceService->plansPayload((int) $tenant->id),
        ]);
    }
}

