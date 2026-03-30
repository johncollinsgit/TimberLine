<?php

namespace App\Http\Controllers;

use App\Services\Marketing\BirthdayReportingService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedRewardsService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantResolver;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ShopifyEmbeddedRewardsController extends Controller
{
    use HandlesShopifyEmbeddedNavigation;

    protected array $tabs = [
        'overview' => 'Overview',
        'earn' => 'Ways to Earn',
        'redeem' => 'Ways to Redeem',
        'referrals' => 'Referrals',
        'birthdays' => 'Birthdays',
        'vip' => 'VIP',
        'notifications' => 'Notifications',
    ];

    public function index(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedRewardsService $rewardsService,
        TenantResolver $tenantResolver
    ): Response
    {
        $pageContext = $contextService->resolvePageContext($request);
        $authorized = (bool) ($pageContext['ok'] ?? false);
        $store = (array) ($pageContext['store'] ?? []);
        $configState = $authorized
            ? $this->rewardsConfigState($store, $tenantResolver)
            : ['available' => false];
        $tenantId = is_numeric($configState['tenant_id'] ?? null) ? (int) $configState['tenant_id'] : null;
        $overview = ($authorized && ($configState['available'] ?? false) && $tenantId !== null)
            ? $rewardsService->overview($tenantId)
            : [];

        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'overview',
            'shopify.rewards-overview',
            [
                'dashboard' => $overview,
                'setupNote' => null,
            ]
        );
    }

    public function earn(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'earn',
            'shopify.rewards'
        );
    }

    public function redeem(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'redeem',
            'shopify.rewards'
        );
    }

    public function referrals(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'referrals',
            'shopify.rewards-placeholder',
            [
                'title' => 'Referrals coming soon',
                'message' => 'Referral tracking and program controls will arrive here once the next phase of the embedded admin is ready.',
            ]
        );
    }

    public function birthdays(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response
    {
        $context = $contextService->resolvePageContext($request);
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $tenantResolver->resolveTenantIdForStoreContext($store)
            : null;

        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'birthdays',
            'shopify.rewards-birthdays',
            [
                'setupNote' => $authorized
                    ? 'Birthday analytics uses canonical delivery records, provider webhook updates, and reward redemption outcomes scoped to this tenant.'
                    : null,
                'birthdayAnalyticsBootstrap' => [
                    'authorized' => $authorized,
                    'tenant_id' => $tenantId,
                    'status' => (string) ($context['status'] ?? 'invalid_request'),
                    'filters' => [
                        'date_from' => now()->subDays(29)->toDateString(),
                        'date_to' => now()->toDateString(),
                        'provider' => null,
                        'provider_resolution_source' => null,
                        'provider_readiness_status' => null,
                        'template_key' => null,
                        'status' => 'all',
                        'comparison_mode' => 'template',
                        'period_view' => 'raw',
                        'compare_from' => null,
                        'compare_to' => null,
                    ],
                    'endpoints' => [
                        'analytics' => route('shopify.app.api.rewards.birthdays.analytics', [], false),
                        'analytics_export' => route('shopify.app.api.rewards.birthdays.analytics.export', [], false),
                    ],
                ],
            ]
        );
    }

    public function birthdayAnalytics(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        BirthdayReportingService $reportingService
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
        if ($tenantId === null) {
            return response()->json([
                'ok' => false,
                'status' => 'tenant_not_mapped',
                'message' => 'This Shopify store is not mapped to a tenant yet. Birthday analytics are unavailable.',
            ], 422);
        }

        try {
            $filters = $this->validatedBirthdayAnalyticsFilters($request);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Birthday analytics filters are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $analytics = $reportingService->birthdayAnalytics([
            ...$filters,
            'tenant_id' => $tenantId,
        ]);

        return response()->json([
            'ok' => true,
            'data' => $analytics,
        ]);
    }

    public function birthdayAnalyticsExport(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        BirthdayReportingService $reportingService
    ): \Symfony\Component\HttpFoundation\Response {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $tenantId = $tenantResolver->resolveTenantIdForStoreContext((array) ($context['store'] ?? []));
        if ($tenantId === null) {
            return response()->json([
                'ok' => false,
                'status' => 'tenant_not_mapped',
                'message' => 'This Shopify store is not mapped to a tenant yet. Birthday analytics export is unavailable.',
            ], 422);
        }

        try {
            $filters = $this->validatedBirthdayAnalyticsFilters($request);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Birthday analytics export filters are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $analytics = $reportingService->birthdayAnalytics([
            ...$filters,
            'tenant_id' => $tenantId,
        ]);
        $columns = $reportingService->birthdayAnalyticsExportColumns($analytics);
        $rows = $reportingService->birthdayAnalyticsExportRows($analytics);

        $dateFrom = (string) data_get($analytics, 'filters.date_from', now()->subDays(29)->toDateString());
        $dateTo = (string) data_get($analytics, 'filters.date_to', now()->toDateString());
        $filename = sprintf(
            'birthday-analytics-tenant-%d-%s-to-%s.csv',
            $tenantId,
            preg_replace('/[^0-9\\-]/', '', $dateFrom) ?: 'start',
            preg_replace('/[^0-9\\-]/', '', $dateTo) ?: 'end'
        );

        return response()->streamDownload(function () use ($columns, $rows): void {
            $stream = fopen('php://output', 'w');
            if (! is_resource($stream)) {
                return;
            }

            fputcsv($stream, $columns);
            foreach ($rows as $row) {
                $record = [];
                foreach ($columns as $column) {
                    $value = $row[$column] ?? '';
                    if (is_bool($value)) {
                        $record[] = $value ? 'true' : 'false';
                        continue;
                    }
                    if (is_array($value)) {
                        $record[] = json_encode($value);
                        continue;
                    }

                    $record[] = is_scalar($value) || $value === null ? (string) ($value ?? '') : json_encode($value);
                }

                fputcsv($stream, $record);
            }

            fclose($stream);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function vip(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'vip',
            'shopify.rewards-placeholder',
            [
                'title' => 'VIP experiences coming soon',
                'message' => 'VIP program controls will be surfaced here once we reuse the existing VIP logic.',
            ]
        );
    }

    public function notifications(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver
    ): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'notifications',
            'shopify.rewards-placeholder',
            [
                'title' => 'Notifications coming soon',
                'message' => 'Notification settings for this program will appear here in a later phase.',
            ]
        );
    }

    public function data(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedRewardsService $rewardsService,
        TenantResolver $tenantResolver
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $configState = $this->rewardsConfigState((array) ($context['store'] ?? []), $tenantResolver);
        if (! ($configState['available'] ?? false)) {
            return $this->unsupportedRewardsConfigResponse($configState);
        }

        $tenantId = is_numeric($configState['tenant_id'] ?? null) ? (int) $configState['tenant_id'] : null;
        if ($tenantId === null) {
            return $this->unsupportedRewardsConfigResponse([
                'status' => 'tenant_not_mapped',
                'message' => 'This Shopify store is not mapped to a tenant yet. Rewards settings are unavailable.',
            ]);
        }

        $payload = $rewardsService->payload($tenantId);

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ], $this->statusForPayload($payload));
    }

    public function updateEarnRule(
        Request $request,
        int $task,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedRewardsService $rewardsService,
        TenantResolver $tenantResolver
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $configState = $this->rewardsConfigState((array) ($context['store'] ?? []), $tenantResolver);
        if (! ($configState['available'] ?? false)) {
            return $this->unsupportedRewardsConfigResponse($configState);
        }

        $tenantId = is_numeric($configState['tenant_id'] ?? null) ? (int) $configState['tenant_id'] : null;
        if ($tenantId === null) {
            return $this->unsupportedRewardsConfigResponse([
                'status' => 'tenant_not_mapped',
                'message' => 'This Shopify store is not mapped to a tenant yet. Rewards settings are unavailable.',
            ]);
        }

        try {
            $data = $this->validateEarnPayload($request);
            $rule = $rewardsService->updateEarnRule(
                $rewardsService->resolveEarnRule($task, $tenantId),
                $data,
                $tenantId
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Earn rule could not be saved.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'ok' => false,
                'status' => 'reward_rule_not_found',
                'message' => 'This earn rule was not found for the current tenant.',
            ], 404);
        }

        $payload = $rewardsService->payload($tenantId);

        return response()->json([
            'ok' => true,
            'message' => 'Earn rule saved.',
            'rule' => $rule,
            'data' => $payload,
        ], $this->statusForPayload($payload));
    }

    public function updateRedeemRule(
        Request $request,
        int $reward,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedRewardsService $rewardsService,
        TenantResolver $tenantResolver
    ): JsonResponse {
        $context = $contextService->resolveAuthenticatedApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $configState = $this->rewardsConfigState((array) ($context['store'] ?? []), $tenantResolver);
        if (! ($configState['available'] ?? false)) {
            return $this->unsupportedRewardsConfigResponse($configState);
        }

        $tenantId = is_numeric($configState['tenant_id'] ?? null) ? (int) $configState['tenant_id'] : null;
        if ($tenantId === null) {
            return $this->unsupportedRewardsConfigResponse([
                'status' => 'tenant_not_mapped',
                'message' => 'This Shopify store is not mapped to a tenant yet. Rewards settings are unavailable.',
            ]);
        }

        try {
            $data = $this->validateRedeemPayload($request);
            $rule = $rewardsService->updateRedeemRule(
                $rewardsService->resolveRedeemRule($reward, $tenantId),
                $data,
                $tenantId
            );
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Redeem rule could not be saved.',
                'errors' => $exception->errors(),
            ], 422);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return response()->json([
                'ok' => false,
                'status' => 'reward_rule_not_found',
                'message' => 'This redeem rule was not found for the current tenant.',
            ], 404);
        }

        $payload = $rewardsService->payload($tenantId);

        return response()->json([
            'ok' => true,
            'message' => 'Redeem rule saved.',
            'rule' => $rule,
            'data' => $payload,
        ], $this->statusForPayload($payload));
    }

    protected function renderSection(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        TenantResolver $tenantResolver,
        string $section,
        string $view,
        array $extra = []
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $configState = $authorized
            ? $this->rewardsConfigState($store, $tenantResolver)
            : ['available' => false, 'tenant_id' => null, 'status' => null, 'message' => null];
        $displayLabels = app(TenantDisplayLabelResolver::class)->resolve($configState['tenant_id'] ?? null);
        $labels = is_array($displayLabels['labels'] ?? null) ? (array) $displayLabels['labels'] : [];
        $rewardsLabel = trim((string) ($labels['rewards_label'] ?? $labels['rewards'] ?? 'Rewards'));
        if ($rewardsLabel === '') {
            $rewardsLabel = 'Rewards';
        }
        $rewardsBalanceLabel = trim((string) ($labels['rewards_balance_label'] ?? ($rewardsLabel.' balance')));
        if ($rewardsBalanceLabel === '') {
            $rewardsBalanceLabel = $rewardsLabel.' balance';
        }
        $rewardsProgramLabel = trim((string) ($labels['rewards_program_label'] ?? ($rewardsLabel.' program')));
        if ($rewardsProgramLabel === '') {
            $rewardsProgramLabel = $rewardsLabel.' program';
        }
        $rewardsRedemptionLabel = trim((string) ($labels['rewards_redemption_label'] ?? ($rewardsLabel.' redemption')));
        if ($rewardsRedemptionLabel === '') {
            $rewardsRedemptionLabel = $rewardsLabel.' redemption';
        }
        $rewardCreditLabel = trim((string) ($labels['reward_credit_label'] ?? 'reward credit'));
        if ($rewardCreditLabel === '') {
            $rewardCreditLabel = 'reward credit';
        }
        $birthdayRewardLabel = trim((string) ($labels['birthday_reward_label'] ?? 'Birthday reward'));
        if ($birthdayRewardLabel === '') {
            $birthdayRewardLabel = 'Birthday reward';
        }

        $viewData = [
            'authorized' => $authorized,
            'status' => $status,
            'shopifyApiKey' => $authorized ? (string) ($store['client_id'] ?? '') : null,
            'shopDomain' => $authorized ? (string) ($store['shop'] ?? '') : ($context['shop_domain'] ?? null),
            'host' => $authorized ? (string) ($context['host'] ?? '') : ($context['host'] ?? null),
            'storeLabel' => $authorized
                ? ucfirst((string) ($store['key'] ?? 'store')) . ' Store'
                : 'Shopify Admin',
            'headline' => $this->headlineForStatus($status, $rewardsLabel),
            'subheadline' => $this->subheadlineForStatus($status, $rewardsProgramLabel),
            'dataEndpoint' => route('shopify.app.api.rewards'),
            'earnUpdateEndpointTemplate' => route('shopify.app.api.rewards.earn.update', ['task' => '__TASK__']),
            'redeemUpdateEndpointTemplate' => route('shopify.app.api.rewards.redeem.update', ['reward' => '__REWARD__']),
            'setupNote' => $authorized
                ? (($configState['available'] ?? false)
                    ? 'This embedded page updates earn rows, redeem rows, and program settings scoped to this tenant.'
                    : null)
                : ($status === 'open_from_shopify'
                    ? 'Open the app from Shopify Admin so the store context can be verified before editing this program.'
                    : null),
            'rewardsEditorAvailable' => $authorized && (bool) ($configState['available'] ?? false),
            'rewardsEditorStatus' => $configState['status'] ?? null,
            'rewardsEditorMessage' => $configState['message'] ?? null,
            'referenceLinks' => $authorized
                ? [
                    [
                        'label' => 'Backstage Customers',
                        'href' => route('marketing.customers'),
                    ],
                    [
                        'label' => Str::title($birthdayRewardLabel),
                        'href' => route('birthdays.rewards'),
                    ],
                ]
                : [],
            'appNavigation' => $this->embeddedAppNavigation(
                'rewards',
                $section,
                $configState['tenant_id'] ?? null
            ),
            'pageActions' => [],
            'displayLabels' => $labels,
            'rewardsLabel' => $rewardsLabel,
            'rewardsBalanceLabel' => $rewardsBalanceLabel,
            'rewardsProgramLabel' => $rewardsProgramLabel,
            'rewardsRedemptionLabel' => $rewardsRedemptionLabel,
            'rewardCreditLabel' => $rewardCreditLabel,
            'birthdayRewardLabel' => $birthdayRewardLabel,
        ];

        $viewData = array_merge($viewData, $extra);

        return $this->embeddedResponse(
            response()->view($view, $viewData),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        );
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

    protected function validateEarnPayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'candle_cash_value' => ['nullable', 'numeric', 'min:0', 'max:50000'],
            'points_value' => ['nullable', 'integer', 'min:0', 'max:50000'],
            'enabled' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    protected function validateRedeemPayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'candle_cash_cost' => ['nullable', 'numeric', 'min:0', 'max:50000'],
            'reward_value' => ['nullable', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    /**
     * @return array{
     *   date_from:?string,
     *   date_to:?string,
     *   provider:?string,
     *   provider_resolution_source:?string,
     *   provider_readiness_status:?string,
     *   template_key:?string,
     *   status:string,
     *   comparison_mode:'template'|'provider'|'period',
     *   period_view:'raw'|'per_day',
     *   compare_from:?string,
     *   compare_to:?string
     * }
     */
    protected function validatedBirthdayAnalyticsFilters(Request $request): array
    {
        $validated = validator($request->query(), [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'provider' => ['nullable', 'string', 'max:60'],
            'provider_resolution_source' => ['nullable', 'in:tenant,fallback,none,unknown'],
            'provider_readiness_status' => ['nullable', 'in:ready,unsupported,incomplete,error,not_configured,unknown'],
            'template_key' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', 'in:all,attempted,sent,delivered,opened,clicked,failed,bounced,unsupported'],
            'comparison_mode' => ['nullable', 'in:template,provider,period'],
            'period_view' => ['nullable', 'in:raw,per_day'],
            'compare_from' => ['nullable', 'date_format:Y-m-d'],
            'compare_to' => ['nullable', 'date_format:Y-m-d'],
        ])->validate();

        $dateFrom = isset($validated['date_from']) ? CarbonImmutable::parse((string) $validated['date_from']) : null;
        $dateTo = isset($validated['date_to']) ? CarbonImmutable::parse((string) $validated['date_to']) : null;
        $compareFrom = isset($validated['compare_from']) ? CarbonImmutable::parse((string) $validated['compare_from']) : null;
        $compareTo = isset($validated['compare_to']) ? CarbonImmutable::parse((string) $validated['compare_to']) : null;
        $comparisonMode = strtolower(trim((string) ($validated['comparison_mode'] ?? 'template')));
        $periodView = strtolower(trim((string) ($validated['period_view'] ?? 'raw')));

        if ($dateFrom && $dateTo) {
            if ($dateFrom->greaterThan($dateTo)) {
                throw ValidationException::withMessages([
                    'date_from' => ['Start date must be on or before end date.'],
                ]);
            }

            if ($dateFrom->diffInDays($dateTo) > 366) {
                throw ValidationException::withMessages([
                    'date_range' => ['Date range must be 366 days or less.'],
                ]);
            }
        }

        if (($compareFrom && ! $compareTo) || (! $compareFrom && $compareTo)) {
            throw ValidationException::withMessages([
                'compare_range' => ['Both compare_from and compare_to are required when using a custom comparison range.'],
            ]);
        }

        if ($comparisonMode !== 'period' && array_key_exists('period_view', $validated)) {
            throw ValidationException::withMessages([
                'period_view' => ['Period view is only supported when comparison_mode is period.'],
            ]);
        }

        if ($compareFrom && $compareTo) {
            if ($comparisonMode !== 'period') {
                throw ValidationException::withMessages([
                    'comparison_mode' => ['Custom comparison range is only supported when comparison_mode is period.'],
                ]);
            }

            if ($compareFrom->greaterThan($compareTo)) {
                throw ValidationException::withMessages([
                    'compare_from' => ['Compare start date must be on or before compare end date.'],
                ]);
            }

            if ($compareFrom->diffInDays($compareTo) > 366) {
                throw ValidationException::withMessages([
                    'compare_range' => ['Comparison date range must be 366 days or less.'],
                ]);
            }
        }

        return [
            'date_from' => isset($validated['date_from']) ? trim((string) $validated['date_from']) : null,
            'date_to' => isset($validated['date_to']) ? trim((string) $validated['date_to']) : null,
            'provider' => $this->nullableString($validated['provider'] ?? null),
            'provider_resolution_source' => $this->nullableString($validated['provider_resolution_source'] ?? null),
            'provider_readiness_status' => $this->nullableString($validated['provider_readiness_status'] ?? null),
            'template_key' => $this->nullableString($validated['template_key'] ?? null),
            'status' => strtolower(trim((string) ($validated['status'] ?? 'all'))),
            'comparison_mode' => $comparisonMode,
            'period_view' => $comparisonMode === 'period' ? $periodView : 'raw',
            'compare_from' => isset($validated['compare_from']) ? trim((string) $validated['compare_from']) : null,
            'compare_to' => isset($validated['compare_to']) ? trim((string) $validated['compare_to']) : null,
        ];
    }

    protected function invalidContextResponse(array $context): JsonResponse
    {
        $status = (string) ($context['status'] ?? 'invalid_request');

        $messages = [
            'open_from_shopify' => 'Open the app from Shopify Admin to load this page.',
            'missing_api_auth' => 'Shopify Admin verification is unavailable. Reload this program page from Shopify Admin and try again.',
            'missing_shop' => 'The Shopify shop context is missing from this request.',
            'unknown_shop' => 'This Shopify shop is not mapped to a Backstage store.',
            'invalid_hmac' => 'This Shopify request could not be verified.',
            'invalid_session_token' => 'Shopify Admin verification failed. Reload this program page from Shopify Admin and try again.',
            'expired_session_token' => 'Your Shopify Admin session expired. Reload this program page from Shopify Admin and try again.',
        ];

        return response()->json([
            'ok' => false,
            'message' => $messages[$status] ?? 'This embedded Shopify request could not be verified.',
            'status' => $status,
        ], $status === 'open_from_shopify' ? 400 : 401);
    }

    protected function headlineForStatus(string $status, string $rewardsLabel = 'Rewards'): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this app from Shopify Admin',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'We could not verify this Shopify request',
            default => $rewardsLabel,
        };
    }

    protected function subheadlineForStatus(string $status, string $rewardsProgramLabel = 'Rewards program'): string
    {
        $programLabel = trim($rewardsProgramLabel) !== '' ? $rewardsProgramLabel : 'Rewards program';

        return match ($status) {
            'open_from_shopify' => 'This page is meant to load inside your Shopify admin so it can verify the store context.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this keeps happening, the store app config needs attention.',
            default => 'Manage '.strtolower($programLabel).' settings.',
        };
    }

    protected function statusForPayload(array $payload): int
    {
        $earnStatus = (string) data_get($payload, 'earn.status', 'error');
        $redeemStatus = (string) data_get($payload, 'redeem.status', 'error');

        return $earnStatus === 'error' && $redeemStatus === 'error' ? 500 : 200;
    }

    /**
     * @param  array<string,mixed>  $store
     * @return array{available:bool,tenant_id:?int,status:?string,message:?string}
     */
    protected function rewardsConfigState(array $store, TenantResolver $tenantResolver): array
    {
        $tenantId = $tenantResolver->resolveTenantIdForStoreContext($store);
        if ($tenantId === null) {
            return [
                'available' => false,
                'tenant_id' => null,
                'status' => 'tenant_not_mapped',
                'message' => 'This Shopify store is not mapped to a tenant yet. Rewards settings are unavailable.',
            ];
        }

        if (! Schema::hasTable('tenant_marketing_settings')
            || ! Schema::hasTable('tenant_candle_cash_task_overrides')
            || ! Schema::hasTable('tenant_candle_cash_reward_overrides')) {
            return [
                'available' => false,
                'tenant_id' => $tenantId,
                'status' => 'tenant_scoped_rewards_storage_unavailable',
                'message' => 'Tenant-scoped rewards storage is not available yet. Run migrations before editing rewards for this tenant.',
            ];
        }

        return [
            'available' => true,
            'tenant_id' => $tenantId,
            'status' => null,
            'message' => null,
        ];
    }

    /**
     * @param  array{status?:?string,message?:?string}  $configState
     */
    protected function unsupportedRewardsConfigResponse(array $configState): JsonResponse
    {
        $status = (string) ($configState['status'] ?? 'tenant_scoped_rewards_config_unsupported');
        $httpStatus = $status === 'tenant_not_mapped' ? 422 : 409;

        return response()->json([
            'ok' => false,
            'status' => $status,
            'message' => (string) ($configState['message']
                ?? 'This embedded program editor is unavailable until earn and redeem rows are isolated per tenant.'),
        ], $httpStatus);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
