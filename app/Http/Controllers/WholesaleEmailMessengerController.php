<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedMessagingWorkspaceService;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use App\Services\Wholesale\WholesaleEmailMessengerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class WholesaleEmailMessengerController extends Controller
{
    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $modules,
        WholesaleEmailMessengerService $drafts,
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized ? $tenantResolver->resolveTenantIdForStoreContext($store) : null;
        $hasAccess = $tenantId !== null && (bool) ($modules->module($tenantId, 'wholesale_operations')['has_access'] ?? false);
        $storeKey = trim((string) ($store['key'] ?? 'wholesale')) ?: 'wholesale';
        $bootstrap = $authorized && $hasAccess && $tenantId !== null
            ? $drafts->draft($tenantId, $storeKey, auth()->id())
            : null;

        return $this->embeddedResponse(response()->view('shopify.wholesale-email-messenger', [
            'authorized' => $authorized && $tenantId !== null && $hasAccess,
            'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
            'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => $context['host'] ?? null,
            'storeLabel' => 'MF Wholesale Backstage',
            'headline' => 'Email Messenger',
            'subheadline' => 'Edit the approved wholesale draft, preview it on desktop or mobile, then send only an explicit test email.',
            'appNavigation' => $this->navigation(),
            'wholesaleMessengerBootstrap' => [
                'authorized' => $authorized && $tenantId !== null && $hasAccess,
                'draft' => $bootstrap,
                'endpoints' => [
                    'save' => route('shopify.app.api.wholesale.messaging.save', [], false),
                    'test_send' => route('shopify.app.api.wholesale.messaging.test-send', [], false),
                ],
            ],
        ]), $authorized && $tenantId !== null && $hasAccess ? 200 : 403);
    }

    public function save(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $modules,
        WholesaleEmailMessengerService $drafts,
    ): JsonResponse {
        $access = $this->apiAccess($request, $contextService, $tenantResolver, $modules);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        try {
            $input = validator($request->all(), [
                'subject' => ['required', 'string', 'max:200'],
                'sections' => ['required', 'array', 'size:16'],
                'personalization' => ['nullable', 'array'],
                'revision' => ['required', 'integer', 'min:1'],
            ])->validate();

            return response()->json(['ok' => true, 'data' => $drafts->save($access['tenant_id'], $access['store_key'], $input, auth()->id())]);
        } catch (ValidationException $exception) {
            return response()->json(['ok' => false, 'message' => 'Draft could not be saved.', 'errors' => $exception->errors()], 422);
        }
    }

    public function testSend(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $modules,
        WholesaleEmailMessengerService $drafts,
        ShopifyEmbeddedMessagingWorkspaceService $messaging,
    ): JsonResponse {
        $access = $this->apiAccess($request, $contextService, $tenantResolver, $modules);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        try {
            $input = validator($request->all(), [
                'test_emails' => ['required', 'array', 'min:1', 'max:5'],
                'test_emails.*' => ['required', 'email:rfc', 'max:190'],
            ])->validate();
            $draft = $drafts->draft($access['tenant_id'], $access['store_key'], auth()->id());
            $result = $messaging->sendEmailSmokeTest(
                tenantId: $access['tenant_id'], testEmails: $input['test_emails'], subject: $draft['subject'], body: 'Wholesale email messenger test.',
                actorId: auth()->id(), storeKey: $access['store_key'], emailTemplateMode: 'sections', emailTemplateKey: 'wholesale_email_messenger',
                emailSections: $drafts->sectionsForTestSend($draft['sections']), emailAdvancedHtml: null,
            );

            return response()->json(['ok' => true, 'message' => 'Test email submitted. No campaign recipients were contacted.', 'data' => $result]);
        } catch (ValidationException $exception) {
            return response()->json(['ok' => false, 'message' => 'Test email could not be sent.', 'errors' => $exception->errors()], 422);
        }
    }

    /** @return array{tenant_id:int,store_key:string}|JsonResponse */
    protected function apiAccess(Request $request, ShopifyEmbeddedAppContext $contexts, TenantResolver $tenants, TenantModuleAccessResolver $modules): array|JsonResponse
    {
        $context = $contexts->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return response()->json(['ok' => false, 'status' => $context['status'] ?? 'invalid_session_token', 'message' => 'Reload this page from Shopify Admin and try again.'], 401);
        }
        $store = (array) ($context['store'] ?? []);
        $tenantId = $tenants->resolveTenantIdForStoreContext($store);
        if ($tenantId === null || ! (bool) ($modules->module($tenantId, 'wholesale_operations')['has_access'] ?? false)) {
            return response()->json(['ok' => false, 'message' => 'Email Messenger is unavailable for this wholesale workspace.'], 403);
        }

        return ['tenant_id' => $tenantId, 'store_key' => trim((string) ($store['key'] ?? 'wholesale')) ?: 'wholesale'];
    }

    /** @return array<string,mixed> */
    protected function navigation(): array
    {
        $routes = [
            'home' => ['Overview', 'shopify.app.wholesale'], 'messaging' => ['Email Messenger', 'shopify.app.wholesale.messaging'],
            'suggestions' => ['Suggestions', 'shopify.app.wholesale.suggestions'], 'customers' => ['Customers', 'shopify.app.wholesale.customers'],
            'orders' => ['Orders', 'shopify.app.wholesale.orders'], 'follow_ups' => ['Follow-Ups', 'shopify.app.wholesale.follow-ups'],
            'prospects' => ['Prospects', 'shopify.app.wholesale.prospects'], 'prospect_discovery' => ['Discover', 'shopify.app.wholesale.prospects.discover'],
            'prospect_review' => ['Review next', 'shopify.app.wholesale.prospects.review'], 'prospect_report' => ['Research report', 'shopify.app.wholesale.prospects.report'],
            'applications' => ['Applications', 'shopify.app.wholesale.applications'],
        ];

        return ['items' => collect($routes)->map(fn ($route, $key) => ['key' => $key, 'label' => $route[0], 'href' => route($route[1], [], false), 'children' => []])->values()->all(), 'workspaceLabel' => 'MF Wholesale Backstage'];
    }

    protected function embeddedResponse(Response $response): Response
    {
        $response->headers->set('Content-Security-Policy', 'frame-ancestors https://admin.shopify.com https://*.myshopify.com https://*.shopify.com;');
        $response->headers->remove('X-Frame-Options');

        return $response;
    }
}
