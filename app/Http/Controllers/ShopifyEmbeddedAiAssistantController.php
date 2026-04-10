<?php

namespace App\Http\Controllers;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignVariant;
use App\Models\MarketingRecommendation;
use App\Models\MarketingSendApproval;
use App\Services\Marketing\MarketingTenantOwnershipService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Shopify\ShopifyEmbeddedUrlGenerator;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use App\Support\Tenancy\TenantModuleActionPresenter;
use App\Support\Tenancy\TenantModuleUi;
use Illuminate\Http\RedirectResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ShopifyEmbeddedAiAssistantController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function __construct(
        protected MarketingTenantOwnershipService $marketingOwnershipService,
        protected ShopifyEmbeddedUrlGenerator $urlGenerator
    ) {
    }

    public function start(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'start'
        );
    }

    public function opportunities(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'opportunities'
        );
    }

    public function drafts(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'drafts'
        );
    }

    public function createDraftFromRecommendation(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): RedirectResponse {
        $resolved = $this->resolveDraftMutationContext(
            $request,
            $contextService,
            $tenantResolver,
            $moduleAccessResolver,
            $alphaBootstrapService
        );
        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $tenantId = (int) $resolved['tenant_id'];
        $context = (array) $resolved['context'];

        $data = $request->validate([
            'recommendation_id' => ['required', 'integer', 'min:1'],
        ]);

        $recommendation = MarketingRecommendation::query()
            ->with([
                'campaign:id,tenant_id,name,channel,objective',
                'profile:id,tenant_id,first_name,last_name,email',
            ])
            ->findOrFail((int) $data['recommendation_id']);

        if (! $this->recommendationInTenantScope($recommendation, $tenantId)) {
            abort(404);
        }

        $draftCampaign = DB::transaction(function () use ($recommendation, $tenantId): MarketingCampaign {
            $title = Str::limit($this->opportunityTitle($recommendation), 90);
            $campaignName = Str::limit(sprintf('%s Draft', $title !== '' ? $title : 'AI Assistant'), 120);
            $draftChannel = $this->recommendationChannel($recommendation);
            $audience = $this->recommendationAudience($recommendation);
            $message = $this->recommendationMessage($recommendation);
            $details = is_array($recommendation->details_json) ? $recommendation->details_json : [];

            $campaign = MarketingCampaign::query()->create([
                'tenant_id' => $tenantId,
                'name' => $campaignName,
                'slug' => null,
                'description' => Str::limit($this->opportunityWhyLine($recommendation), 500),
                'status' => 'draft',
                'channel' => $draftChannel,
                'source_label' => 'ai_assistant_draft',
                'message_subject' => $draftChannel === 'email'
                    ? Str::limit(sprintf('%s update', $title !== '' ? $title : 'Campaign'), 200)
                    : null,
                'message_body' => $message,
                'target_snapshot' => [
                    'created_from' => 'ai_assistant_recommendation',
                    'recommendation_id' => (int) $recommendation->id,
                    'recommendation_type' => strtolower(trim((string) $recommendation->type)),
                    'why_this_was_suggested' => $this->opportunityWhyLine($recommendation),
                    'audience_label' => $audience,
                    'confidence' => is_numeric($recommendation->confidence) ? (float) $recommendation->confidence : null,
                    'details' => $details,
                ],
                'status_counts' => [
                    'queued_for_approval' => 0,
                    'sent' => 0,
                ],
                'objective' => $this->recommendationObjective($recommendation),
                'created_by' => auth()->id(),
                'updated_by' => auth()->id(),
            ]);

            MarketingCampaignVariant::query()->create([
                'campaign_id' => (int) $campaign->id,
                'template_id' => null,
                'name' => 'Primary Draft',
                'variant_key' => 'primary',
                'message_text' => $message,
                'weight' => 100,
                'is_control' => true,
                'status' => 'draft',
                'notes' => 'Created from AI Assistant recommendation. Human review is required before any send action.',
            ]);

            return $campaign;
        });

        return redirect(
            $this->urlGenerator->redirectToRoute(
                'shopify.app.assistant.drafts',
                ['draft' => (int) $draftCampaign->id],
                $request,
                (string) ($context['host'] ?? null)
            )
        )->with('status', 'Draft campaign created. Review Draft before any send action.');
    }

    public function updateDraftCampaign(
        MarketingCampaign $campaign,
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): RedirectResponse {
        $resolved = $this->resolveDraftMutationContext(
            $request,
            $contextService,
            $tenantResolver,
            $moduleAccessResolver,
            $alphaBootstrapService
        );
        if ($resolved instanceof RedirectResponse) {
            return $resolved;
        }

        $tenantId = (int) $resolved['tenant_id'];
        $context = (array) $resolved['context'];
        if (! $this->campaignInTenantScope($campaign, $tenantId)) {
            abort(404);
        }

        $currentStatus = strtolower(trim((string) $campaign->status));
        if (! in_array($currentStatus, ['draft', 'ready_for_review', 'preparing'], true)) {
            return redirect(
                $this->urlGenerator->redirectToRoute(
                    'shopify.app.assistant.drafts',
                    ['draft' => (int) $campaign->id],
                    $request,
                    (string) ($context['host'] ?? null)
                )
            )->with('status_error', 'Only pending drafts can be edited on this page.');
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'audience' => ['nullable', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        $name = Str::limit(trim((string) $data['name']), 120);
        $audience = Str::limit(trim((string) ($data['audience'] ?? '')), 200);
        $message = trim((string) $data['message']);

        DB::transaction(function () use ($campaign, $name, $audience, $message): void {
            $snapshot = is_array($campaign->target_snapshot) ? $campaign->target_snapshot : [];
            $snapshot['audience_label'] = $audience !== '' ? $audience : 'All eligible customers';
            $snapshot['last_editor'] = 'ai_assistant_draft_campaigns';

            $campaign->forceFill([
                'name' => $name,
                'status' => 'draft',
                'message_body' => $message,
                'target_snapshot' => $snapshot,
                'updated_by' => auth()->id(),
            ])->save();

            $variant = $campaign->variants()
                ->orderByDesc('id')
                ->first();

            if ($variant instanceof MarketingCampaignVariant) {
                $variant->forceFill([
                    'message_text' => $message,
                    'status' => 'draft',
                    'notes' => 'Updated in AI Assistant Draft Campaigns. Human review is required before any send action.',
                ])->save();

                return;
            }

            MarketingCampaignVariant::query()->create([
                'campaign_id' => (int) $campaign->id,
                'template_id' => null,
                'name' => 'Primary Draft',
                'variant_key' => 'primary',
                'message_text' => $message,
                'weight' => 100,
                'is_control' => true,
                'status' => 'draft',
                'notes' => 'Created in AI Assistant Draft Campaigns. Human review is required before any send action.',
            ]);
        });

        return redirect(
            $this->urlGenerator->redirectToRoute(
                'shopify.app.assistant.drafts',
                ['draft' => (int) $campaign->id],
                $request,
                (string) ($context['host'] ?? null)
            )
        )->with('status', 'Draft campaign updated. Human review stays in control.');
    }

    public function setup(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'setup'
        );
    }

    public function activity(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): Response {
        return $this->renderSurface(
            request: $request,
            contextService: $contextService,
            tenantResolver: $tenantResolver,
            moduleAccessResolver: $moduleAccessResolver,
            alphaBootstrapService: $alphaBootstrapService,
            activeKey: 'activity'
        );
    }

    protected function renderSurface(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService,
        string $activeKey
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
            // Keep the protected alpha override centralized and auditable.
            $probe->time('alpha_defaults', fn (): array => $alphaBootstrapService->ensureForTenant($tenantId, (string) ($store['key'] ?? '')));
        }

        $probe->forTenant($tenantId);

        $assistantAccess = $tenantId !== null
            ? $probe->time('assistant_access', fn (): array => $this->assistantAccessContext($moduleAccessResolver, $tenantId))
            : $this->defaultAssistantAccessContext();

        $startHere = $activeKey === 'start'
            ? $probe->time('page_payload', fn (): array => $this->startHerePayload($assistantAccess))
            : null;
        $topOpportunities = $activeKey === 'opportunities'
            ? $probe->time('page_payload', fn (): array => $this->topOpportunitiesPayload($tenantId, $request, $assistantAccess))
            : null;
        $draftCampaigns = $activeKey === 'drafts'
            ? $probe->time('page_payload', fn (): array => $this->draftCampaignsPayload($tenantId, $request, $assistantAccess))
            : null;
        $setupChecklist = $activeKey === 'setup'
            ? $probe->time('page_payload', fn (): array => $this->setupChecklistPayload($moduleAccessResolver, $tenantId, $assistantAccess))
            : null;
        $activityFeed = $activeKey === 'activity'
            ? $probe->time('page_payload', fn (): array => $this->activityPayload($tenantId, $request, $assistantAccess))
            : null;

        $assistantModule = is_array($assistantAccess['module'] ?? null)
            ? (array) $assistantAccess['module']
            : $this->defaultAssistantModule();
        $activeSurfaceCapability = $this->assistantCapabilityForSurface($assistantAccess, $activeKey);
        $assistantState = TenantModuleUi::present($assistantModule, 'AI Assistant');
        $assistantEnabled = (bool) ($activeSurfaceCapability['has_access'] ?? false);
        $assistantTierMessage = trim((string) ($activeSurfaceCapability['tier_message'] ?? ''));
        $assistantLockedCta = $this->assistantLockedCta($activeSurfaceCapability);

        $assistantMessage = match (true) {
            $tenantId === null => 'This Shopify store is not mapped to a tenant yet.',
            ! $assistantEnabled => $this->lockedSurfaceMessage($activeKey, $assistantTierMessage),
            default => null,
        };

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
                'headline' => $this->headlineForStatus($status, $this->surfaceLabel($activeKey)),
                'subheadline' => $this->subheadlineForStatus($status, $this->surfaceSummary($activeKey)),
                'appNavigation' => $appNavigation,
                'pageSubnav' => $pageSubnav,
                'pageActions' => [],
                'contextToken' => $authorized ? $contextService->issueContextToken($context) : null,
                'assistantState' => $assistantState,
                'assistantEnabled' => $assistantEnabled,
                'assistantMessage' => $assistantMessage,
                'assistantTierMessage' => $assistantTierMessage,
                'assistantLockedCta' => $assistantLockedCta,
                'assistantSurfaceState' => $activeSurfaceCapability,
                'activeSurfaceKey' => $activeKey,
                'surfaceLabel' => $this->surfaceLabel($activeKey),
                'surfaceSummary' => $this->surfaceSummary($activeKey),
                'surfaceStubItems' => $this->surfaceStubItems($activeKey),
                'startHere' => $startHere,
                'topOpportunities' => $topOpportunities,
                'draftCampaigns' => $draftCampaigns,
                'setupChecklist' => $setupChecklist,
                'activityFeed' => $activityFeed,
            ]),
            $this->pageStatusCode($authorized, $status, $tenantId, $assistantEnabled)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
            'assistant_enabled' => $assistantEnabled,
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

    /**
     * @return array{
     *   assistant_enabled:bool,
     *   assistant_module:array<string,mixed>,
     *   welcome:array{title:string,copy:string},
     *   status_strip:array<int,array{key:string,label:string,count:int}>,
     *   actions:array<int,array{label:string,description:string,href:string}>,
     *   helps_with:array<int,string>
     * }
     */
    protected function startHerePayload(array $assistantAccess): array
    {
        $statusStrip = [
            ['key' => 'ready', 'label' => 'Ready', 'count' => 0],
            ['key' => 'needs_setup', 'label' => 'Needs Setup', 'count' => 0],
            ['key' => 'locked', 'label' => 'Locked', 'count' => 0],
            ['key' => 'coming_soon', 'label' => 'Coming Soon', 'count' => 0],
        ];
        $assistantModule = is_array($assistantAccess['module'] ?? null)
            ? (array) $assistantAccess['module']
            : $this->defaultAssistantModule();
        $surfaceStates = is_array($assistantAccess['surfaces'] ?? null)
            ? (array) $assistantAccess['surfaces']
            : [];

        $counts = [
            'ready' => 0,
            'needs_setup' => 0,
            'locked' => 0,
            'coming_soon' => 0,
        ];

        foreach ($surfaceStates as $surfaceState) {
            if (! is_array($surfaceState)) {
                continue;
            }

            $uiState = strtolower(trim((string) ($surfaceState['ui_state'] ?? 'locked')));
            match ($uiState) {
                'active' => $counts['ready']++,
                'setup_needed' => $counts['needs_setup']++,
                'coming_soon' => $counts['coming_soon']++,
                default => $counts['locked']++,
            };
        }

        $statusStrip = [
            ['key' => 'ready', 'label' => 'Ready', 'count' => $counts['ready']],
            ['key' => 'needs_setup', 'label' => 'Needs Setup', 'count' => $counts['needs_setup']],
            ['key' => 'locked', 'label' => 'Locked', 'count' => $counts['locked']],
            ['key' => 'coming_soon', 'label' => 'Coming Soon', 'count' => $counts['coming_soon']],
        ];

        $counts = [
            'ready' => (int) ($statusStrip[0]['count'] ?? 0),
            'needs_setup' => (int) ($statusStrip[1]['count'] ?? 0),
            'locked' => (int) ($statusStrip[2]['count'] ?? 0),
            'coming_soon' => (int) ($statusStrip[3]['count'] ?? 0),
        ];
        $assistantEnabled = (bool) data_get($surfaceStates, 'start.has_access', false);

        return [
            'assistant_enabled' => $assistantEnabled,
            'assistant_module' => $assistantModule,
            'welcome' => [
                'title' => 'Welcome to AI Assistant',
                'copy' => 'See what is ready now, what needs setup, and the next best click in one place.',
            ],
            'status_strip' => $statusStrip,
            'actions' => $this->startHereActions($assistantEnabled, $counts, $surfaceStates),
            'helps_with' => [
                'Shows which AI Assistant pieces are ready to use right now.',
                'Calls out what still needs setup before you can go further.',
                'Points you to the next best click without a big dashboard.',
            ],
        ];
    }

    /**
     * @return array<int,array{label:string,description:string,href:string}>
     */
    protected function startHereActions(bool $assistantEnabled, array $counts, array $surfaceStates = []): array
    {
        if (! $assistantEnabled) {
            return [[
                'label' => 'Review plans and module access',
                'description' => 'Unlock AI Assistant for this tenant.',
                'href' => route('shopify.app.plans', [], false),
            ]];
        }

        $actions = [];
        $setupAccess = (bool) data_get($surfaceStates, 'setup.has_access', false);
        $opportunitiesAccess = (bool) data_get($surfaceStates, 'opportunities.has_access', false);
        $draftAccess = (bool) data_get($surfaceStates, 'drafts.has_access', false);
        $draftTierMessage = strtolower(trim((string) data_get($surfaceStates, 'drafts.tier_message', '')));

        if ($setupAccess && (int) ($counts['needs_setup'] ?? 0) > 0) {
            $actions[] = [
                'label' => 'Open Setup',
                'description' => 'Complete setup items before launching workflows.',
                'href' => route('shopify.app.assistant.setup', [], false),
            ];
        }

        if ($opportunitiesAccess) {
            $actions[] = [
                'label' => 'View Top Opportunities',
                'description' => 'See where AI Assistant can help first.',
                'href' => route('shopify.app.assistant.opportunities', [], false),
            ];
        }

        if ($draftAccess) {
            $actions[] = [
                'label' => 'Open Draft Campaigns',
                'description' => 'Review draft-ready campaign surfaces.',
                'href' => route('shopify.app.assistant.drafts', [], false),
            ];
        } elseif ($draftTierMessage === 'available with upgrade') {
            $actions[] = [
                'label' => 'Upgrade to unlock Draft Campaigns',
                'description' => 'Move to Pro for fuller AI Assistant workflow surfaces.',
                'href' => route('shopify.app.plans', [], false),
            ];
        }

        if ($actions === [] || ((int) ($counts['locked'] ?? 0) > 0 && count($actions) < 3)) {
            $actions[] = [
                'label' => 'Review plans and module access',
                'description' => 'Unlock additional AI Assistant workflows for your team.',
                'href' => route('shopify.app.plans', [], false),
            ];
        }

        return array_slice($actions, 0, 3);
    }

    /**
     * @return array{
     *   assistant_enabled:bool,
     *   assistant_module:array<string,mixed>,
     *   intro:array{title:string,copy:string},
     *   opportunities:array<int,array{
     *     id:int,
     *     title:string,
     *     why_this_matters:string,
     *     priority:string,
     *     explainability:?string,
     *     action_label:string,
     *     action_href:string
     *   }>,
     *   pagination:array{
     *     current_page:int,
     *     per_page:int,
     *     total:int,
     *     from:int,
     *     to:int,
     *     has_pages:bool,
     *     has_more:bool,
     *     prev_url:?string,
     *     next_url:?string
     *   },
     *   empty_state:array{title:string,copy:string,label:string,href:string},
     *   locked_cta:array{label:string,href:string}
     * }
     */
    protected function topOpportunitiesPayload(
        ?int $tenantId,
        Request $request,
        array $assistantAccess
    ): array {
        $assistantModule = is_array($assistantAccess['module'] ?? null)
            ? (array) $assistantAccess['module']
            : $this->defaultAssistantModule();
        $surfaceState = $this->assistantCapabilityForSurface($assistantAccess, 'opportunities');
        $assistantEnabled = (bool) ($surfaceState['has_access'] ?? false);
        $lockedCta = $this->assistantLockedCta($surfaceState);

        $paginator = null;
        if ($assistantEnabled && $tenantId !== null) {
            $page = max(1, (int) $request->query('opportunity_page', 1));
            $paginator = $this->tenantOpportunityPage($tenantId, $page);
        }

        $opportunities = $paginator instanceof LengthAwarePaginator
            ? $paginator->getCollection()
                ->map(fn (MarketingRecommendation $recommendation): array => $this->presentOpportunityCard($recommendation))
                ->values()
                ->all()
            : [];

        $pagination = [
            'current_page' => $paginator instanceof LengthAwarePaginator ? (int) $paginator->currentPage() : 1,
            'per_page' => $paginator instanceof LengthAwarePaginator ? (int) $paginator->perPage() : 5,
            'total' => $paginator instanceof LengthAwarePaginator ? (int) $paginator->total() : count($opportunities),
            'from' => $paginator instanceof LengthAwarePaginator ? (int) ($paginator->firstItem() ?? 0) : (count($opportunities) > 0 ? 1 : 0),
            'to' => $paginator instanceof LengthAwarePaginator ? (int) ($paginator->lastItem() ?? 0) : count($opportunities),
            'has_pages' => $paginator instanceof LengthAwarePaginator ? $paginator->hasPages() : false,
            'has_more' => $paginator instanceof LengthAwarePaginator ? $paginator->hasMorePages() : false,
            'prev_url' => $paginator instanceof LengthAwarePaginator ? $paginator->previousPageUrl() : null,
            'next_url' => $paginator instanceof LengthAwarePaginator ? $paginator->nextPageUrl() : null,
        ];

        return [
            'assistant_enabled' => $assistantEnabled,
            'assistant_module' => $assistantModule,
            'intro' => [
                'title' => 'Best Opportunities Right Now',
                'copy' => 'Plain-English, operator-reviewed opportunities ranked by impact and confidence.',
            ],
            'opportunities' => $opportunities,
            'pagination' => $pagination,
            'empty_state' => [
                'title' => 'No top opportunities yet',
                'copy' => 'Nothing needs immediate follow-up right now. Keep setup current and check back after new customer or campaign activity.',
                'label' => 'Open Setup',
                'href' => route('shopify.app.assistant.setup', [], false),
            ],
            'locked_cta' => $lockedCta,
        ];
    }

    protected function tenantOpportunityPage(int $tenantId, int $page): LengthAwarePaginator
    {
        if (! Schema::hasTable('marketing_recommendations')) {
            return new LengthAwarePaginator(
                items: [],
                total: 0,
                perPage: 5,
                currentPage: max(1, $page),
                options: [
                    'path' => request()->url(),
                    'pageName' => 'opportunity_page',
                ]
            );
        }

        $query = MarketingRecommendation::query()
            ->select([
                'id',
                'type',
                'campaign_id',
                'marketing_profile_id',
                'title',
                'summary',
                'details_json',
                'confidence',
                'status',
                'created_at',
            ])
            ->with([
                'campaign:id,tenant_id,name',
                'profile:id,tenant_id,first_name,last_name,email',
            ])
            ->where('status', 'pending')
            ->orderByRaw('case when confidence is null then 1 else 0 end')
            ->orderByDesc('confidence')
            ->orderByDesc('id');

        if ($this->marketingOwnershipService->strictModeEnabled()) {
            $recommendationIds = $this->marketingOwnershipService->tenantRecommendationIds($tenantId)->all();
            if ($recommendationIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $recommendationIds);
            }
        } else {
            $query->where(function ($scoped) use ($tenantId): void {
                $scoped->whereHas('campaign', fn ($campaignQuery) => $campaignQuery->forTenantId($tenantId))
                    ->orWhereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
            });
        }

        return $query
            ->paginate(5, ['*'], 'opportunity_page', $page)
            ->withQueryString();
    }

    /**
     * @return array{
     *   id:int,
     *   title:string,
     *   why_this_matters:string,
     *   priority:string,
     *   explainability:?string,
     *   action_label:string,
     *   action_href:string
     * }
     */
    protected function presentOpportunityCard(MarketingRecommendation $recommendation): array
    {
        $details = is_array($recommendation->details_json) ? $recommendation->details_json : [];
        [$actionLabel, $actionHref] = $this->opportunityAction($recommendation);
        $priority = $this->opportunityPriorityLabel($recommendation->confidence);

        return [
            'id' => (int) $recommendation->id,
            'title' => $this->opportunityTitle($recommendation),
            'why_this_matters' => $this->opportunityWhyLine($recommendation),
            'priority' => $priority,
            'explainability' => $this->opportunityExplainability($details),
            'action_label' => $actionLabel,
            'action_href' => $actionHref,
        ];
    }

    protected function opportunityTitle(MarketingRecommendation $recommendation): string
    {
        $title = trim((string) $recommendation->title);
        if ($title !== '') {
            return $title;
        }

        return match (strtolower(trim((string) $recommendation->type))) {
            'segment_opportunity' => 'Bring back past customers',
            'send_suggestion', 'reward_opportunity' => 'Follow up with recent buyers',
            'timing_suggestion' => 'Promote a seasonal scent',
            'channel_suggestion' => 'Clean up missing setup before sending',
            default => 'Review a draft campaign',
        };
    }

    protected function opportunityWhyLine(MarketingRecommendation $recommendation): string
    {
        $why = trim((string) $recommendation->summary);
        if ($why !== '') {
            return $why;
        }

        return 'This recommendation is based on existing campaign and customer behavior in your workspace.';
    }

    protected function opportunityPriorityLabel(mixed $confidence): string
    {
        if (! is_numeric($confidence)) {
            return 'Needs review';
        }

        $value = max(0.0, min(1.0, (float) $confidence));

        return match (true) {
            $value >= 0.85 => 'High priority',
            $value >= 0.65 => 'Medium priority',
            $value > 0.00 => 'Lower priority',
            default => 'Needs review',
        };
    }

    /**
     * @param  array<string,mixed>  $details
     */
    protected function opportunityExplainability(array $details): ?string
    {
        $estimatedProfiles = is_numeric($details['estimated_profiles'] ?? null)
            ? (int) $details['estimated_profiles']
            : null;
        if ($estimatedProfiles !== null && $estimatedProfiles > 0) {
            return sprintf('Based on roughly %d matching customer records.', $estimatedProfiles);
        }

        $eventContext = trim((string) ($details['event_context'] ?? ''));
        if ($eventContext !== '') {
            return sprintf('Based on %s purchase activity.', $eventContext);
        }

        $candidateSegment = trim((string) ($details['candidate_segment'] ?? ($details['segment_name'] ?? '')));
        if ($candidateSegment !== '') {
            return sprintf('Suggested audience: %s.', $candidateSegment);
        }

        $recommendedDaypart = trim((string) ($details['recommended_daypart'] ?? ''));
        $recommendedHour = is_numeric($details['recommended_hour'] ?? null)
            ? (int) $details['recommended_hour']
            : null;
        if ($recommendedDaypart !== '' && $recommendedHour !== null) {
            return sprintf('Suggested send window: %s around %02d:00.', $recommendedDaypart, $recommendedHour);
        }

        $suggestion = trim((string) ($details['suggestion'] ?? ''));
        if ($suggestion !== '') {
            return $suggestion;
        }

        return null;
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function opportunityAction(MarketingRecommendation $recommendation): array
    {
        return match (strtolower(trim((string) $recommendation->type))) {
            'segment_opportunity' => [
                'Bring back past customers',
                route('shopify.app.messaging', [], false),
            ],
            'send_suggestion', 'reward_opportunity' => [
                'Follow up with recent buyers',
                route('shopify.app.messaging', [], false),
            ],
            'channel_suggestion' => [
                'Clean up missing setup before sending',
                route('shopify.app.assistant.setup', [], false),
            ],
            default => [
                'Review a draft campaign',
                route('shopify.app.assistant.drafts', [], false),
            ],
        };
    }

    /**
     * @return array{
     *   assistant_enabled:bool,
     *   assistant_module:array<string,mixed>,
     *   intro:array{title:string,copy:string},
     *   drafts:array<int,array<string,mixed>>,
     *   selected_draft:?array<string,mixed>,
     *   recommendations:array<int,array<string,mixed>>,
     *   empty_state:array{title:string,copy:string,label:string,href:string},
     *   locked_cta:array{label:string,href:string}
     * }
     */
    protected function draftCampaignsPayload(
        ?int $tenantId,
        Request $request,
        array $assistantAccess
    ): array {
        $assistantModule = is_array($assistantAccess['module'] ?? null)
            ? (array) $assistantAccess['module']
            : $this->defaultAssistantModule();
        $surfaceState = $this->assistantCapabilityForSurface($assistantAccess, 'drafts');
        $assistantEnabled = (bool) ($surfaceState['has_access'] ?? false);
        $lockedCta = $this->assistantLockedCta($surfaceState);

        $drafts = [];
        $recommendations = [];
        $selectedDraft = null;

        if ($assistantEnabled && $tenantId !== null) {
            $drafts = $this->tenantDraftCampaigns($tenantId);
            $recommendations = $this->tenantDraftRecommendations($tenantId);

            $requestedDraftId = max(0, (int) $request->query('draft', 0));
            $defaultDraftId = (int) ($drafts[0]['id'] ?? 0);
            $selectedDraftId = $requestedDraftId > 0 ? $requestedDraftId : $defaultDraftId;
            if ($selectedDraftId > 0) {
                $draftModel = $this->draftCampaignById($tenantId, $selectedDraftId);
                if ($draftModel instanceof MarketingCampaign) {
                    $selectedDraft = $this->presentDraftEditor($draftModel);
                }
            }
        }

        return [
            'assistant_enabled' => $assistantEnabled,
            'assistant_module' => $assistantModule,
            'intro' => [
                'title' => 'Draft Campaigns',
                'copy' => 'Review or create AI-assisted drafts in plain English. Nothing sends automatically.',
            ],
            'drafts' => $drafts,
            'selected_draft' => $selectedDraft,
            'recommendations' => $recommendations,
            'empty_state' => [
                'title' => 'No draft campaigns yet',
                'copy' => 'Start from a top opportunity to create your first draft for human review.',
                'label' => 'Open Top Opportunities',
                'href' => route('shopify.app.assistant.opportunities', [], false),
            ],
            'locked_cta' => $lockedCta,
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function tenantDraftCampaigns(int $tenantId): array
    {
        $query = $this->tenantDraftCampaignQuery($tenantId);

        return $query
            ->limit(10)
            ->get()
            ->map(fn (MarketingCampaign $campaign): array => $this->presentDraftCampaignCard($campaign))
            ->values()
            ->all();
    }

    protected function draftCampaignById(int $tenantId, int $draftId): ?MarketingCampaign
    {
        return $this->tenantDraftCampaignQuery($tenantId)
            ->where('id', $draftId)
            ->first();
    }

    /**
     * @return \Illuminate\Database\Eloquent\Builder<MarketingCampaign>
     */
    protected function tenantDraftCampaignQuery(int $tenantId)
    {
        $query = MarketingCampaign::query()
            ->select([
                'id',
                'tenant_id',
                'name',
                'status',
                'channel',
                'source_label',
                'message_body',
                'message_subject',
                'target_snapshot',
                'objective',
                'updated_at',
            ])
            ->with([
                'variants' => fn ($variantQuery) => $variantQuery
                    ->select(['id', 'campaign_id', 'message_text', 'status'])
                    ->orderByDesc('id'),
            ])
            ->whereIn('status', ['draft', 'ready_for_review', 'preparing'])
            ->orderByDesc('updated_at');

        if ($this->marketingOwnershipService->strictModeEnabled()) {
            $campaignIds = $this->marketingOwnershipService->tenantCampaignIds($tenantId)->all();
            if ($campaignIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $campaignIds);
            }

            return $query;
        }

        return $query->forTenantId($tenantId);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function tenantDraftRecommendations(int $tenantId): array
    {
        $query = MarketingRecommendation::query()
            ->select([
                'id',
                'type',
                'campaign_id',
                'marketing_profile_id',
                'title',
                'summary',
                'details_json',
                'confidence',
                'status',
                'created_at',
            ])
            ->with([
                'campaign:id,tenant_id,name,channel,objective',
                'profile:id,tenant_id,first_name,last_name,email',
            ])
            ->where('status', 'pending')
            ->orderByRaw('case when confidence is null then 1 else 0 end')
            ->orderByDesc('confidence')
            ->orderByDesc('id');

        if ($this->marketingOwnershipService->strictModeEnabled()) {
            $recommendationIds = $this->marketingOwnershipService->tenantRecommendationIds($tenantId)->all();
            if ($recommendationIds === []) {
                $query->whereRaw('1 = 0');
            } else {
                $query->whereIn('id', $recommendationIds);
            }
        } else {
            $query->where(function ($scoped) use ($tenantId): void {
                $scoped->whereHas('campaign', fn ($campaignQuery) => $campaignQuery->forTenantId($tenantId))
                    ->orWhereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
            });
        }

        return $query
            ->limit(3)
            ->get()
            ->map(fn (MarketingRecommendation $recommendation): array => $this->presentDraftRecommendation($recommendation))
            ->values()
            ->all();
    }

    /**
     * @return array<string,mixed>
     */
    protected function presentDraftCampaignCard(MarketingCampaign $campaign): array
    {
        $statusLabel = $this->draftStatusLabel((string) $campaign->status);

        return [
            'id' => (int) $campaign->id,
            'title' => trim((string) $campaign->name) !== '' ? (string) $campaign->name : 'Draft campaign',
            'audience' => $this->campaignAudience($campaign),
            'message' => $this->campaignMessage($campaign),
            'why_this_was_suggested' => $this->campaignWhySuggested($campaign),
            'next_step' => 'Review Draft and confirm the message before any send action.',
            'status_label' => $statusLabel,
            'updated_at' => optional($campaign->updated_at)->format('M j, g:i A'),
            'select_href' => route('shopify.app.assistant.drafts', ['draft' => (int) $campaign->id], false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function presentDraftEditor(MarketingCampaign $campaign): array
    {
        $audience = $this->campaignAudience($campaign);
        $message = $this->campaignMessage($campaign);
        $why = $this->campaignWhySuggested($campaign);

        return [
            'id' => (int) $campaign->id,
            'title' => trim((string) $campaign->name) !== '' ? (string) $campaign->name : 'Draft campaign',
            'audience' => $audience,
            'message' => $message,
            'why_this_was_suggested' => $why,
            'next_step' => 'Review Draft with a human operator, then open Messaging when you are ready.',
            'status_label' => $this->draftStatusLabel((string) $campaign->status),
            'update_href' => route('shopify.app.assistant.drafts.update', ['campaign' => (int) $campaign->id], false),
            'review_href' => route('shopify.app.messaging', [], false),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function presentDraftRecommendation(MarketingRecommendation $recommendation): array
    {
        return [
            'id' => (int) $recommendation->id,
            'title' => $this->opportunityTitle($recommendation),
            'why_this_was_suggested' => $this->opportunityWhyLine($recommendation),
            'audience' => $this->recommendationAudience($recommendation),
            'message' => $this->recommendationMessage($recommendation),
            'next_step' => 'Create a draft and review it with your team before any send action.',
            'priority' => $this->opportunityPriorityLabel($recommendation->confidence),
        ];
    }

    protected function campaignAudience(MarketingCampaign $campaign): string
    {
        $snapshot = is_array($campaign->target_snapshot) ? $campaign->target_snapshot : [];
        $audience = trim((string) ($snapshot['audience_label'] ?? ''));
        if ($audience !== '') {
            return $audience;
        }

        $segmentLabel = trim((string) ($snapshot['segment_label'] ?? ($snapshot['segment_name'] ?? '')));
        if ($segmentLabel !== '') {
            return $segmentLabel;
        }

        return 'All eligible customers';
    }

    protected function campaignMessage(MarketingCampaign $campaign): string
    {
        $message = trim((string) ($campaign->message_body ?? ''));
        if ($message !== '') {
            return $message;
        }

        $variantMessage = trim((string) ($campaign->variants->first()?->message_text ?? ''));
        if ($variantMessage !== '') {
            return $variantMessage;
        }

        return 'Draft message will be added after you review this campaign.';
    }

    protected function campaignWhySuggested(MarketingCampaign $campaign): string
    {
        $snapshot = is_array($campaign->target_snapshot) ? $campaign->target_snapshot : [];
        $why = trim((string) ($snapshot['why_this_was_suggested'] ?? ''));
        if ($why !== '') {
            return $why;
        }

        return 'This draft was prepared from your current campaign and customer activity.';
    }

    protected function recommendationAudience(MarketingRecommendation $recommendation): string
    {
        $details = is_array($recommendation->details_json) ? $recommendation->details_json : [];
        $candidateSegment = trim((string) ($details['candidate_segment'] ?? ($details['segment_name'] ?? '')));
        if ($candidateSegment !== '') {
            return $candidateSegment;
        }

        $profileName = trim((string) ($recommendation->profile?->first_name . ' ' . $recommendation->profile?->last_name));
        if ($profileName !== '') {
            return $profileName;
        }

        $profileEmail = trim((string) ($recommendation->profile?->email ?? ''));
        if ($profileEmail !== '') {
            return $profileEmail;
        }

        $campaignName = trim((string) ($recommendation->campaign?->name ?? ''));
        if ($campaignName !== '') {
            return $campaignName;
        }

        return 'All eligible customers';
    }

    protected function recommendationMessage(MarketingRecommendation $recommendation): string
    {
        $details = is_array($recommendation->details_json) ? $recommendation->details_json : [];
        $message = trim((string) ($details['suggested_message'] ?? ''));
        if ($message !== '') {
            return $message;
        }

        $summary = trim((string) $recommendation->summary);
        if ($summary !== '') {
            return $summary;
        }

        return 'Use a short message with one clear offer and one next step.';
    }

    protected function recommendationChannel(MarketingRecommendation $recommendation): string
    {
        $details = is_array($recommendation->details_json) ? $recommendation->details_json : [];
        $channel = strtolower(trim((string) ($details['suggested_channel'] ?? ($recommendation->campaign?->channel ?? 'sms'))));

        return in_array($channel, ['sms', 'email'], true) ? $channel : 'sms';
    }

    protected function recommendationObjective(MarketingRecommendation $recommendation): ?string
    {
        $details = is_array($recommendation->details_json) ? $recommendation->details_json : [];
        $objective = strtolower(trim((string) ($details['objective'] ?? '')));
        if ($objective !== '') {
            return $objective;
        }

        return match (strtolower(trim((string) $recommendation->type))) {
            'segment_opportunity' => 'winback',
            'send_suggestion', 'reward_opportunity' => 'event_followup',
            'timing_suggestion' => 'timing_optimization',
            'channel_suggestion' => 'channel_readiness',
            default => null,
        };
    }

    protected function draftStatusLabel(string $status): string
    {
        return match (strtolower(trim($status))) {
            'ready_for_review' => 'Needs Review',
            'preparing' => 'Building Draft',
            default => 'Draft Ready',
        };
    }

    /**
     * @return array{
     *   assistant_enabled:bool,
     *   assistant_module:array<string,mixed>,
     *   intro:array{title:string,copy:string},
     *   status_strip:array<int,array{key:string,label:string,count:int}>,
     *   checklist:array<int,array{
     *     key:string,
     *     module_key:string,
     *     title:string,
     *     description:string,
     *     state:array<string,mixed>,
     *     action_label:string,
     *     action_href:?string
     *   }>,
     *   next_step:array{title:string,copy:string,label:string,href:?string}
     * }
     */
    protected function setupChecklistPayload(
        TenantModuleAccessResolver $moduleAccessResolver,
        ?int $tenantId,
        array $assistantAccess
    ): array {
        $assistantModule = is_array($assistantAccess['module'] ?? null)
            ? (array) $assistantAccess['module']
            : $this->defaultAssistantModule();
        $surfaceState = $this->assistantCapabilityForSurface($assistantAccess, 'setup');
        $assistantEnabled = (bool) ($surfaceState['has_access'] ?? false);
        $checklistDefinitions = $this->setupChecklistDefinitions();
        $statusCounts = [
            'ready' => 0,
            'needs_setup' => 0,
            'locked' => 0,
            'coming_soon' => 0,
        ];
        $moduleStates = [];

        if ($tenantId !== null) {
            $moduleKeys = array_values(array_unique(array_filter(array_map(
                static fn (array $definition): string => strtolower(trim((string) ($definition['module_key'] ?? ''))),
                $checklistDefinitions
            ))));
            $resolved = $moduleAccessResolver->resolveForTenant($tenantId, $moduleKeys);
            $moduleStates = is_array($resolved['modules'] ?? null) ? (array) $resolved['modules'] : [];
        }

        $checklist = [];

        foreach ($checklistDefinitions as $definition) {
            $moduleKey = strtolower(trim((string) ($definition['module_key'] ?? '')));
            if ($moduleKey === '') {
                continue;
            }

            $title = trim((string) ($definition['title'] ?? ucfirst(str_replace('_', ' ', $moduleKey))));
            $rawState = is_array($moduleStates[$moduleKey] ?? null)
                ? (array) $moduleStates[$moduleKey]
                : [
                    'module_key' => $moduleKey,
                    'label' => $title,
                    'has_access' => false,
                    'enabled' => false,
                    'ui_state' => 'locked',
                    'setup_status' => 'not_started',
                    'reason' => 'tenant_not_mapped',
                    'cta' => 'none',
                ];
            $presentedState = TenantModuleUi::present($rawState, $title);
            $actionState = TenantModuleActionPresenter::present($rawState, $title);
            [$actionLabel, $actionHref] = $this->setupChecklistAction($definition, $presentedState, $actionState);

            $checklist[] = [
                'key' => strtolower(trim((string) ($definition['key'] ?? $moduleKey))),
                'module_key' => $moduleKey,
                'title' => $title,
                'description' => trim((string) ($definition['description'] ?? '')),
                'state' => $presentedState,
                'action_label' => $actionLabel,
                'action_href' => $actionHref,
            ];

            $uiState = strtolower(trim((string) ($presentedState['ui_state'] ?? 'locked')));
            match ($uiState) {
                'active' => $statusCounts['ready']++,
                'setup_needed' => $statusCounts['needs_setup']++,
                'coming_soon' => $statusCounts['coming_soon']++,
                default => $statusCounts['locked']++,
            };
        }

        $checklist = array_slice($checklist, 0, 6);
        $statusStrip = [
            ['key' => 'ready', 'label' => 'Ready', 'count' => $statusCounts['ready']],
            ['key' => 'needs_setup', 'label' => 'Needs Setup', 'count' => $statusCounts['needs_setup']],
            ['key' => 'locked', 'label' => 'Locked', 'count' => $statusCounts['locked']],
            ['key' => 'coming_soon', 'label' => 'Coming Soon', 'count' => $statusCounts['coming_soon']],
        ];

        return [
            'assistant_enabled' => $assistantEnabled,
            'assistant_module' => $assistantModule,
            'intro' => [
                'title' => 'Setup',
                'copy' => 'See what is connected, what is missing, and the one next step to take.',
            ],
            'status_strip' => $statusStrip,
            'checklist' => $checklist,
            'next_step' => $this->setupChecklistNextStep($checklist),
        ];
    }

    /**
     * @param  array{
     *   key:string,
     *   module_key:string,
     *   title:string,
     *   description:string,
     *   action_label:string,
     *   action_href:string
     * }  $definition
     * @param  array<string,mixed>  $state
     * @param  array<string,mixed>  $actionState
     * @return array{0:string,1:?string}
     */
    protected function setupChecklistAction(array $definition, array $state, array $actionState): array
    {
        $uiState = strtolower(trim((string) ($state['ui_state'] ?? 'locked')));
        $defaultLabel = trim((string) ($definition['action_label'] ?? 'Open'));
        $defaultHref = trim((string) ($definition['action_href'] ?? ''));

        if ($uiState === 'locked') {
            $ctaLabel = trim((string) ($actionState['cta_label'] ?? ''));
            $ctaHref = trim((string) ($actionState['cta_href'] ?? ''));
            if ($ctaLabel !== '' && $ctaHref !== '') {
                return [$ctaLabel, $ctaHref];
            }

            return ['Review plans', route('shopify.app.plans', [], false)];
        }

        if ($uiState === 'coming_soon') {
            return ['View Activity', route('shopify.app.assistant.activity', [], false)];
        }

        return [
            $defaultLabel !== '' ? $defaultLabel : 'Open',
            $defaultHref !== '' ? $defaultHref : null,
        ];
    }

    /**
     * @param  array<int,array{
     *   title:string,
     *   action_label:string,
     *   action_href:?string,
     *   state:array<string,mixed>
     * }>  $checklist
     * @return array{title:string,copy:string,label:string,href:?string}
     */
    protected function setupChecklistNextStep(array $checklist): array
    {
        $priority = ['setup_needed', 'locked', 'active', 'coming_soon'];
        foreach ($priority as $stateKey) {
            foreach ($checklist as $item) {
                $itemState = strtolower(trim((string) ($item['state']['ui_state'] ?? '')));
                $actionHref = trim((string) ($item['action_href'] ?? ''));
                if ($itemState !== $stateKey || $actionHref === '') {
                    continue;
                }

                return [
                    'title' => 'Next Best Step',
                    'copy' => sprintf('Start with %s to keep setup moving.', strtolower(trim((string) ($item['title'] ?? 'this item')))),
                    'label' => trim((string) ($item['action_label'] ?? 'Open')),
                    'href' => $actionHref,
                ];
            }
        }

        return [
            'title' => 'Next Best Step',
            'copy' => 'Setup looks healthy. Open Top Opportunities for your next click.',
            'label' => 'Open Top Opportunities',
            'href' => route('shopify.app.assistant.opportunities', [], false),
        ];
    }

    /**
     * @return array<int,array{
     *   key:string,
     *   module_key:string,
     *   title:string,
     *   description:string,
     *   action_label:string,
     *   action_href:string
     * }>
     */
    protected function setupChecklistDefinitions(): array
    {
        return [
            [
                'key' => 'customer_data',
                'module_key' => 'customers',
                'title' => 'Customer Data',
                'description' => 'Keep customer records current so AI suggestions can stay useful.',
                'action_label' => 'Open Customers',
                'action_href' => route('shopify.app.customers.manage', [], false),
            ],
            [
                'key' => 'email_ready',
                'module_key' => 'email',
                'title' => 'Email Ready',
                'description' => 'Confirm sender settings so your campaign drafts are deliverable.',
                'action_label' => 'Open Settings',
                'action_href' => route('shopify.app.settings', [], false),
            ],
            [
                'key' => 'campaigns_ready',
                'module_key' => 'campaigns',
                'title' => 'Campaigns Ready',
                'description' => 'Make sure campaign basics are set before drafting new outreach.',
                'action_label' => 'Open Messaging',
                'action_href' => route('shopify.app.messaging', [], false),
            ],
            [
                'key' => 'recommendations_ready',
                'module_key' => 'ai',
                'title' => 'Recommendations Ready',
                'description' => 'Check your assistant recommendations before creating campaign drafts.',
                'action_label' => 'Open Top Opportunities',
                'action_href' => route('shopify.app.assistant.opportunities', [], false),
            ],
            [
                'key' => 'store_connected',
                'module_key' => 'shopify',
                'title' => 'Store Connected',
                'description' => 'Verify your store connection so customer and order data stays current.',
                'action_label' => 'Open Integrations',
                'action_href' => route('shopify.app.integrations', [], false),
            ],
            [
                'key' => 'review_needed',
                'module_key' => 'messaging',
                'title' => 'Review Needed',
                'description' => 'Keep a person in control by reviewing activity before any send action.',
                'action_label' => 'Open Activity',
                'action_href' => route('shopify.app.assistant.activity', [], false),
            ],
        ];
    }

    /**
     * @return array{
     *   assistant_enabled:bool,
     *   assistant_module:array<string,mixed>,
     *   intro:array{title:string,copy:string},
     *   items:array<int,array{
     *     id:string,
     *     event_label:string,
     *     title:string,
     *     summary:string,
     *     occurred_at_label:string,
     *     occurred_at_iso:?string,
     *     occurred_at_accessible:string,
     *     action_label:?string,
     *     action_href:?string
     *   }>,
     *   pagination:array{
     *     current_page:int,
     *     per_page:int,
     *     total:int,
     *     from:int,
     *     to:int,
     *     has_pages:bool,
     *     has_more:bool,
     *     prev_url:?string,
     *     next_url:?string
     *   },
     *   empty_state:array{title:string,copy:string,label:string,href:string},
     *   locked_cta:array{label:string,href:string}
     * }
     */
    protected function activityPayload(
        ?int $tenantId,
        Request $request,
        array $assistantAccess
    ): array {
        $assistantModule = is_array($assistantAccess['module'] ?? null)
            ? (array) $assistantAccess['module']
            : $this->defaultAssistantModule();
        $surfaceState = $this->assistantCapabilityForSurface($assistantAccess, 'activity');
        $assistantEnabled = (bool) ($surfaceState['has_access'] ?? false);
        $lockedCta = $this->assistantLockedCta($surfaceState);

        $paginator = null;
        if ($assistantEnabled && $tenantId !== null) {
            $paginator = $this->tenantRecentActivityPage($tenantId, $request);
        }

        $items = $paginator instanceof LengthAwarePaginator
            ? $paginator->getCollection()
                ->values()
                ->all()
            : [];

        $pagination = [
            'current_page' => $paginator instanceof LengthAwarePaginator ? (int) $paginator->currentPage() : 1,
            'per_page' => $paginator instanceof LengthAwarePaginator ? (int) $paginator->perPage() : 10,
            'total' => $paginator instanceof LengthAwarePaginator ? (int) $paginator->total() : count($items),
            'from' => $paginator instanceof LengthAwarePaginator ? (int) ($paginator->firstItem() ?? 0) : (count($items) > 0 ? 1 : 0),
            'to' => $paginator instanceof LengthAwarePaginator ? (int) ($paginator->lastItem() ?? 0) : count($items),
            'has_pages' => $paginator instanceof LengthAwarePaginator ? $paginator->hasPages() : false,
            'has_more' => $paginator instanceof LengthAwarePaginator ? $paginator->hasMorePages() : false,
            'prev_url' => $paginator instanceof LengthAwarePaginator ? $paginator->previousPageUrl() : null,
            'next_url' => $paginator instanceof LengthAwarePaginator ? $paginator->nextPageUrl() : null,
        ];

        return [
            'assistant_enabled' => $assistantEnabled,
            'assistant_module' => $assistantModule,
            'intro' => [
                'title' => 'Activity',
                'copy' => 'Recent AI Assistant suggestions and draft history. Human review stays in control.',
            ],
            'items' => $items,
            'pagination' => $pagination,
            'empty_state' => [
                'title' => 'No recent activity yet',
                'copy' => 'You will see recent opportunities, drafts, and review decisions here after your team starts using AI Assistant.',
                'label' => 'Open Top Opportunities',
                'href' => route('shopify.app.assistant.opportunities', [], false),
            ],
            'locked_cta' => $lockedCta,
        ];
    }

    protected function tenantRecentActivityPage(int $tenantId, Request $request): LengthAwarePaginator
    {
        $page = max(1, (int) $request->query('activity_page', 1));
        $perPage = 10;
        $windowStart = now()->subDays(90);
        $scope = $this->activityTenantScope($tenantId);
        $items = $this->tenantRecentActivityItems($tenantId, $windowStart, $scope, 40);

        $offset = ($page - 1) * $perPage;
        $slice = array_slice($items, $offset, $perPage);
        $slice = array_map(static function (array $item): array {
            unset($item['sort_timestamp']);

            return $item;
        }, $slice);

        return (new LengthAwarePaginator(
            items: $slice,
            total: count($items),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'pageName' => 'activity_page',
            ]
        ))->withQueryString();
    }

    /**
     * @param  array{
     *   strict:bool,
     *   campaign_ids:array<int,int>,
     *   recommendation_ids:array<int,int>,
     *   recipient_ids:array<int,int>
     * }  $scope
     * @return array<int,array{
     *   id:string,
     *   event_label:string,
     *   title:string,
     *   summary:string,
     *   occurred_at_label:string,
     *   occurred_at_iso:?string,
     *   occurred_at_accessible:string,
     *   action_label:?string,
     *   action_href:?string,
     *   sort_timestamp:int
     * }>
     */
    protected function tenantRecentActivityItems(
        int $tenantId,
        \Carbon\CarbonInterface $windowStart,
        array $scope,
        int $sourceLimit = 40
    ): array {
        $items = array_merge(
            $this->tenantOpportunityActivityItems($tenantId, $windowStart, $scope, $sourceLimit),
            $this->tenantDraftCreatedActivityItems($tenantId, $windowStart, $scope, $sourceLimit),
            $this->tenantApprovalActivityItems($tenantId, $windowStart, $scope, $sourceLimit),
            $this->tenantDraftStatusActivityItems($tenantId, $windowStart, $scope, $sourceLimit)
        );

        usort($items, static function (array $left, array $right): int {
            $leftTimestamp = (int) ($left['sort_timestamp'] ?? 0);
            $rightTimestamp = (int) ($right['sort_timestamp'] ?? 0);

            if ($leftTimestamp === $rightTimestamp) {
                return strcmp((string) ($right['id'] ?? ''), (string) ($left['id'] ?? ''));
            }

            return $rightTimestamp <=> $leftTimestamp;
        });

        return collect($items)
            ->unique('id')
            ->values()
            ->all();
    }

    /**
     * @param  array{
     *   strict:bool,
     *   campaign_ids:array<int,int>,
     *   recommendation_ids:array<int,int>,
     *   recipient_ids:array<int,int>
     * }  $scope
     * @return array<int,array<string,mixed>>
     */
    protected function tenantOpportunityActivityItems(
        int $tenantId,
        \Carbon\CarbonInterface $windowStart,
        array $scope,
        int $limit
    ): array {
        $query = MarketingRecommendation::query()
            ->select([
                'id',
                'type',
                'campaign_id',
                'marketing_profile_id',
                'title',
                'summary',
                'status',
                'created_at',
            ])
            ->with([
                'campaign:id,tenant_id,name',
                'profile:id,tenant_id,first_name,last_name,email',
            ])
            ->where('created_at', '>=', $windowStart)
            ->orderByDesc('created_at');

        if ((bool) ($scope['strict'] ?? false)) {
            $recommendationIds = array_values((array) ($scope['recommendation_ids'] ?? []));
            if ($recommendationIds === []) {
                return [];
            }
            $query->whereIn('id', $recommendationIds);
        } else {
            $query->where(function ($scoped) use ($tenantId): void {
                $scoped->whereHas('campaign', fn ($campaignQuery) => $campaignQuery->forTenantId($tenantId))
                    ->orWhereHas('profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(function (MarketingRecommendation $recommendation): array {
                return $this->presentActivityEvent(
                    id: 'opportunity:'.$recommendation->id,
                    eventLabel: 'Opportunity surfaced',
                    title: $this->opportunityTitle($recommendation),
                    summary: 'Suggested for review: '.Str::limit($this->opportunityWhyLine($recommendation), 150),
                    occurredAt: $recommendation->created_at,
                    actionLabel: 'Open Top Opportunities',
                    actionHref: route('shopify.app.assistant.opportunities', [], false)
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{
     *   strict:bool,
     *   campaign_ids:array<int,int>,
     *   recommendation_ids:array<int,int>,
     *   recipient_ids:array<int,int>
     * }  $scope
     * @return array<int,array<string,mixed>>
     */
    protected function tenantDraftCreatedActivityItems(
        int $tenantId,
        \Carbon\CarbonInterface $windowStart,
        array $scope,
        int $limit
    ): array {
        $query = MarketingCampaign::query()
            ->select([
                'id',
                'tenant_id',
                'name',
                'status',
                'source_label',
                'created_at',
            ])
            ->where('source_label', 'ai_assistant_draft')
            ->where('created_at', '>=', $windowStart)
            ->orderByDesc('created_at');

        if ((bool) ($scope['strict'] ?? false)) {
            $campaignIds = array_values((array) ($scope['campaign_ids'] ?? []));
            if ($campaignIds === []) {
                return [];
            }
            $query->whereIn('id', $campaignIds);
        } else {
            $query->forTenantId($tenantId);
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(function (MarketingCampaign $campaign): array {
                $title = trim((string) $campaign->name) !== ''
                    ? (string) $campaign->name
                    : 'Draft campaign';

                return $this->presentActivityEvent(
                    id: 'draft-created:'.$campaign->id,
                    eventLabel: 'Draft created',
                    title: $title,
                    summary: 'A draft was prepared from a suggestion. Human review is required before any send action.',
                    occurredAt: $campaign->created_at,
                    actionLabel: 'Review Draft',
                    actionHref: route('shopify.app.assistant.drafts', ['draft' => (int) $campaign->id], false)
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{
     *   strict:bool,
     *   campaign_ids:array<int,int>,
     *   recommendation_ids:array<int,int>,
     *   recipient_ids:array<int,int>
     * }  $scope
     * @return array<int,array<string,mixed>>
     */
    protected function tenantApprovalActivityItems(
        int $tenantId,
        \Carbon\CarbonInterface $windowStart,
        array $scope,
        int $limit
    ): array {
        $query = MarketingSendApproval::query()
            ->select([
                'id',
                'campaign_recipient_id',
                'recommendation_id',
                'status',
                'approved_at',
                'rejected_at',
                'created_at',
            ])
            ->with([
                'recommendation:id,campaign_id,marketing_profile_id,title,summary',
                'recommendation.campaign:id,tenant_id,name',
                'recommendation.profile:id,tenant_id,first_name,last_name,email',
                'campaignRecipient:id,campaign_id,marketing_profile_id,status',
                'campaignRecipient.campaign:id,tenant_id,name',
                'campaignRecipient.profile:id,tenant_id,first_name,last_name,email',
            ])
            ->whereIn('status', ['approved', 'rejected'])
            ->where(function ($scoped) use ($windowStart): void {
                $scoped->where('approved_at', '>=', $windowStart)
                    ->orWhere('rejected_at', '>=', $windowStart)
                    ->orWhere('created_at', '>=', $windowStart);
            })
            ->orderByRaw('coalesce(approved_at, rejected_at, created_at) desc');

        if ((bool) ($scope['strict'] ?? false)) {
            $recommendationIds = array_values((array) ($scope['recommendation_ids'] ?? []));
            $recipientIds = array_values((array) ($scope['recipient_ids'] ?? []));

            if ($recommendationIds === [] && $recipientIds === []) {
                return [];
            }

            $query->where(function ($scoped) use ($recommendationIds, $recipientIds): void {
                $seeded = false;

                if ($recommendationIds !== []) {
                    $scoped->whereIn('recommendation_id', $recommendationIds);
                    $seeded = true;
                }

                if ($recipientIds !== []) {
                    if ($seeded) {
                        $scoped->orWhereIn('campaign_recipient_id', $recipientIds);
                    } else {
                        $scoped->whereIn('campaign_recipient_id', $recipientIds);
                        $seeded = true;
                    }
                }

                if (! $seeded) {
                    $scoped->whereRaw('1 = 0');
                }
            });
        } else {
            $query->where(function ($scoped) use ($tenantId): void {
                $scoped->whereHas('recommendation.campaign', fn ($campaignQuery) => $campaignQuery->forTenantId($tenantId))
                    ->orWhereHas('recommendation.profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId))
                    ->orWhereHas('campaignRecipient.campaign', fn ($campaignQuery) => $campaignQuery->forTenantId($tenantId))
                    ->orWhereHas('campaignRecipient.profile', fn ($profileQuery) => $profileQuery->forTenantId($tenantId));
            });
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(function (MarketingSendApproval $approval): array {
                $status = strtolower(trim((string) $approval->status));
                $occurredAt = $status === 'approved'
                    ? ($approval->approved_at ?? $approval->created_at)
                    : ($approval->rejected_at ?? $approval->created_at);
                $title = $this->approvalActivityTitle($approval);

                if ($status === 'approved') {
                    return $this->presentActivityEvent(
                        id: 'approval:approved:'.$approval->id,
                        eventLabel: 'Approved by your team',
                        title: $title,
                        summary: 'A person approved this suggestion for manual follow-through.',
                        occurredAt: $occurredAt,
                        actionLabel: 'Open Draft Campaigns',
                        actionHref: route('shopify.app.assistant.drafts', [], false)
                    );
                }

                return $this->presentActivityEvent(
                    id: 'approval:rejected:'.$approval->id,
                    eventLabel: 'Not approved',
                    title: $title,
                    summary: 'A person decided not to move this suggestion forward.',
                    occurredAt: $occurredAt,
                    actionLabel: 'Open Top Opportunities',
                    actionHref: route('shopify.app.assistant.opportunities', [], false)
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array{
     *   strict:bool,
     *   campaign_ids:array<int,int>,
     *   recommendation_ids:array<int,int>,
     *   recipient_ids:array<int,int>
     * }  $scope
     * @return array<int,array<string,mixed>>
     */
    protected function tenantDraftStatusActivityItems(
        int $tenantId,
        \Carbon\CarbonInterface $windowStart,
        array $scope,
        int $limit
    ): array {
        $query = MarketingCampaign::query()
            ->select([
                'id',
                'tenant_id',
                'name',
                'status',
                'source_label',
                'created_at',
                'updated_at',
            ])
            ->where('source_label', 'ai_assistant_draft')
            ->where('updated_at', '>=', $windowStart)
            ->where(function ($scoped): void {
                $scoped->where('status', '!=', 'draft')
                    ->orWhereColumn('updated_at', '>', 'created_at');
            })
            ->orderByDesc('updated_at');

        if ((bool) ($scope['strict'] ?? false)) {
            $campaignIds = array_values((array) ($scope['campaign_ids'] ?? []));
            if ($campaignIds === []) {
                return [];
            }
            $query->whereIn('id', $campaignIds);
        } else {
            $query->forTenantId($tenantId);
        }

        return $query
            ->limit($limit)
            ->get()
            ->map(function (MarketingCampaign $campaign): array {
                $title = trim((string) $campaign->name) !== ''
                    ? (string) $campaign->name
                    : 'Draft campaign';
                $status = strtolower(trim((string) $campaign->status));
                $statusLabel = $this->draftStatusLabel($status);
                $summary = match ($status) {
                    'ready_for_review' => 'Status changed to Needs Review. Human review decides what happens next.',
                    'preparing' => 'Status changed to Building Draft while content is prepared for review.',
                    default => 'This draft was updated and remains under human review.',
                };

                return $this->presentActivityEvent(
                    id: 'draft-status:'.$campaign->id.':'.$status,
                    eventLabel: 'Draft status changed',
                    title: $title,
                    summary: $summary.' Current status: '.$statusLabel.'.',
                    occurredAt: $campaign->updated_at,
                    actionLabel: 'Review Draft',
                    actionHref: route('shopify.app.assistant.drafts', ['draft' => (int) $campaign->id], false)
                );
            })
            ->values()
            ->all();
    }

    /**
     * @return array{
     *   strict:bool,
     *   campaign_ids:array<int,int>,
     *   recommendation_ids:array<int,int>,
     *   recipient_ids:array<int,int>
     * }
     */
    protected function activityTenantScope(int $tenantId): array
    {
        $strict = $this->marketingOwnershipService->strictModeEnabled();
        if (! $strict) {
            return [
                'strict' => false,
                'campaign_ids' => [],
                'recommendation_ids' => [],
                'recipient_ids' => [],
            ];
        }

        $campaignIds = $this->marketingOwnershipService->tenantCampaignIds($tenantId)
            ->all();
        $recommendationIds = $this->marketingOwnershipService->tenantRecommendationIds($tenantId)
            ->all();

        $recipientIds = [];
        if ($campaignIds !== [] && Schema::hasTable('marketing_campaign_recipients')) {
            $recipientIds = DB::table('marketing_campaign_recipients')
                ->whereIn('campaign_id', $campaignIds)
                ->pluck('id')
                ->map(fn ($value): int => (int) $value)
                ->filter(fn (int $value): bool => $value > 0)
                ->values()
                ->all();
        }

        return [
            'strict' => true,
            'campaign_ids' => array_values($campaignIds),
            'recommendation_ids' => array_values($recommendationIds),
            'recipient_ids' => array_values($recipientIds),
        ];
    }

    protected function approvalActivityTitle(MarketingSendApproval $approval): string
    {
        $recommendationTitle = trim((string) ($approval->recommendation?->title ?? ''));
        if ($recommendationTitle !== '') {
            return $recommendationTitle;
        }

        $campaignTitle = trim((string) ($approval->recommendation?->campaign?->name ?? $approval->campaignRecipient?->campaign?->name ?? ''));
        if ($campaignTitle !== '') {
            return $campaignTitle;
        }

        return 'Campaign approval';
    }

    /**
     * @return array{
     *   id:string,
     *   event_label:string,
     *   title:string,
     *   summary:string,
     *   occurred_at_label:string,
     *   occurred_at_iso:?string,
     *   occurred_at_accessible:string,
     *   action_label:?string,
     *   action_href:?string,
     *   sort_timestamp:int
     * }
     */
    protected function presentActivityEvent(
        string $id,
        string $eventLabel,
        string $title,
        string $summary,
        mixed $occurredAt,
        ?string $actionLabel = null,
        ?string $actionHref = null
    ): array {
        $timestamp = $occurredAt instanceof \DateTimeInterface
            ? $occurredAt
            : null;
        $safeTitle = trim($title) !== '' ? trim($title) : trim($eventLabel);
        $safeSummary = trim($summary) !== ''
            ? Str::limit(trim($summary), 220)
            : 'Human review stays in control for this activity.';
        $resolvedActionLabel = trim((string) $actionLabel);
        $resolvedActionHref = trim((string) $actionHref);

        return [
            'id' => trim($id),
            'event_label' => trim($eventLabel) !== '' ? trim($eventLabel) : 'Activity',
            'title' => $safeTitle,
            'summary' => $safeSummary,
            'occurred_at_label' => $timestamp?->format('M j, g:i A') ?? 'Time unavailable',
            'occurred_at_iso' => $timestamp?->format(DATE_ATOM),
            'occurred_at_accessible' => $timestamp?->format('F j, Y \a\t g:i A') ?? 'Time unavailable',
            'action_label' => $resolvedActionLabel !== '' ? $resolvedActionLabel : null,
            'action_href' => $resolvedActionHref !== '' ? $resolvedActionHref : null,
            'sort_timestamp' => $timestamp?->getTimestamp() ?? 0,
        ];
    }

    /**
     * @return array{context:array<string,mixed>,tenant_id:int}|RedirectResponse
     */
    protected function resolveDraftMutationContext(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
    ): array|RedirectResponse {
        $context = $contextService->resolveMutationContext($request);
        if (! (bool) ($context['ok'] ?? false)) {
            return redirect(
                $this->urlGenerator->redirectToRoute(
                    'shopify.app.assistant.drafts',
                    [],
                    $request,
                    (string) ($context['host'] ?? null)
                )
            )->with('status_error', $this->draftMutationContextErrorMessage((string) ($context['status'] ?? 'invalid_request')));
        }

        $store = (array) ($context['store'] ?? []);
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        if ($tenantId === null) {
            return redirect(
                $this->urlGenerator->redirectToRoute(
                    'shopify.app.assistant.drafts',
                    [],
                    $request,
                    (string) ($context['host'] ?? null)
                )
            )->with('status_error', 'Tenant context is missing for this store.');
        }

        $alphaBootstrapService->ensureForTenant($tenantId, (string) ($store['key'] ?? ''));
        $draftCapability = $moduleAccessResolver->capability($tenantId, 'ai.draft_campaigns');
        if (! (bool) ($draftCapability['has_access'] ?? false)) {
            $tierMessage = trim((string) ($draftCapability['tier_message'] ?? 'Available with upgrade'));
            return redirect(
                $this->urlGenerator->redirectToRoute(
                    'shopify.app.assistant.drafts',
                    [],
                    $request,
                    (string) ($context['host'] ?? null)
                )
            )->with('status_error', sprintf('Draft Campaigns is %s for this tenant right now.', strtolower($tierMessage)));
        }

        return [
            'context' => $context,
            'tenant_id' => $tenantId,
        ];
    }

    protected function draftMutationContextErrorMessage(string $status): string
    {
        return match ($status) {
            'missing_context_token' => 'This action is missing its page context token. Refresh and try again.',
            'invalid_context_token' => 'This action could not be matched to the current Shopify page context.',
            'missing_api_auth' => 'Embedded authentication is missing. Reload this page from Shopify Admin and try again.',
            default => 'This request could not be verified from Shopify context.',
        };
    }

    protected function recommendationInTenantScope(MarketingRecommendation $recommendation, int $tenantId): bool
    {
        if ($this->marketingOwnershipService->strictModeEnabled()) {
            return $this->marketingOwnershipService->recommendationOwnedByTenant((int) $recommendation->id, $tenantId);
        }

        $recommendation->loadMissing([
            'campaign:id,tenant_id',
            'profile:id,tenant_id',
        ]);

        $campaignTenantId = (int) ($recommendation->campaign?->tenant_id ?? 0);
        $profileTenantId = (int) ($recommendation->profile?->tenant_id ?? 0);

        return $campaignTenantId === $tenantId || $profileTenantId === $tenantId;
    }

    protected function campaignInTenantScope(MarketingCampaign $campaign, int $tenantId): bool
    {
        if ($this->marketingOwnershipService->strictModeEnabled()) {
            return $this->marketingOwnershipService->campaignOwnedByTenant((int) $campaign->id, $tenantId);
        }

        return (int) ($campaign->tenant_id ?? 0) === $tenantId;
    }

    /**
     * @return array{
     *   module:array<string,mixed>,
     *   capabilities:array<string,array<string,mixed>>,
     *   surfaces:array<string,array<string,mixed>>
     * }
     */
    protected function assistantAccessContext(TenantModuleAccessResolver $moduleAccessResolver, int $tenantId): array
    {
        $module = $moduleAccessResolver->module($tenantId, 'ai');
        $capabilityKeys = $this->assistantCapabilityKeys();
        $capabilities = $moduleAccessResolver->resolveCapabilitiesForTenant($tenantId, $capabilityKeys);

        $surfaceMap = [
            'start' => 'ai.start_here',
            'opportunities' => 'ai.opportunities',
            'drafts' => 'ai.draft_campaigns',
            'setup' => 'ai.setup',
            'activity' => 'ai.activity',
        ];
        $surfaces = [];

        foreach ($surfaceMap as $surfaceKey => $capabilityKey) {
            $surfaces[$surfaceKey] = is_array($capabilities[$capabilityKey] ?? null)
                ? (array) $capabilities[$capabilityKey]
                : $this->defaultAssistantCapabilityState($capabilityKey, $surfaceKey);
        }

        return [
            'module' => $module,
            'capabilities' => $capabilities,
            'surfaces' => $surfaces,
        ];
    }

    /**
     * @return array{
     *   module:array<string,mixed>,
     *   capabilities:array<string,array<string,mixed>>,
     *   surfaces:array<string,array<string,mixed>>
     * }
     */
    protected function defaultAssistantAccessContext(): array
    {
        $surfaceMap = [
            'start' => 'ai.start_here',
            'opportunities' => 'ai.opportunities',
            'drafts' => 'ai.draft_campaigns',
            'setup' => 'ai.setup',
            'activity' => 'ai.activity',
        ];
        $surfaces = [];
        foreach ($surfaceMap as $surfaceKey => $capabilityKey) {
            $surfaces[$surfaceKey] = $this->defaultAssistantCapabilityState($capabilityKey, $surfaceKey);
        }

        return [
            'module' => $this->defaultAssistantModule(),
            'capabilities' => [],
            'surfaces' => $surfaces,
        ];
    }

    /**
     * @param  array<string,mixed>  $assistantAccess
     * @return array<string,mixed>
     */
    protected function assistantCapabilityForSurface(array $assistantAccess, string $activeKey): array
    {
        $surfaceKey = strtolower(trim($activeKey));
        $surfaceState = is_array($assistantAccess['surfaces'][$surfaceKey] ?? null)
            ? (array) $assistantAccess['surfaces'][$surfaceKey]
            : null;
        if ($surfaceState !== null) {
            return $surfaceState;
        }

        $capabilityKey = match ($surfaceKey) {
            'opportunities' => 'ai.opportunities',
            'drafts' => 'ai.draft_campaigns',
            'setup' => 'ai.setup',
            'activity' => 'ai.activity',
            default => 'ai.start_here',
        };

        return $this->defaultAssistantCapabilityState($capabilityKey, $surfaceKey);
    }

    /**
     * @return array{label:string,href:string}
     */
    protected function assistantLockedCta(array $surfaceCapability): array
    {
        $tierMessage = strtolower(trim((string) ($surfaceCapability['tier_message'] ?? '')));
        $cta = strtolower(trim((string) ($surfaceCapability['cta'] ?? 'none')));

        if ($tierMessage === 'coming soon') {
            return [
                'label' => 'Open Start Here',
                'href' => route('shopify.app.assistant.start', [], false),
            ];
        }

        return match ($cta) {
            'add' => [
                'label' => 'Review add-ons',
                'href' => route('shopify.app.store', ['module' => 'ai', 'intent' => 'add'], false),
            ],
            'request' => [
                'label' => 'Contact sales',
                'href' => route('shopify.app.plans', ['module' => 'ai'], false),
            ],
            default => [
                'label' => 'Review plans and module access',
                'href' => route('shopify.app.plans', ['module' => 'ai'], false),
            ],
        };
    }

    protected function lockedSurfaceMessage(string $activeKey, string $tierMessage): string
    {
        $surfaceLabel = $this->surfaceLabel($activeKey);
        $message = trim($tierMessage);

        if ($message === '') {
            return sprintf('%s is locked for this tenant right now.', $surfaceLabel);
        }

        if (strtolower($message) === 'coming soon') {
            return sprintf('%s is coming soon for this tenant.', $surfaceLabel);
        }

        if (strtolower($message) === 'contact sales') {
            return sprintf('%s is available by contacting sales for this tenant.', $surfaceLabel);
        }

        return sprintf('%s is %s for this tenant right now.', $surfaceLabel, strtolower($message));
    }

    /**
     * @return array{
     *   module_key:string,
     *   label:string,
     *   has_access:bool,
     *   enabled:bool,
     *   ui_state:string,
     *   setup_status:string,
     *   reason:string,
     *   cta:string,
     *   tier_message:string
     * }
     */
    protected function defaultAssistantCapabilityState(string $capabilityKey, string $surfaceKey): array
    {
        $label = match ($surfaceKey) {
            'opportunities' => 'Top Opportunities',
            'drafts' => 'Draft Campaigns',
            'setup' => 'Setup',
            'activity' => 'Activity',
            default => 'Start Here',
        };

        return [
            'capability_key' => $capabilityKey,
            'module_key' => 'ai',
            'label' => $label,
            'has_access' => false,
            'enabled' => false,
            'ui_state' => 'locked',
            'setup_status' => 'not_started',
            'reason' => 'tenant_not_mapped',
            'cta' => 'upgrade',
            'tier_message' => 'Available with upgrade',
        ];
    }

    /**
     * @return array{
     *   module_key:string,
     *   label:string,
     *   has_access:bool,
     *   ui_state:string,
     *   setup_status:string,
     *   reason:string
     * }
     */
    protected function defaultAssistantModule(): array
    {
        return [
            'module_key' => 'ai',
            'label' => 'AI Assistant',
            'has_access' => false,
            'ui_state' => 'locked',
            'setup_status' => 'not_started',
            'reason' => 'tenant_not_mapped',
        ];
    }

    /**
     * @return array<int,string>
     */
    protected function assistantCapabilityKeys(): array
    {
        return [
            'ai.assistant',
            'ai.start_here',
            'ai.opportunities',
            'ai.draft_campaigns',
            'ai.setup',
            'ai.activity',
        ];
    }

    protected function surfaceLabel(string $activeKey): string
    {
        return match ($activeKey) {
            'opportunities' => 'Top Opportunities',
            'drafts' => 'Draft Campaigns',
            'setup' => 'Setup',
            'activity' => 'Activity',
            default => 'Start Here',
        };
    }

    protected function surfaceSummary(string $activeKey): string
    {
        return match ($activeKey) {
            'opportunities' => 'Recommendation-backed next actions with plain-English priority and explainable context.',
            'drafts' => 'Human-reviewed campaign drafts with clear audience, message, and next-step guidance.',
            'setup' => 'Simple setup checklist for AI readiness and next-step actions.',
            'activity' => 'Recent AI Assistant suggestions and draft history with clear human-review context.',
            default => 'Get oriented quickly and decide your next best click.',
        };
    }

    /**
     * @return array<int,string>
     */
    protected function surfaceStubItems(string $activeKey): array
    {
        return match ($activeKey) {
            'opportunities' => [
                'Routing and access control are active for this surface.',
                'Opportunity scoring and recommendations will be added in a later stage.',
            ],
            'setup' => [
                'Routing and access control are active for this surface.',
                'Detailed setup workflows will be added in a later stage.',
            ],
            'activity' => [
                'Routing and access control are active for this surface.',
                'AI activity timelines will be added in a later stage.',
            ],
            default => [
                'Routing and access control are active for this surface.',
                'Stage 1 is foundation only; operational AI workflows are deferred.',
            ],
        };
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
