<?php

namespace App\Http\Controllers\Billing;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Billing\TenantUsageStatementService;
use Illuminate\Http\Request;

class TenantUsageController extends Controller
{
    public function __invoke(Request $request, TenantUsageStatementService $statements)
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant && $request->user()?->is_active, 403);
        $membership = $request->user()->tenants()->where('tenants.id', $tenant->id)->wherePivot('membership_active', true)->first();
        abort_unless($membership && in_array($membership->pivot->role, ['owner', 'tenant_owner', 'admin'], true), 403);
        $statement = $statements->snapshot($tenant);

        return response()->view('billing.usage', compact('tenant', 'statement'))->header('Cache-Control', 'private, no-store');
    }
}
