<?php

namespace App\Livewire\Onboarding;

use App\Models\Tenant;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Illuminate\Support\Str;
use Livewire\Component;

class Wizard extends Component
{
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

        $moduleKeys = array_keys((array) config('module_catalog.modules', []));
        sort($moduleKeys);

        $resolution = app(TenantModuleAccessResolver::class)->resolveForTenant((int) $tenant->id, $moduleKeys);
        $modules = is_array($resolution['modules'] ?? null) ? (array) $resolution['modules'] : [];

        $moduleCards = collect($moduleKeys)
            ->map(static function (string $moduleKey) use ($modules): array {
                $resolved = is_array($modules[$moduleKey] ?? null) ? (array) $modules[$moduleKey] : [];
                $label = trim((string) ($resolved['label'] ?? ''));
                $description = trim((string) ($resolved['description'] ?? ''));

                return [
                    'module_key' => $moduleKey,
                    'label' => $label !== '' ? $label : Str::headline($moduleKey),
                    'description' => $description,
                    'locked' => ! (bool) ($resolved['has_access'] ?? false),
                    'coming_soon' => (bool) ($resolved['coming_soon'] ?? false),
                    'ui_state' => (string) ($resolved['ui_state'] ?? ''),
                    'reason' => (string) ($resolved['reason'] ?? ''),
                ];
            })
            ->values()
            ->all();

        return view('livewire.onboarding.wizard', [
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
        ])->layout('layouts.app', [
            'title' => 'Onboarding',
        ]);
    }
}

