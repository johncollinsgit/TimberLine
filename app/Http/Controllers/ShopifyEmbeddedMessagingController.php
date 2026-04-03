<?php

namespace App\Http\Controllers;

use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedMessagingWorkspaceService;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Tenancy\TenantModuleAccessResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ShopifyEmbeddedMessagingController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantModuleAccessResolver $moduleAccessResolver,
        ShopifyEmbeddedMessagingWorkspaceService $workspaceService
    ): Response {
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;
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
                'groups' => $workspaceService->groups($tenantId),
                'all_subscribed_summary' => $workspaceService->allSubscribedSummary($tenantId),
                'history' => $workspaceService->history($tenantId, 24),
            ])
            : null;

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('messaging', null, $tenantId));

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
                        'search_customers' => route('shopify.app.api.messaging.customers.search', [], false),
                        'groups' => route('shopify.app.api.messaging.groups', [], false),
                        'group_detail_base' => route('shopify.app.api.messaging.groups.detail', ['group' => '__GROUP__'], false),
                        'create_group' => route('shopify.app.api.messaging.groups.create', [], false),
                        'update_group_base' => route('shopify.app.api.messaging.groups.update', ['group' => '__GROUP__'], false),
                        'send_individual' => route('shopify.app.api.messaging.send.individual', [], false),
                        'send_group' => route('shopify.app.api.messaging.send.group', [], false),
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
                'groups' => $workspaceService->groups($tenantId),
                'all_subscribed_summary' => $workspaceService->allSubscribedSummary($tenantId),
                'history' => $workspaceService->history($tenantId, 24),
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
                'body' => ['required', 'string', 'max:5000'],
                'sender_key' => ['nullable', 'string', 'max:80'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Message could not be sent.', $exception);
        }

        try {
            $payload = $workspaceService->sendIndividual(
                tenantId: (int) $access['tenant_id'],
                profileId: (int) $data['profile_id'],
                channel: (string) $data['channel'],
                body: (string) $data['body'],
                subject: isset($data['subject']) ? (string) $data['subject'] : null,
                senderKey: isset($data['sender_key']) ? (string) $data['sender_key'] : null,
                actorId: auth()->id() !== null ? (int) auth()->id() : null
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
                'body' => ['required', 'string', 'max:5000'],
                'sender_key' => ['nullable', 'string', 'max:80'],
            ])->validate();
        } catch (ValidationException $exception) {
            return $this->validationFailureResponse('Message could not be sent.', $exception);
        }

        try {
            $payload = $workspaceService->sendGroup(
                tenantId: (int) $access['tenant_id'],
                targetType: (string) $data['target_type'],
                groupId: isset($data['group_id']) ? (int) $data['group_id'] : null,
                groupKey: isset($data['group_key']) ? (string) $data['group_key'] : null,
                channel: (string) $data['channel'],
                body: (string) $data['body'],
                subject: isset($data['subject']) ? (string) $data['subject'] : null,
                senderKey: isset($data['sender_key']) ? (string) $data['sender_key'] : null,
                actorId: auth()->id() !== null ? (int) auth()->id() : null
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

    protected function headlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this page from Shopify Admin',
            default => 'Messaging',
        };
    }

    protected function subheadlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this page inside Shopify Admin so Messaging can verify your store context.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this keeps happening, check app configuration.',
            default => 'Send operational SMS and email messages to individual customers or saved groups.',
        };
    }
}
