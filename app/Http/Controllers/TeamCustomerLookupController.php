<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\Marketing\TenantCustomerLookup;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Studio\EventCommerceReport;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;

class TeamCustomerLookupController extends Controller
{
    public function __invoke(Request $request, OperationsAccess $access)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $i = $request->validate(['area' => 'required|uuid', 'query' => 'nullable|string|max:120', 'event_id' => 'nullable|integer|min:1', 'page' => 'sometimes|integer|min:1|max:100000']);
        $access->area($tenant, $request->user(), $i['area'], true);
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'customers'), 403);
        $lookup = app(TenantCustomerLookup::class);
        $action = $request->route('action', 'search');
        if ($action === 'events') {
            $result = ['events' => app(EventCustomerCohort::class)->options($tenant->id)];
        } elseif ($action === 'detail') {
            $result = $lookup->details($tenant->id, (int) $request->route('profile'));
        } else {
            $cohort = null;
            if (isset($i['event_id'])) {
                $candidate = app(EventCommerceReport::class)->candidate($tenant->id, (int) $i['event_id']);
                if ($candidate) {
                    try {
                        $access->record($tenant, $request->user(), $candidate);
                    } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                        $candidate = null;
                    }
                }
                $cohort = app(EventCustomerCohort::class)->read($tenant->id, (int) $i['event_id'], $candidate);
            }
            $result = $lookup->search($tenant->id, $i['query'] ?? '', $cohort['profile_ids'] ?? null, (int) ($i['page'] ?? 1));
            if ($cohort) {
                $result['event_context'] = ['event_id' => (int) $i['event_id'], 'relations' => $cohort['relations'], 'review_items' => $cohort['review_items'], 'basis' => $cohort['basis']];
            }
        }

        return response()->json($result)->header('Cache-Control', 'private, no-store');
    }
}
