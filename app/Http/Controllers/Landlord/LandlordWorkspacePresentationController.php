<?php

namespace App\Http\Controllers\Landlord;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Tenancy\WorkspacePresentationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LandlordWorkspacePresentationController extends Controller
{
    public function update(Request $request, Tenant $tenant, WorkspacePresentationService $presentation): RedirectResponse
    {
        abort_unless($presentation->canEdit($request->user()), 403);
        $data = $request->validate([
            'template' => ['required', Rule::in(array_keys((array) config('workspace_presentation.templates')))],
            'summary' => ['nullable', 'string', 'max:190'],
        ]);
        $presentation->save($tenant, $request->user(), $data['template'], $data['summary'] ?? null);

        return redirect()->route('landlord.tenants.show', ['tenant' => $tenant->id, 'tab' => 'settings'])
            ->with('status', 'Workspace presentation saved.');
    }
}
