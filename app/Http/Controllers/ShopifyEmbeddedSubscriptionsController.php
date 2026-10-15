<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Subscriptions\SubscriptionAccessService;
use App\Services\Subscriptions\SubscriptionModuleService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShopifyEmbeddedSubscriptionsController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        SubscriptionModuleService $subscriptions
    ): Response {
        $context = $contextService->resolvePageContext($request);
        if (! ($context['ok'] ?? false)) {
            return response()->view('shopify.subscriptions', [
                'authorized' => false,
                'shopifyApiKey' => null,
                'shopDomain' => $context['shop_domain'] ?? null,
                'host' => $context['host'] ?? null,
                'storeLabel' => 'Shopify Admin',
                'headline' => 'Subscriptions',
                'subheadline' => 'Subscription setup and local requests for review.',
                'appNavigation' => $this->embeddedAppNavigation('subscriptions', null, null),
                'pageActions' => [],
                'pageSubnav' => [],
                'subscriptionsAccess' => ['enabled' => false, 'message' => 'Open from Shopify Admin to verify store access.'],
                'subscriptionsPayload' => null,
            ], 401);
        }

        /** @var array<string,mixed> $store */
        $store = (array) $context['store'];
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        $moduleState = $moduleAccessResolver->module($tenantId, SubscriptionModuleService::MODULE_KEY);
        $access = app(SubscriptionAccessService::class);
        $enabled = $tenantId !== null && $access->canManage($tenantId, $request->user());
        if ($enabled) {
            $access->storeIdentity($tenantId, $store);
        }

        $payload = $tenantId !== null && $enabled
            ? $subscriptions->adminPayload($tenantId)
            : null;

        return response()->view('shopify.subscriptions', [
            'authorized' => true,
            'shopifyApiKey' => (string) ($store['client_id'] ?? ''),
            'shopDomain' => (string) ($store['shop'] ?? ''),
            'host' => (string) ($context['host'] ?? ''),
            'storeLabel' => ucfirst((string) ($store['key'] ?? 'store')).' Store',
            'headline' => 'Subscriptions',
            'subheadline' => 'Subscription setup and local requests for review.',
            'appNavigation' => $this->embeddedAppNavigation('subscriptions', null, $tenantId),
            'pageActions' => [],
            'pageSubnav' => [],
            'subscriptionsAccess' => [
                'enabled' => $enabled,
                'status' => (string) ($moduleState['ui_state'] ?? 'locked'),
                'message' => $enabled ? null : 'An enabled Subscriptions add-on and an active Everbranch workspace owner, admin or manager sign-in are required. Shopify launch context alone does not grant management access.',
            ],
            'subscriptionsPayload' => $payload,
        ], $enabled ? 200 : 403);
    }

    public function updateSettings(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        SubscriptionModuleService $subscriptions
    ): JsonResponse {
        $tenantId = $this->tenantIdForApiRequest($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($tenantId === null) {
            return response()->json(['ok' => false, 'status' => 'subscriptions_module_locked'], 403);
        }

        $validated = $request->validate([
            'commitment_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'allowed_pauses_per_commitment' => ['nullable', 'integer', 'min:0', 'max:24'],
            'pause_duration_options' => ['nullable', 'array', 'max:12'],
            'pause_duration_options.*' => ['integer', 'min:1', 'max:12'],
            'renewal_reward_months' => ['nullable', 'integer', 'min:1', 'max:60'],
            'first_gift_product_variant_gid' => ['nullable', 'string', 'max:190'],
            'first_gift_label' => ['nullable', 'string', 'max:190'],
            'renewal_gift_product_variant_gid' => ['nullable', 'string', 'max:190'],
            'renewal_gift_label' => ['nullable', 'string', 'max:190'],
            'cancellation_prompt' => ['nullable', 'string', 'max:2000'],
            'voting_reward_candle_cash' => ['nullable', 'integer', 'min:0', 'max:1000'],
            'poll_defaults' => ['nullable', 'array:source_surfaces'],
            'poll_defaults.source_surfaces' => ['sometimes', 'array', 'max:3'],
            'poll_defaults.source_surfaces.*' => ['in:app,storefront,facebook', 'distinct'],
        ]);

        return response()->json([
            'ok' => true,
            'data' => $subscriptions->saveCandleClubSettings($tenantId, $validated, $request->user()?->id),
        ]);
    }

    public function startMigrationDryRun(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        SubscriptionModuleService $subscriptions
    ): JsonResponse {
        $tenantId = $this->tenantIdForApiRequest($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($tenantId === null) {
            return response()->json(['ok' => false, 'status' => 'subscriptions_module_locked'], 403);
        }

        $validated = $request->validate([
            'rows' => ['required', 'array', 'min:1', 'max:500'],
            'rows.*' => ['array'],
        ]);

        return response()->json([
            'ok' => true,
            'data' => $subscriptions->createMigrationDryRun($tenantId, $request->user()?->id, (array) ($validated['rows'] ?? [])),
        ]);
    }

    public function approveCutover(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        SubscriptionModuleService $subscriptions
    ): JsonResponse {
        $tenantId = $this->tenantIdForApiRequest($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($tenantId === null) {
            return response()->json(['ok' => false, 'status' => 'subscriptions_module_locked'], 403);
        }

        $validated = $request->validate([
            'batch_id' => ['required', 'integer'],
            'recharge_billing_paused' => ['required', 'boolean'],
        ]);

        $payload = $subscriptions->approveCutover(
            $tenantId,
            (int) $validated['batch_id'],
            $request->user()?->id,
            (bool) $validated['recharge_billing_paused']
        );

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 422);
    }

    public function action(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        SubscriptionModuleService $subscriptions,
        int $contract
    ): JsonResponse {
        $tenantId = $this->tenantIdForApiRequest($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($tenantId === null) {
            return response()->json(['ok' => false, 'status' => 'subscriptions_module_locked'], 403);
        }

        $validated = $request->validate([
            'action' => ['required', 'string', 'max:80'],
            'payload' => ['nullable', 'array'],
        ]);

        $payload = $subscriptions->recordAdminAction(
            $tenantId,
            $contract,
            (string) $validated['action'],
            $request->user()?->id,
            (array) ($validated['payload'] ?? [])
        );

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 422);
    }

    public function exportFeedback(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        SubscriptionModuleService $subscriptions,
        int $feedback
    ): JsonResponse {
        $tenantId = $this->tenantIdForApiRequest($request, $contextService, $tenantResolver, $moduleAccessResolver);
        if ($tenantId === null) {
            return response()->json(['ok' => false, 'status' => 'subscriptions_module_locked'], 403);
        }

        $payload = $subscriptions->exportCandleClubScentFeedback($tenantId, $feedback, $request->user()?->id);

        return response()->json($payload, (bool) ($payload['ok'] ?? false) ? 200 : 422);
    }

    protected function tenantIdForApiRequest(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver
    ): ?int {
        $context = $contextService->resolveApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return null;
        }

        // Shared embedded verification may accept alternate app credentials.
        // Subscription ownership belongs to one original app installation.
        if (($context['auth_source'] ?? '') !== 'session_token'
            || (string) ($context['shopify_app_client_id'] ?? '') === ''
            || ! hash_equals((string) ($context['store']['client_id'] ?? ''), (string) $context['shopify_app_client_id'])) {
            return null;
        }

        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) $context['store']);
        if ($tenantId === null) {
            return null;
        }

        $access = app(SubscriptionAccessService::class);
        // The Shopify token verifies the store. A separate authenticated workspace
        // membership grants management authority; request emails/headers never do.
        $actor = $request->user();
        if (! $actor instanceof User || ! $access->canManage($tenantId, $actor)) {
            return null;
        }
        $access->bindStore($tenantId, (array) $context['store'], $actor);

        return $tenantId;
    }
}
