<?php

namespace App\Livewire\Onboarding;

use App\Models\Tenant;
use App\Services\Tenancy\TenantModuleCatalogService;
use Illuminate\Support\Str;
use Livewire\Component;

class Wizard extends Component
{
    public string $surface = 'page';

    public ?string $completionRedirectUrl = null;

    public function render()
    {
        /** @var Tenant|null $tenant */
        $tenant = request()->attributes->get('current_tenant');
        abort_unless($tenant instanceof Tenant, 403);

        $tenantToken = trim((string) request()->query('tenant', $tenant->slug ?? ''));
        if ($tenantToken === '') {
            $tenantToken = (string) $tenant->id;
        }

        $requestedRail = strtolower(trim((string) request()->query('rail', '')));
        $contractUrlParams = array_filter([
            'tenant' => $tenantToken,
            'rail' => $requestedRail !== '' ? $requestedRail : null,
        ], static fn (mixed $value): bool => $value !== null && trim((string) $value) !== '');

        $contractUrl = route('onboarding.api.contract', $contractUrlParams);
        $autosaveUrl = route('onboarding.api.draft.autosave', ['tenant' => $tenantToken]);
        $finalizeUrl = route('onboarding.api.blueprint.finalize', ['tenant' => $tenantToken]);
        $postProvisioningSummaryUrl = route('onboarding.api.blueprint.post-provisioning-summary', ['tenant' => $tenantToken]);

        $provisionUrl = config('features.internal_onboarding_provisioning', false)
            ? route('onboarding.api.blueprint.provision-production', ['tenant' => $tenantToken])
            : null;

        $canProvision = (bool) config('features.internal_onboarding_provisioning', false)
            && (auth()->user()?->isAdmin() ?? false);

        $surface = strtolower(trim((string) ($this->surface ?: 'page')));
        $isModalSurface = $surface === 'modal';

        $catalogPayload = app(TenantModuleCatalogService::class)->tenantStorePayload((int) $tenant->id, 'public_site');
        $modules = array_values((array) ($catalogPayload['modules'] ?? []));
        $isLandlordProvisioning = request()->routeIs('landlord.onboarding.wizard');

        $moduleCards = collect($modules)
            ->map(static function (array $module): array {
                $moduleKey = strtolower(trim((string) ($module['module_key'] ?? '')));
                $label = trim((string) ($module['display_name'] ?? ''));
                $description = trim((string) ($module['description'] ?? ''));
                $stateBucket = strtolower(trim((string) ($module['state_bucket'] ?? 'request')));

                return [
                    'module_key' => $moduleKey,
                    'label' => $label !== '' ? $label : Str::headline($moduleKey),
                    'description' => $description,
                    'locked' => ! in_array($stateBucket, ['active', 'available'], true),
                    'coming_soon' => in_array(strtolower(trim((string) data_get($module, 'module_state.ui_state', ''))), ['coming_soon', 'roadmap'], true)
                        || in_array(strtolower(trim((string) ($module['status'] ?? ''))), ['placeholder', 'roadmap'], true),
                    'ui_state' => (string) data_get($module, 'module_state.ui_state', ''),
                    'reason' => (string) data_get($module, 'module_state.reason', ''),
                ];
            })
            ->values()
            ->all();

        $view = view('livewire.onboarding.wizard', [
            'surface' => $surface,
            'isModalSurface' => $isModalSurface,
            'tenantToken' => $tenantToken,
            'tenantId' => (int) $tenant->id,
            'tenantName' => (string) ($tenant->name ?? ''),
            'contractUrl' => $contractUrl,
            'autosaveUrl' => $autosaveUrl,
            'finalizeUrl' => $finalizeUrl,
            'postProvisioningSummaryUrl' => $postProvisioningSummaryUrl,
            'provisionUrl' => $provisionUrl,
            'canProvision' => $canProvision,
            'requestedRail' => $requestedRail !== '' ? $requestedRail : null,
            'moduleCards' => $moduleCards,
            'wizardEyebrow' => $isModalSurface
                ? 'Electrician onboarding'
                : ($isLandlordProvisioning ? 'Landlord Provisioning' : 'Workspace Blueprint'),
            'wizardTitle' => $isModalSurface
                ? 'Set up your electrician workspace'
                : ($isLandlordProvisioning ? 'Provision a Tenant' : 'Create Tenant Blueprint'),
            'wizardSubtitle' => $isModalSurface
                ? 'Three quick steps: pick the electrician template, choose a few safe modules, and confirm the setup.'
                : ($isLandlordProvisioning
                ? 'Build a tenant blueprint from a few answers. Tenant creation, access, modules, and billing remain landlord-controlled and guarded.'
                : 'Create or revise the tenant setup blueprint. Customer-facing setup status lives in Start Here.'),
            'completionRedirectUrl' => $this->completionRedirectUrl,
        ]);

        if (! $isModalSurface) {
            $view->layout('layouts.app', [
                'title' => $isLandlordProvisioning ? 'Provision a Tenant' : 'Create Tenant Blueprint',
            ]);
        }

        return $view;
    }
}
