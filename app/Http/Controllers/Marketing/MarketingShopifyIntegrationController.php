<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionShopifyCustomerForMarketingProfile;
use App\Models\CandleCashRedemption;
use App\Models\CandleCashReward;
use App\Models\CandleCashTransaction;
use App\Models\CustomerExternalProfile;
use App\Models\CustomerBirthdayProfile;
use App\Models\MarketingConsentRequest;
use App\Models\MarketingProfile;
use App\Services\Marketing\CandleCashService;
use App\Services\Marketing\CandleCashReferralService;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\BirthdayProfileService;
use App\Services\Marketing\BirthdayRewardActivationService;
use App\Services\Marketing\BirthdayRewardEngineService;
use App\Services\Marketing\CandleCashAccessGate;
use App\Services\Marketing\CandleCashShopifyDiscountService;
use App\Services\Marketing\GoogleBusinessProfileConnectionService;
use App\Services\Marketing\GoogleBusinessProfileException;
use App\Services\Marketing\MarketingConsentCaptureService;
use App\Services\Marketing\MarketingConsentIncentiveService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\MarketingProfileSyncService;
use App\Services\Marketing\ProductReviewService;
use App\Services\Marketing\ShopifyBirthdayMetafieldService;
use App\Services\Marketing\MarketingStorefrontEventLogger;
use App\Services\Marketing\MarketingStorefrontIdentityService;
use App\Services\Marketing\MarketingStorefrontWidgetService;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantResolver;
use App\Support\Marketing\MarketingStorefrontContract;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class MarketingShopifyIntegrationController extends Controller
{
    public function __construct(
        protected MarketingStorefrontIdentityService $identityService,
        protected MarketingStorefrontWidgetService $widgetService,
        protected MarketingStorefrontEventLogger $eventLogger,
        protected TenantResolver $tenantResolver
    ) {
    }

    public function rewardBalance(Request $request, CandleCashService $candleCashService): JsonResponse
    {
        $storeContext = $this->resolveStoreContext($request);
        if (! $this->hasStoreContext($storeContext)) {
            return $this->missingStoreContextResponse('reward_balance');
        }

        $resolved = $this->resolveProfile($request, scope: 'reward_balance', allowCreate: false);
        if (! $resolved['profile']) {
            $this->logStorefrontEvent($request, 'widget_balance_lookup', [
                'status' => 'error',
                'issue_type' => 'identity_' . $resolved['status'],
                'source_type' => 'shopify_widget_reward_balance',
            ]);

            return $this->identityErrorResponse($resolved['status'], $request);
        }

        $profile = $resolved['profile'];
        $balance = $candleCashService->currentBalance($profile);
        $rewards = $this->activeStorefrontRewards($candleCashService);
        $redemptions = $this->recentRedemptions($profile);
        $states = $this->widgetService->rewardWidgetStates($profile, $balance, $rewards, $redemptions);
        $balancePayload = $this->storefrontBalancePayload($candleCashService, $balance);
        $storefrontReward = $candleCashService->storefrontRewardPayload($candleCashService->storefrontReward(), $balance);

        $this->logStorefrontEvent($request, 'widget_balance_lookup', [
            'status' => 'ok',
            'profile' => $profile,
            'source_type' => 'shopify_widget_reward_balance',
            'source_id' => 'profile:' . $profile->id,
            'meta' => [
                'balance' => $balance,
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'candle_cash_balance' => data_get($balancePayload, 'candle_cash_amount'),
            'candle_cash_balance_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
            'candle_cash_amount' => data_get($balancePayload, 'candle_cash_amount'),
            'candle_cash_amount_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
            'balance' => $balancePayload,
            'redemption_rules' => $candleCashService->redemptionRulesPayload(),
            'storefront_reward' => $storefrontReward,
            'state' => $states[0] ?? 'linked_customer',
            'consent' => [
                'sms' => (bool) $profile->accepts_sms_marketing,
                'email' => (bool) $profile->accepts_email_marketing,
            ],
        ], $this->contractMeta($request), $states);
    }

    public function availableRewards(Request $request, CandleCashService $candleCashService): JsonResponse
    {
        $resolved = null;
        $hasIdentityQuery = trim((string) $request->query('email', '')) !== ''
            || trim((string) $request->query('phone', '')) !== ''
            || $this->resolveShopifyCustomerId($request, allowBody: false) !== ''
            || (int) $request->query('marketing_profile_id', 0) > 0;
        if ($hasIdentityQuery) {
            $storeContext = $this->resolveStoreContext($request);
            if (! $this->hasStoreContext($storeContext)) {
                return $this->missingStoreContextResponse('available_rewards');
            }

            $resolved = $this->resolveProfile($request, scope: 'available_rewards', allowCreate: false);
        }

        $profile = $resolved['profile'] ?? null;
        $rewards = $this->activeStorefrontRewards($candleCashService);
        $redemptions = $profile ? $this->recentRedemptions($profile) : collect();
        $balance = $profile ? $candleCashService->currentBalance($profile) : 0;
        $states = $this->widgetService->rewardWidgetStates($profile, $balance, $rewards, $redemptions);
        $rewardRows = $this->storefrontRewardRows($candleCashService, $profile ? $balance : null);
        $balancePayload = $profile ? $this->storefrontBalancePayload($candleCashService, $balance) : null;

        $this->logStorefrontEvent($request, 'widget_rewards_lookup', [
            'status' => $profile ? 'ok' : 'pending',
            'issue_type' => $profile ? null : 'unknown_customer',
            'profile' => $profile,
            'source_type' => 'shopify_widget_rewards_available',
            'meta' => [
                'reward_count' => count($rewardRows),
            ],
            'resolution_status' => $profile ? 'resolved' : 'open',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => $profile ? (int) $profile->id : null,
            'candle_cash_balance' => $profile ? data_get($balancePayload, 'candle_cash_amount') : null,
            'candle_cash_balance_formatted' => $profile ? data_get($balancePayload, 'candle_cash_amount_formatted') : null,
            'candle_cash_amount' => data_get($balancePayload, 'candle_cash_amount'),
            'candle_cash_amount_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
            'balance' => $balancePayload,
            'state' => $states[0] ?? ($profile ? 'linked_customer' : 'unknown_customer'),
            'redemption_rules' => $candleCashService->redemptionRulesPayload(),
            'rewards' => $rewardRows,
            'storefront_reward' => $rewardRows[0] ?? null,
        ], $this->contractMeta($request), $states);
    }

    public function rewardHistory(Request $request, CandleCashService $candleCashService): JsonResponse
    {
        $storeContext = $this->resolveStoreContext($request);
        if (! $this->hasStoreContext($storeContext)) {
            return $this->missingStoreContextResponse('reward_history');
        }

        $resolved = $this->resolveProfile($request, scope: 'reward_history', allowCreate: false);
        if (! $resolved['profile']) {
            $this->logStorefrontEvent($request, 'widget_reward_history_lookup', [
                'status' => 'error',
                'issue_type' => 'identity_' . $resolved['status'],
                'source_type' => 'shopify_widget_reward_history',
            ]);

            return $this->identityErrorResponse($resolved['status'], $request);
        }

        $profile = $resolved['profile'];
        $transactions = $profile->candleCashTransactions()
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'type', 'candle_cash_delta', 'source', 'source_id', 'description', 'created_at']);
        $redemptions = $this->recentRedemptions($profile);
        $balance = $candleCashService->currentBalance($profile);
        $states = $this->widgetService->rewardWidgetStates($profile, $balance, $this->activeStorefrontRewards($candleCashService), $redemptions);
        $balancePayload = $this->storefrontBalancePayload($candleCashService, $balance);

        $this->logStorefrontEvent($request, 'widget_reward_history_lookup', [
            'status' => 'ok',
            'profile' => $profile,
            'source_type' => 'shopify_widget_reward_history',
            'source_id' => 'profile:' . $profile->id,
            'meta' => [
                'transactions' => $transactions->count(),
                'redemptions' => $redemptions->count(),
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'candle_cash_balance' => data_get($balancePayload, 'candle_cash_amount'),
            'candle_cash_balance_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
            'candle_cash_amount' => data_get($balancePayload, 'candle_cash_amount'),
            'candle_cash_amount_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
            'balance' => $balancePayload,
            'redemption_rules' => $candleCashService->redemptionRulesPayload(),
            'state' => $states[0] ?? 'linked_customer',
            'transactions' => $transactions->map(fn ($row): array => [
                'id' => (int) $row->id,
                'type' => (string) $row->type,
                'candle_cash_amount' => $candleCashService->amountFromPoints($row->candle_cash_delta),
                'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints(abs((float) $row->candle_cash_delta))),
                'signed_candle_cash_amount_formatted' => $candleCashService->candleCashAmountLabelFromPoints($row->candle_cash_delta, true),
                'source' => (string) $row->source,
                'source_id' => $row->source_id ? (string) $row->source_id : null,
                'description' => $row->description ? (string) $row->description : null,
                'created_at' => optional($row->created_at)->toIso8601String(),
            ])->all(),
            'redemptions' => $redemptions->map(fn ($row): array => [
                'id' => (int) $row->id,
                'candle_cash_amount' => $candleCashService->amountFromPoints($row->candle_cash_spent),
                'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints($row->candle_cash_spent)),
                'status' => (string) ($row->status ?: 'issued'),
                'platform' => $row->platform ? (string) $row->platform : null,
                'redemption_code' => (string) $row->redemption_code,
                'issued_at' => optional($row->issued_at)->toIso8601String(),
                'redeemed_at' => optional($row->redeemed_at)->toIso8601String(),
                'expires_at' => optional($row->expires_at)->toIso8601String(),
                'reward' => [
                    'id' => (int) $row->reward_id,
                    'name' => $row->reward && $candleCashService->isStorefrontReward($row->reward)
                        ? 'Redeem ' . $candleCashService->fixedRedemptionFormatted() . ' Candle Cash'
                        : (string) ($row->reward?->name ?: 'Candle Cash'),
                    'reward_type' => (string) ($row->reward?->reward_type ?: ''),
                    'reward_value' => $row->reward?->reward_value ? (string) $row->reward->reward_value : null,
                ],
            ])->all(),
        ], $this->contractMeta($request), $states);
    }

    public function requestRedemption(
        Request $request,
        CandleCashService $candleCashService,
        CandleCashShopifyDiscountService $discountSyncService
    ): JsonResponse
    {
        $data = $request->validate([
            'reward_id' => ['required', 'integer', 'exists:candle_cash_rewards,id'],
            'marketing_profile_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'shopify_customer_id' => ['nullable', 'string', 'max:120'],
            'reuse_existing_code' => ['nullable', 'boolean'],
        ]);

        $storeContext = $this->resolveStoreContext($request, allowBody: true);
        $resolved = $this->resolveProfile($request, scope: 'redeem_request', allowCreate: false, allowBody: true);
        if (! $resolved['profile']) {
            $this->logStorefrontEvent($request, 'widget_redeem_request', [
                'status' => 'error',
                'issue_type' => 'identity_' . $resolved['status'],
                'source_type' => 'shopify_widget_redeem_request',
            ]);

            return $this->identityErrorResponse($resolved['status'], $request);
        }

        $profile = $resolved['profile'];
        $requestedReward = CandleCashReward::query()->findOrFail((int) $data['reward_id']);
        $reward = $candleCashService->storefrontReward();
        if (! $reward || (int) $requestedReward->id !== (int) $reward->id) {
            return MarketingStorefrontContract::error(
                code: 'reward_unavailable',
                message: 'This Candle Cash redemption is not available right now.',
                status: 422,
                details: [
                    'state' => 'reward_unavailable',
                ],
                states: ['reward_unavailable'],
                recoveryStates: $this->widgetService->recoveryStatesForError('reward_unavailable')
            );
        }

        $result = $candleCashService->requestStorefrontRedemption(
            profile: $profile,
            reward: $reward,
            platform: 'shopify',
            reuseActiveCode: (bool) ($data['reuse_existing_code'] ?? true)
        );
        $states = $this->widgetService->redemptionStates($result);
        $state = (string) ($result['state'] ?? ($states[0] ?? 'try_again_later'));
        $recoveryStates = $this->widgetService->recoveryStatesForError((string) ($result['error'] ?? ''));

        $redemption = null;
        if ((int) ($result['redemption_id'] ?? 0) > 0) {
            $redemption = CandleCashRedemption::query()->find((int) $result['redemption_id']);
        }

        if (! (bool) ($result['ok'] ?? false)) {
            $errorCode = (string) ($result['error'] ?? 'redemption_failed');
            $this->logStorefrontEvent($request, 'widget_redeem_request', [
                'status' => 'error',
                'issue_type' => $errorCode,
                'profile' => $profile,
                'candle_cash_redemption_id' => $redemption?->id,
                'source_type' => 'shopify_widget_redeem_request',
                'source_id' => (string) $reward->id,
                'meta' => [
                    'reward_id' => (int) $reward->id,
                    'state' => $state,
                    'balance' => round((float) ($result['balance'] ?? 0), 3),
                    'shopify_store_key' => $this->normalizeStoreKey($storeContext['store_key'] ?? null),
                ],
            ]);

            return MarketingStorefrontContract::error(
                code: $errorCode,
                message: 'Reward redemption request could not be completed.',
                status: 422,
                details: [
                    'balance' => $this->storefrontBalancePayload($candleCashService, $result['balance'] ?? 0),
                    'candle_cash_balance' => $candleCashService->amountFromPoints($result['balance'] ?? 0),
                    'candle_cash_balance_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints($result['balance'] ?? 0)),
                    'candle_cash_amount' => $candleCashService->amountFromPoints($result['balance'] ?? 0),
                    'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints($result['balance'] ?? 0)),
                    'state' => $state,
                    'redemption_rules' => $candleCashService->redemptionRulesPayload(),
                ],
                states: $states,
                recoveryStates: $recoveryStates
            );
        }

        $balancePayload = $this->storefrontBalancePayload($candleCashService, $result['balance'] ?? 0);
        $applyPath = null;
        $syncState = 'synced';

        if ($redemption) {
            $redemption = $this->syncRedemptionStoreContext($redemption, $storeContext);

            try {
                $this->ensureShopifyDiscountForCandleCashRedemption(
                    $discountSyncService,
                    $redemption,
                    $this->normalizeStoreKey($storeContext['store_key'] ?? null)
                );
                $applyPath = ($result['code'] ?? null)
                    ? $this->candleCashApplyPath((string) $result['code'])
                    : null;
            } catch (\Throwable $e) {
                $syncState = 'sync_failed';
                $restore = $candleCashService->cancelIssuedRedemptionAndRestoreBalance(
                    $redemption,
                    'Canceled automatically because Shopify could not prepare the Candle Cash discount yet.'
                );
                $restoredBalance = (int) ($restore['balance'] ?? $candleCashService->currentBalance($profile));
                $balancePayload = $this->storefrontBalancePayload($candleCashService, $restoredBalance);

                $this->logStorefrontEvent($request, 'widget_redeem_request', [
                    'status' => 'error',
                    'issue_type' => 'shopify_discount_sync_failed',
                    'profile' => $profile,
                    'candle_cash_redemption_id' => $redemption->id,
                    'source_type' => 'shopify_widget_redeem_request',
                    'source_id' => (string) $reward->id,
                    'meta' => [
                        'reward_id' => (int) $reward->id,
                        'state' => 'sync_failed',
                        'balance' => $restoredBalance,
                        'error' => $e->getMessage(),
                        'shopify_store_key' => $this->normalizeStoreKey($storeContext['store_key'] ?? null),
                    ],
                ]);

                return MarketingStorefrontContract::error(
                    code: 'discount_not_ready',
                    message: 'Candle Cash is not ready to apply automatically yet.',
                    status: 422,
                    details: [
                        'balance' => $balancePayload,
                        'candle_cash_balance' => data_get($balancePayload, 'candle_cash_amount'),
                        'candle_cash_balance_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
                        'candle_cash_amount' => data_get($balancePayload, 'candle_cash_amount'),
                        'candle_cash_amount_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
                        'state' => 'sync_failed',
                        'redemption_rules' => $candleCashService->redemptionRulesPayload(),
                    ],
                    states: ['sync_failed'],
                    recoveryStates: $this->widgetService->recoveryStatesForError('redemption_failed')
                );
            }
        }

        $this->logStorefrontEvent($request, 'widget_redeem_request', [
            'status' => 'ok',
            'profile' => $profile,
            'candle_cash_redemption_id' => $redemption?->id,
            'source_type' => 'shopify_widget_redeem_request',
            'source_id' => (string) $reward->id,
            'meta' => [
                'reward_id' => (int) $reward->id,
                'state' => $syncState === 'synced' ? $state : 'sync_failed',
                'balance' => round((float) ($result['balance'] ?? 0), 3),
                'shopify_store_key' => $this->normalizeStoreKey($storeContext['store_key'] ?? null),
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'reward_id' => (int) $reward->id,
            'redemption_id' => (int) ($result['redemption_id'] ?? 0),
            'redemption_code' => (string) ($result['code'] ?? ''),
            'apply_path' => $applyPath,
            'state' => $syncState === 'synced' ? $state : 'sync_failed',
            'status' => $redemption?->status ?: 'issued',
            'expires_at' => optional($redemption?->expires_at)->toIso8601String(),
            'candle_cash_balance' => data_get($balancePayload, 'candle_cash_amount'),
            'candle_cash_balance_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
            'candle_cash_amount' => data_get($balancePayload, 'candle_cash_amount'),
            'candle_cash_amount_formatted' => data_get($balancePayload, 'candle_cash_amount_formatted'),
            'balance' => $balancePayload,
            'reward' => $candleCashService->storefrontRewardPayload($reward, (int) ($result['balance'] ?? 0)),
            'redemption_rules' => $candleCashService->redemptionRulesPayload(),
            'discount_sync_status' => $syncState,
        ], $this->contractMeta($request), $states);
    }

    public function logRewardEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', 'string', 'in:reward_view,reward_activate_click,reward_activation_success,reward_activation_failure,reward_apply_click,reward_apply_success,reward_apply_failure,reward_confetti_shown'],
            'request_key' => ['nullable', 'string', 'max:120'],
            'marketing_profile_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'shopify_customer_id' => ['nullable', 'string', 'max:120'],
            'reward_code' => ['nullable', 'string', 'max:120'],
            'reward_kind' => ['nullable', 'string', 'max:80'],
            'surface' => ['nullable', 'string', 'max:80'],
            'state' => ['nullable', 'string', 'max:120'],
            'message' => ['nullable', 'string', 'max:255'],
            'meta' => ['nullable', 'array'],
        ]);

        $storeContext = $this->resolveStoreContext($request, allowBody: true);
        $resolved = $this->resolveProfile($request, scope: 'reward_event', allowCreate: false, allowBody: true);
        $profile = $resolved['profile'] ?? null;

        $event = $this->eventLogger->log((string) $data['event_type'], [
            'status' => str_contains((string) $data['event_type'], 'failure') ? 'error' : 'ok',
            'issue_type' => str_contains((string) $data['event_type'], 'failure')
                ? ((string) ($data['state'] ?? 'reward_interaction_failed') ?: 'reward_interaction_failed')
                : null,
            'source_surface' => trim((string) ($data['surface'] ?? 'shopify_rewards_surface')) ?: 'shopify_rewards_surface',
            'endpoint' => '/' . ltrim((string) $request->path(), '/'),
            'request_key' => trim((string) ($data['request_key'] ?? '')) ?: null,
            'dedupe_key' => trim((string) ($data['request_key'] ?? '')) ?: null,
            'profile' => $profile,
            'tenant_id' => $storeContext['tenant_id'] ?? null,
            'source_type' => 'shopify_widget_reward_surface',
            'source_id' => trim((string) ($data['reward_code'] ?? '')) ?: ($profile ? 'profile:' . $profile->id : null),
            'meta' => array_filter([
                'reward_code' => trim((string) ($data['reward_code'] ?? '')) ?: null,
                'reward_kind' => trim((string) ($data['reward_kind'] ?? '')) ?: null,
                'state' => trim((string) ($data['state'] ?? '')) ?: null,
                'message' => trim((string) ($data['message'] ?? '')) ?: null,
                'identity_status' => (string) ($resolved['status'] ?? 'missing_identity'),
                'shopify_store_key' => $this->normalizeStoreKey($storeContext['store_key'] ?? null),
                'extra' => (array) ($data['meta'] ?? []),
            ], static fn ($value): bool => $value !== null && $value !== []),
            'resolution_status' => str_contains((string) $data['event_type'], 'failure') ? 'open' : 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'event_id' => (int) $event->id,
            'profile_id' => $profile ? (int) $profile->id : null,
            'event_type' => (string) $event->event_type,
        ], $this->contractMeta($request), ['reward_event_logged']);
    }

    public function requestConsentOptin(
        Request $request,
        MarketingConsentCaptureService $consentCaptureService,
        MarketingConsentService $consentService,
        MarketingConsentIncentiveService $incentiveService,
        MarketingProfileSyncService $profileSyncService,
        CandleCashTaskService $taskService
    ): JsonResponse {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'consent_sms' => ['nullable', 'boolean'],
            'consent_email' => ['nullable', 'boolean'],
            'award_bonus' => ['nullable', 'boolean'],
            'shopify_customer_id' => ['nullable', 'string', 'max:120'],
            'flow' => ['nullable', 'in:direct,verification'],
        ]);

        $flow = (string) ($data['flow'] ?? 'direct');
        $shopifyCustomerId = trim((string) ($data['shopify_customer_id'] ?? ''));
        $storeContext = $this->resolveStoreContext($request, allowBody: true);
        $sourceId = $this->identityService->deterministicSourceId(
            prefix: 'shopify_widget_optin',
            email: (string) ($data['email'] ?? ''),
            phone: (string) ($data['phone'] ?? ''),
            extra: [
                $shopifyCustomerId,
                (string) ($storeContext['store_key'] ?? ''),
                (string) ($storeContext['tenant_id'] ?? ''),
            ]
        );

        $identityPayload = [
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
        ];
        $syncContext = [
            'source_type' => 'shopify_widget_contact',
            'source_id' => $sourceId,
            'source_label' => 'shopify_widget_optin',
            'source_channels' => ['shopify', 'online', 'shopify_widget'],
            'tenant_id' => $storeContext['tenant_id'],
            'source_meta' => [
                'shopify_customer_id' => $shopifyCustomerId !== '' ? $shopifyCustomerId : null,
                'shopify_store_key' => $storeContext['store_key'],
                'tenant_id' => $storeContext['tenant_id'],
            ],
            'flow' => $flow === 'verification' ? 'verification' : 'direct_confirmed',
            'allow_create' => true,
            'award_bonus' => (bool) ($data['award_bonus'] ?? false),
            'request_meta' => [
                'ip' => (string) $request->ip(),
            ],
        ];
        $consentSmsRequested = array_key_exists('consent_sms', $data);

        if ($consentSmsRequested) {
            $result = $consentCaptureService->requestSmsConfirmation($identityPayload, $syncContext);
        } else {
            $sync = $profileSyncService->syncExternalIdentity([
                'first_name' => trim((string) ($identityPayload['first_name'] ?? '')) ?: null,
                'last_name' => trim((string) ($identityPayload['last_name'] ?? '')) ?: null,
                'raw_email' => trim((string) ($identityPayload['email'] ?? '')) ?: null,
                'raw_phone' => trim((string) ($identityPayload['phone'] ?? '')) ?: null,
                'source_channels' => (array) ($syncContext['source_channels'] ?? ['shopify_widget']),
                'source_links' => [[
                    'source_type' => (string) $syncContext['source_type'],
                    'source_id' => (string) $syncContext['source_id'],
                    'source_meta' => (array) ($syncContext['source_meta'] ?? []),
                ]],
                'primary_source' => [
                    'source_type' => (string) $syncContext['source_type'],
                    'source_id' => (string) $syncContext['source_id'],
                ],
            ], [
                'review_context' => [
                    'source_label' => (string) ($syncContext['source_label'] ?? 'shopify_widget_contact'),
                    'source_id' => (string) $syncContext['source_id'],
                    'tenant_id' => $storeContext['tenant_id'],
                ],
                'allow_create' => true,
                'tenant_id' => $storeContext['tenant_id'],
            ]);

            $result = [
                'status' => (string) ($sync['status'] ?? 'review_required'),
                'profile' => (int) ($sync['profile_id'] ?? 0) > 0 ? MarketingProfile::query()->find((int) $sync['profile_id']) : null,
                'request' => null,
                'token' => null,
                'sync' => $sync,
            ];
        }

        if (! ($result['profile'] ?? null)) {
            $this->logStorefrontEvent($request, 'widget_consent_request', [
                'status' => 'verification_required',
                'issue_type' => 'identity_review_required',
                'source_type' => 'shopify_widget_optin',
            ]);

            return MarketingStorefrontContract::error(
                code: 'identity_review_required',
                message: 'Identity could not be safely auto-linked and was routed to review.',
                status: 422,
                states: ['needs_verification'],
                recoveryStates: ['verification_required', 'contact_support']
            );
        }

        /** @var MarketingProfile $profile */
        $profile = $result['profile'];
        $this->queueShopifyCustomerProvisioning(
            profile: $profile,
            storeKey: $storeContext['store_key'] ?? null,
            tenantId: $storeContext['tenant_id'] ?? $profile->tenant_id,
            trigger: 'shopify_widget_optin'
        );

        $consentSms = (bool) ($data['consent_sms'] ?? false);
        $consentEmail = (bool) ($data['consent_email'] ?? false);

        if ($consentEmail) {
            $consentService->setEmailConsent($profile, true, [
                'source_type' => 'shopify_widget_optin',
                'source_id' => $sourceId,
                'tenant_id' => $storeContext['tenant_id'] ?: $profile->tenant_id,
            ]);

            $taskService->awardSystemTask($profile, 'email-signup', [
                'source_type' => 'shopify_widget_optin',
                'source_id' => $sourceId . ':email',
                'metadata' => [
                    'surface' => 'shopify_widget',
                    'flow' => $flow,
                ],
            ]);
        }

        $state = (bool) $profile->accepts_sms_marketing ? 'confirmed' : 'not_consented';
        $bonusAwarded = 0;
        $verificationToken = null;

        if ($consentSmsRequested && $consentSms && $flow === 'verification') {
            $verificationToken = (string) ($result['token'] ?? '');
            $state = 'requested';
        } elseif ($consentSmsRequested && $consentSms) {
            $consentService->setSmsConsent($profile, true, [
                'source_type' => 'shopify_widget_optin',
                'source_id' => $sourceId,
                'tenant_id' => $storeContext['tenant_id'] ?: $profile->tenant_id,
                'details' => ['flow' => 'direct_confirmed'],
            ]);

            $bonus = $incentiveService->awardSmsConsentBonusOnce(
                profile: $profile,
                sourceId: $sourceId,
                description: 'Shopify widget SMS consent bonus'
            );
            if ($bonus['awarded']) {
                $bonusAwarded = (int) ($bonus['candle_cash'] ?? 0);
            }

            if ($result['request']) {
                $result['request']->forceFill([
                    'status' => 'confirmed',
                    'confirmed_at' => now(),
                    'reward_awarded_candle_cash' => $bonusAwarded > 0 ? $bonusAwarded : (int) ($result['request']->reward_awarded_candle_cash ?? 0),
                    'reward_awarded_at' => $bonusAwarded > 0 ? now() : $result['request']->reward_awarded_at,
                ])->save();
            }

            $state = 'confirmed';
        } elseif ($consentSmsRequested) {
            $consentService->setSmsConsent($profile, false, [
                'source_type' => 'shopify_widget_optin',
                'source_id' => $sourceId,
                'tenant_id' => $storeContext['tenant_id'] ?: $profile->tenant_id,
                'details' => ['flow' => 'explicit_revoke'],
            ]);

            if ($result['request']) {
                $result['request']->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => now(),
                ])->save();
            }

            $state = 'revoked';
        }

        /** @var MarketingConsentRequest|null $requestRow */
        $requestRow = $result['request'] ?? null;
        $states = $this->widgetService->consentWidgetStates(
            profile: $profile->fresh(),
            request: $requestRow?->fresh(),
            incentiveEnabled: (bool) ($data['award_bonus'] ?? false)
        );

        $this->logStorefrontEvent($request, 'widget_consent_request', [
            'status' => $state === 'requested' ? 'pending' : 'ok',
            'issue_type' => $state === 'requested' ? 'verification_required' : null,
            'profile' => $profile,
            'source_type' => 'shopify_widget_optin',
            'source_id' => $sourceId,
            'meta' => [
                'consent_state' => $state,
                'flow' => $flow,
                'bonus_awarded' => $bonusAwarded,
                'request_id' => $requestRow?->id,
            ],
            'resolution_status' => $state === 'requested' ? 'open' : 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'consent_state' => $state,
            'verification_required' => $state === 'requested',
            'verification_token' => $verificationToken,
            'accepts_sms_marketing' => (bool) $profile->fresh()->accepts_sms_marketing,
            'accepts_email_marketing' => (bool) $profile->fresh()->accepts_email_marketing,
            'bonus_awarded' => $bonusAwarded,
            'state' => $state === 'confirmed' ? 'sms_confirmed' : ($state === 'requested' ? 'sms_requested' : 'sms_not_consented'),
        ], $this->contractMeta($request), $states);
    }

    public function confirmConsentOptin(
        Request $request,
        MarketingConsentCaptureService $consentCaptureService
    ): JsonResponse {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:120'],
        ]);

        $result = $consentCaptureService->confirmSmsByToken((string) $data['token'], [
            'source_type' => 'shopify_widget_confirm',
            'bonus_description' => 'Shopify widget SMS consent confirmation bonus',
        ]);

        if (($result['status'] ?? '') === 'invalid' || ($result['status'] ?? '') === 'expired') {
            $errorCode = ($result['status'] ?? '') === 'expired'
                ? 'consent_token_expired'
                : 'consent_token_invalid';

            $this->logStorefrontEvent($request, 'widget_consent_confirm', [
                'status' => 'error',
                'issue_type' => $errorCode,
                'source_type' => 'shopify_widget_confirm',
                'meta' => [
                    'token_tail' => substr((string) $data['token'], -8),
                ],
            ]);

            return MarketingStorefrontContract::error(
                code: $errorCode,
                message: 'Consent confirmation could not be completed.',
                status: 422,
                details: ['status' => (string) ($result['status'] ?? '')],
                states: ['verification_required'],
                recoveryStates: ['try_again_later', 'contact_support']
            );
        }

        /** @var MarketingProfile|null $profile */
        $profile = $result['profile'] ?? null;
        /** @var MarketingConsentRequest|null $requestRow */
        $requestRow = $result['request'] ?? null;
        $states = $this->widgetService->consentWidgetStates(
            profile: $profile,
            request: $requestRow,
            incentiveEnabled: true
        );

        $this->logStorefrontEvent($request, 'widget_consent_confirm', [
            'status' => 'ok',
            'profile' => $profile,
            'source_type' => 'shopify_widget_confirm',
            'source_id' => $requestRow?->source_id,
            'meta' => [
                'request_id' => $requestRow?->id,
                'bonus_awarded' => (int) ($result['bonus_awarded'] ?? 0),
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => $profile ? (int) $profile->id : null,
            'consent_state' => (string) ($result['status'] ?? 'confirmed'),
            'bonus_awarded' => (int) ($result['bonus_awarded'] ?? 0),
            'state' => 'sms_confirmed',
        ], $this->contractMeta($request), $states);
    }

    public function consentStatus(Request $request): JsonResponse
    {
        $storeContext = $this->resolveStoreContext($request);
        if (! $this->hasStoreContext($storeContext)) {
            return $this->missingStoreContextResponse('consent_status');
        }

        $resolved = $this->resolveProfile($request, scope: 'consent_status', allowCreate: false);
        if (! $resolved['profile']) {
            $identityStates = $this->widgetService->customerStatusStates(null, (string) $resolved['status']);
            $states = collect(array_merge($identityStates, ['sms_not_consented', 'email_not_consented']))
                ->map(fn ($value): string => trim(strtolower((string) $value)))
                ->filter(fn (string $value): bool => $value !== '')
                ->unique()
                ->values()
                ->all();

            $this->logStorefrontEvent($request, 'widget_consent_status_lookup', [
                'status' => 'pending',
                'issue_type' => (string) $resolved['status'],
                'source_type' => 'shopify_widget_consent_status',
                'meta' => [
                    'resolved' => false,
                ],
                'resolution_status' => 'open',
            ]);

            return MarketingStorefrontContract::success([
                'profile_id' => null,
                'state' => in_array('needs_verification', $states, true) ? 'needs_verification' : 'unknown_customer',
                'consent_state' => 'not_consented',
                'verification_required' => in_array('needs_verification', $states, true),
                'consent' => [
                    'sms' => false,
                    'email' => false,
                ],
                'incentive' => [
                    'available' => false,
                    'already_awarded' => false,
                ],
            ], $this->contractMeta($request), $states);
        }

        $profile = $resolved['profile'];
        $requestRow = MarketingConsentRequest::query()
            ->where('marketing_profile_id', $profile->id)
            ->orderByDesc('id')
            ->first();

        $states = $this->widgetService->consentWidgetStates(
            profile: $profile,
            request: $requestRow,
            incentiveEnabled: true
        );
        $bonusAlreadyAwarded = CandleCashTransaction::query()
            ->where('marketing_profile_id', $profile->id)
            ->where('source', 'consent')
            ->where('candle_cash_delta', '>', 0)
            ->exists();

        $state = in_array('sms_confirmed', $states, true)
            ? 'sms_confirmed'
            : (in_array('sms_requested', $states, true) ? 'sms_requested' : 'sms_not_consented');

        $this->logStorefrontEvent($request, 'widget_consent_status_lookup', [
            'status' => 'ok',
            'profile' => $profile,
            'source_type' => 'shopify_widget_consent_status',
            'source_id' => 'profile:' . $profile->id,
            'meta' => [
                'state' => $state,
                'request_id' => $requestRow?->id,
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'state' => $state,
            'consent_state' => $state === 'sms_confirmed'
                ? 'confirmed'
                : ($state === 'sms_requested' ? 'requested' : 'not_consented'),
            'verification_required' => $state === 'sms_requested',
            'consent' => [
                'sms' => (bool) $profile->accepts_sms_marketing,
                'email' => (bool) $profile->accepts_email_marketing,
            ],
            'incentive' => [
                'available' => in_array('incentive_available', $states, true),
                'already_awarded' => $bonusAlreadyAwarded || in_array('incentive_already_awarded', $states, true),
            ],
            'latest_request' => [
                'id' => $requestRow ? (int) $requestRow->id : null,
                'status' => $requestRow?->status ? (string) $requestRow->status : null,
                'requested_at' => optional($requestRow?->created_at)->toIso8601String(),
                'confirmed_at' => optional($requestRow?->confirmed_at)->toIso8601String(),
            ],
        ], $this->contractMeta($request), $states);
    }

    public function birthdayStatus(Request $request, BirthdayRewardEngineService $rewardEngine): JsonResponse
    {
        $storeContext = $this->resolveStoreContext($request);
        if (! $this->hasStoreContext($storeContext)) {
            return $this->missingStoreContextResponse('birthday_status');
        }

        $resolved = $this->resolveProfile($request, scope: 'birthday_status', allowCreate: false);
        if (! $resolved['profile']) {
            $states = ['unknown_customer', 'add_birthday_unlock_reward'];
            $this->logStorefrontEvent($request, 'widget_birthday_status_lookup', [
                'status' => 'pending',
                'issue_type' => (string) $resolved['status'],
                'source_type' => 'shopify_widget_birthday_status',
                'meta' => ['resolved' => false],
                'resolution_status' => 'open',
            ]);

            return MarketingStorefrontContract::success([
                'profile_id' => null,
                'state' => 'unknown_customer',
                'birthday' => null,
                'reward' => [
                    'state' => 'add_birthday_unlock_reward',
                    'issuance' => null,
                ],
            ], $this->contractMeta($request), $states);
        }

        /** @var MarketingProfile $profile */
        $profile = $resolved['profile'];
        $birthdayProfile = $profile->birthdayProfile;

        $status = $rewardEngine->statusForProfile($birthdayProfile);
        if (($status['state'] ?? '') === 'birthday_reward_eligible' && $birthdayProfile instanceof CustomerBirthdayProfile) {
            $rewardEngine->issueAnnualReward($birthdayProfile);
            $birthdayProfile = $birthdayProfile->fresh();
            $status = $rewardEngine->statusForProfile($birthdayProfile);
        }

        $stateList = array_values(array_unique(array_filter([
            'linked_customer',
            (string) ($status['state'] ?? 'birthday_saved'),
            $birthdayProfile ? 'birthday_saved' : 'add_birthday_unlock_reward',
        ])));

        $this->logStorefrontEvent($request, 'widget_birthday_status_lookup', [
            'status' => 'ok',
            'profile' => $profile,
            'source_type' => 'shopify_widget_birthday_status',
            'source_id' => 'profile:'.$profile->id,
            'meta' => [
                'birthday_present' => $birthdayProfile !== null && $birthdayProfile->birth_month && $birthdayProfile->birth_day,
                'reward_state' => (string) ($status['state'] ?? 'birthday_saved'),
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'state' => (string) ($status['state'] ?? 'birthday_saved'),
            'birthday' => $birthdayProfile ? $this->birthdayPayload($birthdayProfile) : null,
            'reward' => [
                'state' => (string) ($status['state'] ?? 'birthday_saved'),
                'issuance' => $this->birthdayIssuancePayload($status['issuance'] ?? null),
            ],
        ], $this->contractMeta($request), $stateList);
    }

    public function captureBirthday(
        Request $request,
        BirthdayProfileService $birthdayProfileService,
        BirthdayRewardEngineService $rewardEngine,
        ShopifyBirthdayMetafieldService $shopifyBirthdayMetafieldService,
        CandleCashTaskService $taskService
    ): JsonResponse {
        $data = $request->validate([
            'marketing_profile_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'shopify_customer_id' => ['nullable', 'string', 'max:120'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'birth_month' => ['nullable', 'integer', 'between:1,12'],
            'birth_day' => ['nullable', 'integer', 'between:1,31'],
            'birth_year' => ['nullable', 'integer', 'between:1900,2100'],
            'birthday_full_date' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'max:120'],
        ]);

        $resolved = $this->resolveProfile($request, scope: 'birthday_capture', allowCreate: true, allowBody: true);
        if (! $resolved['profile']) {
            $this->logStorefrontEvent($request, 'widget_birthday_capture', [
                'status' => 'error',
                'issue_type' => 'identity_'.$resolved['status'],
                'source_type' => 'shopify_widget_birthday_capture',
            ]);

            return $this->identityErrorResponse($resolved['status'], $request);
        }

        /** @var MarketingProfile $profile */
        $profile = $resolved['profile'];

        try {
            $birthdayProfile = $birthdayProfileService->captureForProfile(
                profile: $profile,
                payload: [
                    'birth_month' => $data['birth_month'] ?? null,
                    'birth_day' => $data['birth_day'] ?? null,
                    'birth_year' => $data['birth_year'] ?? null,
                    'birthday_full_date' => $data['birthday_full_date'] ?? null,
                    'source' => (string) ($data['source'] ?? 'shopify_widget'),
                ],
                options: [
                    'source' => (string) ($data['source'] ?? 'shopify_widget'),
                    'replace_source' => true,
                ]
            );
        } catch (\Throwable $e) {
            return MarketingStorefrontContract::error(
                code: 'invalid_birthday_payload',
                message: $e->getMessage(),
                status: 422,
                details: ['error' => $e->getMessage()],
                states: ['invalid_birthday_payload'],
                recoveryStates: ['try_again_later']
            );
        }

        $syncResult = $shopifyBirthdayMetafieldService->writeBirthdayForProfile($profile, $birthdayProfile);
        $birthdaySignupFrequency = (string) data_get($taskService->programConfig(), 'birthday_reward_frequency', 'once_per_year');
        $birthdayTaskSourceId = $birthdaySignupFrequency === 'once_per_lifetime'
            ? 'birthday-signup:profile:' . $profile->id
            : 'birthday-signup:profile:' . $profile->id . ':year:' . now()->year;
        $taskService->awardSystemTask($profile, 'birthday-signup', [
            'source_type' => 'birthday_capture',
            'source_id' => $birthdayTaskSourceId,
            'metadata' => [
                'birth_month' => (int) $birthdayProfile->birth_month,
                'birth_day' => (int) $birthdayProfile->birth_day,
            ],
        ]);
        $status = $rewardEngine->statusForProfile($birthdayProfile);
        if (($status['state'] ?? '') === 'birthday_reward_eligible') {
            $rewardEngine->issueAnnualReward($birthdayProfile);
            $birthdayProfile = $birthdayProfile->fresh();
            $status = $rewardEngine->statusForProfile($birthdayProfile);
        }

        $states = array_values(array_unique(array_filter([
            'birthday_saved',
            (string) ($status['state'] ?? 'birthday_saved'),
            (($syncResult['errors'] ?? []) !== []) ? 'shopify_sync_failed' : 'shopify_sync_ok',
        ])));

        $this->logStorefrontEvent($request, 'widget_birthday_capture', [
            'status' => (($syncResult['errors'] ?? []) === []) ? 'ok' : 'error',
            'issue_type' => (($syncResult['errors'] ?? []) === []) ? null : 'shopify_sync_failed',
            'profile' => $profile,
            'source_type' => 'shopify_widget_birthday_capture',
            'source_id' => 'profile:'.$profile->id,
            'meta' => [
                'reward_state' => (string) ($status['state'] ?? 'birthday_saved'),
                'write_back_updates' => (int) ($syncResult['updated'] ?? 0),
                'write_back_errors' => (array) ($syncResult['errors'] ?? []),
            ],
            'resolution_status' => (($syncResult['errors'] ?? []) === []) ? 'resolved' : 'open',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'state' => (string) ($status['state'] ?? 'birthday_saved'),
            'birthday' => $this->birthdayPayload($birthdayProfile),
            'reward' => [
                'state' => (string) ($status['state'] ?? 'birthday_saved'),
                'issuance' => $this->birthdayIssuancePayload($status['issuance'] ?? null),
            ],
            'shopify_sync' => [
                'updated' => (int) ($syncResult['updated'] ?? 0),
                'stores' => (array) ($syncResult['stores'] ?? []),
                'errors' => (array) ($syncResult['errors'] ?? []),
            ],
        ], $this->contractMeta($request), $states);
    }

    public function claimBirthdayReward(
        Request $request,
        BirthdayRewardEngineService $rewardEngine,
        BirthdayRewardActivationService $activationService
    ): JsonResponse
    {
        $resolved = $this->resolveProfile($request, scope: 'birthday_claim', allowCreate: false, allowBody: true);
        if (! $resolved['profile']) {
            return $this->identityErrorResponse($resolved['status'], $request);
        }

        /** @var MarketingProfile $profile */
        $profile = $resolved['profile'];
        $birthdayProfile = $profile->birthdayProfile;
        if (! $birthdayProfile) {
            return MarketingStorefrontContract::error(
                code: 'missing_birthday',
                message: 'Birthday is required before claiming a birthday reward.',
                status: 422,
                details: ['profile_id' => (int) $profile->id],
                states: ['add_birthday_unlock_reward'],
                recoveryStates: ['try_again_later']
            );
        }

        $status = $rewardEngine->statusForProfile($birthdayProfile);
        if (($status['state'] ?? '') === 'birthday_reward_eligible') {
            $rewardEngine->issueAnnualReward($birthdayProfile);
            $birthdayProfile = $birthdayProfile->fresh();
            $status = $rewardEngine->statusForProfile($birthdayProfile);
        }

        $issuance = $status['issuance'] ?? null;
        if (! $issuance) {
            return MarketingStorefrontContract::error(
                code: 'birthday_reward_not_ready',
                message: 'Birthday reward claim could not be completed.',
                status: 422,
                details: [
                    'state' => (string) ($status['state'] ?? 'birthday_reward_not_ready'),
                ],
                states: [(string) ($status['state'] ?? 'birthday_reward_not_ready')],
                recoveryStates: ['try_again_later']
            );
        }

        $result = $activationService->activate($issuance, [
            'source_surface' => 'shopify_widget',
            'endpoint' => '/shopify/marketing/birthday/claim',
            'store_key' => $this->preferredBirthdayStoreKey($profile),
        ]);
        if (! (bool) ($result['ok'] ?? false)) {
            return MarketingStorefrontContract::error(
                code: (string) ($result['error'] ?? 'birthday_reward_claim_failed'),
                message: 'Birthday reward claim could not be completed.',
                status: 422,
                details: [
                    'state' => (string) ($result['state'] ?? 'birthday_reward_not_ready'),
                ],
                states: [(string) ($result['state'] ?? 'birthday_reward_not_ready')],
                recoveryStates: ['try_again_later']
            );
        }

        $issuancePayload = $this->birthdayIssuancePayload($result['issuance'] ?? null);
        $states = ['already_claimed'];

        $this->logStorefrontEvent($request, 'widget_birthday_claim', [
            'status' => 'ok',
            'profile' => $profile,
            'source_type' => 'shopify_widget_birthday_claim',
            'source_id' => 'profile:'.$profile->id,
            'meta' => [
                'issuance' => $issuancePayload,
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'state' => 'already_claimed',
            'reward' => [
                'state' => 'already_claimed',
                'issuance' => $issuancePayload,
            ],
        ], $this->contractMeta($request), $states);
    }

    public function candleCashStatus(
        Request $request,
        CandleCashService $candleCashService,
        CandleCashTaskService $taskService,
        CandleCashReferralService $referralService,
        BirthdayRewardEngineService $birthdayRewardEngine,
        CandleCashShopifyDiscountService $discountSyncService
    ): JsonResponse {
        $storeContext = $this->resolveStoreContext($request);
        if (! $this->hasStoreContext($storeContext)) {
            return $this->missingStoreContextResponse('candle_cash_status');
        }

        $resolved = $this->resolveProfile($request, scope: 'candle_cash_status', allowCreate: false);
        $profile = $resolved['profile'] ?? null;
        $referralCode = Str::upper(trim((string) $request->query('ref', '')));

        if ($profile && $referralCode !== '') {
            $referralService->captureReferral($profile, $referralCode, [
                'email' => $profile->email,
                'phone' => $profile->phone,
            ], [
                'metadata' => [
                    'surface' => 'candle_cash_central',
                    'source' => 'shopify_widget',
                ],
            ]);
        }

        $taskRows = $taskService->storefrontTasks($profile);
        $taskHistory = $profile
            ? $profile->candleCashTaskCompletions()->with('task:id,handle,title')->latest('id')->limit(20)->get()
            : collect();
        $summary = $profile ? $taskService->customerSummary($profile) : [
            'current_balance' => 0,
            'current_balance_amount' => 0,
            'lifetime_earned' => 0,
            'lifetime_earned_amount' => 0,
            'lifetime_redeemed' => 0,
            'lifetime_redeemed_amount' => 0,
            'pending_rewards' => 0,
            'referral_count' => 0,
            'completed_tasks' => 0,
        ];
        $birthdayStatus = $profile
            ? $birthdayRewardEngine->statusForProfile($profile->birthdayProfile)
            : ['state' => 'add_birthday_unlock_reward', 'issuance' => null];
        $recentTransactions = $profile
            ? $profile->candleCashTransactions()->latest('id')->limit(20)->get(['id', 'type', 'candle_cash_delta', 'source', 'source_id', 'description', 'created_at'])
            : collect();
        $activeCodes = $profile
            ? $profile->candleCashRedemptions()
                ->with('reward:id,name,reward_type,reward_value')
                ->where('status', 'issued')
                ->where(function ($query): void {
                    $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
                })
                ->latest('id')
                ->limit(12)
                ->get()
                ->filter(function (CandleCashRedemption $row) use ($candleCashService): bool {
                    return $candleCashService->storefrontRedemptionMatchesCurrentRules($row, $row->reward);
                })
                ->values()
            : collect();
        $referrals = $profile
            ? $profile->candleCashReferralsMade()->with('referredProfile:id,first_name,last_name,email')->latest('id')->limit(12)->get()
            : collect();
        $referralConfig = $taskService->referralConfig();
        $frontendConfig = $taskService->frontendConfig();
        $integrationConfig = $taskService->integrationConfig();
        $googleReviewUrl = trim((string) data_get($integrationConfig, 'google_review_url', '')) ?: null;
        $voteLockedJoinUrl = trim((string) data_get($integrationConfig, 'vote_locked_join_url', '')) ?: null;
        $rewardCodeRows = [];
        $restoredFailedSyncRedemption = false;

        foreach ($activeCodes as $row) {
            $platform = trim((string) ($row->platform ?? ''));
            $isShopify = $platform === '' || $platform === 'shopify';
            $discountSyncStatus = 'not_required';
            $applyPath = null;

            if ($isShopify) {
                try {
                    $this->ensureShopifyDiscountForCandleCashRedemption(
                        $discountSyncService,
                        $row,
                        $this->normalizeStoreKey($storeContext['store_key'] ?? null)
                    );
                    $discountSyncStatus = 'synced';
                    $applyPath = $this->candleCashApplyPath((string) $row->redemption_code);
                } catch (\Throwable $e) {
                    $discountSyncStatus = 'sync_failed';
                    $candleCashService->cancelIssuedRedemptionAndRestoreBalance(
                        $row,
                        'Canceled automatically because Shopify could not prepare the Candle Cash discount yet.'
                    );
                    $restoredFailedSyncRedemption = true;
                    continue;
                }
            }

            $rewardCodeRows[] = [
                'id' => (int) $row->id,
                'status' => (string) ($row->status ?: 'issued'),
                'platform' => $platform !== '' ? $platform : null,
                'redemption_code' => (string) $row->redemption_code,
                'issued_at' => optional($row->issued_at)->toIso8601String(),
                'expires_at' => optional($row->expires_at)->toIso8601String(),
                'is_usable' => (string) ($row->status ?: '') === 'issued'
                    && (! $row->expires_at || $row->expires_at->isFuture())
                    && $discountSyncStatus !== 'sync_failed',
                'apply_path' => $applyPath,
                'discount_sync_status' => $discountSyncStatus,
                'candle_cash_amount' => $candleCashService->redemptionAmountForIssuedCode($row, $row->reward),
                'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->redemptionAmountForIssuedCode($row, $row->reward)),
                'reward' => [
                    'id' => (int) $row->reward_id,
                    'name' => $row->reward && $candleCashService->isStorefrontReward($row->reward)
                        ? 'Redeem ' . $candleCashService->fixedRedemptionFormatted() . ' Candle Cash'
                        : (string) ($row->reward?->name ?: 'Candle Cash'),
                    'reward_type' => (string) ($row->reward?->reward_type ?: ''),
                    'reward_value' => $row->reward?->reward_value !== null ? (string) $row->reward->reward_value : null,
                ],
            ];
        }

        if ($restoredFailedSyncRedemption && $profile) {
            $summary = array_merge($summary, [
                'current_balance' => $candleCashService->currentBalance($profile),
                'current_balance_amount' => $candleCashService->amountFromPoints($candleCashService->currentBalance($profile)),
            ]);
        }

        $summary = $this->normalizeCandleCashSummary($summary);

        $currentBalancePoints = (int) ($summary['current_balance'] ?? 0);
        $storefrontRewardRows = $this->storefrontRewardRows($candleCashService, $profile ? $currentBalancePoints : null);
        $balancePayload = $this->storefrontBalancePayload($candleCashService, $currentBalancePoints, [
            'expires_at' => null,
        ]);
        $states = $profile ? ['linked_customer'] : ['unknown_customer', 'verification_required'];
        if ($profile && $currentBalancePoints > 0) {
            $states[] = 'known_customer_has_balance';
        }
        if ($taskRows->contains(fn (array $row): bool => data_get($row, 'eligibility.state') === 'pending')) {
            $states[] = 'task_pending_review';
        }
        if ($birthdayStatus['state'] ?? null) {
            $states[] = (string) $birthdayStatus['state'];
        }
        $states = array_values(array_unique(array_filter($states)));

        $this->logStorefrontEvent($request, 'widget_candle_cash_status_lookup', [
            'status' => $profile ? 'ok' : 'pending',
            'issue_type' => $profile ? null : (string) ($resolved['status'] ?? 'missing_identity'),
            'profile' => $profile,
            'source_type' => 'shopify_widget_candle_cash_status',
            'source_id' => $profile ? ('profile:' . $profile->id) : null,
            'meta' => [
                'task_count' => $taskRows->count(),
                'pending_rewards' => (int) ($summary['pending_rewards'] ?? 0),
                'captured_referral' => $referralCode !== '',
            ],
            'resolution_status' => $profile ? 'resolved' : 'open',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => $profile ? (int) $profile->id : null,
            'state' => $states[0] ?? 'unknown_customer',
            'copy' => [
                'title' => (string) data_get($frontendConfig, 'central_title', 'Candle Cash Central'),
                'subtitle' => (string) data_get($frontendConfig, 'central_subtitle', 'Earn rewards through verified actions like signups, reviews, referrals, and member-only perks.'),
                'faq_approval_copy' => (string) data_get($frontendConfig, 'faq_approval_copy', ''),
                'faq_stack_copy' => (string) data_get($frontendConfig, 'faq_stack_copy', ''),
                'faq_pending_copy' => (string) data_get($frontendConfig, 'faq_pending_copy', ''),
                'faq_verification_copy' => (string) data_get($frontendConfig, 'faq_verification_copy', ''),
            ],
            'summary' => $summary,
            'redemption_rules' => $candleCashService->redemptionRulesPayload(),
            'balance' => $balancePayload,
            'storefront_reward' => $storefrontRewardRows[0] ?? null,
            'available_rewards' => $storefrontRewardRows,
            'consent' => [
                'sms' => (bool) ($profile?->accepts_sms_marketing ?? false),
                'email' => (bool) ($profile?->accepts_email_marketing ?? false),
            ],
            'reward_codes' => $rewardCodeRows,
            'referral' => [
                'enabled' => $referralService->isEnabled(),
                'code' => $profile ? $referralService->referralCodeForProfile($profile) : null,
                'link' => $profile ? $referralService->referralLinkForProfile($profile) : null,
                'headline' => (string) data_get($referralConfig, 'program_headline', 'Share Candle Cash with a friend'),
                'copy' => (string) data_get($referralConfig, 'program_copy', 'Share your link and earn Candle Cash when a friend places a qualifying first order.'),
                'referrer_reward_amount' => (float) data_get($referralConfig, 'referrer_reward_amount', 10),
                'referred_reward_amount' => (float) data_get($referralConfig, 'referred_reward_amount', 5),
                'count' => $profile ? (int) $profile->candleCashReferralsMade()->count() : 0,
                'rows' => $referrals->map(function ($referral): array {
                    $referred = $referral->referredProfile;

                    return [
                        'id' => (int) $referral->id,
                        'status' => (string) $referral->status,
                        'referrer_reward_status' => (string) $referral->referrer_reward_status,
                        'referred_reward_status' => (string) $referral->referred_reward_status,
                        'qualifying_order_number' => $referral->qualifying_order_number ? (string) $referral->qualifying_order_number : null,
                        'qualifying_order_total' => $referral->qualifying_order_total !== null ? (string) $referral->qualifying_order_total : null,
                        'qualified_at' => optional($referral->qualified_at)->toIso8601String(),
                        'rewarded_at' => optional($referral->rewarded_at)->toIso8601String(),
                        'referred_customer' => $referred
                            ? [
                                'id' => (int) $referred->id,
                                'name' => trim(($referred->first_name ?? '') . ' ' . ($referred->last_name ?? '')) ?: ($referred->email ?: 'Friend'),
                            ]
                            : null,
                    ];
                })->all(),
            ],
            'tasks' => $taskRows->map(function (array $row) use ($googleReviewUrl, $voteLockedJoinUrl): array {
                $handle = (string) data_get($row, 'task.handle');
                $actionUrl = data_get($row, 'task.action_url');
                if (($actionUrl === null || trim((string) $actionUrl) === '') && $handle === 'google-review' && $googleReviewUrl) {
                    $actionUrl = $googleReviewUrl;
                }

                $eligibility = (array) data_get($row, 'eligibility', []);
                if ($handle === 'candle-club-vote' && $voteLockedJoinUrl) {
                    $eligibility['locked_cta_url'] = $voteLockedJoinUrl;
                }

                return [
                    'id' => (int) data_get($row, 'task.id'),
                    'handle' => $handle,
                    'title' => (string) data_get($row, 'task.title'),
                    'description' => (string) data_get($row, 'task.description'),
                    'reward_amount' => (string) data_get($row, 'task.reward_amount'),
                    'task_type' => (string) data_get($row, 'task.task_type'),
                    'verification_mode' => (string) data_get($row, 'task.verification_mode'),
                    'auto_award' => (bool) data_get($row, 'task.auto_award'),
                    'action_url' => $actionUrl,
                    'button_text' => (string) (data_get($row, 'task.button_text') ?: 'Complete task'),
                    'campaign_key' => data_get($row, 'task.campaign_key'),
                    'external_object_id' => data_get($row, 'task.external_object_id'),
                    'verification_window_hours' => data_get($row, 'task.verification_window_hours'),
                    'matching_rules' => data_get($row, 'task.matching_rules'),
                    'metadata' => data_get($row, 'task.metadata'),
                    'requires_manual_approval' => (bool) data_get($row, 'task.requires_manual_approval'),
                    'requires_customer_submission' => (bool) data_get($row, 'task.requires_customer_submission'),
                    'eligibility' => $eligibility,
                ];
            })->all(),
            'history' => [
                'tasks' => $taskHistory->map(function ($row): array {
                    return [
                        'id' => (int) $row->id,
                        'task_title' => (string) ($row->task?->title ?: 'Task'),
                        'task_handle' => (string) ($row->task?->handle ?: ''),
                        'status' => (string) $row->status,
                        'reward_amount' => $row->reward_amount !== null ? (string) $row->reward_amount : null,
                        'review_notes' => $row->review_notes ? (string) $row->review_notes : null,
                        'created_at' => optional($row->created_at)->toIso8601String(),
                        'awarded_at' => optional($row->awarded_at)->toIso8601String(),
                    ];
                })->all(),
                'ledger' => $recentTransactions->map(function ($row) use ($candleCashService): array {
                    return [
                        'id' => (int) $row->id,
                        'type' => (string) $row->type,
                        'amount' => $candleCashService->amountFromPoints($row->candle_cash_delta),
                        'candle_cash_amount' => $candleCashService->amountFromPoints($row->candle_cash_delta),
                        'candle_cash_amount_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints(abs((float) $row->candle_cash_delta))),
                        'signed_candle_cash_amount_formatted' => $candleCashService->candleCashAmountLabelFromPoints($row->candle_cash_delta, true),
                        'description' => $row->description ? (string) $row->description : null,
                        'created_at' => optional($row->created_at)->toIso8601String(),
                    ];
                })->all(),
            ],
            'birthday' => [
                'state' => (string) ($birthdayStatus['state'] ?? 'add_birthday_unlock_reward'),
                'issuance' => $this->birthdayIssuancePayload($birthdayStatus['issuance'] ?? null),
            ],
        ], $this->contractMeta($request), $states);
    }

    public function submitCandleCashTask(
        Request $request,
        CandleCashTaskService $taskService
    ): JsonResponse {
        $data = $request->validate([
            'task_handle' => ['required', 'string', 'max:120'],
            'proof_url' => ['nullable', 'url', 'max:500'],
            'proof_text' => ['nullable', 'string', 'max:2000'],
            'campaign_key' => ['nullable', 'string', 'max:160'],
            'request_key' => ['nullable', 'string', 'max:200'],
            'marketing_profile_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'shopify_customer_id' => ['nullable', 'string', 'max:120'],
        ]);

        $resolved = $this->resolveProfile($request, scope: 'candle_cash_task_submit', allowCreate: false, allowBody: true);
        if (! $resolved['profile']) {
            return $this->identityErrorResponse((string) $resolved['status'], $request);
        }

        /** @var MarketingProfile $profile */
        $profile = $resolved['profile'];
        $result = $taskService->submitCustomerTask(
            $profile,
            (string) $data['task_handle'],
            [
                'proof_url' => $data['proof_url'] ?? null,
                'proof_text' => $data['proof_text'] ?? null,
            ],
            [
                'source_type' => 'shopify_widget_task',
                'source_id' => trim((string) ($data['campaign_key'] ?? '')) !== ''
                    ? ((string) $data['task_handle'] . ':campaign:' . trim((string) $data['campaign_key']))
                    : (string) $data['task_handle'],
                'source_event_key' => trim((string) ($data['campaign_key'] ?? '')) !== ''
                    ? ((string) $data['task_handle'] . ':profile:' . $profile->id . ':campaign:' . trim((string) $data['campaign_key']))
                    : '',
                'request_key' => trim((string) ($data['request_key'] ?? '')),
                'metadata' => [
                    'surface' => 'candle_cash_central',
                    'campaign_key' => trim((string) ($data['campaign_key'] ?? '')) ?: null,
                ],
            ]
        );

        $completion = $result['completion'] ?? null;
        $ok = (bool) ($result['ok'] ?? false);
        $state = (string) ($result['state'] ?? ($ok ? 'awarded' : 'blocked'));

        $this->logStorefrontEvent($request, 'widget_candle_cash_task_submit', [
            'status' => $ok ? 'ok' : 'error',
            'issue_type' => $ok ? null : ((string) ($result['error'] ?? 'task_submit_failed')),
            'profile' => $profile,
            'source_type' => 'shopify_widget_candle_cash_task',
            'source_id' => (string) $data['task_handle'],
            'request_key' => trim((string) ($data['request_key'] ?? '')),
            'meta' => [
                'task_handle' => (string) $data['task_handle'],
                'state' => $state,
                'completion_id' => $completion?->id,
            ],
            'resolution_status' => $ok ? 'resolved' : 'open',
        ]);

        if (! $ok) {
            return MarketingStorefrontContract::error(
                code: (string) ($result['error'] ?? 'task_submit_failed'),
                message: 'That task could not be completed right now.',
                status: 422,
                details: ['state' => $state],
                states: [$state],
                recoveryStates: ['try_again_later']
            );
        }

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'task_handle' => (string) $data['task_handle'],
            'state' => $state,
            'completion' => $completion ? [
                'id' => (int) $completion->id,
                'status' => (string) $completion->status,
                'reward_amount' => $completion->reward_amount !== null ? (string) $completion->reward_amount : null,
                'awarded_at' => optional($completion->awarded_at)->toIso8601String(),
                'reviewed_at' => optional($completion->reviewed_at)->toIso8601String(),
            ] : null,
        ], $this->contractMeta($request), [$state]);
    }

    public function startGoogleBusinessReview(
        Request $request,
        GoogleBusinessProfileConnectionService $connectionService
    ): JsonResponse {
        $data = $request->validate([
            'request_key' => ['required', 'string', 'max:200'],
            'marketing_profile_id' => ['nullable', 'integer'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'shopify_customer_id' => ['nullable', 'string', 'max:120'],
        ]);

        $resolved = $this->resolveProfile($request, scope: 'google_business_review_start', allowCreate: false, allowBody: true);
        if (! $resolved['profile']) {
            return $this->identityErrorResponse((string) $resolved['status'], $request);
        }

        /** @var MarketingProfile $profile */
        $profile = $resolved['profile'];

        try {
            $result = $connectionService->startReview(
                $profile,
                trim((string) $data['request_key']),
                'candle_cash_central'
            );
        } catch (GoogleBusinessProfileException $exception) {
            $this->logStorefrontEvent($request, 'widget_google_business_review_start', [
                'status' => 'error',
                'issue_type' => $exception->errorCode,
                'profile' => $profile,
                'source_type' => 'shopify_widget_google_business_review',
                'source_id' => trim((string) $data['request_key']),
                'request_key' => trim((string) $data['request_key']),
                'meta' => [
                    'request_key' => trim((string) $data['request_key']),
                ],
                'resolution_status' => 'open',
            ]);

            return MarketingStorefrontContract::error(
                code: $exception->errorCode,
                message: $exception->getMessage(),
                status: 422,
                details: ['request_key' => trim((string) $data['request_key'])],
                states: ['google_review_not_ready'],
                recoveryStates: ['retry_after_fix']
            );
        }

        $this->logStorefrontEvent($request, 'widget_google_business_review_start', [
            'status' => 'ok',
            'profile' => $profile,
            'source_type' => 'shopify_widget_google_business_review',
            'source_id' => (string) ($result['location_id'] ?? ''),
            'request_key' => trim((string) $data['request_key']),
            'meta' => [
                'location_id' => $result['location_id'] ?? null,
                'location_title' => $result['location_title'] ?? null,
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'state' => 'google_review_started',
            'review_url' => (string) ($result['review_url'] ?? ''),
            'location_id' => $result['location_id'] ?? null,
            'location_title' => $result['location_title'] ?? null,
        ], $this->contractMeta($request), ['google_review_started']);
    }

    public function productReviewStatus(
        Request $request,
        ProductReviewService $productReviewService
    ): JsonResponse {
        $data = $request->validate([
            'product_id' => ['required', 'string', 'max:120'],
            'product_handle' => ['nullable', 'string', 'max:160'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'product_url' => ['nullable', 'string', 'max:500'],
        ]);

        $storeContext = $this->resolveStoreContext($request);
        if (! $this->hasStoreContext($storeContext)) {
            return $this->missingStoreContextResponse('product_review_status');
        }

        $resolved = $this->resolveProfile($request, scope: 'product_review_status', allowCreate: false);
        $profile = $resolved['profile'] ?? null;
        $product = $this->productReviewContext($data, $storeContext);
        $payload = $productReviewService->storefrontPayload($product, $profile);
        $states = ['product_reviews_ready', $profile ? 'linked_customer' : 'unknown_customer'];

        return MarketingStorefrontContract::success([
            'profile_id' => $profile?->id,
            ...$payload,
        ], $this->contractMeta($request), array_values(array_unique($states)));
    }

    public function submitProductReview(
        Request $request,
        ProductReviewService $productReviewService
    ): JsonResponse {
        $data = $request->validate([
            'product_id' => ['required', 'string', 'max:120'],
            'product_handle' => ['nullable', 'string', 'max:160'],
            'product_title' => ['nullable', 'string', 'max:255'],
            'product_url' => ['nullable', 'string', 'max:500'],
            'rating' => ['required', 'integer', 'min:1', 'max:5'],
            'title' => ['nullable', 'string', 'max:190'],
            'body' => ['required', 'string', 'max:4000'],
            'name' => ['nullable', 'string', 'max:160'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:40'],
            'request_key' => ['nullable', 'string', 'max:200'],
            'marketing_profile_id' => ['nullable', 'integer'],
            'shopify_customer_id' => ['nullable', 'string', 'max:120'],
        ]);

        $storeContext = $this->resolveStoreContext($request, allowBody: true);
        if (! $this->hasStoreContext($storeContext)) {
            return $this->missingStoreContextResponse('product_review_submit');
        }

        $resolved = $this->resolveProfile($request, scope: 'product_review_submit', allowCreate: false, allowBody: true);
        $profile = $resolved['profile'] ?? null;

        try {
            $result = $productReviewService->submitReview($profile, $this->productReviewContext($data, $storeContext), [
                'rating' => (int) $data['rating'],
                'title' => $data['title'] ?? null,
                'body' => (string) $data['body'],
                'name' => $data['name'] ?? null,
                'email' => $data['email'] ?? null,
                'request_key' => $data['request_key'] ?? null,
                'source_surface' => 'shopify_product_page',
            ]);
        } catch (\InvalidArgumentException $exception) {
            return MarketingStorefrontContract::error(
                code: 'missing_store_context',
                message: $exception->getMessage(),
                status: 422,
                details: [
                    'store_key' => $storeContext['store_key'],
                ],
                states: ['store_context_required'],
                recoveryStates: ['reload_storefront']
            );
        }

        if (! (bool) ($result['ok'] ?? false)) {
            return MarketingStorefrontContract::error(
                code: (string) ($result['error'] ?? 'product_review_submit_failed'),
                message: (string) ($result['message'] ?? 'That review could not be submitted right now.'),
                status: 422,
                details: [
                    'product_id' => (string) $data['product_id'],
                ],
                states: [(string) ($result['error'] ?? 'product_review_submit_failed')],
                recoveryStates: ['try_again_later']
            );
        }

        /** @var \App\Models\MarketingReviewHistory|null $review */
        $review = $result['review'] ?? null;

        return MarketingStorefrontContract::success([
            'profile_id' => $review?->marketing_profile_id ?: $profile?->id,
            'state' => (string) ($result['state'] ?? 'review_live'),
            'review' => $review ? [
                'id' => (int) $review->id,
                'status' => (string) $review->status,
                'rating' => (int) $review->rating,
                'title' => $review->title ? (string) $review->title : null,
                'body' => $review->body ? (string) $review->body : null,
                'reviewer_name' => $review->displayReviewerName(),
                'submitted_at' => optional($review->submitted_at ?: $review->created_at)->toIso8601String(),
                'approved_at' => optional($review->approved_at)->toIso8601String(),
                'is_verified_buyer' => (bool) $review->is_verified_buyer,
            ] : null,
            'award' => [
                'state' => data_get($result, 'award.state'),
                'completion_id' => data_get($result, 'award.completion.id'),
                'event_id' => data_get($result, 'award.event.id'),
            ],
        ], $this->contractMeta($request), [(string) ($result['state'] ?? 'review_live')]);
    }

    public function customerStatus(
        Request $request,
        CandleCashService $candleCashService,
        BirthdayRewardEngineService $birthdayRewardEngine
    ): JsonResponse
    {
        $storeContext = $this->resolveStoreContext($request);
        if (! $this->hasStoreContext($storeContext)) {
            return $this->missingStoreContextResponse('customer_status');
        }

        $resolved = $this->resolveProfile($request, scope: 'customer_status', allowCreate: false);
        if (! $resolved['profile']) {
            $states = $this->widgetService->customerStatusStates(null, (string) $resolved['status']);
            $states[] = 'add_birthday_unlock_reward';
            $states = array_values(array_unique(array_filter($states)));
            $this->logStorefrontEvent($request, 'widget_customer_status_lookup', [
                'status' => 'verification_required',
                'issue_type' => (string) $resolved['status'],
                'source_type' => 'shopify_widget_customer_status',
                'resolution_status' => 'open',
            ]);

        return MarketingStorefrontContract::success([
            'profile_id' => null,
            'state' => $states[0] ?? 'unknown_customer',
            'consent' => ['sms' => false, 'email' => false],
            'source_channels' => [],
            'candle_cash_balance' => 0,
            'candle_cash_balance_amount' => 0,
            'candle_cash_balance_formatted' => $candleCashService->formatCurrency(0),
            'redemption_rules' => $candleCashService->redemptionRulesPayload(),
            'groups' => [],
            'eligibility' => [
                'winback' => false,
                    'reward_nudge' => false,
                ],
                'birthday' => [
                    'state' => 'add_birthday_unlock_reward',
                    'birthday' => null,
                    'issuance' => null,
                ],
            ], $this->contractMeta($request), $states);
        }

        $profile = $resolved['profile'];
        $profile->load('groups:id,name,is_internal');
        $birthdayProfile = $profile->birthdayProfile;
        $birthdayStatus = $birthdayRewardEngine->statusForProfile($birthdayProfile);
        $balance = $candleCashService->currentBalance($profile);
        $minRewardCandleCash = $candleCashService->fixedRedemptionPoints();
        $states = $this->widgetService->customerStatusStates($profile, (string) $resolved['status'], [
            'candle_cash_balance' => $balance,
            'min_reward_candle_cash' => $minRewardCandleCash,
        ]);
        $states[] = (string) ($birthdayStatus['state'] ?? 'birthday_saved');
        $states = array_values(array_unique(array_filter($states)));

        $this->logStorefrontEvent($request, 'widget_customer_status_lookup', [
            'status' => 'ok',
            'profile' => $profile,
            'source_type' => 'shopify_widget_customer_status',
            'source_id' => 'profile:' . $profile->id,
            'meta' => [
                'states' => $states,
                'group_count' => $profile->groups->where('is_internal', false)->count(),
                'birthday_state' => (string) ($birthdayStatus['state'] ?? 'birthday_saved'),
            ],
            'resolution_status' => 'resolved',
        ]);

        return MarketingStorefrontContract::success([
            'profile_id' => (int) $profile->id,
            'state' => $states[0] ?? 'linked_customer',
            'consent' => [
                'sms' => (bool) $profile->accepts_sms_marketing,
                'email' => (bool) $profile->accepts_email_marketing,
            ],
            'source_channels' => array_values(array_filter((array) $profile->source_channels)),
            'candle_cash_balance' => $balance,
            'candle_cash_balance_amount' => $candleCashService->amountFromPoints($balance),
            'candle_cash_balance_formatted' => $candleCashService->formatCurrency($candleCashService->amountFromPoints($balance)),
            'redemption_rules' => $candleCashService->redemptionRulesPayload(),
            'groups' => $profile->groups->where('is_internal', false)->values()->map(fn ($group): array => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'is_internal' => (bool) $group->is_internal,
            ])->values()->all(),
            'eligibility' => [
                'winback' => in_array('eligible_for_winback', $states, true),
                'reward_nudge' => in_array('eligible_for_reward_nudge', $states, true),
            ],
            'birthday' => [
                'state' => (string) ($birthdayStatus['state'] ?? 'birthday_saved'),
                'birthday' => $birthdayProfile ? $this->birthdayPayload($birthdayProfile) : null,
                'issuance' => $this->birthdayIssuancePayload($birthdayStatus['issuance'] ?? null),
            ],
        ], $this->contractMeta($request), $states);
    }

    public function proxyHealth(Request $request): JsonResponse
    {
        $resolved = $this->resolveProfile($request, scope: 'proxy_health', allowCreate: false);
        $profile = $resolved['profile'] ?? null;

        $identityStates = $this->widgetService->customerStatusStates(
            $profile,
            (string) ($resolved['status'] ?? 'missing_identity')
        );
        $identityState = $identityStates[0] ?? ($profile ? 'linked_customer' : 'unknown_customer');

        return MarketingStorefrontContract::success([
            'transport' => 'ok',
            'state' => $identityState,
            'identity' => [
                'status' => (string) ($resolved['status'] ?? 'missing_identity'),
                'state' => $identityState,
                'profile_id' => $profile ? (int) $profile->id : null,
            ],
            'runtime' => [
                'app_proxy_enabled' => (bool) config('marketing.shopify.app_proxy_enabled', false),
                'has_signing_secret' => trim((string) config('marketing.shopify.signing_secret', '')) !== '',
                'has_app_proxy_secret' => trim((string) (config('marketing.shopify.app_proxy_secret')
                    ?: config('marketing.shopify.signing_secret')
                    ?: '')) !== '',
                'contract_version' => trim((string) config('marketing.shopify.contract_version', 'v1')) ?: 'v1',
            ],
            'timestamp' => now()->toIso8601String(),
        ], $this->contractMeta($request), $identityStates);
    }

    /**
     * @return array{status:string,profile:?MarketingProfile,sync:array<string,mixed>}
     */
    protected function resolveProfile(
        Request $request,
        string $scope,
        bool $allowCreate = false,
        bool $allowBody = false
    ): array {
        $storeContext = $this->resolveStoreContext($request, $allowBody);
        $profileId = (int) ($request->query('marketing_profile_id', 0) ?: ($allowBody ? $request->input('marketing_profile_id', 0) : 0));
        if ($profileId > 0) {
            $profileQuery = MarketingProfile::query();
            if ((int) ($storeContext['tenant_id'] ?? 0) > 0) {
                $profileQuery->forTenantId((int) $storeContext['tenant_id']);
            }

            $profile = $profileQuery->find($profileId);

            return [
                'status' => $profile ? 'resolved' : 'not_found',
                'profile' => $profile,
                'sync' => [],
            ];
        }

        $emailInput = $allowBody ? $request->input('email', $request->query('email', '')) : $request->query('email', '');
        $phoneInput = $allowBody ? $request->input('phone', $request->query('phone', '')) : $request->query('phone', '');
        $firstName = $allowBody ? $request->input('first_name', '') : '';
        $lastName = $allowBody ? $request->input('last_name', '') : '';
        $shopifyCustomerId = $this->resolveShopifyCustomerId($request, $allowBody);
        $emailInput = trim((string) $emailInput);
        $phoneInput = trim((string) $phoneInput);
        if ($emailInput === '' && $phoneInput === '' && $shopifyCustomerId === '') {
            return [
                'status' => 'missing_identity',
                'profile' => null,
                'sync' => [],
            ];
        }

        if ($emailInput === '' && $phoneInput === '' && $shopifyCustomerId !== '') {
            $externalProfile = $this->profileFromShopifyCustomerId(
                $shopifyCustomerId,
                $storeContext['store_key'] ?? null,
                is_numeric($storeContext['tenant_id'] ?? null) ? (int) $storeContext['tenant_id'] : null
            );
            if ($externalProfile) {
                return [
                    'status' => 'resolved',
                    'profile' => $externalProfile,
                    'sync' => [],
                ];
            }
        }

        $sourceType = 'shopify_widget_' . Str::slug($scope, '_');
        $sourceId = $this->identityService->deterministicSourceId(
            prefix: $sourceType,
            email: $emailInput,
            phone: $phoneInput,
            extra: [
                $shopifyCustomerId,
                (string) ($storeContext['store_key'] ?? ''),
                (string) ($storeContext['tenant_id'] ?? ''),
            ]
        );

        return $this->identityService->resolve([
            'email' => $emailInput,
            'phone' => $phoneInput,
            'first_name' => (string) $firstName,
            'last_name' => (string) $lastName,
        ], [
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'source_label' => 'shopify_widget_' . $scope,
            'source_channels' => ['shopify', 'online', 'shopify_widget'],
            'tenant_id' => $storeContext['tenant_id'],
            'source_meta' => [
                'shopify_customer_id' => $shopifyCustomerId !== '' ? $shopifyCustomerId : null,
                'shopify_store_key' => $storeContext['store_key'],
                'tenant_id' => $storeContext['tenant_id'],
                'endpoint' => $scope,
            ],
            'allow_create' => $allowCreate,
        ]);
    }

    /**
     * @param array<string,mixed> $data
     * @param array{store_key:?string,tenant_id:?int} $storeContext
     * @return array{product_id:string,product_handle:?string,product_title:?string,product_url:?string,store_key:string,tenant_id:?int}
     */
    protected function productReviewContext(array $data, array $storeContext): array
    {
        return [
            'product_id' => trim((string) ($data['product_id'] ?? '')),
            'product_handle' => $this->nullableString($data['product_handle'] ?? null),
            'product_title' => $this->nullableString($data['product_title'] ?? null),
            'product_url' => $this->nullableString($data['product_url'] ?? null),
            'store_key' => (string) ($storeContext['store_key'] ?? ''),
            'tenant_id' => is_numeric($storeContext['tenant_id'] ?? null)
                ? (int) $storeContext['tenant_id']
                : null,
        ];
    }

    /**
     * @return array{store_key:?string,tenant_id:?int}
     */
    protected function resolveStoreContext(Request $request, bool $allowBody = false): array
    {
        $storeKey = $this->normalizeStoreKey(
            $allowBody
                ? ($request->input('store_key') ?? $request->input('store') ?? $request->query('store_key') ?? $request->query('store'))
                : ($request->query('store_key') ?? $request->query('store'))
        );

        $resolvedStore = null;
        if ($storeKey !== null) {
            $resolvedStore = ShopifyStores::find($storeKey, true);
            $storeKey = $this->normalizeStoreKey($resolvedStore['key'] ?? $storeKey);
        }

        if (! $resolvedStore) {
            $shopDomain = $this->nullableString(
                $allowBody
                    ? ($request->input('shop') ?? $request->query('shop') ?? $request->header('X-Shopify-Shop-Domain'))
                    : ($request->query('shop') ?? $request->header('X-Shopify-Shop-Domain'))
            );
            if ($shopDomain !== null) {
                $resolvedStore = ShopifyStores::findByShopDomain($shopDomain);
                $storeKey = $this->normalizeStoreKey($resolvedStore['key'] ?? $storeKey);
            }
        }

        $tenantId = $resolvedStore
            ? $this->tenantResolver->resolveTenantIdForStoreContext($resolvedStore)
            : $this->tenantResolver->resolveTenantIdForStoreKey($storeKey);

        return [
            'store_key' => $storeKey,
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * @param array{store_key:?string,tenant_id:?int} $storeContext
     */
    protected function hasStoreContext(array $storeContext): bool
    {
        return $this->normalizeStoreKey($storeContext['store_key'] ?? null) !== null;
    }

    protected function missingStoreContextResponse(string $scope): JsonResponse
    {
        Log::warning('shopify storefront request missing store context', [
            'scope' => $scope,
            'route' => request()->route()?->getName(),
            'path' => request()->path(),
            'query' => request()->query(),
        ]);

        return MarketingStorefrontContract::error(
            code: 'missing_store_context',
            message: 'A verified Shopify store context is required for this request.',
            status: 422,
            details: ['scope' => $scope],
            states: ['store_context_required'],
            recoveryStates: ['reload_storefront']
        );
    }

    protected function normalizeStoreKey(mixed $value): ?string
    {
        $normalized = strtolower(trim((string) $value));

        return $normalized !== '' ? $normalized : null;
    }

    protected function queueShopifyCustomerProvisioning(
        MarketingProfile $profile,
        ?string $storeKey,
        mixed $tenantId,
        string $trigger
    ): void {
        $normalizedStoreKey = $this->normalizeStoreKey($storeKey);
        $resolvedTenantId = is_numeric($tenantId)
            ? (int) $tenantId
            : (is_numeric($profile->tenant_id) ? (int) $profile->tenant_id : 0);

        if ($normalizedStoreKey === null || $resolvedTenantId <= 0) {
            return;
        }

        try {
            ProvisionShopifyCustomerForMarketingProfile::dispatch(
                marketingProfileId: (int) $profile->id,
                storeKey: $normalizedStoreKey,
                tenantId: $resolvedTenantId,
                trigger: $trigger
            )->afterCommit();
        } catch (\Throwable $e) {
            Log::warning('shopify customer provisioning dispatch failed', [
                'marketing_profile_id' => (int) $profile->id,
                'store_key' => $normalizedStoreKey,
                'trigger' => $trigger,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveShopifyCustomerId(Request $request, bool $allowBody = false): string
    {
        $candidates = [
            $allowBody ? $request->input('shopify_customer_id', '') : '',
            $request->query('shopify_customer_id', ''),
            $request->query('logged_in_customer_id', ''),
            $request->query('customer_id', ''),
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeShopifyCustomerId($candidate);
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return '';
    }

    protected function normalizeShopifyCustomerId(mixed $value): string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return '';
        }

        if (preg_match('/\/Customer\/(\d+)$/', $raw, $matches)) {
            return (string) $matches[1];
        }

        return $raw;
    }

    protected function profileFromShopifyCustomerId(string $shopifyCustomerId, ?string $storeKey = null, ?int $tenantId = null): ?MarketingProfile
    {
        $externalQuery = CustomerExternalProfile::query()
            ->forTenantId($tenantId)
            ->where('provider', 'shopify')
            ->where('external_customer_id', $shopifyCustomerId)
            ->whereNotNull('marketing_profile_id');

        $normalizedStoreKey = $this->normalizeStoreKey($storeKey);
        if ($normalizedStoreKey !== null) {
            $externalQuery->where(function ($query) use ($normalizedStoreKey): void {
                $query->where('store_key', $normalizedStoreKey)
                    ->orWhereNull('store_key');
            });
        }

        $external = $externalQuery
            ->orderByDesc('synced_at')
            ->orderByDesc('id')
            ->first(['marketing_profile_id']);

        if (! $external || (int) $external->marketing_profile_id <= 0) {
            return null;
        }

        $profileQuery = MarketingProfile::query()->forTenantId($tenantId);

        return $profileQuery->find((int) $external->marketing_profile_id);
    }

    protected function identityErrorResponse(string $status, ?Request $request = null): JsonResponse
    {
        if ($status === 'review_required') {
            return MarketingStorefrontContract::error(
                code: 'identity_review_required',
                message: 'Identity is ambiguous and requires internal review.',
                status: 422,
                details: ['status' => $status],
                states: ['needs_verification'],
                recoveryStates: ['verification_required', 'contact_support']
            );
        }
        if ($status === 'missing_identity') {
            return MarketingStorefrontContract::error(
                code: 'missing_identity',
                message: 'Customer identity is required for this widget request.',
                status: 422,
                details: ['status' => $status],
                states: ['verification_required'],
                recoveryStates: ['verification_required']
            );
        }

        $states = $status === 'not_found' ? ['unknown_customer'] : ['verification_required'];
        $recovery = $status === 'not_found' ? ['verification_required'] : ['try_again_later'];

        if ($request) {
            $this->logStorefrontEvent($request, 'widget_identity_error', [
                'status' => 'error',
                'issue_type' => $status,
                'source_type' => 'shopify_widget_identity',
                'meta' => [
                    'status' => $status,
                ],
            ]);
        }

        return MarketingStorefrontContract::error(
            code: 'profile_not_found',
            message: 'No matching customer profile was found.',
            status: 404,
            details: ['status' => $status],
            states: $states,
            recoveryStates: $recovery
        );
    }

    /**
     * @return array<string,mixed>
     */
    protected function contractMeta(Request $request): array
    {
        $mode = (string) $request->attributes->get('marketing_storefront_auth_mode', 'unknown');

        return [
            'auth_mode' => $mode,
            'integration_mode' => $mode === 'app_proxy' ? 'shopify_app_proxy' : 'shopify_theme_signed',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function birthdayPayload(?CustomerBirthdayProfile $birthdayProfile): ?array
    {
        if (! $birthdayProfile) {
            return null;
        }

        return [
            'birth_month' => $birthdayProfile->birth_month !== null ? (int) $birthdayProfile->birth_month : null,
            'birth_day' => $birthdayProfile->birth_day !== null ? (int) $birthdayProfile->birth_day : null,
            'birth_year' => $birthdayProfile->birth_year !== null ? (int) $birthdayProfile->birth_year : null,
            'birthday_full_date' => optional($birthdayProfile->birthday_full_date)->toDateString(),
            'source' => $birthdayProfile->source ? (string) $birthdayProfile->source : null,
            'source_captured_at' => optional($birthdayProfile->source_captured_at)->toIso8601String(),
            'updated_at' => optional($birthdayProfile->updated_at)->toIso8601String(),
            'reward_last_issued_at' => optional($birthdayProfile->reward_last_issued_at)->toIso8601String(),
            'reward_last_issued_year' => $birthdayProfile->reward_last_issued_year !== null
                ? (int) $birthdayProfile->reward_last_issued_year
                : null,
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    protected function birthdayIssuancePayload(mixed $issuance): ?array
    {
        if (! is_object($issuance)) {
            return null;
        }

        $rewardCode = isset($issuance->reward_code) && $issuance->reward_code !== null
            ? trim((string) $issuance->reward_code)
            : '';

        return [
            'id' => isset($issuance->id) ? (int) $issuance->id : null,
            'cycle_year' => isset($issuance->cycle_year) ? (int) $issuance->cycle_year : null,
            'reward_type' => isset($issuance->reward_type) ? (string) $issuance->reward_type : null,
            'reward_name' => isset($issuance->reward_name) && $issuance->reward_name !== null
                ? (string) $issuance->reward_name
                : null,
            'reward_value' => isset($issuance->reward_value) && $issuance->reward_value !== null
                ? (string) $issuance->reward_value
                : null,
            'status' => isset($issuance->status) ? (string) $issuance->status : null,
            'candle_cash_awarded' => isset($issuance->candle_cash_awarded) && $issuance->candle_cash_awarded !== null
                ? (int) $issuance->candle_cash_awarded
                : null,
            'reward_code' => $rewardCode !== ''
                ? $rewardCode
                : null,
            'discount_title' => $rewardCode !== ''
                ? $this->birthdayDiscountTitle($issuance)
                : null,
            'apply_path' => $rewardCode !== ''
                ? $this->birthdayApplyPath($rewardCode)
                : null,
            'shopify_discount_id' => isset($issuance->shopify_discount_id) && $issuance->shopify_discount_id !== null
                ? (string) $issuance->shopify_discount_id
                : null,
            'shopify_discount_node_id' => isset($issuance->shopify_discount_node_id) && $issuance->shopify_discount_node_id !== null
                ? (string) $issuance->shopify_discount_node_id
                : null,
            'shopify_store_key' => isset($issuance->shopify_store_key) && $issuance->shopify_store_key !== null
                ? (string) $issuance->shopify_store_key
                : null,
            'discount_sync_status' => method_exists($issuance, 'resolvedDiscountSyncStatus')
                ? (string) $issuance->resolvedDiscountSyncStatus()
                : (isset($issuance->discount_sync_status) ? (string) $issuance->discount_sync_status : null),
            'discount_sync_error' => isset($issuance->discount_sync_error) && $issuance->discount_sync_error !== null
                ? (string) $issuance->discount_sync_error
                : null,
            'issued_at' => isset($issuance->issued_at) ? optional($issuance->issued_at)->toIso8601String() : null,
            'claimed_at' => isset($issuance->claimed_at) ? optional($issuance->claimed_at)->toIso8601String() : null,
            'activated_at' => method_exists($issuance, 'resolvedActivationAt')
                ? optional($issuance->resolvedActivationAt())->toIso8601String()
                : (isset($issuance->activated_at) ? optional($issuance->activated_at)->toIso8601String() : null),
            'expires_at' => isset($issuance->expires_at) ? optional($issuance->expires_at)->toIso8601String() : null,
            'redeemed_at' => isset($issuance->redeemed_at) ? optional($issuance->redeemed_at)->toIso8601String() : null,
            'claim_window_starts_at' => isset($issuance->claim_window_starts_at)
                ? optional($issuance->claim_window_starts_at)->toIso8601String()
                : null,
            'claim_window_ends_at' => isset($issuance->claim_window_ends_at)
                ? optional($issuance->claim_window_ends_at)->toIso8601String()
                : null,
            'order_number' => isset($issuance->order_number) && $issuance->order_number !== null
                ? (string) $issuance->order_number
                : null,
            'order_total' => isset($issuance->order_total) && $issuance->order_total !== null
                ? (string) $issuance->order_total
                : null,
            'attributed_revenue' => isset($issuance->attributed_revenue) && $issuance->attributed_revenue !== null
                ? (string) $issuance->attributed_revenue
                : null,
            'is_activated' => method_exists($issuance, 'isActivated') ? (bool) $issuance->isActivated() : in_array((string) ($issuance->status ?? ''), ['claimed', 'redeemed'], true),
            'is_redeemed' => method_exists($issuance, 'isRedeemed') ? (bool) $issuance->isRedeemed() : (string) ($issuance->status ?? '') === 'redeemed',
            'is_usable' => method_exists($issuance, 'isUsable') ? (bool) $issuance->isUsable() : false,
        ];
    }

    protected function birthdayDiscountTitle(mixed $issuance): string
    {
        $base = trim((string) (($issuance->reward_name ?? null) ?: 'Birthday Reward'));

        return sprintf('%s %s #%d', $base, (string) ($issuance->cycle_year ?? date('Y')), (int) ($issuance->id ?? 0));
    }

    protected function birthdayApplyPath(string $rewardCode): string
    {
        $redirect = '/cart?forestry_reward_code=' . rawurlencode($rewardCode) . '&forestry_reward_kind=birthday';

        return '/discount/' . rawurlencode($rewardCode) . '?redirect=' . rawurlencode($redirect);
    }

    protected function candleCashApplyPath(string $rewardCode): string
    {
        $redirect = '/cart?forestry_reward_code=' . rawurlencode($rewardCode) . '&forestry_reward_kind=candle_cash';

        return '/discount/' . rawurlencode($rewardCode) . '?redirect=' . rawurlencode($redirect);
    }

    /**
     * @param array{store_key:?string,tenant_id:?int} $storeContext
     */
    protected function syncRedemptionStoreContext(CandleCashRedemption $redemption, array $storeContext): CandleCashRedemption
    {
        $storeKey = $this->normalizeStoreKey($storeContext['store_key'] ?? null);
        $tenantId = is_numeric($storeContext['tenant_id'] ?? null) && (int) ($storeContext['tenant_id'] ?? 0) > 0
            ? (int) $storeContext['tenant_id']
            : null;

        if ($storeKey === null && $tenantId === null) {
            return $redemption;
        }

        $context = is_array($redemption->redemption_context ?? null) ? $redemption->redemption_context : [];
        $nextContext = array_filter([
            ...$context,
            'shopify_store_key' => $storeKey ?? ($context['shopify_store_key'] ?? null),
            'tenant_id' => $tenantId ?? ($context['tenant_id'] ?? null),
        ], static fn ($value): bool => $value !== null && $value !== '');

        if ($nextContext === $context) {
            return $redemption;
        }

        $redemption->forceFill(['redemption_context' => $nextContext])->save();

        return $redemption->fresh() ?? $redemption;
    }

    protected function ensureShopifyDiscountForCandleCashRedemption(
        CandleCashShopifyDiscountService $discountSyncService,
        CandleCashRedemption $redemption,
        ?string $preferredStoreKey = null
    ): void {
        $platform = strtolower(trim((string) ($redemption->platform ?? '')));
        if ($platform !== '' && $platform !== 'shopify') {
            return;
        }

        $discountSyncService->ensureDiscountForRedemption($redemption, $this->normalizeStoreKey($preferredStoreKey));
    }

    protected function preferredBirthdayStoreKey(MarketingProfile $profile): ?string
    {
        $shopifyLinks = $profile->links()
            ->where('source_type', 'shopify_customer')
            ->pluck('source_id')
            ->map(function ($sourceId): ?string {
                $value = trim((string) $sourceId);
                if (preg_match('/^(retail|wholesale):/i', $value, $matches) === 1) {
                    return strtolower((string) $matches[1]);
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values();

        if ($shopifyLinks->contains('retail')) {
            return 'retail';
        }

        return $shopifyLinks->first();
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function storefrontBalancePayload(
        CandleCashService $candleCashService,
        int $points,
        array $extra = []
    ): array {
        return array_merge($candleCashService->balancePayloadFromPoints($points), $extra);
    }

    /**
     * @param  array<string,mixed>  $summary
     * @return array<string,mixed>
     */
    protected function normalizeCandleCashSummary(array $summary): array
    {
        return [
            'current_balance' => (int) ($summary['current_balance'] ?? $summary['current_balance_points'] ?? 0),
            'current_balance_amount' => (float) ($summary['current_balance_amount'] ?? 0),
            'lifetime_earned' => (int) ($summary['lifetime_earned'] ?? $summary['lifetime_earned_points'] ?? 0),
            'lifetime_earned_amount' => (float) ($summary['lifetime_earned_amount'] ?? 0),
            'lifetime_redeemed' => (int) ($summary['lifetime_redeemed'] ?? $summary['lifetime_redeemed_points'] ?? 0),
            'lifetime_redeemed_amount' => (float) ($summary['lifetime_redeemed_amount'] ?? 0),
            'pending_rewards' => (int) ($summary['pending_rewards'] ?? 0),
            'referral_count' => (int) ($summary['referral_count'] ?? 0),
            'completed_tasks' => (int) ($summary['completed_tasks'] ?? 0),
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function storefrontRewardRows(CandleCashService $candleCashService, ?int $balancePoints = null): array
    {
        $row = $candleCashService->storefrontRewardPayload($candleCashService->storefrontReward(), $balancePoints);

        return $row ? [$row] : [];
    }

    /**
     * @return Collection<int,CandleCashReward>
     */
    protected function activeStorefrontRewards(CandleCashService $candleCashService): Collection
    {
        $reward = $candleCashService->storefrontReward();

        if (! $reward) {
            return collect();
        }

        $storefrontReward = clone $reward;
        $storefrontReward->candle_cash_cost = $candleCashService->storefrontRewardPointsCost($reward);

        return collect([$storefrontReward]);
    }

    /**
     * @return Collection<int,CandleCashReward>
     */
    protected function activeRewards(): Collection
    {
        return CandleCashReward::query()
            ->where('is_active', true)
            ->orderBy('candle_cash_cost')
            ->get(['id', 'name', 'description', 'candle_cash_cost', 'reward_type', 'reward_value', 'is_active']);
    }

    /**
     * @return Collection<int,CandleCashRedemption>
     */
    protected function recentRedemptions(MarketingProfile $profile): Collection
    {
        return $profile->candleCashRedemptions()
            ->with('reward:id,name,reward_type,reward_value')
            ->orderByDesc('id')
            ->limit(20)
            ->get();
    }

    /**
     * @param array<string,mixed> $context
     */
    protected function logStorefrontEvent(Request $request, string $eventType, array $context = []): void
    {
        $endpoint = '/' . ltrim((string) $request->path(), '/');
        $authMode = (string) $request->attributes->get('marketing_storefront_auth_mode', 'unknown');

        $this->eventLogger->log($eventType, [
            'status' => (string) ($context['status'] ?? 'ok'),
            'issue_type' => $context['issue_type'] ?? null,
            'source_surface' => 'shopify_widget',
            'endpoint' => $endpoint,
            'request_key' => (string) ($context['request_key'] ?? substr(hash('sha1', $request->fullUrl() . '|' . $request->ip() . '|' . $eventType), 0, 48)),
            'signature_mode' => $authMode,
            'profile' => $context['profile'] ?? null,
            'marketing_profile_id' => $context['marketing_profile_id'] ?? null,
            'event_instance_id' => $context['event_instance_id'] ?? null,
            'candle_cash_redemption_id' => $context['candle_cash_redemption_id'] ?? null,
            'source_type' => $context['source_type'] ?? null,
            'source_id' => $context['source_id'] ?? null,
            'meta' => array_merge((array) ($context['meta'] ?? []), [
                'method' => strtoupper((string) $request->getMethod()),
                'ip' => (string) $request->ip(),
            ]),
            'resolution_status' => (string) ($context['resolution_status'] ?? 'open'),
            'occurred_at' => now(),
        ]);
    }
}
