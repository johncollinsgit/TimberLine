<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Jobs\ProvisionShopifyCustomerForMarketingProfile;
use App\Models\MarketingConsentRequest;
use App\Models\MarketingProfile;
use App\Services\Marketing\CandleCashTaskService;
use App\Services\Marketing\MarketingConsentCaptureService;
use App\Services\Marketing\MarketingConsentService;
use App\Services\Marketing\MarketingStorefrontEventLogger;
use App\Services\Shopify\ShopifyStores;
use App\Services\Tenancy\TenantDisplayLabelResolver;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketingConsentCaptureController extends Controller
{
    public function __construct(
        protected MarketingStorefrontEventLogger $eventLogger,
        protected TenantDisplayLabelResolver $displayLabelResolver
    ) {
    }

    public function showOptin(Request $request): View
    {
        $tenantContext = $this->resolveTenantContext($request, app(TenantResolver::class));
        $displayLabels = $this->displayLabelsForTenantId($tenantContext['tenant_id']);

        $this->eventLogger->log('public_consent_optin_view', [
            'status' => 'ok',
            'source_surface' => 'public_event',
            'endpoint' => '/marketing/consent/optin',
            'source_type' => 'storefront_optin',
            'source_id' => 'optin',
            'resolution_status' => 'resolved',
        ]);

        return view('marketing/consent/optin', [
            'token' => (string) $request->query('token', ''),
            'displayLabels' => $displayLabels,
        ]);
    }

    public function storeOptin(
        Request $request,
        MarketingConsentCaptureService $captureService,
        MarketingConsentService $consentService,
        CandleCashTaskService $taskService,
        TenantResolver $tenantResolver
    ): RedirectResponse {
        $data = $request->validate([
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:40', 'required_without:email'],
            'first_name' => ['nullable', 'string', 'max:120'],
            'last_name' => ['nullable', 'string', 'max:120'],
            'accepts_email' => ['nullable', 'boolean'],
            'award_bonus' => ['nullable', 'boolean'],
        ]);

        $tenantContext = $this->resolveTenantContext($request, $tenantResolver);
        $sourceId = 'storefront-optin:' . sha1(
            strtolower(trim((string) ($data['email'] ?? ''))) . '|' . trim((string) ($data['phone'] ?? ''))
        );

        $result = $captureService->requestSmsConfirmation([
            'email' => (string) ($data['email'] ?? ''),
            'phone' => (string) ($data['phone'] ?? ''),
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
        ], [
            'source_type' => 'storefront_optin',
            'source_id' => $sourceId,
            'source_label' => 'storefront_consent_optin',
            'source_channels' => ['storefront_optin'],
            'source_meta' => [
                'entrypoint' => 'stage8_hardened',
                'shopify_store_key' => $tenantContext['store_key'],
                'tenant_id' => $tenantContext['tenant_id'],
            ],
            'flow' => 'verification',
            'allow_create' => true,
            'tenant_id' => $tenantContext['tenant_id'],
            'award_bonus' => (bool) ($data['award_bonus'] ?? false),
            'request_meta' => [
                'ip' => (string) $request->ip(),
            ],
        ]);

        /** @var MarketingProfile|null $profile */
        $profile = $result['profile'];
        if (! $profile) {
            $this->eventLogger->log('public_consent_optin_submit', [
                'status' => 'verification_required',
                'issue_type' => 'identity_review_required',
                'source_surface' => 'public_event',
                'endpoint' => '/marketing/consent/optin',
                'source_type' => 'storefront_optin',
                'source_id' => $sourceId,
                'meta' => [
                    'email' => (string) ($data['email'] ?? ''),
                    'phone' => (string) ($data['phone'] ?? ''),
                ],
            ]);

            throw ValidationException::withMessages([
                'identity' => 'This consent request was queued for identity review. A profile could not be safely auto-resolved.',
            ]);
        }

        $this->queueShopifyCustomerProvisioning(
            profile: $profile,
            storeKey: $tenantContext['store_key'] ?? null,
            tenantId: $tenantContext['tenant_id'] ?? $profile->tenant_id,
            trigger: 'marketing_consent_optin'
        );

        if ((bool) ($data['accepts_email'] ?? false)) {
            $consentService->setEmailConsent($profile, true, [
                'source_type' => 'storefront_optin',
                'source_id' => $sourceId,
                'tenant_id' => $tenantContext['tenant_id'] ?: $profile->tenant_id,
                'details' => ['stage' => 'optin_capture'],
            ]);

            $taskService->awardSystemTask($profile, 'email-signup', [
                'source_type' => 'storefront_optin',
                'source_id' => $sourceId . ':email',
                'metadata' => [
                    'surface' => 'public_optin',
                ],
            ]);
        }

        $this->eventLogger->log('public_consent_optin_submit', [
            'status' => 'pending',
            'source_surface' => 'public_event',
            'endpoint' => '/marketing/consent/optin',
            'profile' => $profile,
            'source_type' => 'storefront_optin',
            'source_id' => $sourceId,
            'meta' => [
                'request_id' => (int) ($result['request']?->id ?? 0),
                'token_tail' => substr((string) ($result['token'] ?? ''), -8),
            ],
            'resolution_status' => 'open',
        ]);

        return redirect()
            ->route('marketing.consent.verify', ['token' => (string) ($result['token'] ?? '')])
            ->with('status', 'Consent request captured. Complete verification to confirm SMS consent.');
    }

    public function showVerify(Request $request): View
    {
        $token = Str::lower(trim((string) $request->query('token', '')));
        $consentRequest = $token !== ''
            ? MarketingConsentRequest::query()->where('token', $token)->first()
            : null;

        $profile = null;
        if ($consentRequest?->marketing_profile_id) {
            $profile = MarketingProfile::query()->find((int) $consentRequest->marketing_profile_id);
        }
        $tenantContext = $this->resolveTenantContext($request, app(TenantResolver::class));
        $tenantId = is_numeric(data_get((array) ($consentRequest?->payload ?? []), 'tenant_id'))
            ? (int) data_get((array) ($consentRequest?->payload ?? []), 'tenant_id')
            : (is_numeric($profile?->tenant_id) ? (int) $profile->tenant_id : $tenantContext['tenant_id']);
        $displayLabels = $this->displayLabelsForTenantId($tenantId);

        $this->eventLogger->log('public_consent_verify_view', [
            'status' => $consentRequest && $consentRequest->status === 'confirmed' ? 'resolved' : 'pending',
            'issue_type' => $consentRequest ? null : 'token_missing_or_invalid',
            'source_surface' => 'public_event',
            'endpoint' => '/marketing/consent/verify',
            'profile' => $profile,
            'source_type' => 'storefront_verify',
            'source_id' => $consentRequest?->source_id,
            'meta' => [
                'request_id' => (int) ($consentRequest?->id ?? 0),
            ],
            'resolution_status' => $consentRequest && $consentRequest->status === 'confirmed' ? 'resolved' : 'open',
        ]);

        return view('marketing/consent/verify', [
            'token' => $token,
            'payload' => $consentRequest ? [
                'profile_id' => $consentRequest->marketing_profile_id,
                'award_bonus' => (bool) data_get((array) $consentRequest->payload, 'award_bonus', false),
                'source_id' => $consentRequest->source_id,
                'status' => $consentRequest->status,
            ] : null,
            'profile' => $profile,
            'confirmed' => (bool) $request->query('confirmed', false) || (bool) ($consentRequest && $consentRequest->status === 'confirmed'),
            'displayLabels' => $displayLabels,
        ]);
    }

    public function storeVerify(
        Request $request,
        MarketingConsentCaptureService $captureService
    ): RedirectResponse {
        $data = $request->validate([
            'token' => ['required', 'string', 'min:20', 'max:120'],
        ]);

        $result = $captureService->confirmSmsByToken((string) $data['token'], [
            'source_type' => 'storefront_verify',
            'bonus_description' => 'SMS consent verification bonus',
        ]);

        if (($result['status'] ?? '') === 'invalid') {
            $this->eventLogger->log('public_consent_verify_submit', [
                'status' => 'error',
                'issue_type' => 'token_invalid',
                'source_surface' => 'public_event',
                'endpoint' => '/marketing/consent/verify',
                'source_type' => 'storefront_verify',
                'source_id' => substr((string) $data['token'], -8),
            ]);

            throw ValidationException::withMessages([
                'token' => 'Verification token is invalid or no longer available.',
            ]);
        }

        if (($result['status'] ?? '') === 'expired') {
            $this->eventLogger->log('public_consent_verify_submit', [
                'status' => 'error',
                'issue_type' => 'token_expired',
                'source_surface' => 'public_event',
                'endpoint' => '/marketing/consent/verify',
                'source_type' => 'storefront_verify',
                'source_id' => substr((string) $data['token'], -8),
            ]);

            throw ValidationException::withMessages([
                'token' => 'Verification token expired. Submit a new consent request.',
            ]);
        }

        /** @var MarketingProfile|null $profile */
        $profile = $result['profile'] ?? null;
        $this->eventLogger->log('public_consent_verify_submit', [
            'status' => 'resolved',
            'source_surface' => 'public_event',
            'endpoint' => '/marketing/consent/verify',
            'profile' => $profile,
            'source_type' => 'storefront_verify',
            'source_id' => (string) ($result['request']?->source_id ?? ''),
            'meta' => [
                'request_id' => (int) ($result['request']?->id ?? 0),
                'bonus_awarded' => (int) ($result['bonus_awarded'] ?? 0),
            ],
            'resolution_status' => 'resolved',
        ]);

        return redirect()->route('marketing.consent.verify', [
            'token' => trim((string) $data['token']),
            'confirmed' => 1,
            'bonus' => (int) ($result['bonus_awarded'] ?? 0),
        ])->with('status', 'SMS consent verified successfully.');
    }

    /**
     * @return array{store_key:?string,tenant_id:?int}
     */
    protected function resolveTenantContext(Request $request, TenantResolver $tenantResolver): array
    {
        $storeKey = strtolower(trim((string) ($request->input('store_key', $request->query('store_key', '')))));
        if ($storeKey === '') {
            $shop = trim((string) ($request->input('shop', $request->query('shop', ''))));
            $resolvedStore = $shop !== '' ? ShopifyStores::findByShopDomain($shop) : null;
            $storeKey = strtolower(trim((string) ($resolvedStore['key'] ?? '')));
        }

        if ($storeKey === '') {
            return [
                'store_key' => null,
                'tenant_id' => null,
            ];
        }

        return [
            'store_key' => $storeKey,
            'tenant_id' => $tenantResolver->resolveTenantIdForStoreKey($storeKey),
        ];
    }

    protected function queueShopifyCustomerProvisioning(
        MarketingProfile $profile,
        ?string $storeKey,
        mixed $tenantId,
        string $trigger
    ): void {
        $normalizedStoreKey = strtolower(trim((string) $storeKey));
        $resolvedTenantId = is_numeric($tenantId)
            ? (int) $tenantId
            : (is_numeric($profile->tenant_id) ? (int) $profile->tenant_id : 0);

        if ($normalizedStoreKey === '' || $resolvedTenantId <= 0) {
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

    /**
     * @return array<string,string>
     */
    protected function displayLabelsForTenantId(?int $tenantId): array
    {
        $resolved = $this->displayLabelResolver->resolve($tenantId);

        return is_array($resolved['labels'] ?? null)
            ? (array) $resolved['labels']
            : [];
    }

}
