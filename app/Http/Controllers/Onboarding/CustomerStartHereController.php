<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\CustomerAccessRequest;
use App\Models\Tenant;
use App\Services\Tenancy\TenantCommercialExperienceService;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;

class CustomerStartHereController extends Controller
{
    public function show(TenantCommercialExperienceService $experienceService): Response
    {
        /** @var Tenant|null $tenant */
        $tenant = request()->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $accessRequest = null;
        if (Schema::hasTable('customer_access_requests')) {
            $email = strtolower(trim((string) (request()->user()?->email ?? '')));
            if ($email !== '') {
                $accessRequest = CustomerAccessRequest::query()
                    ->where('email', $email)
                    ->where('status', 'approved')
                    ->where(function ($query) use ($tenant): void {
                        $query->whereNull('tenant_id')
                            ->orWhere('tenant_id', (int) $tenant->id);
                    })
                    ->orderByDesc('id')
                    ->first();
            }
        }

        return response()->view('onboarding.start-here', [
            'tenant' => $tenant,
            'journey' => $experienceService->merchantJourneyPayload((int) $tenant->id),
            'plans' => $experienceService->plansPayload((int) $tenant->id),
            'access_request' => $accessRequest,
        ]);
    }
}
