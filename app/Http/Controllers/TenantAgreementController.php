<?php

namespace App\Http\Controllers;

use App\Models\Agreement;
use App\Models\Tenant;
use App\Services\Tenancy\AuthenticatedTenantContextResolver;
use App\Services\Tenancy\TenantFinancialAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TenantAgreementController extends Controller
{
    public function __construct(protected AuthenticatedTenantContextResolver $tenants, protected TenantFinancialAccess $access) {}

    public function index(Request $request): View
    {
        $tenant = $this->tenant($request);
        $agreements = Agreement::query()->forTenant($tenant)->with(['currentVersion', 'acceptance', 'termination'])->whereIn('status', ['accepted', 'active', 'termination_pending', 'terminated'])->latest('id')->get();

        return view('agreements.tenant.index', ['tenant' => $tenant, 'agreements' => $agreements, 'receipts' => $tenant->billingReceipts()->latest('billed_at')->get()]);
    }

    public function show(Request $request, Agreement $agreement): View
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $agreement->tenant_id === (int) $tenant->id && in_array($agreement->status, ['accepted', 'active', 'termination_pending', 'terminated'], true), 404);

        return view('agreements.tenant.show', ['tenant' => $tenant, 'agreement' => $agreement->load(['currentVersion', 'acceptance', 'termination'])]);
    }

    public function download(Request $request, Agreement $agreement): BinaryFileResponse
    {
        $tenant = $this->tenant($request);
        abort_unless((int) $agreement->tenant_id === (int) $tenant->id, 404);
        $acceptance = $agreement->acceptance()->firstOrFail();

        return Storage::disk('local')->download((string) $acceptance->snapshot_path, 'user-agreement-'.$agreement->id.'.html', ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    protected function tenant(Request $request): Tenant
    {
        $user = $request->user();
        $tenant = $user ? $this->tenants->resolveForRequest($request, $user) : null;
        abort_unless($tenant && $this->access->allows($user, $tenant), 403);

        return $tenant;
    }
}
