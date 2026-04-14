<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\LandlordCommercialConfigService;
use App\Services\Tenancy\LandlordOperatorActionAuditService;
use App\Support\Tenancy\TenantHostBuilder;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class StripeHostedBillingService
{
    public function __construct(
        protected LandlordCommercialConfigService $commercialConfigService,
        protected LandlordOperatorActionAuditService $auditService,
        protected TenantHostBuilder $hostBuilder,
    ) {
    }

    /**
     * @param  array{
     *   preferred_plan_key:?string,
     *   addons_interest:array<int,string>,
     *   source:?string,
     *   captured_at:?string,
     *   access_request_id:?int
     * }  $billingInterest
     * @return array{ok:bool,url:?string,session_id:?string,message:?string}
     */
    public function createCheckoutSession(Tenant $tenant, User $actor, array $billingInterest): array
    {
        if (! $this->hostedBillingEnabled()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Hosted billing is not enabled.'];
        }

        if (! $this->stripeConfigured()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe is not configured.'];
        }

        $tenantId = (int) $tenant->id;
        $preferredPlanKey = strtolower(trim((string) ($billingInterest['preferred_plan_key'] ?? '')));
        if ($preferredPlanKey === '' || ! array_key_exists($preferredPlanKey, (array) config('module_catalog.plans', []))) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'A valid preferred tier is required to start checkout.'];
        }

        $addonsInterest = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($billingInterest['addons_interest'] ?? [])
        ), static fn (string $value): bool => $value !== ''));

        $eligibleAddons = $this->eligibleAddonsForPlan($preferredPlanKey);
        $addonsInterest = array_values(array_filter(
            $addonsInterest,
            static fn (string $addonKey): bool => array_key_exists($addonKey, (array) config('module_catalog.addons', []))
                && in_array($addonKey, $eligibleAddons, true)
        ));

        $stripeMapping = (array) config('commercial.stripe_mapping', []);
        $tierMap = is_array($stripeMapping['tiers'] ?? null) ? (array) $stripeMapping['tiers'] : [];
        $addonMap = is_array($stripeMapping['addons'] ?? null) ? (array) $stripeMapping['addons'] : [];

        $tierRow = is_array($tierMap[$preferredPlanKey] ?? null) ? (array) $tierMap[$preferredPlanKey] : [];
        $tierLookupKey = trim((string) ($tierRow['recurring_price_lookup_key'] ?? ''));
        if ($tierLookupKey === '') {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe mapping missing for the selected tier.'];
        }

        $lookupKeys = [$tierLookupKey];
        foreach ($addonsInterest as $addonKey) {
            $addonRow = is_array($addonMap[$addonKey] ?? null) ? (array) $addonMap[$addonKey] : [];
            $addonLookupKey = trim((string) ($addonRow['recurring_price_lookup_key'] ?? ''));
            if ($addonLookupKey === '') {
                return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe mapping missing for add-on: '.$addonKey];
            }
            $lookupKeys[] = $addonLookupKey;
        }

        $resolved = $this->resolvePriceIdsByLookupKey($lookupKeys);
        if (! ($resolved['ok'] ?? false)) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => (string) ($resolved['message'] ?? 'Stripe price lookup failed.')];
        }

        $priceIdsByLookupKey = is_array($resolved['price_ids_by_lookup_key'] ?? null) ? (array) $resolved['price_ids_by_lookup_key'] : [];
        $tierPriceId = trim((string) ($priceIdsByLookupKey[strtolower($tierLookupKey)] ?? ''));
        if ($tierPriceId === '') {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe price resolution failed for the selected tier.'];
        }

        $lineItems = [
            ['price_id' => $tierPriceId, 'quantity' => 1],
        ];
        foreach ($addonsInterest as $addonKey) {
            $addonRow = is_array($addonMap[$addonKey] ?? null) ? (array) $addonMap[$addonKey] : [];
            $addonLookupKey = strtolower(trim((string) ($addonRow['recurring_price_lookup_key'] ?? '')));
            $addonPriceId = trim((string) ($priceIdsByLookupKey[$addonLookupKey] ?? ''));
            if ($addonLookupKey !== '' && $addonPriceId !== '') {
                $lineItems[] = ['price_id' => $addonPriceId, 'quantity' => 1];
            }
        }

        $successUrl = $this->tenantReturnUrl($tenant, 'success');
        $cancelUrl = $this->tenantReturnUrl($tenant, 'cancel');
        $successSessionSeparator = str_contains($successUrl, '?') ? '&' : '?';

        $commercialProfile = $this->commercialConfigService->tenantCommercialProfile($tenantId);
        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $stripeCustomerReference = trim((string) data_get($billingMapping, 'stripe.customer_reference', ''));

        $metadata = $this->stripeMetadata($tenant, $actor, $billingInterest, [
            'checkout_plan_key' => $preferredPlanKey,
            'checkout_addons_interest' => $addonsInterest,
        ]);

        $payload = [
            'mode' => 'subscription',
            'success_url' => $successUrl.$successSessionSeparator.'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => 'tenant-'.$tenantId,
        ];

        if ($stripeCustomerReference !== '') {
            $payload['customer'] = $stripeCustomerReference;
        } else {
            $payload['customer_email'] = (string) ($actor->email ?? '');
        }

        foreach ($metadata as $key => $value) {
            $payload['metadata['.$key.']'] = $value;
            $payload['subscription_data[metadata]['.$key.']'] = $value;
        }

        foreach ($lineItems as $index => $item) {
            $payload['line_items['.$index.'][price]'] = (string) $item['price_id'];
            $payload['line_items['.$index.'][quantity]'] = (int) $item['quantity'];
        }

        $idempotencyKey = $this->checkoutIdempotencyKey($tenantId, $billingInterest);
        $response = $this->stripeRequest()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post($this->stripeApiBaseUrl().'/v1/checkout/sessions', $payload);

        $json = is_array($response->json()) ? $response->json() : [];
        if ($response->failed()) {
            $this->auditService->record(
                tenantId: $tenantId,
                actorUserId: (int) $actor->id,
                actionType: 'tenant_billing.hosted_checkout_session',
                status: 'failed',
                targetType: 'tenant',
                targetId: $tenantId,
                context: [
                    'stripe_status' => $response->status(),
                    'stripe_error' => trim((string) data_get($json, 'error.message', '')),
                ],
                beforeState: null,
                afterState: null,
            );

            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => $this->stripeErrorMessage($json, $response->status())];
        }

        $sessionId = trim((string) ($json['id'] ?? ''));
        $url = trim((string) ($json['url'] ?? ''));

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: (int) $actor->id,
            actionType: 'tenant_billing.hosted_checkout_session',
            status: 'success',
            targetType: 'tenant',
            targetId: $tenantId,
            context: [
                'stripe_session_id' => $sessionId,
                'preferred_plan_key' => $preferredPlanKey,
                'addons_interest' => $addonsInterest,
            ],
            beforeState: null,
            afterState: null,
        );

        if ($sessionId === '' || $url === '') {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe checkout session returned no redirect URL.'];
        }

        return ['ok' => true, 'url' => $url, 'session_id' => $sessionId, 'message' => null];
    }

    /**
     * @return array{ok:bool,url:?string,session_id:?string,message:?string}
     */
    public function createBillingPortalSession(Tenant $tenant, User $actor): array
    {
        if (! $this->hostedBillingEnabled()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Hosted billing is not enabled.'];
        }

        if (! $this->stripeConfigured()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe is not configured.'];
        }

        $tenantId = (int) $tenant->id;
        $commercialProfile = $this->commercialConfigService->tenantCommercialProfile($tenantId);
        $billingMapping = is_array($commercialProfile['billing_mapping'] ?? null)
            ? (array) $commercialProfile['billing_mapping']
            : [];
        $stripeCustomerReference = trim((string) data_get($billingMapping, 'stripe.customer_reference', ''));

        if ($stripeCustomerReference === '') {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'No Stripe customer reference is available for this tenant yet.'];
        }

        $returnUrl = $this->tenantReturnUrl($tenant, 'return');
        $payload = [
            'customer' => $stripeCustomerReference,
            'return_url' => $returnUrl,
        ];

        $idempotencyKey = sprintf('tenant-%d-portal-v1', $tenantId);
        $response = $this->stripeRequest()
            ->withHeaders(['Idempotency-Key' => $idempotencyKey])
            ->post($this->stripeApiBaseUrl().'/v1/billing_portal/sessions', $payload);

        $json = is_array($response->json()) ? $response->json() : [];
        if ($response->failed()) {
            $this->auditService->record(
                tenantId: $tenantId,
                actorUserId: (int) $actor->id,
                actionType: 'tenant_billing.portal_session',
                status: 'failed',
                targetType: 'tenant',
                targetId: $tenantId,
                context: [
                    'stripe_status' => $response->status(),
                    'stripe_error' => trim((string) data_get($json, 'error.message', '')),
                ],
                beforeState: null,
                afterState: null,
            );

            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => $this->stripeErrorMessage($json, $response->status())];
        }

        $sessionId = trim((string) ($json['id'] ?? ''));
        $url = trim((string) ($json['url'] ?? ''));

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: (int) $actor->id,
            actionType: 'tenant_billing.portal_session',
            status: 'success',
            targetType: 'tenant',
            targetId: $tenantId,
            context: [
                'stripe_session_id' => $sessionId,
                'stripe_customer_reference' => $stripeCustomerReference,
            ],
            beforeState: null,
            afterState: null,
        );

        if ($sessionId === '' || $url === '') {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe billing portal session returned no redirect URL.'];
        }

        return ['ok' => true, 'url' => $url, 'session_id' => $sessionId, 'message' => null];
    }

    protected function hostedBillingEnabled(): bool
    {
        return (bool) config('commercial.billing_readiness.checkout_active', false)
            || (bool) config('commercial.billing_readiness.lifecycle_mutations_enabled', false);
    }

    protected function stripeConfigured(): bool
    {
        $secret = trim((string) config('services.stripe.secret', ''));

        return $secret !== '' && str_starts_with($secret, 'sk_');
    }

    protected function stripeApiBaseUrl(): string
    {
        return rtrim(trim((string) config('services.stripe.api_base', 'https://api.stripe.com')), '/');
    }

    protected function stripeRequest(): PendingRequest
    {
        $timeout = max(5, (int) config('services.stripe.timeout', 20));
        $secret = trim((string) config('services.stripe.secret', ''));

        return Http::asForm()
            ->acceptJson()
            ->timeout($timeout)
            ->retry(1, 250, throw: false)
            ->withBasicAuth($secret, '');
    }

    /**
     * @param  array<int,string>  $lookupKeys
     * @return array{ok:bool,message:?string,price_ids_by_lookup_key:array<string,string>}
     */
    protected function resolvePriceIdsByLookupKey(array $lookupKeys): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            $lookupKeys
        ), static fn (string $value): bool => $value !== ''));

        $normalized = array_values(array_unique($normalized));
        if ($normalized === []) {
            return [
                'ok' => false,
                'message' => 'No Stripe lookup keys were available for checkout.',
                'price_ids_by_lookup_key' => [],
            ];
        }

        $query = [
            'active' => 'true',
            'limit' => 100,
        ];
        foreach ($normalized as $index => $lookupKey) {
            $query['lookup_keys['.$index.']'] = $lookupKey;
        }

        $response = $this->stripeRequest()->get($this->stripeApiBaseUrl().'/v1/prices', $query);
        $json = is_array($response->json()) ? $response->json() : [];

        if ($response->failed()) {
            return [
                'ok' => false,
                'message' => $this->stripeErrorMessage($json, $response->status()),
                'price_ids_by_lookup_key' => [],
            ];
        }

        $priceRows = is_array($json['data'] ?? null) ? (array) $json['data'] : [];
        $resolved = [];
        foreach ($priceRows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $lookupKey = strtolower(trim((string) ($row['lookup_key'] ?? '')));
            $id = trim((string) ($row['id'] ?? ''));
            if ($lookupKey === '' || $id === '') {
                continue;
            }
            $resolved[$lookupKey] = $id;
        }

        foreach ($normalized as $lookupKey) {
            if (! array_key_exists($lookupKey, $resolved)) {
                return [
                    'ok' => false,
                    'message' => 'Missing Stripe price lookup key: '.$lookupKey,
                    'price_ids_by_lookup_key' => $resolved,
                ];
            }
        }

        return [
            'ok' => true,
            'message' => null,
            'price_ids_by_lookup_key' => $resolved,
        ];
    }

    protected function tenantReturnUrl(Tenant $tenant, string $mode): string
    {
        $modeToken = strtolower(trim($mode));
        $modeToken = in_array($modeToken, ['success', 'cancel', 'return'], true) ? $modeToken : 'return';

        $host = filled($tenant->slug ?? null) ? $this->hostBuilder->hostForSlug((string) $tenant->slug) : null;

        $path = route('app.start', [
            'billing' => $modeToken,
        ], false);

        $scheme = $this->hostBuilder->canonicalScheme();

        if ($host === null) {
            $defaultHost = $this->hostBuilder->canonicalLandlordHost();
            if ($defaultHost === null) {
                $defaultHost = trim((string) parse_url((string) config('app.url', ''), PHP_URL_HOST));
                $defaultHost = $defaultHost !== '' ? $defaultHost : trim((string) config('tenancy.landlord.primary_host', ''));
            }
            if ($defaultHost !== '') {
                return $scheme.'://'.$defaultHost.$path;
            }

            return url($path);
        }

        return $scheme.'://'.$host.$path;
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,string>
     */
    protected function stripeMetadata(Tenant $tenant, User $actor, array $billingInterest, array $extra = []): array
    {
        $addonsInterest = array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($billingInterest['addons_interest'] ?? [])
        ), static fn (string $value): bool => $value !== ''));

        $metadata = [
            'tenant_id' => (string) ((int) $tenant->id),
            'tenant_slug' => (string) ($tenant->slug ?? ''),
            'actor_user_id' => (string) ((int) $actor->id),
            'preferred_plan_key' => (string) ($billingInterest['preferred_plan_key'] ?? ''),
            'addons_interest' => $addonsInterest !== [] ? implode(',', $addonsInterest) : '',
            'interest_source' => (string) ($billingInterest['source'] ?? ''),
            'access_request_id' => (string) ((int) ($billingInterest['access_request_id'] ?? 0)),
        ];

        foreach ($extra as $key => $value) {
            $token = strtolower(trim((string) $key));
            if ($token === '') {
                continue;
            }
            $metadata[$token] = is_scalar($value) ? (string) $value : json_encode($value);
        }

        return array_filter($metadata, static fn (string $value): bool => $value !== '');
    }

    protected function checkoutIdempotencyKey(int $tenantId, array $billingInterest): string
    {
        $raw = json_encode([
            'tenant_id' => $tenantId,
            'preferred_plan_key' => (string) ($billingInterest['preferred_plan_key'] ?? ''),
            'addons_interest' => (array) ($billingInterest['addons_interest'] ?? []),
        ]);

        return sprintf('tenant-%d-checkout-%s-v1', $tenantId, substr(hash('sha256', (string) $raw), 0, 24));
    }

    protected function stripeErrorMessage(array $json, int $status): string
    {
        $message = trim((string) data_get($json, 'error.message', ''));
        if ($message === '') {
            $message = 'Stripe API request failed with status '.$status.'.';
        }

        return $message;
    }

    /**
     * @return array<int,string>
     */
    protected function eligibleAddonsForPlan(string $planKey): array
    {
        $plan = is_array(config('module_catalog.plans.'.strtolower(trim($planKey))))
            ? (array) config('module_catalog.plans.'.strtolower(trim($planKey)))
            : [];

        return array_values(array_filter(array_map(
            static fn (mixed $value): string => strtolower(trim((string) $value)),
            (array) ($plan['eligible_addons'] ?? [])
        ), static fn (string $value): bool => $value !== ''));
    }
}
