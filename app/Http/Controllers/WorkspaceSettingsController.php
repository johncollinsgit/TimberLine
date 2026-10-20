<?php

namespace App\Http\Controllers;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Onboarding\WorkspaceSetupGuide;
use App\Services\Tenancy\TenantBlueprintProfileService;
use App\Services\Tenancy\WorkspaceSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WorkspaceSettingsController extends Controller
{
    private function tenant(Request $request): Tenant
    {
        $tenant = $request->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);
        app(WorkspaceSettingsService::class)->authorize($tenant, $request->user());
        abort_if($request->has('tenant_id') && (string) $request->input('tenant_id') !== (string) $tenant->id, 403);

        return $tenant;
    }

    public function index(Request $request, WorkspaceSettingsService $settings)
    {
        $tenant = $this->tenant($request);
        $input = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'category' => ['nullable', Rule::in(array_keys($settings->categories()))]]);
        $catalog = $settings->catalog($tenant, $request->user());

        return view('workspace-settings.index', ['tenant' => $tenant, 'categories' => $settings->categories(),
            'category' => $input['category'] ?? '', 'query' => trim($input['q'] ?? ''),
            'results' => $settings->search($catalog, $input['q'] ?? '', $input['category'] ?? '')]);
    }

    public function business(Request $request, TenantBlueprintProfileService $profiles, WorkspaceSetupGuide $guide)
    {
        $tenant = $this->tenant($request);
        abort_unless(app(WorkspaceSettingsService::class)->canManage($tenant, $request->user()), 403);
        $context = $profiles->companyContext($tenant);
        $suggested = $context;
        if ($context === []) {
            $template = (string) data_get($tenant->accessProfile?->metadata, 'tenant_blueprint.business_template', 'generic');
            $suggested['category'] = in_array($template, ['electrician', 'landscaping'], true) || str_starts_with($template, 'trades_') ? 'field_service' : (str_contains($template, 'retail') || str_contains($template, 'maker') || $template === 'apparel' ? 'retail' : (str_contains($template, 'school') || str_contains($template, 'coop') ? 'education' : ''));
            if (in_array($template, ['electrician', 'trades_electrical'], true)) {
                $suggested['specialty'] = 'Electrical';
            }
        }
        $step = $request->query('step', empty($context['category']) ? 'business' : (empty($context['model']) ? 'model' : (empty($context['confirmed_at']) ? 'goal' : 'business')));
        abort_unless(in_array($step, ['business', 'model', 'goal'], true), 404);

        return view('workspace-settings.business', ['tenant' => $tenant, 'profile' => $suggested, 'revision' => $profiles->companyContextRevision($tenant),
            'step' => $step, 'options' => config('workspace_setup'), 'goals' => $guide->goals($tenant, $request->user()), 'canSave' => $tenant->accessProfile !== null]);
    }

    public function saveBusiness(Request $request, TenantBlueprintProfileService $profiles, WorkspaceSetupGuide $guide)
    {
        $tenant = $this->tenant($request);
        abort_unless(app(WorkspaceSettingsService::class)->canManage($tenant, $request->user()), 403);
        $validated = $request->validate(['step' => ['required', Rule::in(['business', 'model', 'goal'])], 'revision' => ['required', 'string', 'size:64']]);
        $step = $validated['step'];
        $rules = match ($step) {
            'business' => ['category' => ['required', Rule::in(array_keys(config('workspace_setup.categories')))], 'specialty' => ['nullable', 'string', 'max:80'], 'description' => ['nullable', 'string', 'max:300']],
            'model' => ['model' => ['required', Rule::in(array_keys(config('workspace_setup.models')))], 'channels' => ['nullable', 'array', 'max:4'], 'channels.*' => [Rule::in(array_keys(config('workspace_setup.channels')))], 'customer_types' => ['nullable', 'array', 'max:2'], 'customer_types.*' => [Rule::in(array_keys(config('workspace_setup.customer_types')))]],
            default => ['goal' => ['nullable', Rule::in(array_keys($guide->goals($tenant, $request->user())))]],
        };
        $input = $request->validate($rules);
        if ($step === 'model') {
            $input['channels'] = array_values(array_unique($input['channels'] ?? []));
            $input['customer_types'] = array_values(array_unique($input['customer_types'] ?? []));
        }
        $context = [...$profiles->companyContext($tenant), ...$input];
        if ($step === 'goal' && (empty($context['category']) || empty($context['model']))) {
            throw ValidationException::withMessages(['profile' => 'Complete the business and operating-model steps before confirming your profile.']);
        }
        $profiles->saveCompanyContext($tenant, $request->user(), $context, $validated['revision'], $step === 'goal');
        $next = match ($step) {
            'business' => 'model', 'model' => 'goal', default => null
        };

        return $next ? redirect()->route('workspace-settings.business', ['tenant' => $tenant->slug, 'step' => $next])->with('status', 'Progress saved. You can return whenever you are ready.')
            : redirect()->route('workspace-settings.setup', ['tenant' => $tenant->slug])->with('status', 'Business profile saved. Your records, branches, permissions and agreements have not changed.');
    }

    public function setup(Request $request, WorkspaceSetupGuide $guide)
    {
        $tenant = $this->tenant($request);

        return view('workspace-settings.setup', ['tenant' => $tenant, 'guide' => $guide->forTenant($tenant, $request->user())]);
    }

    public function preferences(Request $request, WorkspaceSetupGuide $guide)
    {
        $tenant = $this->tenant($request);
        $optional = collect($guide->forTenant($tenant, $request->user())['steps'])->where('required', false)->pluck('id')->all();
        $input = $request->validate(['hidden' => ['sometimes', 'boolean'], 'dismissed_steps' => ['sometimes', 'array'], 'dismissed_steps.*' => [Rule::in($optional)]]);
        if ($request->boolean('dismissed_steps_present')) {
            $input['dismissed_steps'] = $input['dismissed_steps'] ?? [];
        }
        DB::transaction(function () use ($request, $tenant, $input): void {
            $user = User::query()->whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            $answers = (array) $user->onboarding_guide_answers;
            $path = 'workspace_guidance.'.$tenant->id;
            data_set($answers, $path, [...(array) data_get($answers, $path, []), ...$input]);
            $user->onboarding_guide_answers = $answers;
            $user->save();
        });

        return back()->with('status', 'Your guidance preference was saved. Organization progress is unchanged.');
    }
}
