<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyEmbeddedAiAssistantWorkspaceService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyEmbeddedAiAssistantController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function start(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedAiAssistantWorkspaceService $workspaceService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            workspaceService: $workspaceService,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'start',
            defaultHeadline: 'Start Here',
            defaultSubheadline: 'Begin with tenant-aware AI readiness, setup coverage, and human-reviewed workflow guidance.'
        );
    }

    public function opportunities(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedAiAssistantWorkspaceService $workspaceService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            workspaceService: $workspaceService,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'opportunities',
            defaultHeadline: 'Top Opportunities',
            defaultSubheadline: 'Focus on the highest-impact opportunities before preparing any campaign drafts.'
        );
    }

    public function drafts(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedAiAssistantWorkspaceService $workspaceService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            workspaceService: $workspaceService,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'drafts',
            defaultHeadline: 'Draft Campaigns',
            defaultSubheadline: 'Prepare campaign drafts with clear readiness context. Human review remains required before sending.'
        );
    }

    public function setup(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedAiAssistantWorkspaceService $workspaceService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            workspaceService: $workspaceService,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'setup',
            defaultHeadline: 'Setup',
            defaultSubheadline: 'Confirm the prerequisite setup states that keep AI-assisted workflows accurate and safe.'
        );
    }

    public function activity(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedAiAssistantWorkspaceService $workspaceService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            workspaceService: $workspaceService,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'activity',
            defaultHeadline: 'Activity',
            defaultSubheadline: 'Review recent tenant-aware activity signals that inform assistant recommendations.'
        );
    }

    protected function renderSurface(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedAiAssistantWorkspaceService $workspaceService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService,
        string $activeKey,
        string $defaultHeadline,
        string $defaultSubheadline
    ): Response {
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;

        if ($authorized && $tenantId !== null) {
            $probe->time('alpha_defaults', fn (): array => $alphaBootstrapService->ensureForTenant($tenantId, (string) ($store['key'] ?? '')));
        }

        $probe->forTenant($tenantId);

        $assistantModule = $tenantId !== null
            ? $probe->time('module_access', fn (): array => $moduleAccessResolver->module($tenantId, 'ai'))
            : [
                'module_key' => 'ai',
                'label' => 'AI Assistant',
                'has_access' => false,
                'ui_state' => 'locked',
                'setup_status' => 'not_started',
                'reason' => 'tenant_not_mapped',
            ];
        $hasAssistantAccess = (bool) ($assistantModule['has_access'] ?? false);

        $workspacePayload = ($authorized && $tenantId !== null)
            ? $probe->time('page_payload', fn (): array => $workspaceService->payload($request, $tenantId))
            : [
                'assistant' => [
                    'enabled' => false,
                    'status' => $tenantId === null ? 'tenant_not_mapped' : 'ai_assistant_locked',
                    'state' => [
                        'module_key' => 'ai',
                        'label' => 'AI Assistant',
                        'ui_state' => 'locked',
                        'state_label' => 'Locked',
                        'setup_status_label' => 'Not Started',
                        'description' => 'AI Assistant is not available for this tenant context.',
                    ],
                    'message' => $tenantId === null
                        ? 'This Shopify store is not mapped to a tenant yet.'
                        : 'AI Assistant is not unlocked for this tenant yet. Review plan and module access to continue.',
                ],
                'experience_profile' => [],
                'dashboard' => ['hero' => [], 'summary_cards' => []],
                'top_opportunities' => [],
                'draft_campaigns' => [],
                'setup_items' => [],
                'activity' => ['hero' => [], 'summary_cards' => [], 'quick_actions' => []],
                'human_review_policy' => 'Every draft stays in review mode until a person confirms the final send.',
                'primary_actions' => [
                    ['label' => 'Review Plans', 'href' => route('shopify.app.plans', [], false)],
                ],
            ];

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('assistant', $activeKey, $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->embeddedAssistantSubnav($activeKey, $tenantId));

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.ai-assistant', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')).' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, $defaultHeadline),
                'subheadline' => $this->subheadlineForStatus($status, $defaultSubheadline),
                'appNavigation' => $appNavigation,
                'pageSubnav' => $pageSubnav,
                'pageActions' => [],
                'assistantModuleState' => $assistantModule,
                'assistantAccess' => is_array($workspacePayload['assistant'] ?? null)
                    ? (array) $workspacePayload['assistant']
                    : ['enabled' => $hasAssistantAccess, 'message' => null, 'status' => 'unknown', 'state' => []],
                'assistantPayload' => $workspacePayload,
                'assistantSurface' => $activeKey,
            ]),
            $this->pageStatusCode($authorized, $status, $tenantId, $hasAssistantAccess)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
            'assistant_enabled' => $hasAssistantAccess,
            'assistant_surface' => $activeKey,
        ])->finish($response);
    }

    protected function pageStatusCode(bool $authorized, string $status, ?int $tenantId, bool $hasAssistantAccess): int
    {
        if (! $authorized) {
            return $status === 'open_from_shopify' ? 200 : 401;
        }

        if ($tenantId !== null && ! $hasAssistantAccess) {
            return 403;
        }

        return 200;
    }

    protected function embeddedResponse(Response $response, int $status = 200): Response
    {
        $response->setStatusCode($status);
        $response->headers->set(
            'Content-Security-Policy',
            'frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;'
        );
        $response->headers->remove('X-Frame-Options');

        return $response;
    }

    protected function embeddedProbe(Request $request): ShopifyEmbeddedPerformanceProbe
    {
        /** @var ShopifyEmbeddedPerformanceProbe $probe */
        $probe = app(ShopifyEmbeddedPerformanceProbe::class);

        return $probe->forRequest($request);
    }

    protected function headlineForStatus(string $status, string $defaultHeadline): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this app from Shopify Admin',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'We could not verify this Shopify request',
            default => $defaultHeadline,
        };
    }

    protected function subheadlineForStatus(string $status, string $defaultSubheadline): string
    {
        return match ($status) {
            'open_from_shopify' => 'This page is meant to load inside Shopify Admin so store context and module access state can be verified.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this repeats, verify store app configuration and embedded auth context.',
            default => $defaultSubheadline,
        };
    }
}
