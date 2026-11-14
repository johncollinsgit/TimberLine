<?php

namespace App\Http\Controllers\Onboarding;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use App\Services\Onboarding\BranchSelectionService;
use App\Services\Onboarding\SelfServiceTemplateSelectionService;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class BranchSelectionController extends Controller
{
    public function show(Request $request, BranchSelectionService $selection, ?SelfServiceTemplateSelectionService $templates = null): Response
    {
        $templates ??= app(SelfServiceTemplateSelectionService::class);
        $keys = (array) $request->session()->get(BranchSelectionService::SESSION_KEY, []);
        $templateKey = $request->session()->get(SelfServiceTemplateSelectionService::SESSION_KEY, data_get($request->user()?->onboarding_guide_answers, 'business_template'));
        try {
            $selectedTemplate = $templates->normalize(is_string($templateKey) ? $templateKey : null);
        } catch (\Illuminate\Validation\ValidationException) {
            $selectedTemplate = null;
        }
        if ($keys === [] && $selectedTemplate !== null) {
            $keys = $templates->branches($selectedTemplate);
        }
        if (is_string($request->query('branch'))) {
            $keys[] = $request->query('branch');
        }

        return response()->view('platform.branch-builder', [
            'modules' => $selection->modules(),
            'summary' => $selection->summary($keys),
            'accountsAvailable' => $request->user() !== null || $selection->accountsAvailable(),
            'templates' => $templates->templates(), 'selectedTemplate' => $selectedTemplate,
        ]);
    }

    public function save(Request $request, BranchSelectionService $selection, SelfServiceTemplateSelectionService $templates): RedirectResponse
    {
        $input = $request->validate([
            'branches' => ['nullable', 'array', 'max:50'],
            'branches.*' => ['string', 'max:80'],
            'continue' => ['nullable', 'boolean'],
            'template' => ['nullable', 'string', 'max:80'],
        ]);
        $templateKey = $templates->normalize($input['template'] ?? null);
        $branches = $templateKey !== null ? $templates->branches($templateKey) : $selection->normalize((array) ($input['branches'] ?? []));
        $request->session()->put(BranchSelectionService::SESSION_KEY, $branches);
        if ($templateKey !== null) {
            $request->session()->put(SelfServiceTemplateSelectionService::SESSION_KEY, $templateKey);
        }

        if ($request->user()) {
            \Illuminate\Support\Facades\DB::transaction(function () use ($request, $templateKey): void {
                $user = \App\Models\User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $user->forceFill(['onboarding_guide_answers' => [
                    ...(array) $user->onboarding_guide_answers,
                    'branch_selection' => (array) $request->session()->get(BranchSelectionService::SESSION_KEY, []),
                    ...($templateKey !== null ? ['business_template' => $templateKey] : []),
                ]])->save();
            });
        }

        if ($request->boolean('continue')) {
            if ($request->user()) {
                $tenant = $request->user()->tenants()->where('tenants.id', (int) $request->session()->get('tenant_id'))->first()
                    ?? $request->user()->tenants()->orderBy('tenants.id')->first();
                if ($tenant) {
                    $profile = \App\Models\TenantAccessProfile::query()->where('tenant_id', $tenant->id)->first();

                    return redirect()->route(data_get($profile?->metadata, 'self_service_registration') === true ? 'self-service.purchase' : 'workspace.guide', ['tenant' => $tenant->slug]);
                }
            }
            if (! $request->user() && config('self_service.registration_enabled')) {
                return redirect()->route('self-service.signup');
            }
            if (! $request->user() && ! $selection->accountsAvailable()) {
                return redirect()->route('platform.request-access')->with('status', 'Your branches are saved. We can help create your account.');
            }

            return redirect()->route('workspace.first-login');
        }

        return redirect()->route('platform.build')->with('status', $templateKey !== null ? 'Your template is saved.' : 'Your branches are saved.');
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
