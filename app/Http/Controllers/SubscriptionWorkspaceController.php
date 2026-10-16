<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionInstallation;
use App\Models\SubscriptionProviderResource;
use App\Models\Tenant;
use App\Services\Subscriptions\SubscriptionAccessService;
use App\Services\Subscriptions\SubscriptionProviderReadService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Saved provider evidence only. Opening this workspace never contacts Shopify. */
class SubscriptionWorkspaceController extends Controller
{
    public function index(Request $request, SubscriptionAccessService $access, SubscriptionProviderReadService $reads): View
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        $access->assertManager((int) $tenant->id, $request->user());
        $filters = $request->validate([
            'installation' => ['nullable', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in(['all', 'active', 'paused', 'cancelled', 'expired', 'failed'])],
        ]);
        $installations = SubscriptionInstallation::query()->forTenantId((int) $tenant->id)
            ->orderByDesc('verified_at')->orderBy('id')->get();
        $installation = isset($filters['installation'])
            ? $installations->firstWhere('id', (int) $filters['installation']) : $installations->first();
        abort_if(isset($filters['installation']) && ! $installation, 404);
        $readiness = $installation ? $reads->readiness((int) $tenant->id, (int) $installation->id) : null;

        $contracts = SubscriptionProviderResource::query()->forTenantId((int) $tenant->id)
            ->where('installation_id', $installation?->id ?? 0)->where('resource_type', 'contract');
        if (! $installation || $installation->status !== 'verified') {
            $contracts->whereRaw('1 = 0');
        }
        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $contracts->whereIn('status', [$status, strtoupper($status)]);
        }
        if (filled($filters['q'] ?? null)) {
            $contracts->where('provider_gid', 'like', '%'.addcslashes($filters['q'], '%_\\').'%');
        }

        return view('subscriptions.workspace', [
            'tenant' => $tenant, 'installations' => $installations, 'installation' => $installation,
            'readiness' => $readiness, 'filters' => $filters, 'status' => $status,
            'contracts' => $contracts->orderByDesc('observed_at')->orderByDesc('id')->paginate(25)->withQueryString(),
        ]);
    }
}
