<?php

namespace App\Http\Controllers;

use App\Models\CandleCashReward;
use App\Models\CandleCashTask;
use App\Services\Shopify\ShopifyEmbeddedAppContext;
use App\Services\Shopify\ShopifyEmbeddedRewardsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
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

    public function index(Request $request, ShopifyEmbeddedAppContext $contextService): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            'overview',
            'shopify.rewards-overview'
        );
    }

    public function earn(Request $request, ShopifyEmbeddedAppContext $contextService): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            'earn',
            'shopify.rewards'
        );
    }

    public function redeem(Request $request, ShopifyEmbeddedAppContext $contextService): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            'redeem',
            'shopify.rewards'
        );
    }

    public function referrals(Request $request, ShopifyEmbeddedAppContext $contextService): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            'referrals',
            'shopify.rewards-placeholder',
            [
                'title' => 'Referrals coming soon',
                'message' => 'Referral tracking and rewards will arrive here once the next phase of the embedded admin is ready.',
            ]
        );
    }

    public function birthdays(Request $request, ShopifyEmbeddedAppContext $contextService): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            'birthdays',
            'shopify.rewards-placeholder',
            [
                'title' => 'Birthday rewards coming soon',
                'message' => 'Birthday-specific reward controls are still managed from Backstage. This tab will mirror that data soon.',
            ]
        );
    }

    public function vip(Request $request, ShopifyEmbeddedAppContext $contextService): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            'vip',
            'shopify.rewards-placeholder',
            [
                'title' => 'VIP experiences coming soon',
                'message' => 'VIP program controls will be surfaced here once we reuse the existing Candle Cash VIP logic.',
            ]
        );
    }

    public function notifications(Request $request, ShopifyEmbeddedAppContext $contextService): Response
    {
        return $this->renderSection(
            $request,
            $contextService,
            'notifications',
            'shopify.rewards-placeholder',
            [
                'title' => 'Notifications coming soon',
                'message' => 'Notification settings for Candle Cash will appear here in a later phase.',
            ]
        );
    }

    public function data(
        Request $request,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedRewardsService $rewardsService
    ): JsonResponse {
        $context = $contextService->resolveApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        $payload = $rewardsService->payload();

        return response()->json([
            'ok' => true,
            'data' => $payload,
        ], $this->statusForPayload($payload));
    }

    public function updateEarnRule(
        Request $request,
        CandleCashTask $task,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedRewardsService $rewardsService
    ): JsonResponse {
        $context = $contextService->resolveApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        try {
            $data = $this->validateEarnPayload($request);
            $rule = $rewardsService->updateEarnRule($task, $data);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Earn rule could not be saved.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $payload = $rewardsService->payload();

        return response()->json([
            'ok' => true,
            'message' => 'Earn rule saved.',
            'rule' => $rule,
            'data' => $payload,
        ], $this->statusForPayload($payload));
    }

    public function updateRedeemRule(
        Request $request,
        CandleCashReward $reward,
        ShopifyEmbeddedAppContext $contextService,
        ShopifyEmbeddedRewardsService $rewardsService
    ): JsonResponse {
        $context = $contextService->resolveApiContext($request);
        if (! ($context['ok'] ?? false)) {
            return $this->invalidContextResponse($context);
        }

        try {
            $data = $this->validateRedeemPayload($request);
            $rule = $rewardsService->updateRedeemRule($reward, $data);
        } catch (ValidationException $exception) {
            return response()->json([
                'ok' => false,
                'message' => 'Redeem rule could not be saved.',
                'errors' => $exception->errors(),
            ], 422);
        }

        $payload = $rewardsService->payload();

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
        string $section,
        string $view,
        array $extra = []
    ): Response {
        $context = $contextService->resolvePageContext($request);
        $status = (string) ($context['status'] ?? 'invalid_request');
        $authorized = (bool) ($context['ok'] ?? false);
        $store = (array) ($context['store'] ?? []);

        $viewData = [
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
            'contextToken' => $authorized ? $contextService->issueContextToken($context) : null,
            'dataEndpoint' => route('shopify.app.api.rewards'),
            'earnUpdateEndpointTemplate' => route('shopify.app.api.rewards.earn.update', ['task' => '__TASK__']),
            'redeemUpdateEndpointTemplate' => route('shopify.app.api.rewards.redeem.update', ['reward' => '__REWARD__']),
            'setupNote' => $authorized
                ? 'This embedded page updates the live Candle Cash task and reward rows already used by Backstage.'
                : ($status === 'open_from_shopify'
                    ? 'Open the app from Shopify Admin so the store context can be verified before editing rewards.'
                    : null),
            'referenceLinks' => $authorized
                ? [
                    [
                        'label' => 'Backstage Customers',
                        'href' => route('marketing.customers'),
                    ],
                    [
                        'label' => 'Birthday Rewards',
                        'href' => route('birthdays.rewards'),
                    ],
                ]
                : [],
            'appNavigation' => $this->embeddedAppNavigation('rewards', $section),
            'pageActions' => [],
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
            'points_value' => ['required', 'integer', 'min:0', 'max:50000'],
            'enabled' => ['required', 'boolean'],
            'sort_order' => ['required', 'integer', 'min:0', 'max:9999'],
        ]);
    }

    protected function validateRedeemPayload(Request $request): array
    {
        return $request->validate([
            'title' => ['required', 'string', 'max:160'],
            'description' => ['nullable', 'string', 'max:500'],
            'points_cost' => ['required', 'integer', 'min:0', 'max:50000'],
            'reward_value' => ['nullable', 'string', 'max:120'],
            'enabled' => ['required', 'boolean'],
        ]);
    }

    protected function invalidContextResponse(array $context): JsonResponse
    {
        $status = (string) ($context['status'] ?? 'invalid_request');

        $messages = [
            'open_from_shopify' => 'Open the app from Shopify Admin to load this page.',
            'missing_shop' => 'The Shopify shop context is missing from this request.',
            'unknown_shop' => 'This Shopify shop is not mapped to a Backstage store.',
            'invalid_hmac' => 'This Shopify request could not be verified.',
            'invalid_context_token' => 'This embedded admin session expired. Reload the app from Shopify Admin.',
        ];

        return response()->json([
            'ok' => false,
            'message' => $messages[$status] ?? 'This embedded Shopify request could not be verified.',
            'status' => $status,
        ], $status === 'open_from_shopify' ? 400 : 401);
    }

    protected function headlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'Open this app from Shopify Admin',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'We could not verify this Shopify request',
            default => 'Rewards',
        };
    }

    protected function subheadlineForStatus(string $status): string
    {
        return match ($status) {
            'open_from_shopify' => 'This page is meant to load inside your Shopify admin so it can verify the store context.',
            'missing_shop', 'unknown_shop', 'invalid_hmac' => 'Open the app again from Shopify Admin. If this keeps happening, the store app config needs attention.',
            default => 'Manage Candle Cash rewards and program settings.',
        };
    }

    protected function statusForPayload(array $payload): int
    {
        $earnStatus = (string) data_get($payload, 'earn.status', 'error');
        $redeemStatus = (string) data_get($payload, 'redeem.status', 'error');

        return $earnStatus === 'error' && $redeemStatus === 'error' ? 500 : 200;
    }
}
