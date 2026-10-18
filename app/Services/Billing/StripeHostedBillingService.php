<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use App\Models\TenantAccessAddon;
use App\Models\TenantAccessProfile;
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
    ) {}

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

        foreach ((array) ($billingInterest['addons_interest'] ?? []) as $interest) {
            $addonKey = str_starts_with((string) $interest, 'addon.') ? substr($interest, 6) : $interest;
            if (config('module_catalog.addons.'.$addonKey.'.purchase_enabled') === false) {
                return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Purchasing this add-on is paused until servicing and billing are ready.'];
            }
        }

        if (($billingInterest['preferred_plan_key'] ?? '') === 'standard') {
            return $this->createStandardCheckoutSession($tenant, $actor, $billingInterest);
        }

        return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'This legacy tier is retired for new purchases. Existing agreements retain their recorded terms.'];
    }

    /** The standard offer has its own immutable prices; legacy agreements use their snapshots. */
    protected function createStandardCheckoutSession(Tenant $tenant, User $actor, array $billingInterest): array
    {
        $failure = static fn (string $message): array => ['ok' => false, 'url' => null, 'session_id' => null, 'message' => $message];
        $offer = (array) config('module_catalog.offers.standard');
        $planKey = trim((string) ($offer['fulfillment_plan_key'] ?? ''));
        if ($planKey === '' || ! is_array(config('module_catalog.plans.'.$planKey))) {
            return $failure('Standard pricing is ready. Workspace capacity and fulfillment require review before checkout can open.');
        }
        if (\App\Models\Agreement::query()->where('tenant_id', $tenant->id)->whereIn('status', ['accepted', 'active', 'termination_pending'])->exists()
            || \App\Models\TenantBillingSubscription::query()->where('tenant_id', $tenant->id)->exists()) {
            return $failure('This business has an existing agreement or subscription. Its terms require review and are not replaced by the new offer.');
        }
        if ((array) ($billingInterest['addons_interest'] ?? []) !== []) {
            return $failure('Additional services need their own reviewed purchase; they are not included in standard setup.');
        }

        try {
            return \Illuminate\Support\Facades\Cache::lock('standard-checkout-'.$tenant->id, 60)->block(3, function () use ($tenant, $actor, $offer, $planKey, $failure): array {
                $profile = \App\Models\TenantCommercialOverride::query()->firstOrCreate(['tenant_id' => $tenant->id]);
                $mapping = (array) $profile->billing_mapping;
                if (filled(data_get($mapping, 'stripe.subscription_reference')) || filled(data_get($mapping, 'stripe.checkout_completed_at'))) {
                    return $failure('This business already has billing records. Manage its existing agreement instead of starting a new subscription.');
                }
                $attempt = data_get($profile->metadata, 'standard_checkout');
                if (is_array($attempt)) {
                    if (filled($attempt['session_id'] ?? null)) {
                        $response = $this->stripeRequest()->get($this->stripeApiBaseUrl().'/v1/checkout/sessions/'.rawurlencode($attempt['session_id']));
                        $session = $response->json();
                        if ($response->failed() || ! is_array($session) || ($session['status'] ?? '') !== 'open') {
                            return $failure('The previous checkout needs review. No second setup charge or subscription was created.');
                        }

                        return ['ok' => true, 'url' => $attempt['url'], 'session_id' => $attempt['session_id'], 'message' => null];
                    }
                    // Stripe may discard idempotency keys after 24 hours. Never blindly replay an uncertain old request.
                    if (\Illuminate\Support\Carbon::parse($attempt['reserved_at'])->lt(now()->subHours(23))) {
                        return $failure('The saved payment attempt needs provider review before another checkout can be created.');
                    }
                } else {
                    $lines = [];
                    foreach (['recurring_price_lookup_key' => 'monthly_cents', 'setup_price_lookup_key' => 'setup_cents'] as $lookupField => $amountField) {
                        $lookup = $offer[$lookupField];
                        $response = $this->stripeRequest()->get($this->stripeApiBaseUrl().'/v1/prices', ['active' => 'true', 'lookup_keys[0]' => $lookup, 'limit' => 2]);
                        $rows = (array) $response->json('data', []);
                        $price = count($rows) === 1 ? $rows[0] : [];
                        $recurring = $amountField === 'monthly_cents';
                        if ($response->failed() || ($price['lookup_key'] ?? '') !== $lookup || ! ($price['active'] ?? false)
                            || ($price['currency'] ?? '') !== 'usd' || ($price['unit_amount'] ?? null) !== $offer[$amountField]
                            || ($price['type'] ?? '') !== ($recurring ? 'recurring' : 'one_time')
                            || ($recurring && (data_get($price, 'recurring.interval') !== 'month' || data_get($price, 'recurring.interval_count') !== 1 || data_get($price, 'recurring.usage_type') !== 'licensed'))
                            || (! $recurring && ! empty($price['recurring'])) || empty($price['id'])) {
                            return $failure('The configured Stripe price does not match the standard USD monthly/setup offer.');
                        }
                        $lines[] = ['price' => $price['id'], 'quantity' => 1];
                    }
                    $payload = [
                        'mode' => 'subscription',
                        'success_url' => $this->tenantReturnUrl($tenant, 'success').'&session_id={CHECKOUT_SESSION_ID}',
                        'cancel_url' => $this->tenantReturnUrl($tenant, 'cancel'),
                        'client_reference_id' => 'tenant-'.$tenant->id,
                        'customer_email' => (string) $actor->email,
                        'line_items' => $lines,
                        'metadata' => ['tenant_id' => (string) $tenant->id, 'checkout_plan_key' => $planKey, 'offer_version' => $offer['version']],
                        'subscription_data' => ['metadata' => ['tenant_id' => (string) $tenant->id, 'checkout_plan_key' => $planKey, 'offer_version' => $offer['version']]],
                    ];
                    if (filled(data_get($mapping, 'stripe.customer_reference'))) {
                        unset($payload['customer_email']);
                        $payload['customer'] = data_get($mapping, 'stripe.customer_reference');
                    }
                    $attempt = ['key' => 'standard-'.(string) \Illuminate\Support\Str::uuid(), 'reserved_at' => now()->toIso8601String(), 'offer' => $offer, 'payload' => $payload];
                    $metadata = (array) $profile->metadata;
                    $metadata['standard_checkout'] = $attempt;
                    $profile->forceFill(['metadata' => $metadata])->save();
                }
                $response = $this->stripeRequest()->withHeaders(['Idempotency-Key' => $attempt['key']])->post($this->stripeApiBaseUrl().'/v1/checkout/sessions', $attempt['payload']);
                $json = (array) $response->json();
                $url = (string) ($json['url'] ?? '');
                if ($response->failed() || empty($json['id']) || ! str_starts_with($url, 'https://checkout.stripe.com/')) {
                    return $failure('Stripe could not confirm checkout. The saved attempt is retained for a safe retry.');
                }
                $attempt['session_id'] = (string) $json['id'];
                $attempt['url'] = $url;
                $metadata = (array) $profile->fresh()->metadata;
                $metadata['standard_checkout'] = $attempt;
                $profile->forceFill(['metadata' => $metadata])->save();

                return ['ok' => true, 'url' => $url, 'session_id' => $attempt['session_id'], 'message' => null];
            });
        } catch (\Throwable $exception) {
            report($exception);

            return $failure('Checkout is unavailable. No payment or activation has been confirmed; retry the saved request later.');
        }
    }

    /**
     * Create a server-priced Stripe Checkout session for one canonical add-on.
     * Client-provided prices and line items are intentionally ignored.
     *
     * @return array{ok:bool,url:?string,session_id:?string,message:?string}
     */
    public function createAddonCheckoutSession(Tenant $tenant, User $actor, string $addonKey): array
    {
        if (! $this->hostedBillingEnabled()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Hosted billing is not enabled.'];
        }

        if (! $this->stripeConfigured()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe is not configured.'];
        }

        $tenantId = (int) $tenant->id;
        $addonKey = strtolower(trim($addonKey));
        $addon = is_array(config('module_catalog.addons.'.$addonKey))
            ? (array) config('module_catalog.addons.'.$addonKey)
            : [];
        if ($addon === [] || strtolower(trim((string) ($addon['billing_mode'] ?? ''))) !== 'add_on') {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'This add-on is not available for self-serve checkout.'];
        }
        if (! \App\Support\Tenancy\CatalogPurchasePolicy::addonEnabled($addonKey)) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Purchasing this add-on is paused until servicing and billing are ready.'];
        }

        $planKey = strtolower(trim((string) TenantAccessProfile::query()
            ->where('tenant_id', $tenantId)
            ->value('plan_key')));
        if ($planKey === '') {
            $planKey = strtolower(trim((string) config('module_catalog.defaults.plan', 'starter')));
        }
        if (! in_array($addonKey, $this->eligibleAddonsForPlan($planKey), true)) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'This add-on is not available for the current plan.'];
        }

        if (TenantAccessAddon::query()->where('tenant_id', $tenantId)->where('addon_key', $addonKey)->where('enabled', true)->exists()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'This add-on is already active.'];
        }

        $lookupKey = strtolower(trim((string) data_get($addon, 'stripe.recurring_price_lookup_key', '')));
        if ($lookupKey === '') {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => 'Stripe mapping is missing for this add-on.'];
        }

        $resolved = $this->resolvePriceIdsByLookupKey([$lookupKey]);
        $priceId = trim((string) data_get($resolved, 'price_ids_by_lookup_key.'.$lookupKey, ''));
        if (! ($resolved['ok'] ?? false) || $priceId === '') {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => (string) ($resolved['message'] ?? 'Stripe price lookup failed.')];
        }

        $commercialProfile = $this->commercialConfigService->tenantCommercialProfile($tenantId);
        $stripeCustomerReference = trim((string) data_get($commercialProfile, 'billing_mapping.stripe.customer_reference', ''));
        $successUrl = $this->tenantReturnUrl($tenant, 'success');
        $cancelUrl = $this->tenantReturnUrl($tenant, 'cancel');
        $separator = str_contains($successUrl, '?') ? '&' : '?';
        $billingInterest = [
            'preferred_plan_key' => $planKey,
            'addons_interest' => [$addonKey],
            'source' => 'tenant_module_store',
            'access_request_id' => null,
        ];
        $metadata = $this->stripeMetadata($tenant, $actor, $billingInterest, [
            'purpose' => 'addon_checkout',
            'checkout_plan_key' => $planKey,
            'checkout_addons_interest' => $addonKey,
        ]);

        $payload = [
            'mode' => 'subscription',
            'success_url' => $successUrl.$separator.'session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => $cancelUrl,
            'client_reference_id' => 'tenant-'.$tenantId,
            'line_items[0][price]' => $priceId,
            'line_items[0][quantity]' => 1,
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

        $response = $this->stripeRequest()
            ->withHeaders(['Idempotency-Key' => sprintf('tenant-%d-addon-%s-checkout-v1', $tenantId, $addonKey)])
            ->post($this->stripeApiBaseUrl().'/v1/checkout/sessions', $payload);
        $json = is_array($response->json()) ? $response->json() : [];
        $sessionId = trim((string) ($json['id'] ?? ''));
        $url = trim((string) ($json['url'] ?? ''));

        $this->auditService->record(
            tenantId: $tenantId,
            actorUserId: (int) $actor->id,
            actionType: 'tenant_billing.addon_checkout_session',
            status: $response->successful() && $sessionId !== '' && $url !== '' ? 'success' : 'failed',
            targetType: 'tenant',
            targetId: $tenantId,
            context: [
                'stripe_status' => $response->status(),
                'stripe_session_id' => $sessionId,
                'addon_key' => $addonKey,
                'plan_key' => $planKey,
            ],
            beforeState: null,
            afterState: null,
        );

        if ($response->failed()) {
            return ['ok' => false, 'url' => null, 'session_id' => null, 'message' => $this->stripeErrorMessage($json, $response->status())];
        }
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
