<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Onboarding\BranchSelectionService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BranchSelectionController extends Controller
{
    public function show(Request $request, BranchSelectionService $selection): Response
    {
        $keys = (array) $request->session()->get(BranchSelectionService::SESSION_KEY, []);
        if (is_string($request->query('branch'))) {
            $keys[] = $request->query('branch');
        }

        return response()->view('platform.branch-builder', [
            'modules' => $selection->modules(),
            'summary' => $selection->summary($keys),
            'accountsAvailable' => $request->user() !== null || $selection->accountsAvailable(),
        ]);
    }

    public function save(Request $request, BranchSelectionService $selection): RedirectResponse
    {
        $input = $request->validate([
            'branches' => ['nullable', 'array', 'max:50'],
            'branches.*' => ['string', 'max:80'],
            'continue' => ['nullable', 'boolean'],
        ]);
        $request->session()->put(BranchSelectionService::SESSION_KEY, $selection->normalize((array) ($input['branches'] ?? [])));

        if ($request->boolean('continue')) {
            if (! $request->user() && ! $selection->accountsAvailable()) {
                return redirect()->route('platform.request-access')->with('status', 'Your branches are saved. We can help create your account.');
            }

            return redirect()->route('workspace.first-login');
        }

        return redirect()->route('platform.build')->with('status', 'Your branches are saved.');
    }

    public function guide(Request $request, TenantModuleCatalogService $catalog): Response
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        return response()->view('onboarding.workspace-guide', [
            'catalog' => $catalog->tenantStorePayload((int) $tenant->id, 'direct'),
            'tenant' => $tenant,
        ]);
    }

    public function add(Request $request, BranchSelectionService $selection): RedirectResponse
    {
        $input = $request->validate(['branch' => ['required', 'string', 'max:80']]);
        $keys = $selection->normalize([$input['branch']]);
        $saved = $selection->normalize((array) $request->session()->get(BranchSelectionService::SESSION_KEY, []), false);
        $request->session()->put(BranchSelectionService::SESSION_KEY, array_values(array_unique(array_merge($saved, $keys))));

        return redirect()->route('platform.build')->with('status', 'Branch added to your setup.');
    }
}
