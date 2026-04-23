<?php

namespace App\Http\Controllers;

use App\Models\DevelopmentChangeLog;
use App\Models\DevelopmentNote;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedDevelopmentNotesAccess;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class ShopifyEmbeddedDevelopmentNotesController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
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

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation('settings', null, $tenantId));

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view('shopify.development-notes', [
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
                'developmentNotesBootstrap' => [
                    'authorized' => $authorized,
                    'status' => $status,
                    'endpoints' => [
                        'access' => route('shopify.app.api.development-notes.access', [], false),
                        'bootstrap' => route('shopify.app.api.development-notes.bootstrap', [], false),
                        'storeNote' => route('shopify.app.api.development-notes.notes.store', [], false),
                        'updateNote' => route('shopify.app.api.development-notes.notes.update', ['note' => '__NOTE_ID__'], false),
                        'deleteNote' => route('shopify.app.api.development-notes.notes.destroy', ['note' => '__NOTE_ID__'], false),
                        'storeChangeLog' => route('shopify.app.api.development-notes.change-logs.store', [], false),
                    ],
                    'settingsHref' => route('shopify.app.settings', [], false),
                ],
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
        ])->finish($response);
    }

    public function access(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        ShopifyEmbeddedDevelopmentNotesAccess $accessService
    ): JsonResponse {
        $resolved = $this->resolveAuthorizedApiAccess($request, $contextService, $tenantResolver, $accessService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'tenant_id' => $resolved['tenant_id'],
                'shop_domain' => $resolved['access']['shop_domain'] ?? null,
                'actor_email' => $resolved['access']['actor_email'] ?? null,
                'shopify_admin_user_id' => $resolved['access']['shopify_admin_user_id'] ?? null,
            ],
        ]);
    }

    public function bootstrap(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        ShopifyEmbeddedDevelopmentNotesAccess $accessService
    ): JsonResponse {
        $resolved = $this->resolveAuthorizedApiAccess($request, $contextService, $tenantResolver, $accessService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $tenantId = (int) $resolved['tenant_id'];
        $notes = DevelopmentNote::query()
            ->forTenantId($tenantId)
            ->with(['creator:id,name,email', 'updater:id,name,email'])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
        $changeLogs = DevelopmentChangeLog::query()
            ->forTenantId($tenantId)
            ->with(['creator:id,name,email'])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'ok' => true,
            'data' => [
                'tenant_id' => $tenantId,
                'notes' => $notes->map(fn (DevelopmentNote $note): array => $this->notePayload($note))->all(),
                'change_logs' => $changeLogs->map(fn (DevelopmentChangeLog $entry): array => $this->changeLogPayload($entry))->all(),
            ],
        ]);
    }

    public function storeNote(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        ShopifyEmbeddedDevelopmentNotesAccess $accessService
    ): JsonResponse {
        $resolved = $this->resolveAuthorizedApiAccess($request, $contextService, $tenantResolver, $accessService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        try {
            $data = validator($request->all(), [
                'title' => ['nullable', 'string', 'max:180'],
                'body' => ['required', 'string', 'max:12000'],
            ])->validate();
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Project note is invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $note = DevelopmentNote::query()->create([
            'tenant_id' => (int) $resolved['tenant_id'],
            'title' => $this->nullableString($data['title'] ?? null),
            'body' => (string) $data['body'],
            'created_by' => $this->actorUserId($request),
            'updated_by' => $this->actorUserId($request),
            'shopify_admin_user_id' => $this->nullableString($resolved['access']['shopify_admin_user_id'] ?? null),
            'shopify_admin_email' => $this->nullableString($resolved['access']['actor_email'] ?? null),
        ]);

        $note->load(['creator:id,name,email', 'updater:id,name,email']);

        return response()->json([
            'ok' => true,
            'message' => 'Project note added.',
            'data' => [
                'note' => $this->notePayload($note),
            ],
        ], 201);
    }

    public function updateNote(
        Request $request,
        DevelopmentNote $note,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        ShopifyEmbeddedDevelopmentNotesAccess $accessService
    ): JsonResponse {
        $resolved = $this->resolveAuthorizedApiAccess($request, $contextService, $tenantResolver, $accessService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $tenantId = (int) $resolved['tenant_id'];
        if ((int) $note->tenant_id !== $tenantId) {
            return response()->json([
                'ok' => false,
                'message' => 'Project note not found for this tenant.',
            ], 404);
        }

        try {
            $data = validator($request->all(), [
                'title' => ['nullable', 'string', 'max:180'],
                'body' => ['required', 'string', 'max:12000'],
            ])->validate();
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Project note update is invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $note->title = $this->nullableString($data['title'] ?? null);
        $note->body = (string) $data['body'];
        $note->updated_by = $this->actorUserId($request);
        $note->shopify_admin_user_id = $this->nullableString($resolved['access']['shopify_admin_user_id'] ?? null);
        $note->shopify_admin_email = $this->nullableString($resolved['access']['actor_email'] ?? null);
        $note->save();

        $note->load(['creator:id,name,email', 'updater:id,name,email']);

        return response()->json([
            'ok' => true,
            'message' => 'Project note updated.',
            'data' => [
                'note' => $this->notePayload($note),
            ],
        ]);
    }

    public function destroyNote(
        Request $request,
        DevelopmentNote $note,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        ShopifyEmbeddedDevelopmentNotesAccess $accessService
    ): JsonResponse {
        $resolved = $this->resolveAuthorizedApiAccess($request, $contextService, $tenantResolver, $accessService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        $tenantId = (int) $resolved['tenant_id'];
        if ((int) $note->tenant_id !== $tenantId) {
            return response()->json([
                'ok' => false,
                'message' => 'Project note not found for this tenant.',
            ], 404);
        }

        $note->delete();

        return response()->json([
            'ok' => true,
            'message' => 'Project note deleted.',
        ]);
    }

    public function storeChangeLog(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        ShopifyEmbeddedDevelopmentNotesAccess $accessService
    ): JsonResponse {
        $resolved = $this->resolveAuthorizedApiAccess($request, $contextService, $tenantResolver, $accessService);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }

        try {
            $data = validator($request->all(), [
                'title' => ['required', 'string', 'max:180'],
                'summary' => ['required', 'string', 'max:12000'],
                'area' => ['nullable', 'string', 'max:120'],
            ])->validate();
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Change log entry is invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $entry = DevelopmentChangeLog::query()->create([
            'tenant_id' => (int) $resolved['tenant_id'],
            'title' => (string) $data['title'],
            'summary' => (string) $data['summary'],
            'area' => $this->nullableString($data['area'] ?? null),
            'created_by' => $this->actorUserId($request),
            'shopify_admin_user_id' => $this->nullableString($resolved['access']['shopify_admin_user_id'] ?? null),
            'shopify_admin_email' => $this->nullableString($resolved['access']['actor_email'] ?? null),
        ]);

        $entry->load(['creator:id,name,email']);

        return response()->json([
            'ok' => true,
            'message' => 'Change log entry added.',
            'data' => [
                'entry' => $this->changeLogPayload($entry),
            ],
        ], 201);
    }

    /**
     * @return array{context:array<string,mixed>,tenant_id:int,access:array<string,mixed>}|JsonResponse
     */
    protected function resolveAuthorizedApiAccess(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        ShopifyEmbeddedDevelopmentNotesAccess $accessService
    ): array|JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $tenantId = $this->resolveTenantIdFromContext($context, $tenantResolver);
        if ($tenantId === null) {
            return $this->tenantNotMappedResponse();
        }

        $access = $accessService->evaluate($request, $context);
        if (! (bool) ($access['allowed'] ?? false)) {
            return $this->forbiddenResponse($access);
        }

        return [
            'context' => $context,
            'tenant_id' => $tenantId,
            'access' => $access,
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
            'message' => 'This Shopify store is not mapped to a tenant yet. Development notes are unavailable.',
        ], 422);
    }

    /**
     * @param  array<string,mixed>  $access
     */
    protected function forbiddenResponse(array $access): JsonResponse
    {
        $reason = strtolower(trim((string) ($access['reason'] ?? 'forbidden')));

        $messages = [
            'shop_not_allowlisted' => 'This store is not allowlisted for Development Notes.',
            'identity_not_allowlisted' => 'This admin identity is not allowlisted for Development Notes.',
            'app_user_not_admin' => 'Only active admin users can access Development Notes.',
        ];

        return response()->json([
            'ok' => false,
            'status' => 'forbidden',
            'message' => $messages[$reason] ?? 'Development Notes access is blocked.',
            'reason' => $reason,
        ], 403);
    }

    protected function headlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this app from Shopify Admin',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'We could not verify this Shopify request',
            default => 'Development Notes',
        };
    }

    protected function subheadlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'This page is meant to load inside your Shopify admin so it can verify store access.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this keeps happening, the store app config needs attention.',
            default => 'Internal workspace for implementation notes and change history. Admin-only and tenant-scoped.',
        };
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

    protected function actorUserId(Request $request): ?int
    {
        $id = optional($request->user())->id;

        return is_numeric($id) ? (int) $id : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }

    protected function notePayload(DevelopmentNote $note): array
    {
        return [
            'id' => (int) $note->id,
            'title' => $this->nullableString($note->title),
            'body' => (string) $note->body,
            'created_at' => optional($note->created_at)->toIso8601String(),
            'updated_at' => optional($note->updated_at)->toIso8601String(),
            'shopify_admin_email' => $this->nullableString($note->shopify_admin_email),
            'shopify_admin_user_id' => $this->nullableString($note->shopify_admin_user_id),
            'creator' => [
                'id' => is_numeric($note->creator?->id) ? (int) $note->creator->id : null,
                'name' => $this->nullableString($note->creator?->name),
                'email' => $this->nullableString($note->creator?->email),
            ],
            'updater' => [
                'id' => is_numeric($note->updater?->id) ? (int) $note->updater->id : null,
                'name' => $this->nullableString($note->updater?->name),
                'email' => $this->nullableString($note->updater?->email),
            ],
        ];
    }

    protected function changeLogPayload(DevelopmentChangeLog $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'title' => (string) $entry->title,
            'summary' => (string) $entry->summary,
            'area' => $this->nullableString($entry->area),
            'created_at' => optional($entry->created_at)->toIso8601String(),
            'shopify_admin_email' => $this->nullableString($entry->shopify_admin_email),
            'shopify_admin_user_id' => $this->nullableString($entry->shopify_admin_user_id),
            'creator' => [
                'id' => is_numeric($entry->creator?->id) ? (int) $entry->creator->id : null,
                'name' => $this->nullableString($entry->creator?->name),
                'email' => $this->nullableString($entry->creator?->email),
            ],
        ];
    }
}
