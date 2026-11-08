<?php

namespace App\Http\Controllers;

use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Marketing\CustomerEventValueService;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Team\Studio\EventCommerceReport;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Http\Request;

class TeamCustomerEventValueController extends Controller
{
    public function __invoke(Request $request, string $event)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $input = $request->validate(['area' => 'required|uuid', 'page' => 'sometimes|integer|min:1|max:100000']);
        $access = app(OperationsAccess::class);
        $access->financial($tenant, $request->user());
        $access->area($tenant, $request->user(), $input['area']);
        abort_unless(app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'customers'), 403);
        $record = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $input['area'], 'kind' => 'event'])->findOrFail($event);
        $access->record($tenant, $request->user(), $record);
        $commerce = app(EventCommerceReport::class)->commerceId($record);
        abort_unless($commerce, 422, 'Review the original commerce event association first.');

        return response()->json(app(CustomerEventValueService::class)->forEvent($tenant->id, $commerce, (int) ($input['page'] ?? 1)))
            ->header('Cache-Control', 'private, no-store');
    }
}
