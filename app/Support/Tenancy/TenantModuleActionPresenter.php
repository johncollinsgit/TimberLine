<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Facades\Route;

class TenantModuleActionPresenter
{
    /**
     * @var array<string,array{label:string,description:string}>
     */
    private const REASON_META = [
        'enabled' => [
            'label' => 'Enabled',
            'description' => 'This module is currently enabled for the tenant.',
        ],
        'setup_required' => [
            'label' => 'Setup required',
            'description' => 'This module is enabled, but setup still needs to be completed.',
        ],
        'add_on_required' => [
            'label' => 'Available as an add-on',
            'description' => 'This module is available to add from the module catalog.',
        ],
        'plan_upgrade_required' => [
            'label' => 'Upgrade required',
            'description' => 'This module is available on a higher plan tier.',
        ],
        'contact_sales_required' => [
            'label' => 'Contact sales',
            'description' => 'This module requires manual sales or contract enablement.',
        ],
        'rollout_pending' => [
            'label' => 'Rollout pending',
            'description' => 'This module is visible for planning, but it is not live for self-serve activation yet.',
        ],
        'channel_not_supported' => [
            'label' => 'Channel not supported',
            'description' => 'This module is not supported for the current tenant channel or operating mode.',
        ],
        'module_unavailable' => [
            'label' => 'Unavailable',
            'description' => 'This module is not available for this tenant right now.',
        ],
        'dependency_not_enabled' => [
            'label' => 'Dependency required',
            'description' => 'A prerequisite module must be enabled before this module can be used.',
        ],
        'disabled_by_override' => [
            'label' => 'Disabled by override',
            'description' => 'This module is explicitly disabled for the tenant.',
        ],
        'disabled_by_entitlement' => [
            'label' => 'Disabled by entitlement',
            'description' => 'This module is explicitly disabled in the entitlement layer.',
        ],
        'not_enabled' => [
            'label' => 'Not enabled',
            'description' => 'This module is not currently enabled for the tenant.',
        ],
    ];

    /**
     * @return array<string,mixed>
     */
    public static function present(?array $moduleState, ?string $fallbackLabel = null, array $options = []): array
    {
        $presented = TenantModuleUi::present($moduleState, $fallbackLabel);
        $state = is_array($moduleState) ? $moduleState : [];
        $reason = strtolower(trim((string) ($state['reason'] ?? (($presented['ui_state'] ?? '') === 'coming_soon' ? 'rollout_pending' : 'not_enabled'))));
        $source = strtolower(trim((string) ($state['source'] ?? 'flag')));
        $cta = strtolower(trim((string) ($state['cta'] ?? 'none')));
        $reasonMeta = self::REASON_META[$reason] ?? self::REASON_META['not_enabled'];
        $ctaMeta = self::ctaMeta($cta, $reason, $presented['label'] ?? 'Module');

        return array_merge($presented, [
            'enabled' => (bool) ($state['enabled'] ?? $state['has_access'] ?? false),
            'reason' => $reason,
            'reason_label' => $reasonMeta['label'],
            'reason_description' => $reasonMeta['description'],
            'source' => in_array($source, ['plan', 'addon', 'override', 'flag'], true) ? $source : 'flag',
            'cta' => $cta,
            'cta_label' => $ctaMeta['label'],
            'cta_description' => $ctaMeta['description'],
            'cta_target' => $ctaMeta['target'],
            'cta_href' => self::ctaHref($cta, (string) ($presented['module_key'] ?? ''), $options),
            'self_serve_eligible' => $cta === 'add',
            'billing_mode' => strtolower(trim((string) ($state['billing_mode'] ?? 'unavailable'))),
            'market_state' => strtoupper(trim((string) ($state['market_state'] ?? 'INTERNAL_ONLY'))),
            'visibility' => is_array($state['visibility'] ?? null) ? (array) $state['visibility'] : [],
        ]);
    }

    /**
     * @return array{label:string,description:string,target:string}
     */
    protected static function ctaMeta(string $cta, string $reason, string $label): array
    {
        return match ($cta) {
            'add' => [
                'label' => 'Add module',
                'description' => 'Activate this module for the tenant.',
                'target' => 'app_store',
            ],
            'upgrade' => [
                'label' => 'Upgrade plan',
                'description' => 'Review the plan level needed to unlock this module.',
                'target' => 'app_store',
            ],
            'request' => [
                'label' => $reason === 'contact_sales_required' ? 'Contact sales' : 'Request access',
                'description' => $reason === 'contact_sales_required'
                    ? 'This module needs a manual sales or contract step.'
                    : 'Request access for '.$label.'.',
                'target' => $reason === 'contact_sales_required' ? 'contact' : 'app_store',
            ],
            default => [
                'label' => '',
                'description' => '',
                'target' => 'none',
            ],
        };
    }

    protected static function ctaHref(string $cta, string $moduleKey, array $options): ?string
    {
        $moduleKey = strtolower(trim($moduleKey));
        if ($cta === 'none' || $moduleKey === '') {
            return null;
        }

        $storeRoute = self::routeName($options, 'store_route', ['shopify.app.store', 'marketing.modules']);
        $plansRoute = self::routeName($options, 'plans_route', ['shopify.app.plans']);
        $contactRoute = self::routeName($options, 'contact_route', ['platform.contact']);

        return match ($cta) {
            'add' => $storeRoute ? route($storeRoute, ['module' => $moduleKey, 'intent' => 'add'], false) : null,
            'upgrade' => $storeRoute
                ? route($storeRoute, ['module' => $moduleKey, 'intent' => 'upgrade'], false)
                : ($plansRoute ? route($plansRoute, ['module' => $moduleKey], false) : null),
            'request' => $storeRoute
                ? route($storeRoute, ['module' => $moduleKey, 'intent' => 'request'], false)
                : ($contactRoute ? route($contactRoute, ['module' => $moduleKey, 'intent' => 'module_access'], false) : null),
            default => null,
        };
    }

    /**
     * @param  array<int,string>  $fallbackRoutes
     */
    protected static function routeName(array $options, string $key, array $fallbackRoutes): ?string
    {
        $candidate = trim((string) ($options[$key] ?? ''));
        if ($candidate !== '' && Route::has($candidate)) {
            return $candidate;
        }

        foreach ($fallbackRoutes as $routeName) {
            if (Route::has($routeName)) {
                return $routeName;
            }
        }

        return null;
    }
}
