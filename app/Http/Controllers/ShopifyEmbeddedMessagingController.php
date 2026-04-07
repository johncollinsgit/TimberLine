<?php

namespace App\Http\Controllers;

use App\Models\MarketingMessageMediaAsset;
use App\Models\MarketingEmailDelivery;
use App\Models\MarketingMessageDelivery;
use App\Models\TenantModuleState;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use App\Services\Marketing\MessagingResponseInboxService;
use App\Services\Marketing\MessageAnalyticsService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedMessagingWorkspaceService;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Shopify\ShopifyStorefrontTrackingSetupService;
use App\Services\Shopify\ShopifyWebPixelConnectionService;
use App\Services\Tenancy\ModernForestryAlphaBootstrapService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;
use Illuminate\Validation\ValidationException;

class ShopifyEmbeddedMessagingController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
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

        $messagingModule = $tenantId !== null
            ? $probe->time('module_access', fn (): array => $moduleAccessResolver->module($tenantId, 'messaging'))
            : [
                'module_key' => 'messaging',
                'has_access' => false,
                'ui_state' => 'locked',
                'reason' => 'tenant_not_mapped',
            ];
        $hasMessagingAccess = (bool) ($messagingModule['has_access'] ?? false);

        $bootstrap = ($authorized && $tenantId !== null && $hasMessagingAccess)
            ? $probe->time('page_payload', fn (): array => [
                'groups' => $workspaceService->groups($tenantId, includeAutoCounts: false),
                'templates' => $workspaceService->emailTemplateDefinitions(),
                'audience_summary' => $workspaceService->audienceSummary($tenantId),
            ])
            : null;

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('messaging', 'workspace', $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->embeddedMessagingSubnav('workspace', $tenantId));

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.messaging', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')) . ' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status),
                'subheadline' => $this->subheadlineForStatus($status),
                'appNavigation' => $appNavigation,
                'pageSubnav' => $pageSubnav,
                'pageActions' => [],
                'messagingModuleState' => $messagingModule,
                'messagingAccess' => [
                    'enabled' => $hasMessagingAccess,
                    'status' => $tenantId === null
                        ? 'tenant_not_mapped'
                        : ($hasMessagingAccess ? 'enabled' : 'messaging_module_locked'),
                    'message' => $tenantId === null
                        ? 'This Shopify store is not mapped to a tenant yet.'
                        : ($hasMessagingAccess
                            ? null
                            : 'Messaging is not enabled for this tenant. Enable the Messaging module to use this workspace.'),
                ],
                'messagingBootstrap' => [
                    'authorized' => $authorized,
                    'tenant_id' => $tenantId,
                    'status' => $status,
                    'module_access' => $hasMessagingAccess,
                    'module_state' => $messagingModule,
                    'data' => $bootstrap,
                    'endpoints' => [
                        'bootstrap' => route('shopify.app.api.messaging.bootstrap', [], false),
                        'audience_summary' => route('shopify.app.api.messaging.audience.summary', [], false),
                    'search_customers' => route('shopify.app.api.messaging.customers.search', [], false),
                    'search_products' => route('shopify.app.api.messaging.products.search', [], false),
                    'media_list' => route('shopify.app.api.messaging.media.index', [], false),
                    'media_upload' => route('shopify.app.api.messaging.media.store', [], false),
                    'groups' => route('shopify.app.api.messaging.groups', [], false),
                    'group_detail_base' => route('shopify.app.api.messaging.groups.detail', ['group' => '__GROUP__'], false),
                    'create_group' => route('shopify.app.api.messaging.groups.create', [], false),
                        'update_group_base' => route('shopify.app.api.messaging.groups.update', ['group' => '__GROUP__'], false),
                        'preview_group' => route('shopify.app.api.messaging.preview.group', [], false),
                        'send_individual' => route('shopify.app.api.messaging.send.individual', [], false),
                        'send_group' => route('shopify.app.api.messaging.send.group', [], false),
                        'cancel_campaign_base' => route('shopify.app.api.messaging.campaigns.cancel', ['campaign' => '__CAMPAIGN__'], false),
                        'smoke_sms' => route('shopify.app.api.messaging.smoke.sms', [], false),
                        'smoke_email' => route('shopify.app.api.messaging.smoke.email', [], false),
                        'history' => route('shopify.app.api.messaging.history', [], false),
                    ],
                ],
            ]),
            $this->pageStatusCode($authorized, $status, $tenantId, $hasMessagingAccess)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
            'messaging_enabled' => $hasMessagingAccess,
        ])->finish($response);
    }

    public function responses(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
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

        $messagingModule = $tenantId !== null
            ? $probe->time('module_access', fn (): array => $moduleAccessResolver->module($tenantId, 'messaging'))
            : [
                'module_key' => 'messaging',
                'has_access' => false,
                'ui_state' => 'locked',
                'reason' => 'tenant_not_mapped',
            ];
        $hasMessagingAccess = (bool) ($messagingModule['has_access'] ?? false);

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('messaging', 'responses', $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->embeddedMessagingSubnav('responses', $tenantId));

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.messaging-responses', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')) . ' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, 'Responses'),
                'subheadline' => $this->subheadlineForStatus($status, 'Review inbound SMS and email replies in one Backstage inbox.'),
                'appNavigation' => $appNavigation,
                'pageSubnav' => $pageSubnav,
                'pageActions' => [],
                'messagingModuleState' => $messagingModule,
                'messagingAccess' => [
                    'enabled' => $hasMessagingAccess,
                    'status' => $tenantId === null
                        ? 'tenant_not_mapped'
                        : ($hasMessagingAccess ? 'enabled' : 'messaging_module_locked'),
                    'message' => $tenantId === null
                        ? 'This Shopify store is not mapped to a tenant yet.'
                        : ($hasMessagingAccess
                            ? null
                            : 'Messaging is not enabled for this tenant. Enable the Messaging module to use Responses.'),
                ],
                'messagingResponsesBootstrap' => [
                    'authorized' => $authorized,
                    'tenant_id' => $tenantId,
                    'status' => $status,
                    'module_access' => $hasMessagingAccess,
                    'endpoints' => [
                        'index' => route('shopify.app.api.messaging.responses.index', [], false),
                        'detail_base' => route('shopify.app.api.messaging.responses.show', ['conversation' => '__CONVERSATION__'], false),
                        'update_base' => route('shopify.app.api.messaging.responses.update', ['conversation' => '__CONVERSATION__'], false),
                        'reply_base' => route('shopify.app.api.messaging.responses.reply', ['conversation' => '__CONVERSATION__'], false),
                    ],
                ],
            ]),
            $this->pageStatusCode($authorized, $status, $tenantId, $hasMessagingAccess)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
            'messaging_enabled' => $hasMessagingAccess,
        ])->finish($response);
    }

    public function analytics(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        MessageAnalyticsService $messageAnalyticsService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
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

        $messagingModule = $tenantId !== null
            ? $probe->time('module_access', fn (): array => $moduleAccessResolver->module($tenantId, 'messaging'))
            : [
                'module_key' => 'messaging',
                'has_access' => false,
                'ui_state' => 'locked',
                'reason' => 'tenant_not_mapped',
            ];
        $hasMessagingAccess = (bool) ($messagingModule['has_access'] ?? false);
        $storeKey = $this->nullableString(data_get($store, 'key'));
        $filters = $messageAnalyticsService->normalizeFilters($request->query());
        $analyticsTab = strtolower(trim((string) $request->query('analytics_tab', 'home')));
        if (! in_array($analyticsTab, ['home', 'performance', 'history', 'sales_success'], true)) {
            $analyticsTab = 'home';
        }
        $analyticsLoadOptions = match ($analyticsTab) {
            'performance' => [
                'include_messages' => true,
                'include_history_outcomes' => false,
                'include_sales_success' => false,
            ],
            'history' => [
                'include_messages' => false,
                'include_history_outcomes' => true,
                'include_sales_success' => false,
            ],
            'sales_success' => [
                'include_messages' => false,
                'include_history_outcomes' => false,
                'include_sales_success' => true,
            ],
            default => [
                'include_messages' => false,
                'include_history_outcomes' => false,
                'include_sales_success' => false,
            ],
        };
        $analyticsPayload = ($authorized && $tenantId !== null && $hasMessagingAccess)
            ? $probe->time('page_payload', fn (): array => $messageAnalyticsService->index($tenantId, $storeKey, $filters, $analyticsLoadOptions))
            : null;

        $messageKey = trim((string) $request->query('message_key', ''));
        $detail = (is_array($analyticsPayload) && $messageKey !== '' && $analyticsTab === 'performance')
            ? $probe->time('page_payload', fn (): ?array => $messageAnalyticsService->detail($tenantId, $storeKey, $messageKey))
            : null;

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('messaging', 'analytics', $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->embeddedMessagingSubnav('analytics', $tenantId));

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.messaging-analytics', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')) . ' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, 'Message Analytics'),
                'subheadline' => $this->subheadlineForStatus($status, 'Track opens, clicks, URLs, and attributed orders from message sends.'),
                'appNavigation' => $appNavigation,
                'pageSubnav' => $pageSubnav,
                'pageActions' => [],
                'messagingModuleState' => $messagingModule,
                'messagingAccess' => $this->messagingAccessPayload($tenantId, $hasMessagingAccess, 'analytics'),
                'messageAnalyticsFilters' => [
                    'date_from' => ($filters['date_from'] instanceof \DateTimeInterface)
                        ? $filters['date_from']->format('Y-m-d')
                        : null,
                    'date_to' => ($filters['date_to'] instanceof \DateTimeInterface)
                        ? $filters['date_to']->format('Y-m-d')
                        : null,
                    'channel' => $filters['channel'] ?? 'all',
                    'opened' => $filters['opened'] ?? 'all',
                    'clicked' => $filters['clicked'] ?? 'all',
                    'has_orders' => (bool) ($filters['has_orders'] ?? false),
                    'url_search' => $filters['url_search'] ?? null,
                    'customer' => $filters['customer'] ?? null,
                    'message' => $filters['message'] ?? null,
                    'per_page' => (int) ($filters['per_page'] ?? 25),
                ],
                'messageAnalytics' => $analyticsPayload,
                'messageAnalyticsTab' => $analyticsTab,
                'messageAnalyticsDetail' => $detail,
                'messageAnalyticsSelectedMessageKey' => $messageKey !== '' ? $messageKey : null,
                'messageAnalyticsAttribution' => [
                    'model' => 'last_click_within_window',
                    'window_days' => max(1, (int) config('marketing.message_analytics.attribution_window_days', 7)),
                ],
            ]),
            $this->pageStatusCode($authorized, $status, $tenantId, $hasMessagingAccess)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
            'messaging_enabled' => $hasMessagingAccess,
        ])->finish($response);
    }

    public function setup(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        TenantEmailSettingsService $tenantEmailSettingsService,
        ShopifyStorefrontTrackingSetupService $storefrontTrackingSetupService,
        ModernForestryAlphaBootstrapService $alphaBootstrapService
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

        $messagingModule = $tenantId !== null
            ? $probe->time('module_access', fn (): array => $moduleAccessResolver->module($tenantId, 'messaging'))
            : [
                'module_key' => 'messaging',
                'has_access' => false,
                'ui_state' => 'locked',
                'reason' => 'tenant_not_mapped',
            ];
        $hasMessagingAccess = (bool) ($messagingModule['has_access'] ?? false);
        $storeKey = $this->nullableString(data_get($store, 'key'));
        $emailSettings = ($authorized && $tenantId !== null && $hasMessagingAccess)
            ? $probe->time('page_payload', fn (): array => $tenantEmailSettingsService->forAdmin($tenantId))
            : [];
        $messagesSent = ($authorized && $tenantId !== null && $hasMessagingAccess)
            ? $probe->time('page_payload', fn (): int => $this->trackedMessageSendCount($tenantId, $storeKey))
            : 0;
        $storefrontTracking = $authorized
            ? $probe->time('page_payload', fn (): array => $storefrontTrackingSetupService->build($store, $context['host'] ?? null))
            : [];
        $setupGuide = $this->buildMessagingSetupGuide(
            module: $messagingModule,
            hasMessagingAccess: $hasMessagingAccess,
            emailSettings: $emailSettings,
            messagesSent: $messagesSent,
            storefrontTracking: $storefrontTracking
        );

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('messaging', 'setup', $tenantId));
        $pageSubnav = $probe->time('shell_payload', fn (): array => $this->embeddedMessagingSubnav('setup', $tenantId));

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.messaging-setup', [
                'authorized' => $authorized,
                'status' => $status,
                'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
                'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
                'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
                'storeLabel' => $authorized
                    ? ucfirst((string) ($store['key'] ?? 'store')) . ' Store'
                    : 'Shopify Admin',
                'headline' => $this->headlineForStatus($status, 'Messaging Setup'),
                'subheadline' => $this->subheadlineForStatus($status, 'Finish module setup and storefront tracking before running campaigns.'),
                'appNavigation' => $appNavigation,
                'pageSubnav' => $pageSubnav,
                'pageActions' => [],
                'messagingModuleState' => $messagingModule,
                'messagingAccess' => $this->messagingAccessPayload($tenantId, $hasMessagingAccess, 'setup'),
                'messagingSetupGuide' => $setupGuide,
                'messageAnalyticsTrackingEndpoints' => [
                    'status' => route('shopify.app.api.messaging.storefront-tracking.status', [], false),
                    'connect_pixel' => route('shopify.app.api.messaging.storefront-tracking.connect-pixel', [], false),
                ],
            ]),
            $this->pageStatusCode($authorized, $status, $tenantId, $hasMessagingAccess)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
            'messaging_enabled' => $hasMessagingAccess,
        ])->finish($response);
    }

    public function completeSetup(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        if (! Schema::hasTable('tenant_module_states')) {
            return response()->json([
                'ok' => false,
                'status' => 'setup_state_unavailable',
                'message' => 'Module setup state storage is unavailable on this environment.',
            ], 503);
        }

        $tenantId = (int) $access['tenant_id'];
        $state = TenantModuleState::query()->firstOrNew([
            'tenant_id' => $tenantId,
            'module_key' => 'messaging',
        ]);

        $metadata = is_array($state->metadata) ? $state->metadata : [];
        $state->setup_status = 'configured';
        $state->setup_completed_at = now();
        $state->metadata = [
            ...$metadata,
            'configured_via' => 'shopify_embedded_messaging_setup',
            'configured_at' => now()->toIso8601String(),
        ];
        $state->save();

        return response()->json([
            'ok' => true,
            'message' => 'Messaging setup marked complete.',
            'data' => [
                'module_key' => 'messaging',
                'setup_status' => (string) $state->setup_status,
                'setup_completed_at' => optional($state->setup_completed_at)->toIso8601String(),
            ],
        ]);
    }

    public function storefrontTrackingStatus(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyStorefrontTrackingSetupService $storefrontTrackingSetupService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $context = (array) ($access['context'] ?? []);
        $tracking = $storefrontTrackingSetupService->build(
            (array) ($context['store'] ?? []),
            $this->nullableString($context['host'] ?? null)
        );

        return response()->json([
            'ok' => true,
            'tracking' => $tracking,
        ]);
    }

    public function connectStorefrontPixel(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyWebPixelConnectionService $webPixelConnectionService,
        ShopifyStorefrontTrackingSetupService $storefrontTrackingSetupService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $context = (array) ($access['context'] ?? []);
        $store = (array) ($context['store'] ?? []);
        $result = $webPixelConnectionService->connect($store);

        if (! (bool) ($result['ok'] ?? false)) {
            $status = (string) ($result['status'] ?? 'shopify_error');
            $httpStatus = match ($status) {
                'missing_scopes', 'store_not_installed' => 422,
                default => 500,
            };

            return response()->json([
                'ok' => false,
                'status' => $status,
                'message' => (string) ($result['message'] ?? 'Could not connect the Shopify web pixel.'),
                'details' => $result,
            ], $httpStatus);
        }

        $tracking = $storefrontTrackingSetupService->build($store, $this->nullableString($context['host'] ?? null));

        return response()->json([
            'ok' => true,
            'status' => (string) ($result['status'] ?? 'connected'),
            'message' => (string) ($result['message'] ?? 'Shopify web pixel connected.'),
            'pixel' => $result,
            'tracking' => $tracking,
        ]);
    }

    public function bootstrap(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $tenantId = (int) $access['tenant_id'];

        return response()->json([
            'ok' => true,
            'data' => [
                'groups' => $workspaceService->groups($tenantId, includeAutoCounts: false),
                'templates' => $workspaceService->emailTemplateDefinitions(),
            ],
        ]);
    }

    public function audienceSummary(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $tenantId = (int) $access['tenant_id'];
        $summary = $workspaceService->audienceSummary($tenantId);

        return response()->json([
            'ok' => true,
            'data' => [
                'all_subscribed_summary' => (array) ($summary['summary'] ?? []),
                'group_summaries' => (array) ($summary['group_summaries'] ?? []),
                'diagnostics' => (array) ($summary['diagnostics'] ?? []),
            ],
        ]);
    }

    public function searchCustomers(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->query(), [
                'q' => ['nullable', 'string', 'max:120'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Search query is invalid.', $exception);
        }

        $results = $workspaceService->searchCustomers(
            query: trim((string) ($data['q'] ?? '')),
            tenantId: (int) $access['tenant_id'],
            limit: isset($data['limit']) ? (int) $data['limit'] : 12
        );

        return response()->json([
            'ok' => true,
            'data' => $results,
        ]);
    }

    public function searchProducts(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->query(), [
                'q' => ['required', 'string', 'max:120'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Product search query is invalid.', $exception);
        }

        try {
            $results = $workspaceService->searchProducts(
                query: trim((string) ($data['q'] ?? '')),
                storeContext: (array) data_get($access, 'context.store', []),
                limit: isset($data['limit']) ? (int) $data['limit'] : 12
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Product search failed.', $exception);
        }

        return response()->json([
            'ok' => true,
            'data' => $results,
        ]);
    }

    public function mediaIndex(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        if (! Schema::hasTable('marketing_message_media_assets')) {
            return response()->json([
                'ok' => false,
                'message' => 'Messaging media library is not available on this environment yet.',
            ], 503);
        }

        try {
            $data = validator($request->query(), [
                'channel' => ['nullable', 'in:email,sms'],
                'limit' => ['nullable', 'integer', 'min:1', 'max:48'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Media library query is invalid.', $exception);
        }

        $tenantId = (int) $access['tenant_id'];
        $storeKey = $this->normalizedStoreKeyFromAccess($access);
        $channel = (string) ($data['channel'] ?? 'email');
        $limit = isset($data['limit']) ? (int) $data['limit'] : 18;

        $assets = MarketingMessageMediaAsset::query()
            ->forTenantId($tenantId)
            ->when(
                $storeKey !== null,
                fn ($query) => $query->where('store_key', $storeKey),
                fn ($query) => $query->whereNull('store_key')
            )
            ->where('channel', $channel)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get()
            ->map(fn (MarketingMessageMediaAsset $asset): array => $this->serializeMediaAsset($asset))
            ->values()
            ->all();

        return response()->json([
            'ok' => true,
            'data' => $assets,
        ]);
    }

    public function mediaStore(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        if (! Schema::hasTable('marketing_message_media_assets')) {
            return response()->json([
                'ok' => false,
                'message' => 'Messaging media library is not available on this environment yet.',
            ], 503);
        }

        try {
            $data = validator(
                [
                    'image' => $request->file('image'),
                    'channel' => $request->input('channel'),
                    'alt_text' => $request->input('alt_text'),
                ],
                [
                    'image' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,gif,webp', 'max:8192'],
                    'channel' => ['nullable', 'in:email,sms'],
                    'alt_text' => ['nullable', 'string', 'max:255'],
                ]
            )->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Image upload failed.', $exception);
        }

        $file = $request->file('image');
        if (! $file?->isValid()) {
            return response()->json([
                'ok' => false,
                'message' => 'Image upload failed.',
                'errors' => [
                    'image' => ['Choose a valid image file before uploading.'],
                ],
            ], 422);
        }

        $tenantId = (int) $access['tenant_id'];
        $storeKey = $this->normalizedStoreKeyFromAccess($access);
        $channel = (string) ($data['channel'] ?? 'email');
        $disk = 'public';
        $directory = sprintf(
            'messaging-media/tenant-%d/%s/%s',
            $tenantId,
            $storeKey ?? 'shared',
            $channel
        );
        $extension = strtolower((string) $file->getClientOriginalExtension());
        $filename = sprintf(
            '%s-%s%s',
            now()->format('YmdHis'),
            Str::random(12),
            $extension !== '' ? '.'.$extension : ''
        );
        $path = $file->storeAs($directory, $filename, $disk);
        $dimensions = @getimagesize($file->getRealPath() ?: '');

        $asset = MarketingMessageMediaAsset::query()->create([
            'tenant_id' => $tenantId,
            'store_key' => $storeKey,
            'channel' => $channel,
            'disk' => $disk,
            'path' => $path,
            'public_url' => Storage::disk($disk)->url($path),
            'original_name' => (string) $file->getClientOriginalName(),
            'mime_type' => (string) ($file->getMimeType() ?: 'application/octet-stream'),
            'size_bytes' => (int) $file->getSize(),
            'width' => is_array($dimensions) ? (int) ($dimensions[0] ?? 0) ?: null : null,
            'height' => is_array($dimensions) ? (int) ($dimensions[1] ?? 0) ?: null : null,
            'alt_text' => $this->nullableString($data['alt_text'] ?? null),
            'uploaded_by' => auth()->id() !== null ? (int) auth()->id() : null,
            'metadata' => [
                'source' => 'shopify_embedded_messaging',
            ],
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Image uploaded.',
            'data' => $this->serializeMediaAsset($asset),
        ]);
    }

    public function groups(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        return response()->json([
            'ok' => true,
            'data' => $workspaceService->groups((int) $access['tenant_id']),
        ]);
    }

    public function groupDetail(
        Request $request,
        string $group,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $groupId = (int) $group;
        if ($groupId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Group not found for this tenant.',
            ], 404);
        }

        $payload = $workspaceService->groupDetailById($groupId, (int) $access['tenant_id']);
        if (! is_array($payload)) {
            return response()->json([
                'ok' => false,
                'message' => 'Group not found for this tenant.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ]);
    }

    public function createGroup(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->all(), [
                'name' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:500'],
                'member_profile_ids' => ['required', 'array', 'min:1'],
                'member_profile_ids.*' => ['integer', 'min:1'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Group could not be saved.', $exception);
        }

        try {
            $payload = $workspaceService->createGroup(
                tenantId: (int) $access['tenant_id'],
                name: (string) $data['name'],
                profileIds: array_map('intval', (array) $data['member_profile_ids']),
                description: isset($data['description']) ? (string) $data['description'] : null,
                actorId: auth()->id() !== null ? (int) auth()->id() : null
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Group could not be saved.', $exception);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Group saved.',
            'data' => $payload,
        ]);
    }

    public function updateGroup(
        Request $request,
        string $group,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $groupId = (int) $group;
        if ($groupId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Group not found for this tenant.',
            ], 404);
        }

        try {
            $data = validator($request->all(), [
                'name' => ['required', 'string', 'max:120'],
                'description' => ['nullable', 'string', 'max:500'],
                'member_profile_ids' => ['required', 'array', 'min:1'],
                'member_profile_ids.*' => ['integer', 'min:1'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Group could not be updated.', $exception);
        }

        try {
            $payload = $workspaceService->updateGroup(
                groupId: $groupId,
                tenantId: (int) $access['tenant_id'],
                name: (string) $data['name'],
                profileIds: array_map('intval', (array) $data['member_profile_ids']),
                description: isset($data['description']) ? (string) $data['description'] : null
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Group could not be updated.', $exception);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Group updated.',
            'data' => $payload,
        ]);
    }

    public function sendIndividual(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->all(), [
                'profile_id' => ['required', 'integer', 'min:1'],
                'channel' => ['required', 'in:sms,email'],
                'subject' => ['nullable', 'string', 'max:200', 'required_if:channel,email'],
                'body' => ['nullable', 'string', 'max:5000', 'required_if:channel,sms'],
                'sender_key' => ['nullable', 'string', 'max:80'],
                'email_template_mode' => ['nullable', 'in:sections,legacy_html'],
                'email_sections' => ['nullable', 'array', 'max:60'],
                'email_advanced_html' => ['nullable', 'string', 'max:200000'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Message could not be sent.', $exception);
        }

        if (($data['channel'] ?? null) === 'email' && ! $this->emailPayloadHasContent($data)) {
            return response()->json([
                'ok' => false,
                'message' => 'Message could not be sent.',
                'errors' => [
                    'body' => ['Add message text, email sections, or advanced HTML before sending.'],
                ],
            ], 422);
        }

        try {
            $payload = $workspaceService->sendIndividual(
                tenantId: (int) $access['tenant_id'],
                profileId: (int) $data['profile_id'],
                channel: (string) $data['channel'],
                body: (string) ($data['body'] ?? ''),
                subject: isset($data['subject']) ? (string) $data['subject'] : null,
                senderKey: isset($data['sender_key']) ? (string) $data['sender_key'] : null,
                actorId: auth()->id() !== null ? (int) auth()->id() : null,
                storeKey: (string) data_get($access, 'context.store.key', ''),
                emailTemplateMode: isset($data['email_template_mode']) ? (string) $data['email_template_mode'] : null,
                emailSections: $data['email_sections'] ?? null,
                emailAdvancedHtml: isset($data['email_advanced_html']) ? (string) $data['email_advanced_html'] : null,
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Message could not be sent.', $exception);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Message sent.',
            'data' => $payload,
        ]);
    }

    public function sendGroup(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->all(), [
                'target_type' => ['required', 'in:saved,auto'],
                'group_id' => ['nullable', 'integer', 'min:1', 'required_if:target_type,saved'],
                'group_key' => ['nullable', 'string', 'max:120', 'required_if:target_type,auto'],
                'channel' => ['required', 'in:sms,email'],
                'subject' => ['nullable', 'string', 'max:200', 'required_if:channel,email'],
                'body' => ['nullable', 'string', 'max:5000', 'required_if:channel,sms'],
                'sender_key' => ['nullable', 'string', 'max:80'],
                'email_template_mode' => ['nullable', 'in:sections,legacy_html'],
                'email_template_key' => ['nullable', 'string', 'max:80'],
                'email_sections' => ['nullable', 'array', 'max:60'],
                'email_advanced_html' => ['nullable', 'string', 'max:200000'],
                'schedule_for' => ['nullable', 'date'],
                'shorten_links' => ['nullable', 'boolean'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Message could not be sent.', $exception);
        }

        if (($data['channel'] ?? null) === 'email' && ! $this->emailPayloadHasContent($data)) {
            return response()->json([
                'ok' => false,
                'message' => 'Message could not be sent.',
                'errors' => [
                    'body' => ['Add message text, email sections, or advanced HTML before sending.'],
                ],
            ], 422);
        }

        $tenantId = (int) $access['tenant_id'];
        try {
            $payload = $workspaceService->sendGroup(
                tenantId: $tenantId,
                targetType: (string) $data['target_type'],
                groupId: isset($data['group_id']) ? (int) $data['group_id'] : null,
                groupKey: isset($data['group_key']) ? (string) $data['group_key'] : null,
                channel: (string) $data['channel'],
                body: (string) ($data['body'] ?? ''),
                subject: isset($data['subject']) ? (string) $data['subject'] : null,
                senderKey: isset($data['sender_key']) ? (string) $data['sender_key'] : null,
                actorId: auth()->id() !== null ? (int) auth()->id() : null,
                storeKey: (string) data_get($access, 'context.store.key', ''),
                emailTemplateMode: isset($data['email_template_mode']) ? (string) $data['email_template_mode'] : null,
                emailTemplateKey: isset($data['email_template_key']) ? (string) $data['email_template_key'] : null,
                emailSections: $data['email_sections'] ?? null,
                emailAdvancedHtml: isset($data['email_advanced_html']) ? (string) $data['email_advanced_html'] : null,
                scheduleFor: $data['schedule_for'] ?? null,
                shortenLinks: (bool) ($data['shorten_links'] ?? false),
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Message could not be sent.', $exception);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'ok' => false,
                'message' => 'Message could not be scheduled.',
                'status' => 'messaging_send_failed',
            ], 500);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Message sent.',
            'data' => $payload,
        ]);
    }

    public function previewGroup(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->all(), [
                'target_type' => ['required', 'in:saved,auto'],
                'group_id' => ['nullable', 'integer', 'min:1', 'required_if:target_type,saved'],
                'group_key' => ['nullable', 'string', 'max:120', 'required_if:target_type,auto'],
                'channel' => ['required', 'in:sms,email'],
                'subject' => ['nullable', 'string', 'max:200', 'required_if:channel,email'],
                'body' => ['nullable', 'string', 'max:5000', 'required_if:channel,sms'],
                'email_template_mode' => ['nullable', 'in:sections,legacy_html'],
                'email_template_key' => ['nullable', 'string', 'max:80'],
                'email_sections' => ['nullable', 'array', 'max:60'],
                'email_advanced_html' => ['nullable', 'string', 'max:200000'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Preview could not be generated.', $exception);
        }

        if (($data['channel'] ?? null) === 'email' && ! $this->emailPayloadHasContent($data)) {
            return response()->json([
                'ok' => false,
                'message' => 'Preview could not be generated.',
                'errors' => [
                    'body' => ['Add message text, email sections, or advanced HTML before previewing.'],
                ],
            ], 422);
        }

        try {
            $payload = $workspaceService->previewGroupSend(
                tenantId: (int) $access['tenant_id'],
                targetType: (string) $data['target_type'],
                groupId: isset($data['group_id']) ? (int) $data['group_id'] : null,
                groupKey: isset($data['group_key']) ? (string) $data['group_key'] : null,
                channel: (string) $data['channel'],
                body: (string) ($data['body'] ?? ''),
                subject: isset($data['subject']) ? (string) $data['subject'] : null,
                emailTemplateMode: isset($data['email_template_mode']) ? (string) $data['email_template_mode'] : null,
                emailTemplateKey: isset($data['email_template_key']) ? (string) $data['email_template_key'] : null,
                emailSections: $data['email_sections'] ?? null,
                emailAdvancedHtml: isset($data['email_advanced_html']) ? (string) $data['email_advanced_html'] : null,
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Preview could not be generated.', $exception);
        }

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ]);
    }

    public function cancelCampaign(
        Request $request,
        string $campaign,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $campaignId = (int) $campaign;
        if ($campaignId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Campaign could not be canceled.',
                'errors' => [
                    'campaign_id' => ['Campaign not found for this tenant.'],
                ],
            ], 422);
        }

        try {
            $payload = $workspaceService->cancelCampaign(
                tenantId: (int) $access['tenant_id'],
                campaignId: $campaignId,
                actorId: auth()->id() !== null ? (int) auth()->id() : null
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Campaign could not be canceled.', $exception);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Pending campaign canceled.',
            'data' => $payload,
        ]);
    }

    public function history(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->query(), [
                'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('History query is invalid.', $exception);
        }

        $limit = isset($data['limit']) ? (int) $data['limit'] : 40;

        return response()->json([
            'ok' => true,
            'data' => $workspaceService->history((int) $access['tenant_id'], $limit),
        ]);
    }

    public function smokeSms(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->all(), [
                'test_numbers' => ['required', 'array', 'min:1', 'max:20'],
                'test_numbers.*' => ['required', 'string', 'max:40'],
                'message' => ['required', 'string', 'max:5000'],
                'sender_key' => ['nullable', 'string', 'max:80'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('SMS smoke test failed.', $exception);
        }

        try {
            $payload = $workspaceService->sendSmsSmokeTest(
                tenantId: (int) $access['tenant_id'],
                testNumbers: array_values((array) ($data['test_numbers'] ?? [])),
                message: (string) $data['message'],
                senderKey: isset($data['sender_key']) ? (string) $data['sender_key'] : null,
                actorId: auth()->id() !== null ? (int) auth()->id() : null,
                storeKey: (string) data_get($access, 'context.store.key', '')
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('SMS smoke test failed.', $exception);
        }

        return response()->json([
            'ok' => true,
            'message' => 'SMS smoke test sent.',
            'data' => $payload,
        ]);
    }

    public function smokeEmail(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $data = validator($request->all(), [
                'test_emails' => ['required', 'array', 'min:1', 'max:20'],
                'test_emails.*' => ['required', 'string', 'max:190'],
                'subject' => ['required', 'string', 'max:200'],
                'body' => ['nullable', 'string', 'max:5000'],
                'email_template_mode' => ['nullable', 'in:sections,legacy_html'],
                'email_template_key' => ['nullable', 'string', 'max:80'],
                'email_sections' => ['nullable', 'array', 'max:60'],
                'email_advanced_html' => ['nullable', 'string', 'max:200000'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Email smoke test failed.', $exception);
        }

        if (! $this->emailPayloadHasContent($data)) {
            return response()->json([
                'ok' => false,
                'message' => 'Email smoke test failed.',
                'errors' => [
                    'body' => ['Add message text, email sections, or advanced HTML before sending a smoke test.'],
                ],
            ], 422);
        }

        try {
            $payload = $workspaceService->sendEmailSmokeTest(
                tenantId: (int) $access['tenant_id'],
                testEmails: array_values((array) ($data['test_emails'] ?? [])),
                subject: (string) $data['subject'],
                body: (string) ($data['body'] ?? ''),
                actorId: auth()->id() !== null ? (int) auth()->id() : null,
                storeKey: (string) data_get($access, 'context.store.key', ''),
                emailTemplateMode: isset($data['email_template_mode']) ? (string) $data['email_template_mode'] : null,
                emailTemplateKey: isset($data['email_template_key']) ? (string) $data['email_template_key'] : null,
                emailSections: $data['email_sections'] ?? null,
                emailAdvancedHtml: isset($data['email_advanced_html']) ? (string) $data['email_advanced_html'] : null,
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Email smoke test failed.', $exception);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Email smoke test sent.',
            'data' => $payload,
        ]);
    }

    public function responsesIndex(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        MessagingResponseInboxService $inboxService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        try {
            $filters = validator($request->query(), [
                'channel' => ['nullable', 'in:sms,email'],
                'filter' => ['nullable', 'in:open,unread,opted_out,assigned_to_me,all'],
                'search' => ['nullable', 'string', 'max:120'],
                'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Responses query is invalid.', $exception);
        }

        return response()->json([
            'ok' => true,
            'data' => $inboxService->index(
                tenantId: (int) $access['tenant_id'],
                storeKey: $this->normalizedStoreKeyFromAccess($access),
                filters: $filters
            ),
        ]);
    }

    public function responsesShow(
        Request $request,
        string $conversation,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        MessagingResponseInboxService $inboxService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $conversationId = (int) $conversation;
        if ($conversationId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Conversation not found for this tenant.',
            ], 404);
        }

        return response()->json([
            'ok' => true,
            'data' => $inboxService->show(
                tenantId: (int) $access['tenant_id'],
                storeKey: $this->normalizedStoreKeyFromAccess($access),
                conversationId: $conversationId
            ),
        ]);
    }

    public function responsesUpdate(
        Request $request,
        string $conversation,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        MessagingResponseInboxService $inboxService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $conversationId = (int) $conversation;
        if ($conversationId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Conversation not found for this tenant.',
            ], 404);
        }

        try {
            $payload = $inboxService->updateConversation(
                tenantId: (int) $access['tenant_id'],
                storeKey: $this->normalizedStoreKeyFromAccess($access),
                conversationId: $conversationId,
                payload: $request->all(),
                actor: auth()->user()
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Conversation could not be updated.', $exception);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Conversation updated.',
            'data' => $payload,
        ]);
    }

    public function responsesReply(
        Request $request,
        string $conversation,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        MessagingResponseInboxService $inboxService
    ): JsonResponse {
        $access = $this->resolveMessagingApiAccess($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($access instanceof JsonResponse) {
            return $access;
        }

        $conversationId = (int) $conversation;
        if ($conversationId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Conversation not found for this tenant.',
            ], 404);
        }

        try {
            $payload = $inboxService->reply(
                tenantId: (int) $access['tenant_id'],
                storeKey: $this->normalizedStoreKeyFromAccess($access),
                conversationId: $conversationId,
                payload: $request->all(),
                actor: auth()->user()
            );
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Reply could not be sent.', $exception);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Reply sent.',
            'data' => $payload,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function emailPayloadHasContent(array $payload): bool
    {
        $body = trim((string) ($payload['body'] ?? ''));
        if ($body !== '') {
            return true;
        }

        $sections = $payload['email_sections'] ?? null;
        if (is_array($sections) && count($sections) > 0) {
            return true;
        }

        $advanced = trim((string) ($payload['email_advanced_html'] ?? ''));

        return $advanced !== '';
    }

    protected function pageStatusCode(bool $authorized, string $status, ?int $tenantId, bool $hasMessagingAccess): int
    {
        if (! $authorized) {
            return $status === 'open_from_shopify' ? 200 : 401;
        }

        if ($tenantId !== null && ! $hasMessagingAccess) {
            return 403;
        }

        return 200;
    }

    protected function messagingAccessPayload(?int $tenantId, bool $hasMessagingAccess, string $surface): array
    {
        $surfaceLabel = match ($surface) {
            'workspace' => 'this workspace',
            'responses' => 'Responses',
            'setup' => 'Setup',
            default => 'message analytics',
        };

        return [
            'enabled' => $hasMessagingAccess,
            'status' => $tenantId === null
                ? 'tenant_not_mapped'
                : ($hasMessagingAccess ? 'enabled' : 'messaging_module_locked'),
            'message' => $tenantId === null
                ? 'This Shopify store is not mapped to a tenant yet.'
                : ($hasMessagingAccess
                    ? null
                    : sprintf('Messaging is not enabled for this tenant. Enable the Messaging module to use %s.', $surfaceLabel)),
        ];
    }

    /**
     * @param  array<string,mixed>  $module
     * @param  array<string,mixed>  $emailSettings
     * @param  array<string,mixed>  $storefrontTracking
     * @return array<string,mixed>
     */
    protected function buildMessagingSetupGuide(
        array $module,
        bool $hasMessagingAccess,
        array $emailSettings,
        int $messagesSent,
        array $storefrontTracking
    ): array {
        $setupStatus = strtolower(trim((string) ($module['setup_status'] ?? 'not_started')));
        $emailSettingsReady = (bool) data_get($emailSettings, 'email_enabled', false)
            && (bool) data_get($emailSettings, 'analytics_enabled', false)
            && trim((string) data_get($emailSettings, 'from_email', '')) !== '';

        return [
            'status' => $setupStatus,
            'is_configured' => $setupStatus === 'configured',
            'steps' => [
                [
                    'key' => 'module_access',
                    'label' => 'Messaging module access is enabled',
                    'done' => $hasMessagingAccess,
                ],
                [
                    'key' => 'email_tracking',
                    'label' => 'Email sending + analytics are enabled in Settings',
                    'done' => $emailSettingsReady,
                    'hint' => sprintf(
                        'Provider: %s · Status: %s',
                        strtoupper((string) data_get($emailSettings, 'email_provider', 'sendgrid')),
                        strtolower(trim((string) data_get($emailSettings, 'provider_status', 'unknown')))
                    ),
                ],
                [
                    'key' => 'first_tracked_send',
                    'label' => 'At least one tracked message has been sent',
                    'done' => $messagesSent > 0,
                    'hint' => $messagesSent > 0
                        ? sprintf('%s tracked sends detected.', number_format($messagesSent))
                        : 'Send a message from Messaging workspace to seed analytics.',
                ],
                ...((array) ($storefrontTracking['steps'] ?? [])),
            ],
            'actions' => [
                'settings_href' => route('shopify.app.settings', [], false),
                'workspace_href' => route('shopify.app.messaging', [], false),
                'complete_endpoint' => route('shopify.app.api.messaging.setup.complete', [], false),
                'theme_editor_href' => data_get($storefrontTracking, 'actions.theme_editor_href'),
                'customer_events_href' => data_get($storefrontTracking, 'actions.customer_events_href'),
                'reconnect_href' => data_get($storefrontTracking, 'actions.reconnect_href'),
                'connect_pixel_endpoint' => route('shopify.app.api.messaging.storefront-tracking.connect-pixel', [], false),
            ],
            'can_mark_complete' => $hasMessagingAccess,
            'tracking' => $storefrontTracking,
        ];
    }

    protected function trackedMessageSendCount(?int $tenantId, ?string $storeKey): int
    {
        if ($tenantId === null || $storeKey === null) {
            return 0;
        }

        $smsCount = Schema::hasTable('marketing_message_deliveries')
            ? MarketingMessageDelivery::query()
                ->forTenantId($tenantId)
                ->where('store_key', $storeKey)
                ->whereNotNull('source_label')
                ->where('source_label', 'like', 'shopify_embedded_messaging%')
                ->count()
            : 0;

        $emailCount = Schema::hasTable('marketing_email_deliveries')
            ? MarketingEmailDelivery::query()
                ->forTenantId($tenantId)
                ->where('store_key', $storeKey)
                ->whereNotNull('source_label')
                ->where('source_label', 'like', 'shopify_embedded_messaging%')
                ->count()
            : 0;

        return (int) $smsCount + (int) $emailCount;
    }

    protected function resolveMessagingApiAccess(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver
    ): array|JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $tenantId = $this->resolveTenantIdFromContext($context, $tenantResolver);
        if ($tenantId === null) {
            return $this->tenantNotMappedResponse();
        }

        $module = $moduleAccessResolver->module($tenantId, 'messaging');
        if (! (bool) ($module['has_access'] ?? false)) {
            return $this->messagingLockedResponse();
        }

        return [
            'tenant_id' => $tenantId,
            'context' => $context,
            'module' => $module,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function resolveTenantIdFromContext(array $context, TenantResolver $tenantResolver): ?int
    {
        return $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function invalidApiContextResponse(array $context): JsonResponse
    {
        $status = (string) ($context['status'] ?? 'invalid_request');

        $messages = [
            'missing_api_auth' => 'Shopify Admin verification is unavailable. Reload this page from Shopify Admin and try again.',
            'invalid_session_token' => 'Shopify Admin verification failed. Reload this page from Shopify Admin and try again.',
            'expired_session_token' => 'Your Shopify Admin session expired. Reload this page from Shopify Admin and try again.',
        ];

        return response()->json([
            'ok' => false,
            'status' => $status,
            'message' => $messages[$status] ?? 'This Shopify request could not be verified.',
        ], 401);
    }

    protected function tenantNotMappedResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'status' => 'tenant_not_mapped',
            'message' => 'This Shopify store is not mapped to a tenant yet. Messaging is unavailable.',
        ], 422);
    }

    protected function messagingLockedResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'status' => 'messaging_module_locked',
            'message' => 'Messaging is not enabled for this tenant.',
        ], 403);
    }

    protected function validationFailureResponse(string $message, ValidationException $exception): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => $message,
            'errors' => $exception->errors(),
        ], 422);
    }

    protected function embeddedResponse(Response $response, int $status = 200): Response
    {
        $response->setStatusCode($status);
        $response->headers->set(
            'Content-Security-Policy',
            "frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;"
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

    protected function headlineForStatus(string $status, string $default = 'Messaging'): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this page from Shopify Admin',
            default => $default,
        };
    }

    protected function subheadlineForStatus(string $status, string $default = 'Send operational SMS and email messages to individual customers or saved groups.'): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this page inside Shopify Admin so Messaging can verify your store context.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this keeps happening, check app configuration.',
            default => $default,
        };
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function serializeMediaAsset(MarketingMessageMediaAsset $asset): array
    {
        return [
            'id' => (int) $asset->id,
            'channel' => (string) $asset->channel,
            'url' => (string) $asset->public_url,
            'original_name' => (string) $asset->original_name,
            'alt_text' => $this->nullableString($asset->alt_text),
            'mime_type' => (string) $asset->mime_type,
            'size_bytes' => (int) $asset->size_bytes,
            'width' => $asset->width !== null ? (int) $asset->width : null,
            'height' => $asset->height !== null ? (int) $asset->height : null,
            'created_at' => optional($asset->created_at)->toIso8601String(),
        ];
    }

    /**
     * @param  array<string,mixed>  $access
     */
    protected function normalizedStoreKeyFromAccess(array $access): ?string
    {
        return $this->nullableString(data_get($access, 'context.store.key'));
    }
}
