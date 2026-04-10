<?php

namespace App\Services\Shopify;

use App\Models\Tenant;
use App\Services\Dashboard\UnifiedDashboardService;
use App\Services\Navigation\UnifiedAppNavigationService;
use App\Services\Tenancy\TenantExperienceProfileService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantModuleCatalogService;
use App\Support\Tenancy\TenantModuleUi;
use Illuminate\Http\Request;

class ShopifyEmbeddedAiAssistantWorkspaceService
{
    public function __construct(
        protected TenantModuleAccessResolver $moduleAccessResolver,
        protected TenantExperienceProfileService $experienceProfileService,
        protected UnifiedAppNavigationService $unifiedNavigationService,
        protected UnifiedDashboardService $unifiedDashboardService,
        protected TenantModuleCatalogService $moduleCatalogService
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(Request $request, ?int $tenantId): array
    {
        $user = $request->user();

        $assistantModule = $this->moduleAccessResolver->module($tenantId, 'ai');
        $assistantState = TenantModuleUi::present($assistantModule, 'AI Assistant');
        $assistantEnabled = (bool) ($assistantModule['has_access'] ?? false);

        $experienceProfile = $this->experienceProfileService->forTenant($tenantId, $user);
        $scopedRequest = $this->scopedRequest($request, $tenantId, $user);
        $dashboard = $this->unifiedDashboardService->forRequest($scopedRequest, $user);
        $navigation = $this->unifiedNavigationService->build($scopedRequest, $user);
        $catalog = $tenantId !== null
            ? $this->moduleCatalogService->tenantStorePayload($tenantId, 'shopify')
            : ['sections' => []];

        return [
            'tenant_id' => $tenantId,
            'assistant' => [
                'enabled' => $assistantEnabled,
                'state' => $assistantState,
                'status' => $tenantId === null
                    ? 'tenant_not_mapped'
                    : ($assistantEnabled ? 'enabled' : 'ai_assistant_locked'),
                'message' => $tenantId === null
                    ? 'This Shopify store is not mapped to a tenant yet.'
                    : ($assistantEnabled
                        ? null
                        : 'AI Assistant is not unlocked for this tenant yet. Review plan and module access to continue.'),
            ],
            'experience_profile' => $experienceProfile,
            'dashboard' => [
                'hero' => is_array($dashboard['hero'] ?? null) ? (array) $dashboard['hero'] : [],
                'summary_cards' => array_values(array_slice((array) ($dashboard['summary_cards'] ?? []), 0, 4)),
            ],
            'top_opportunities' => $this->topOpportunities($dashboard, $catalog),
            'draft_campaigns' => $this->draftCampaigns($tenantId),
            'setup_items' => $this->setupItems($tenantId),
            'activity' => $this->activityItems($dashboard, $navigation),
            'human_review_policy' => 'Every draft stays in review mode until a person confirms the final send.',
            'primary_actions' => [
                [
                    'label' => 'Open Start Here',
                    'href' => route('shopify.app.assistant.start', [], false),
                ],
                [
                    'label' => 'Open Setup',
                    'href' => route('shopify.app.assistant.setup', [], false),
                ],
                [
                    'label' => 'Review Plans',
                    'href' => route('shopify.app.plans', [], false),
                ],
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $dashboard
     * @param  array<string,mixed>  $catalog
     * @return array<int,array<string,mixed>>
     */
    protected function topOpportunities(array $dashboard, array $catalog): array
    {
        $dashboardActions = collect((array) ($dashboard['next_actions'] ?? []))
            ->map(function (array $action): array {
                return [
                    'title' => trim((string) ($action['label'] ?? 'Opportunity')),
                    'description' => trim((string) ($action['description'] ?? '')),
                    'href' => isset($action['href']) ? trim((string) $action['href']) : null,
                    'tone' => strtolower(trim((string) ($action['tone'] ?? 'neutral'))),
                    'source' => 'workspace',
                ];
            })
            ->filter(fn (array $action): bool => ($action['title'] ?? '') !== '')
            ->values();

        $catalogRows = collect(array_merge(
            (array) data_get($catalog, 'sections.available', []),
            (array) data_get($catalog, 'sections.upgrade', []),
            (array) data_get($catalog, 'sections.request', [])
        ))
            ->map(function (array $row): array {
                $moduleName = trim((string) ($row['display_name'] ?? 'Module'));
                $stateLabel = trim((string) data_get($row, 'module_state.state_label', 'Locked'));
                $reasonDescription = trim((string) data_get($row, 'module_state.reason_description', 'Review module access and setup state.'));

                return [
                    'title' => $moduleName,
                    'description' => $stateLabel !== ''
                        ? sprintf('%s. %s', $stateLabel, $reasonDescription)
                        : $reasonDescription,
                    'href' => data_get($row, 'module_state.cta_href'),
                    'tone' => 'info',
                    'source' => 'module_catalog',
                ];
            })
            ->values();

        $rows = $dashboardActions
            ->concat($catalogRows)
            ->unique(fn (array $row): string => strtolower(trim((string) ($row['title'] ?? '')).'|'.trim((string) ($row['source'] ?? ''))))
            ->take(5)
            ->values()
            ->all();

        if ($rows !== []) {
            return $rows;
        }

        return [[
            'title' => 'Review AI readiness',
            'description' => 'Confirm setup and module access before drafting campaigns.',
            'href' => route('shopify.app.assistant.setup', [], false),
            'tone' => 'neutral',
            'source' => 'fallback',
        ]];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function draftCampaigns(?int $tenantId): array
    {
        $resolved = $this->moduleAccessResolver->resolveForTenant($tenantId, ['campaigns', 'messaging', 'customers']);
        $states = is_array($resolved['modules'] ?? null) ? (array) $resolved['modules'] : [];

        $draftDefinitions = [
            [
                'key' => 'returning_customers',
                'title' => 'Welcome Back Sequence',
                'description' => 'Reconnect with recent buyers who have not purchased in the last 30 days.',
                'module_key' => 'campaigns',
            ],
            [
                'key' => 'high_value_follow_up',
                'title' => 'High-Value Follow-Up',
                'description' => 'Draft outreach for high-value customers ready for a personalized offer.',
                'module_key' => 'messaging',
            ],
            [
                'key' => 'new_customer_nurture',
                'title' => 'New Customer Nurture',
                'description' => 'Prepare a first-month nurture draft for new customer cohorts.',
                'module_key' => 'customers',
            ],
        ];

        return array_map(function (array $draft) use ($states): array {
            $moduleKey = strtolower(trim((string) ($draft['module_key'] ?? '')));
            $state = is_array($states[$moduleKey] ?? null)
                ? TenantModuleUi::present((array) $states[$moduleKey], ucfirst(str_replace('_', ' ', $moduleKey)))
                : TenantModuleUi::present([
                    'module_key' => $moduleKey,
                    'label' => ucfirst(str_replace('_', ' ', $moduleKey)),
                    'ui_state' => 'locked',
                    'setup_status' => 'not_started',
                    'has_access' => false,
                ]);

            $status = match ($state['ui_state']) {
                'active' => 'Ready for Review',
                'setup_needed' => 'Needs Setup',
                'coming_soon' => 'Coming Soon',
                default => 'Locked',
            };

            return [
                'key' => (string) ($draft['key'] ?? ''),
                'title' => (string) ($draft['title'] ?? 'Draft Campaign'),
                'description' => (string) ($draft['description'] ?? ''),
                'status' => $status,
                'module_state' => $state,
                'review_note' => 'Requires human review before any send can be initiated.',
                'href' => route('shopify.app.assistant.opportunities', [], false),
            ];
        }, $draftDefinitions);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function setupItems(?int $tenantId): array
    {
        $setupKeys = ['ai', 'customers', 'messaging', 'campaigns', 'integrations'];
        $resolved = $this->moduleAccessResolver->resolveForTenant($tenantId, $setupKeys);
        $states = is_array($resolved['modules'] ?? null) ? (array) $resolved['modules'] : [];

        $rows = [];
        foreach ($setupKeys as $moduleKey) {
            $state = is_array($states[$moduleKey] ?? null)
                ? TenantModuleUi::present((array) $states[$moduleKey], ucfirst(str_replace('_', ' ', $moduleKey)))
                : null;

            if (! is_array($state)) {
                continue;
            }

            $rows[] = [
                'module_key' => $moduleKey,
                'label' => (string) ($state['label'] ?? ucfirst(str_replace('_', ' ', $moduleKey))),
                'state_label' => (string) ($state['state_label'] ?? 'Locked'),
                'setup_status_label' => (string) ($state['setup_status_label'] ?? 'Not Started'),
                'description' => (string) ($state['description'] ?? ''),
                'module_state' => $state,
                'href' => route('shopify.app.assistant.start', [], false),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string,mixed>  $dashboard
     * @param  array<string,mixed>  $navigation
     * @return array<string,mixed>
     */
    protected function activityItems(array $dashboard, array $navigation): array
    {
        $hero = is_array($dashboard['hero'] ?? null) ? (array) $dashboard['hero'] : [];
        $summaryCards = collect((array) ($dashboard['summary_cards'] ?? []))
            ->take(3)
            ->map(function (array $card): array {
                return [
                    'label' => trim((string) ($card['label'] ?? 'Metric')),
                    'value' => trim((string) ($card['value'] ?? '0')),
                    'detail' => trim((string) ($card['detail'] ?? '')),
                ];
            })
            ->values()
            ->all();

        $quickActions = collect((array) ($navigation['quick_actions'] ?? []))
            ->take(4)
            ->map(function (array $action): array {
                return [
                    'label' => trim((string) ($action['label'] ?? 'Action')),
                    'description' => trim((string) ($action['description'] ?? '')),
                    'href' => isset($action['href']) ? trim((string) $action['href']) : null,
                ];
            })
            ->filter(fn (array $action): bool => ($action['label'] ?? '') !== '')
            ->values()
            ->all();

        return [
            'hero' => [
                'label' => trim((string) ($hero['label'] ?? 'Workspace readiness')),
                'value' => trim((string) ($hero['value'] ?? 'Ready')),
                'supporting' => trim((string) ($hero['supporting'] ?? '')),
                'tone' => strtolower(trim((string) ($hero['tone'] ?? 'neutral'))),
            ],
            'summary_cards' => $summaryCards,
            'quick_actions' => $quickActions,
        ];
    }

    protected function scopedRequest(Request $request, ?int $tenantId, mixed $user): Request
    {
        $scoped = clone $request;
        $scoped->setUserResolver(static fn () => $user);

        if ($tenantId === null) {
            return $scoped;
        }

        $tenant = $request->attributes->get('current_tenant');
        if (! $tenant instanceof Tenant || (int) $tenant->id !== $tenantId) {
            $tenant = Tenant::query()->find($tenantId);
        }

        if ($tenant instanceof Tenant) {
            $scoped->attributes->set('current_tenant', $tenant);
        }

        return $scoped;
    }
}
