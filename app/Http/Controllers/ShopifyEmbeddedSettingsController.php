<?php

namespace App\Http\Controllers;

use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\Email\TenantEmailSettingsService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Models\ShopifyStore;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ShopifyEmbeddedSettingsController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    public function show(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TwilioSenderConfigService $senderConfigService,
        TenantResolver $tenantResolver,
        TenantEmailSettingsService $emailSettingsService
    ): Response {
        $context = $contextService->resolvePageContext($request);

        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $tenantResolver->resolveTenantIdForStoreContext($store)
            : null;

        return $this->embeddedResponse(
            response()->view('shopify.settings', [
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
                'appNavigation' => $this->embeddedAppNavigation('settings', null, $tenantId),
                'pageActions' => [],
                'smsSenders' => $senderConfigService->all(),
                'defaultSmsSender' => $senderConfigService->defaultSender(),
                'emailSettingsBootstrap' => [
                    'authorized' => $authorized,
                    'tenant_id' => $tenantId,
                    'store_key' => $authorized ? ($store['key'] ?? null) : null,
                    'status' => $status,
                    'settings' => $authorized && $tenantId !== null
                        ? $emailSettingsService->forAdmin($tenantId)
                        : null,
                    'endpoints' => [
                        'load' => route('shopify.app.api.settings.email', [], false),
                        'save' => route('shopify.app.api.settings.email.save', [], false),
                        'validate' => route('shopify.app.api.settings.email.validate', [], false),
                        'test' => route('shopify.app.api.settings.email.test', [], false),
                        'health' => route('shopify.app.api.settings.email.health', [], false),
                    ],
                ],
                'widgetSettingsBootstrap' => $this->widgetSettingsBootstrap(
                    $authorized ? (string) ($store['key'] ?? '') : null,
                    $authorized ? ($store['storefront_widget_settings'] ?? null) : null
                ),
            ]),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
    }

    public function widgetSettings(
        Request $request,
        ShopifyEmbeddedAppContext $contextService
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $store = $this->resolveStoreFromContext($context);
        if ($store === null) {
            return $this->storeNotMappedResponse();
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'store_key' => $store->store_key,
                'settings' => $this->mergeWidgetSettings($store->storefront_widget_settings),
            ],
        ]);
    }

    public function saveWidgetSettings(
        Request $request,
        ShopifyEmbeddedAppContext $contextService
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $store = $this->resolveStoreFromContext($context);
        if ($store === null) {
            return $this->storeNotMappedResponse();
        }

        try {
            $data = $this->validatedWidgetSettingsData($request);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Widget settings could not be saved.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $settings = $this->mergeWidgetSettings([
            'wishlist_behavior' => (string) $data['wishlist_behavior'],
            'wishlist_drawer_id' => $data['wishlist_drawer_id'] ?? 'sidebar-wishlist',
            'reviews_position' => (string) $data['reviews_position'],
            'image_radius_px' => $data['image_radius_px'] ?? $this->defaultWidgetSettings()['image_radius_px'],
        ]);

        $store->storefront_widget_settings = $settings;
        $store->save();

        return response()->json([
            'ok' => true,
            'message' => 'Widget settings saved.',
            'data' => [
                'store_key' => $store->store_key,
                'settings' => $settings,
            ],
        ]);
    }

    public function emailSettings(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantEmailSettingsService $emailSettingsService
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $tenantId = $this->resolveTenantIdFromContext($context, $tenantResolver);
        if ($tenantId === null) {
            return $this->tenantNotMappedResponse();
        }

        return response()->json([
            'ok' => true,
            'data' => [
                'tenant_id' => $tenantId,
                'settings' => $emailSettingsService->forAdmin($tenantId),
            ],
        ]);
    }

    public function saveEmailSettings(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantEmailSettingsService $emailSettingsService,
        TenantEmailDispatchService $emailDispatchService
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $tenantId = $this->resolveTenantIdFromContext($context, $tenantResolver);
        if ($tenantId === null) {
            return $this->tenantNotMappedResponse();
        }

        try {
            $data = $this->validatedEmailSettingsData($request);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Email settings could not be saved.',
                'errors' => $exception->errors(),
            ], 422);
        }

        try {
            $emailSettingsService->saveForTenant($tenantId, $data);
            $validation = $emailDispatchService->validateConfiguration([
                'tenant_id' => $tenantId,
                'perform_live_check' => false,
            ]);

            $status = $this->statusFromValidation($validation, (string) ($data['email_provider'] ?? 'sendgrid'));
            $emailSettingsService->setProviderDiagnostics(
                $tenantId,
                $status,
                $validation['valid'] ? null : (string) ($validation['issues'][0] ?? 'Configuration incomplete.')
            );

            return response()->json([
                'ok' => true,
                'message' => 'Email settings saved.',
                'data' => [
                    'tenant_id' => $tenantId,
                    'settings' => $emailSettingsService->forAdmin($tenantId),
                    'validation' => $validation,
                ],
            ]);
        } catch (\Throwable $exception) {
            Log::error('shopify email settings save failed', [
                'tenant_id' => $tenantId,
                'error' => $exception->getMessage(),
                'provider' => $data['email_provider'] ?? null,
            ]);

            return response()->json([
                'ok' => false,
                'message' => 'Failed to save email settings.',
            ], 500);
        }
    }

    public function validateEmailSettings(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantEmailSettingsService $emailSettingsService,
        TenantEmailDispatchService $emailDispatchService
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $tenantId = $this->resolveTenantIdFromContext($context, $tenantResolver);
        if ($tenantId === null) {
            return $this->tenantNotMappedResponse();
        }

        $emailSettingsService->setProviderDiagnostics($tenantId, 'testing');

        $validation = $emailDispatchService->validateConfiguration([
            'tenant_id' => $tenantId,
            'perform_live_check' => true,
        ]);

        $status = $this->statusFromValidation($validation, (string) data_get($emailSettingsService->forAdmin($tenantId), 'email_provider', 'sendgrid'));
        $emailSettingsService->setProviderDiagnostics(
            $tenantId,
            $status,
            $validation['valid'] ? null : (string) ($validation['issues'][0] ?? 'Configuration validation failed.'),
            true,
        );

        return response()->json([
            'ok' => (bool) $validation['valid'],
            'message' => (bool) $validation['valid']
                ? 'Provider configuration validated.'
                : (string) ($validation['issues'][0] ?? 'Provider configuration has issues.'),
            'data' => [
                'tenant_id' => $tenantId,
                'validation' => $validation,
                'settings' => $emailSettingsService->forAdmin($tenantId),
            ],
        ], (bool) $validation['valid'] ? 200 : 422);
    }

    public function sendTestEmail(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantEmailSettingsService $emailSettingsService,
        TenantEmailDispatchService $emailDispatchService
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $tenantId = $this->resolveTenantIdFromContext($context, $tenantResolver);
        if ($tenantId === null) {
            return $this->tenantNotMappedResponse();
        }

        try {
            $data = validator($request->all(), [
                'to_email' => ['required', 'email', 'max:255'],
                'subject' => ['nullable', 'string', 'max:200'],
                'dry_run' => ['nullable', 'boolean'],
            ])->validate();
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Test email request is invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $emailSettingsService->setProviderDiagnostics($tenantId, 'testing');

        $result = $emailDispatchService->sendTestEmail(
            (string) $data['to_email'],
            [
                'tenant_id' => $tenantId,
                'subject' => $data['subject'] ?? null,
                'dry_run' => (bool) ($data['dry_run'] ?? false),
            ]
        );

        $success = (bool) ($result['success'] ?? false);
        $emailSettingsService->setProviderDiagnostics(
            $tenantId,
            $success ? 'healthy' : $this->statusFromSendResult($result),
            $success ? null : (string) ($result['error_message'] ?? 'Test email failed.'),
            true
        );

        return response()->json([
            'ok' => $success,
            'message' => $success
                ? 'Test email sent.'
                : (string) ($result['error_message'] ?? 'Test email failed.'),
            'data' => [
                'tenant_id' => $tenantId,
                'result' => $result,
                'settings' => $emailSettingsService->forAdmin($tenantId),
            ],
        ], $success ? 200 : 422);
    }

    public function emailProviderHealth(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        TenantEmailSettingsService $emailSettingsService,
        TenantEmailDispatchService $emailDispatchService
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidApiContextResponse($context);
        }

        $tenantId = $this->resolveTenantIdFromContext($context, $tenantResolver);
        if ($tenantId === null) {
            return $this->tenantNotMappedResponse();
        }

        $health = $emailDispatchService->healthStatus([
            'tenant_id' => $tenantId,
            'perform_live_check' => true,
        ]);

        $status = $this->statusFromHealth($health);

        $emailSettingsService->setProviderDiagnostics(
            $tenantId,
            $status,
            $status === 'healthy' ? null : (string) ($health['message'] ?? 'Provider health check failed.'),
            true,
        );

        return response()->json([
            'ok' => $status === 'healthy',
            'message' => (string) ($health['message'] ?? 'Health status unavailable.'),
            'data' => [
                'tenant_id' => $tenantId,
                'health' => $health,
                'settings' => $emailSettingsService->forAdmin($tenantId),
            ],
        ], $status === 'healthy' ? 200 : 422);
    }

    protected function headlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this app from Shopify Admin',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'We could not verify this Shopify request',
            default => 'Email settings',
        };
    }

    protected function subheadlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'This page is meant to load inside your Shopify admin so it can verify the store context.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this keeps happening, the store app config needs attention.',
            default => 'Configure per-tenant email provider, sender defaults, and health checks for app-driven email delivery.',
        };
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

    /**
     * @return array<string,mixed>
     */
    protected function validatedEmailSettingsData(Request $request): array
    {
        return validator($request->all(), [
            'email_provider' => ['required', 'in:shopify_email,sendgrid,custom'],
            'email_enabled' => ['required', 'boolean'],
            'from_name' => ['nullable', 'string', 'max:120'],
            'from_email' => ['nullable', 'email', 'max:255'],
            'reply_to_email' => ['nullable', 'email', 'max:255'],
            'analytics_enabled' => ['required', 'boolean'],
            'provider_config' => ['nullable', 'array'],
            'provider_config.api_key' => ['nullable', 'string', 'max:500'],
            'provider_config.clear_api_key' => ['nullable', 'boolean'],
            'provider_config.sender_mode' => ['nullable', 'in:global_fallback,single_sender,domain_authenticated'],
            'provider_config.verified_sender_email' => ['nullable', 'email', 'max:255'],
            'provider_config.verified_sender_name' => ['nullable', 'string', 'max:120'],
            'provider_config.reply_to_email' => ['nullable', 'email', 'max:255'],
            'provider_config.tracking_enabled' => ['nullable', 'boolean'],
            'provider_config.template_defaults' => ['nullable', 'array'],
            'provider_config.driver' => ['nullable', 'string', 'max:80'],
            'provider_config.api_endpoint' => ['nullable', 'url', 'max:500'],
            'provider_config.auth_scheme' => ['nullable', 'string', 'max:80'],
            'provider_config.notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();
    }

    /**
     * @return array<string,mixed>
     */
    protected function validatedWidgetSettingsData(Request $request): array
    {
        return validator($request->all(), [
            'wishlist_behavior' => ['required', 'in:drawer,account'],
            'wishlist_drawer_id' => ['nullable', 'string', 'max:120'],
            'reviews_position' => ['required', 'in:left,right'],
            'image_radius_px' => ['nullable', 'numeric', 'min:4', 'max:32'],
        ])->validate();
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function resolveTenantIdFromContext(array $context, TenantResolver $tenantResolver): ?int
    {
        return $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function resolveStoreFromContext(array $context): ?ShopifyStore
    {
        $storeKey = trim((string) ($context['store']['key'] ?? ''));
        if ($storeKey === '') {
            return null;
        }

        return ShopifyStore::query()->where('store_key', $storeKey)->first();
    }

    /**
     * @param array<string,mixed> $context
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
            'message' => 'This Shopify store is not mapped to a tenant yet. Email settings are unavailable.',
        ], 422);
    }

    protected function storeNotMappedResponse(): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'message' => 'This Shopify store is not installed yet. Storefront widget settings are unavailable.',
        ], 422);
    }

    /**
     * @param array<string,mixed> $validation
     */
    protected function statusFromValidation(array $validation, string $provider): string
    {
        $status = strtolower(trim((string) ($validation['status'] ?? 'error')));
        if (in_array($status, ['healthy', 'unhealthy', 'unverified', 'unknown', 'testing'], true)) {
            return $status;
        }

        if ((bool) ($validation['valid'] ?? false)) {
            return 'healthy';
        }

        return in_array($status, ['not_configured', 'configured'], true)
            ? ($status === 'configured' ? 'healthy' : 'unknown')
            : 'unhealthy';
    }

    /**
     * @param  array<string,mixed>  $result
     */
    protected function statusFromSendResult(array $result): string
    {
        $errorCode = strtolower(trim((string) ($result['error_code'] ?? '')));

        return match ($errorCode) {
            'missing_from_email', 'missing_api_key' => 'unknown',
            'sender_not_verified', 'unauthorized_sender' => 'unverified',
            default => 'unhealthy',
        };
    }

    /**
     * @param  array<string,mixed>  $health
     */
    protected function statusFromHealth(array $health): string
    {
        $status = strtolower(trim((string) ($health['status'] ?? 'unhealthy')));

        return in_array($status, ['healthy', 'unhealthy', 'unverified', 'unknown', 'testing'], true)
            ? $status
            : (in_array($status, ['configured', 'not_configured'], true)
                ? ($status === 'configured' ? 'healthy' : 'unknown')
                : 'unhealthy');
    }

    /**
     * @return array<string,mixed>
     */
    protected function defaultWidgetSettings(): array
    {
        return [
            'wishlist_behavior' => 'drawer',
            'wishlist_drawer_id' => 'sidebar-wishlist',
            'reviews_position' => 'left',
            'image_radius_px' => 14,
        ];
    }

    /**
     * @param array<string,mixed>|null $settings
     * @return array<string,mixed>
     */
    protected function mergeWidgetSettings($settings): array
    {
        $defaults = $this->defaultWidgetSettings();
        if (! is_array($settings)) {
            return $defaults;
        }

        return array_merge($defaults, array_filter([
            'wishlist_behavior' => $settings['wishlist_behavior'] ?? null,
            'wishlist_drawer_id' => $settings['wishlist_drawer_id'] ?? null,
            'reviews_position' => $settings['reviews_position'] ?? null,
            'image_radius_px' => $settings['image_radius_px'] ?? null,
        ], static fn ($value) => $value !== null));
    }

    /**
     * @param array<string,mixed>|null $settings
     * @return array<string,mixed>
     */
    protected function widgetSettingsBootstrap(?string $storeKey, ?array $settings): array
    {
        $authorized = $storeKey !== null && $storeKey !== '';

        return [
            'authorized' => $authorized,
            'store_key' => $storeKey,
            'settings' => $this->mergeWidgetSettings($settings),
            'defaults' => $this->defaultWidgetSettings(),
            'endpoints' => [
                'load' => route('shopify.app.api.settings.widgets', [], false),
                'save' => route('shopify.app.api.settings.widgets.save', [], false),
            ],
        ];
    }
}
