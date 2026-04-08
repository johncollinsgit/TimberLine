<?php

namespace App\Http\Controllers;

use App\Services\Marketing\BirthdayReportingService;
use App\Services\Shopify\Dashboard\ShopifyEmbeddedDashboardDataService;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedPerformanceProbe;
use App\Services\Shopify\ShopifyEmbeddedRewardsService;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantModuleAccessResolver;
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
        ShopifyEmbeddedDashboardDataService $dashboardDataService,
        TenantResolver $tenantResolver
    ): Response
    {
        $probe = $this->embeddedProbe($request);
        $pageContext = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $authorized = (bool) ($pageContext['ok'] ?? false);
        $store = (array) ($pageContext['store'] ?? []);
        $resolvedTenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;
        $configState = $authorized
            ? $probe->time('page_payload', fn (): array => $this->rewardsConfigState($store, $tenantResolver, $resolvedTenantId))
            : ['available' => false];
        $tenantId = is_numeric($configState['tenant_id'] ?? null) ? (int) $configState['tenant_id'] : $resolvedTenantId;
        $probe->forTenant($tenantId);
        $hasTenantContext = $authorized && $tenantId !== null;
        $overview = $hasTenantContext
            ? $probe->time('page_payload', fn (): array => $rewardsService->overview($tenantId))
            : [];
        // Rewards overview is intentionally "lite" by default. Pulling the full
        // embedded dashboard analytics payload blocks first paint and has been
        // responsible for timeouts/failed loads in production.
        $wantsAnalytics = $request->boolean('analytics');
        $analytics = [];

        if ($hasTenantContext && $wantsAnalytics) {
            $allowedKeys = [
                'timeframe',
                'comparison',
                'location_grouping',
                'custom_start_date',
                'custom_end_date',
                'refresh',
            ];
            $query = array_intersect_key($request->query(), array_flip($allowedKeys));

            $analytics = $probe->time('page_payload', fn (): array => $dashboardDataService->payload([
                ...$query,
                'tenant_id' => $tenantId,
            ]));
        }

        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'overview',
            'shopify.rewards-overview',
            [
                'dashboard' => $overview,
                'analytics' => $analytics,
                'analyticsEnabled' => $wantsAnalytics,
                'analyticsEndpoint' => route('shopify.app', ['full' => 1], false),
                'setupNote' => null,
            ],
            resolvedContext: $pageContext,
            resolvedConfigState: $configState,
            resolvedTenantId: $resolvedTenantId,
            probe: $probe
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
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $tenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;
        $probe->forTenant($tenantId);

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
            ],
            resolvedContext: $context,
            resolvedTenantId: $tenantId,
            probe: $probe
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
        $probe = $this->embeddedProbe($request);
        $context = $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $resolvedTenantId = $authorized
            ? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store))
            : null;
        $configState = $authorized
            ? $probe->time('page_payload', fn (): array => $this->rewardsConfigState(
                $store,
                $tenantResolver,
                $resolvedTenantId
            ))
            : ['available' => false, 'editable' => false];
        $probe->forTenant(is_numeric($configState['tenant_id'] ?? null) ? (int) $configState['tenant_id'] : null);

        return $this->renderSection(
            $request,
            $contextService,
            $tenantResolver,
            'notifications',
            'shopify.rewards-notifications',
            [
                'rewardsPolicyEndpoint' => route('shopify.app.api.rewards.policy'),
                'rewardsPolicyUpdateEndpoint' => route('shopify.app.api.rewards.policy.update'),
                'rewardsPolicyReviewEndpoint' => route('shopify.app.api.rewards.policy.review'),
                'rewardsPolicyAlphaDefaultsEndpoint' => route('shopify.app.api.rewards.policy.defaults.alpha'),
                'rewardsPolicyReminderDebugEndpoint' => route('shopify.app.api.rewards.policy.reminders.explain'),
                'rewardsPolicyReminderHistoryEndpoint' => route('shopify.app.api.rewards.policy.reminders.customer-history'),
                'rewardsPolicyReminderRequeueEndpoint' => route('shopify.app.api.rewards.policy.reminders.requeue'),
                'rewardsPolicyReminderSkipEndpoint' => route('shopify.app.api.rewards.policy.reminders.skip'),
                'rewardsPolicyReminderExportEndpoint' => route('shopify.app.api.rewards.policy.exports', ['type' => 'reminder_history'], false),
                'rewardsPolicyIssuanceExportEndpoint' => route('shopify.app.api.rewards.policy.exports', ['type' => 'reward_issuance'], false),
                'rewardsPolicyRedemptionExportEndpoint' => route('shopify.app.api.rewards.policy.exports', ['type' => 'reward_redemption'], false),
                'rewardsPolicyExpiringExportEndpoint' => route('shopify.app.api.rewards.policy.exports', ['type' => 'expiring_rewards'], false),
                'rewardsPolicyFinanceExportEndpoint' => route('shopify.app.api.rewards.policy.exports', ['type' => 'finance_summary'], false),
                'rewardsPolicyEditable' => (bool) ($configState['editable'] ?? false),
            ],
            resolvedContext: $context,
            resolvedConfigState: $configState,
            resolvedTenantId: $resolvedTenantId,
            probe: $probe
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

        $policyContext = [
            'editable' => (bool) ($configState['editable'] ?? false),
            'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
            'actor_user' => $request->user(),
        ];
        $payload = $rewardsService->payload($tenantId);
        $payload['meta']['policy'] = $rewardsService->policy($tenantId, $policyContext);
        $payload['meta']['access'] = [
            'editable' => (bool) ($configState['editable'] ?? false),
            'status' => $configState['status'] ?? null,
            'message' => $configState['message'] ?? null,
        ];

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ], $this->statusForPayload($payload));
    }

    public function policy(
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

        try {
            $reportFilters = $this->validatedReminderReportingFilters($request);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Reminder reporting filters are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $policy = $rewardsService->policy($tenantId, [
            'editable' => (bool) ($configState['editable'] ?? false),
            'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
            'actor_user' => $request->user(),
            'report_filters' => $reportFilters,
        ]);

        return response()->json([
            'ok' => true,
            'editable' => (bool) ($configState['editable'] ?? false),
            'status' => $configState['status'] ?? null,
            'message' => $configState['message'] ?? null,
            'data' => $policy,
        ]);
    }

    public function updatePolicy(
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

        if (! ($configState['editable'] ?? false)) {
            return $this->blockedRewardsEditResponse($configState);
        }

        try {
            $payload = $this->validatePolicyPayload($request);
            $requestedAutomationMode = data_get($payload, 'automation_and_reporting.automation_mode');
            if ($requestedAutomationMode !== null) {
                $currentPolicy = $rewardsService->policy($tenantId, [
                    'editable' => true,
                    'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
                    'actor_user' => $request->user(),
                ]);
                $currentAutomationMode = (string) data_get($currentPolicy, 'automation_and_reporting.automation_mode', 'manual');

                if (strtolower(trim((string) $requestedAutomationMode)) !== strtolower(trim($currentAutomationMode))) {
                    if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'automation')) {
                        return $permissionResponse;
                    }
                }
            }

            if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'publish')) {
                return $permissionResponse;
            }

            $policy = $rewardsService->updatePolicy($tenantId, $payload, [
                'editable' => true,
                'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
                'actor_user' => $request->user(),
                'actor_user_id' => optional($request->user())->id,
                'shopify_admin_user_id' => $context['shopify_admin_user_id'] ?? null,
                'shopify_admin_session_id' => $context['shopify_admin_session_id'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Program settings could not be saved.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Program settings saved.',
            'data' => $policy,
        ]);
    }

    public function reviewPolicy(
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

        if (! ($configState['editable'] ?? false)) {
            return $this->blockedRewardsEditResponse($configState);
        }

        if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'edit')) {
            return $permissionResponse;
        }

        $payload = $this->validatePolicyPayload($request);
        $review = $rewardsService->reviewPolicy($tenantId, $payload, [
            'editable' => true,
            'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
            'actor_user' => $request->user(),
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Review preview generated.',
            'data' => $review,
        ]);
    }

    public function applyAlphaDefaults(
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

        if (! ($configState['editable'] ?? false)) {
            return $this->blockedRewardsEditResponse($configState);
        }

        if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'publish')) {
            return $permissionResponse;
        }

        try {
            $policy = $rewardsService->applyAlphaDefaults($tenantId, [
                'editable' => true,
                'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
                'actor_user' => $request->user(),
                'actor_user_id' => optional($request->user())->id,
                'shopify_admin_user_id' => $context['shopify_admin_user_id'] ?? null,
                'shopify_admin_session_id' => $context['shopify_admin_session_id'] ?? null,
            ]);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Alpha starter settings could not be applied.',
                'errors' => $exception->errors(),
            ], 422);
        }

        return response()->json([
            'ok' => true,
            'message' => 'Alpha starter settings applied.',
            'data' => $policy,
        ]);
    }

    public function explainReminder(
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

        try {
            $filters = $this->validatedReminderSupportPayload($request, false);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Reminder lookup details are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'support')) {
            return $permissionResponse;
        }

        $result = $rewardsService->explainReminder($tenantId, $filters, [
            'editable' => (bool) ($configState['editable'] ?? false),
            'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
            'actor_user' => $request->user(),
            'actor_user_id' => optional($request->user())->id,
            'shopify_admin_user_id' => $context['shopify_admin_user_id'] ?? null,
            'shopify_admin_session_id' => $context['shopify_admin_session_id'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Reminder explanation ready.',
            'data' => $result,
        ]);
    }

    public function reminderCustomerHistory(
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

        try {
            $filters = $this->validatedReminderReportingFilters($request);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Reminder history filters are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $marketingProfileId = is_numeric($request->query('marketing_profile_id'))
            ? max(0, (int) $request->query('marketing_profile_id'))
            : 0;

        if ($marketingProfileId <= 0) {
            return response()->json([
                'ok' => false,
                'message' => 'Customer reminder history needs a customer id.',
                'errors' => [
                    'marketing_profile_id' => ['A customer id is required to load reminder history.'],
                ],
            ], 422);
        }

        if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'support')) {
            return $permissionResponse;
        }

        $result = $rewardsService->reminderHistoryForCustomer($tenantId, $marketingProfileId, $filters, [
            'actor_user' => $request->user(),
            'actor_user_id' => optional($request->user())->id,
            'shopify_admin_user_id' => $context['shopify_admin_user_id'] ?? null,
            'shopify_admin_session_id' => $context['shopify_admin_session_id'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => 'Customer reminder history loaded.',
            'data' => $result,
        ]);
    }

    public function requeueReminder(
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

        if (! ($configState['editable'] ?? false)) {
            return $this->blockedRewardsEditResponse($configState);
        }

        if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'support')) {
            return $permissionResponse;
        }

        try {
            $filters = $this->validatedReminderSupportPayload($request, true);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Reminder requeue details are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $result = $rewardsService->requeueReminder($tenantId, $filters, [
            'editable' => true,
            'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
            'actor_user' => $request->user(),
            'actor_user_id' => optional($request->user())->id,
            'shopify_admin_user_id' => $context['shopify_admin_user_id'] ?? null,
            'shopify_admin_session_id' => $context['shopify_admin_session_id'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => ((int) ($result['queued_count'] ?? 0)) > 0
                ? 'Reminder requeue requested.'
                : 'No eligible due reminder matched that request.',
            'data' => $result,
        ]);
    }

    public function skipReminder(
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

        if (! ($configState['editable'] ?? false)) {
            return $this->blockedRewardsEditResponse($configState);
        }

        if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'support')) {
            return $permissionResponse;
        }

        try {
            $filters = $this->validatedReminderSupportPayload($request, true);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Reminder skip details are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $result = $rewardsService->markReminderSkipped($tenantId, $filters, [
            'editable' => true,
            'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
            'actor_user' => $request->user(),
            'actor_user_id' => optional($request->user())->id,
            'shopify_admin_user_id' => $context['shopify_admin_user_id'] ?? null,
            'shopify_admin_session_id' => $context['shopify_admin_session_id'] ?? null,
        ]);

        return response()->json([
            'ok' => true,
            'message' => ((int) data_get($result, 'summary.skipped_count', 0)) > 0
                ? 'Reminder marked as skipped.'
                : 'No due reminder matched that request.',
            'data' => $result,
        ]);
    }

    public function exportRewardsData(
        string $type,
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedRewardsService $rewardsService,
        TenantResolver $tenantResolver
    ): \Symfony\Component\HttpFoundation\Response {
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
            $filters = $this->validatedReminderReportingFilters($request);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Rewards export filters are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $export = $rewardsService->exportData($type, $tenantId, $filters, [
            'editable' => (bool) ($configState['editable'] ?? false),
            'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
            'actor_user' => $request->user(),
        ]);

        return $this->streamCsvExport(
            columns: (array) ($export['columns'] ?? []),
            rows: (array) ($export['rows'] ?? []),
            filename: (string) ($export['filename'] ?? 'rewards-export.csv')
        );
    }

    public function downloadSignedRewardsExport(
        int $tenant,
        string $type,
        Request $request,
        ShopifyEmbeddedRewardsService $rewardsService
    ): \Symfony\Component\HttpFoundation\Response {
        try {
            $filters = $this->validatedReminderReportingFilters($request);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Rewards export filters are invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $export = $rewardsService->exportData($type, $tenant, $filters, [
            'editable' => false,
            'sms_channel_enabled' => true,
        ]);

        return $this->streamCsvExport(
            columns: (array) ($export['columns'] ?? []),
            rows: (array) ($export['rows'] ?? []),
            filename: (string) ($export['filename'] ?? 'rewards-export.csv')
        );
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

        if (! ($configState['editable'] ?? false)) {
            return $this->blockedRewardsEditResponse($configState);
        }

        if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'publish')) {
            return $permissionResponse;
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

        if (! ($configState['editable'] ?? false)) {
            return $this->blockedRewardsEditResponse($configState);
        }

        if ($permissionResponse = $this->authorizeRewardsAction($request, $rewardsService, $tenantId, $configState, 'publish')) {
            return $permissionResponse;
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
        array $extra = [],
        ?array $resolvedContext = null,
        ?array $resolvedConfigState = null,
        ?int $resolvedTenantId = null,
        ?ShopifyEmbeddedPerformanceProbe $probe = null
    ): Response {
        $probe ??= $this->embeddedProbe($request);
        $context = $resolvedContext ?? $probe->time('context', fn (): array => $contextService->resolvePageContext($request));
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);
        $configTenantId = is_numeric($resolvedConfigState['tenant_id'] ?? null)
            ? (int) $resolvedConfigState['tenant_id']
            : null;
        $resolvedTenantId = $authorized
            ? ($resolvedTenantId ?? $configTenantId ?? $probe->time('tenant_resolve', fn (): ?int => $tenantResolver->resolveTenantIdForStoreContext($store)))
            : null;
        $configState = $resolvedConfigState ?? ($authorized
            ? $probe->time('page_payload', fn (): array => $this->rewardsConfigState($store, $tenantResolver, $resolvedTenantId))
            : [
                'available' => false,
                'editable' => false,
                'tenant_id' => null,
                'status' => null,
                'message' => null,
                'sms_channel_enabled' => false,
            ]);
        $probe->forTenant(is_numeric($configState['tenant_id'] ?? null) ? (int) $configState['tenant_id'] : $resolvedTenantId);
        $displayLabels = $probe->time('shell_payload', fn (): array => app(TenantDisplayLabelResolver::class)->resolve($configState['tenant_id'] ?? null));
        $labels = is_array($displayLabels['labels'] ?? null) ? (array) $displayLabels['labels'] : [];
        $rewardsLabel = trim((string) ($labels['rewards_label'] ?? $labels['rewards'] ?? 'Rewards'));
        if ($rewardsLabel === '') {
            $rewardsLabel = 'Rewards';
        }
        if ($authorized && ($configState['tenant_id'] ?? null) === null && $rewardsLabel === 'Rewards') {
            $rewardsLabel = 'Candle Cash';
        }
        $rewardsBalanceLabel = trim((string) ($labels['rewards_balance_label'] ?? ($rewardsLabel.' balance')));
        if ($rewardsBalanceLabel === '') {
            $rewardsBalanceLabel = $rewardsLabel.' balance';
        }
        $rewardsProgramLabel = trim((string) ($labels['rewards_program_label'] ?? ($rewardsLabel.' program')));
        if ($rewardsProgramLabel === '') {
            $rewardsProgramLabel = $rewardsLabel.' program';
        }
        if ($authorized && ($configState['tenant_id'] ?? null) === null && $rewardsProgramLabel === 'Rewards program') {
            $rewardsProgramLabel = 'Candle Cash rewards and program';
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

        $appNavigation = $probe->time('shell_payload', fn (): array => $this->embeddedAppNavigation(
            'rewards',
            $section,
            $configState['tenant_id'] ?? null
        ));

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
            'policyEndpoint' => route('shopify.app.api.rewards.policy'),
            'policyUpdateEndpoint' => route('shopify.app.api.rewards.policy.update'),
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
            'rewardsEditorEditable' => (bool) ($configState['editable'] ?? false),
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
            'appNavigation' => $appNavigation,
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

        $response = $probe->time('view_render', fn (): Response => $this->embeddedResponse(
            response()->view($view, $viewData),
            $authorized ? 200 : ($status === 'open_from_shopify' ? 200 : 401)
        ));

        return $probe->addContext([
            'authorized' => $authorized,
            'status' => $status,
        ])->finish($response);
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
     * @return array<string,mixed>
     */
    protected function validatePolicyPayload(Request $request): array
    {
        $sections = [
            'program_identity',
            'value_model',
            'earning_rules',
            'redemption_rules',
            'expiration_and_reminders',
            'customer_experience',
            'finance_and_safety',
            'access_state',
            'automation_and_reporting',
            'team_access',
        ];

        $payload = is_array($request->all()) ? $request->all() : [];

        return collect($payload)
            ->only($sections)
            ->map(fn ($value): array => is_array($value) ? $value : [])
            ->all();
    }

    /**
     * @return array{
     *   date_from:?string,
     *   date_to:?string,
     *   channel:?string,
     *   status:?string,
     *   reward_type:?string,
     *   activity_window_days:int,
     *   upcoming_window_days:int,
     *   expiring_soon_days:int
     * }
     */
    protected function validatedReminderReportingFilters(Request $request): array
    {
        $validated = validator($request->query(), [
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'channel' => ['nullable', 'in:email,sms'],
            'status' => ['nullable', 'in:sent,skipped,failed,attempted,scheduled'],
            'reward_type' => ['nullable', 'string', 'max:80'],
            'activity_window_days' => ['nullable', 'integer', 'min:1', 'max:180'],
            'upcoming_window_days' => ['nullable', 'integer', 'min:1', 'max:30'],
            'expiring_soon_days' => ['nullable', 'integer', 'min:1', 'max:90'],
        ])->validate();

        $dateFrom = isset($validated['date_from']) ? CarbonImmutable::parse((string) $validated['date_from']) : null;
        $dateTo = isset($validated['date_to']) ? CarbonImmutable::parse((string) $validated['date_to']) : null;

        if ($dateFrom && $dateTo && $dateFrom->greaterThan($dateTo)) {
            throw ValidationException::withMessages([
                'date_from' => ['Start date must be on or before end date.'],
            ]);
        }

        if ($dateFrom && $dateTo && $dateFrom->diffInDays($dateTo) > 366) {
            throw ValidationException::withMessages([
                'date_range' => ['Reminder reporting date range must be 366 days or less.'],
            ]);
        }

        return [
            'date_from' => isset($validated['date_from']) ? trim((string) $validated['date_from']) : null,
            'date_to' => isset($validated['date_to']) ? trim((string) $validated['date_to']) : null,
            'channel' => $this->nullableString($validated['channel'] ?? null),
            'status' => $this->nullableString($validated['status'] ?? null),
            'reward_type' => $this->nullableString($validated['reward_type'] ?? null),
            'activity_window_days' => max(1, (int) ($validated['activity_window_days'] ?? 30)),
            'upcoming_window_days' => max(1, (int) ($validated['upcoming_window_days'] ?? 7)),
            'expiring_soon_days' => max(1, (int) ($validated['expiring_soon_days'] ?? 14)),
        ];
    }

    /**
     * @return array{
     *   reward_identifier:?string,
     *   marketing_profile_id:?int,
     *   channel:?string,
     *   timing_days_before_expiration:?int,
     *   reason:?string
     * }
     */
    protected function validatedReminderSupportPayload(Request $request, bool $requireReason): array
    {
        $validated = validator($request->all(), [
            'reward_identifier' => ['nullable', 'string', 'max:160'],
            'marketing_profile_id' => ['nullable', 'integer', 'min:1'],
            'channel' => ['nullable', 'in:email,sms'],
            'timing_days_before_expiration' => ['nullable', 'integer', 'min:0', 'max:365'],
            'reason' => $requireReason
                ? ['required', 'string', 'max:240']
                : ['nullable', 'string', 'max:240'],
        ])->validate();

        $rewardIdentifier = $this->nullableString($validated['reward_identifier'] ?? null);
        $marketingProfileId = is_numeric($validated['marketing_profile_id'] ?? null)
            ? max(1, (int) $validated['marketing_profile_id'])
            : null;

        if ($rewardIdentifier === null && $marketingProfileId === null) {
            throw ValidationException::withMessages([
                'reward_identifier' => ['Enter a reward id or a customer id before running this action.'],
            ]);
        }

        return [
            'reward_identifier' => $rewardIdentifier,
            'marketing_profile_id' => $marketingProfileId,
            'channel' => $this->nullableString($validated['channel'] ?? null),
            'timing_days_before_expiration' => is_numeric($validated['timing_days_before_expiration'] ?? null)
                ? max(0, (int) $validated['timing_days_before_expiration'])
                : null,
            'reason' => $this->nullableString($validated['reason'] ?? null),
        ];
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

    /**
     * @param  array<int,string>  $columns
     * @param  array<int,array<string,mixed>>  $rows
     */
    protected function streamCsvExport(array $columns, array $rows, string $filename): \Symfony\Component\HttpFoundation\Response
    {
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
            default => $programLabel === 'Candle Cash rewards and program'
                ? 'Manage Candle Cash rewards and program settings.'
                : 'Manage '.strtolower($programLabel).' settings.',
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
     * @return array{available:bool,editable:bool,tenant_id:?int,status:?string,message:?string,sms_channel_enabled:bool}
     */
    protected function rewardsConfigState(array $store, TenantResolver $tenantResolver, ?int $resolvedTenantId = null): array
    {
        $tenantId = $resolvedTenantId ?? $tenantResolver->resolveTenantIdForStoreContext($store);
        if ($tenantId === null) {
            return [
                'available' => false,
                'editable' => false,
                'tenant_id' => null,
                'status' => 'tenant_not_mapped',
                'message' => 'This Shopify store is not mapped to a tenant yet. Rewards settings are unavailable.',
                'sms_channel_enabled' => false,
            ];
        }

        if (! Schema::hasTable('tenant_marketing_settings')
            || ! Schema::hasTable('tenant_candle_cash_task_overrides')
            || ! Schema::hasTable('tenant_candle_cash_reward_overrides')) {
            return [
                'available' => false,
                'editable' => false,
                'tenant_id' => $tenantId,
                'status' => 'tenant_scoped_rewards_storage_unavailable',
                'message' => 'Tenant-scoped rewards storage is not available yet. Run migrations before editing rewards for this tenant.',
                'sms_channel_enabled' => false,
            ];
        }

        /** @var TenantModuleAccessResolver $moduleResolver */
        $moduleResolver = app(TenantModuleAccessResolver::class);
        $rewardsModule = $moduleResolver->module($tenantId, 'rewards');
        $smsModule = $moduleResolver->module($tenantId, 'sms');
        $hasRewardsAccess = (bool) ($rewardsModule['has_access'] ?? false);
        $rewardsUiState = strtolower(trim((string) ($rewardsModule['ui_state'] ?? 'locked')));
        $editable = $hasRewardsAccess && $rewardsUiState !== 'locked' && $rewardsUiState !== 'coming_soon';
        $status = $editable ? null : (($rewardsUiState === 'coming_soon') ? 'rewards_module_coming_soon' : 'rewards_plan_locked');
        $message = $editable
            ? null
            : ($rewardsUiState === 'coming_soon'
                ? 'Rewards settings are read-only while this module is marked coming soon for this tenant.'
                : 'Plan access is required before rewards settings can be edited.');

        return [
            'available' => true,
            'editable' => $editable,
            'tenant_id' => $tenantId,
            'status' => $status,
            'message' => $message,
            'sms_channel_enabled' => (bool) ($smsModule['has_access'] ?? false),
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

    /**
     * @param  array{status?:?string,message?:?string}  $configState
     */
    protected function blockedRewardsEditResponse(array $configState): JsonResponse
    {
        $status = (string) ($configState['status'] ?? 'rewards_plan_locked');

        return response()->json([
            'ok' => false,
            'status' => $status,
            'message' => (string) ($configState['message']
                ?? 'Rewards settings are currently read-only for this tenant.'),
        ], 403);
    }

    /**
     * @param  array{editable?:bool,sms_channel_enabled?:bool}  $configState
     */
    protected function authorizeRewardsAction(
        Request $request,
        ShopifyEmbeddedRewardsService $rewardsService,
        int $tenantId,
        array $configState,
        string $action
    ): ?JsonResponse {
        if (! $request->user()) {
            return null;
        }

        $policy = $rewardsService->policy($tenantId, [
            'editable' => (bool) ($configState['editable'] ?? false),
            'sms_channel_enabled' => (bool) ($configState['sms_channel_enabled'] ?? false),
            'actor_user' => $request->user(),
        ]);

        if ((bool) data_get($policy, 'permissions.actions.'.$action.'.allowed', true)) {
            return null;
        }

        return response()->json([
            'ok' => false,
            'status' => 'rewards_action_forbidden',
            'message' => match ($action) {
                'automation' => 'Your team role cannot switch rewards automation mode.',
                'publish' => 'Your team role cannot publish live rewards changes.',
                'support' => 'Your team role cannot use rewards reminder support tools.',
                default => 'Your team role cannot edit these rewards settings.',
            },
        ], 403);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
