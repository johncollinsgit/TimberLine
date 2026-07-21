<?php

namespace App\Services\Tenancy;

use Illuminate\Support\Collection;

class TenantModuleActivationPolicyService
{
    public const POLICIES = [
        'baseline_auto',
        'template_recommended',
        'integration_required',
        'operator_only',
        'internal_only',
    ];

    /** @return array<string,mixed> */
    public function module(string $moduleKey, ?string $templateKey = null, ?string $planKey = null): array
    {
        $definition = (array) config('module_catalog.modules.'.$moduleKey, []);
        $policy = (string) ($definition['activation_policy'] ?? config('module_catalog.defaults.activation_policy', 'operator_only'));
        if (! in_array($policy, self::POLICIES, true)) {
            $policy = 'operator_only';
        }
        $recommended = collect((array) config('tenant_blueprints.templates.'.$templateKey.'.recommended_modules', []))
            ->map(fn ($key): string => strtolower(trim((string) $key)))
            ->contains($moduleKey);
        $included = $planKey !== null && in_array($planKey, (array) ($definition['included_in_plans'] ?? []), true);

        return [
            'module_key' => $moduleKey,
            'policy' => $policy,
            'recommended' => $recommended,
            'included_in_plan' => $included,
            'auto_activate' => $policy === 'baseline_auto' && $included && (bool) ($definition['default_enabled'] ?? false),
            'requires_operator_approval' => in_array($policy, ['template_recommended', 'integration_required', 'operator_only'], true),
            'requires_integration_readiness' => $policy === 'integration_required',
            'tenant_visible' => $policy !== 'internal_only',
        ];
    }

    /** @return Collection<int,array<string,mixed>> */
    public function forTemplate(?string $templateKey, ?string $planKey): Collection
    {
        $definitions = (array) config('module_catalog.modules', []);

        return collect(array_keys($definitions))
            ->map(fn (string $moduleKey): array => $this->module($moduleKey, $templateKey, $planKey))
            ->filter(fn (array $decision): bool => $decision['auto_activate'] || $decision['recommended'])
            ->values();
    }
}
