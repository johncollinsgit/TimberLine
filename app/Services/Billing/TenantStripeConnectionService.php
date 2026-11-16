<?php

namespace App\Services\Billing;

use App\Models\IntegrationConnection;
use App\Models\Tenant;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TenantStripeConnectionService
{
    public const ACCOUNTS_V2_VERSION = '2026-10-28.endive';

    private const ACCOUNTS_V2_ENDPOINT = 'https://api.stripe.com/v2/core/accounts';

    private const ACCOUNTS_V1_UNSUPPORTED = 'Stripe no longer recommends Accounts v1 for new Connect integrations. Create connected accounts with POST /v2/core/accounts instead';

    private function stripe()
    {
        return Http::asForm()->withBasicAuth((string) config('services.stripe.secret'), '')
            ->withoutRedirecting()->connectTimeout(5)->timeout(20);
    }

    public function account(Tenant $tenant, User $actor, string $email): TenantPaymentAccount
    {
        abort_unless(app(TenantPaymentsReadinessService::class)->canStartOnboarding($tenant), 423, 'Payment setup is waiting for Everbranch to activate Stripe Connect.');
        $fingerprint = $this->fingerprint();

        // Commit the immutable request before contacting Stripe. Retrying after a
        // timeout or changed email must reuse both the key and identical payload.
        $attempt = DB::transaction(function () use ($tenant, $actor, $email, $fingerprint) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $existing = $tenant->paymentAccount()->first();
            if ($existing?->provider_account_id) {
                abort_unless($existing->provider === 'stripe_connect', 409);
                abort_if(data_get($existing->metadata, 'setup_fingerprint') && data_get($existing->metadata, 'setup_fingerprint') !== $fingerprint, 409, 'The Stripe platform account or environment changed. Contact support before reconnecting.');

                return $existing;
            }
            $express = in_array($tenant->slug, config('tenant_payments.legacy_express_tenants', []), true);
            $attempt = IntegrationConnection::query()->forTenant($tenant)->firstOrCreate(
                ['tenant_id' => $tenant->id, 'provider' => 'tenant_stripe_onboarding', 'external_account_id' => $this->fingerprint()],
                ['status' => 'pending', 'connected_by_user_id' => $actor->id, 'external_account_secret' => json_encode($express ? [
                    'key' => (string) Str::uuid(), 'payload' => ['type' => 'express', 'country' => 'US', 'email' => $email,
                        'capabilities' => ['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']],
                        'business_profile' => ['name' => $tenant->name, 'product_description' => 'Customer payments for '.$tenant->name],
                        'metadata' => ['tenant_id' => (string) $tenant->id, 'tenant_slug' => $tenant->slug]],
                ] : $this->newAccountsV2Request($email, $tenant->name, ['tenant_id' => (string) $tenant->id, 'tenant_slug' => $tenant->slug]), JSON_THROW_ON_ERROR)]
            );

            return $attempt;
        });
        if ($attempt instanceof TenantPaymentAccount) {
            return $attempt;
        }

        if ($attempt->status === 'rejected' && data_get($attempt->metadata, 'rejection') === 'stripe_missing_transfers_capability') {
            $attempt = $this->capabilityReplacement($tenant, $actor, $attempt);
        }
        if ($attempt->status === 'rejected' && data_get($attempt->metadata, 'rejection') === 'stripe_accounts_v1_create_unsupported') {
            $attempt = $this->accountsV2Replacement($tenant, $actor, $attempt);
        }

        abort_if($attempt->created_at->lt(now()->subHours(23)), 409, 'This payment setup attempt needs support review before retrying. This prevents creating a duplicate account.');

        $frozen = json_decode($attempt->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
        $response = $this->sendAccountRequest($frozen);
        // This specific validation rejection proves the old request did not create
        // an account. Retain its immutable payload/key and reserve a separate,
        // auditable corrected request. Timeouts and other failures never qualify.
        if ($response->status() === 400 && $response->json('error.type') === 'invalid_request_error'
            && str_starts_with((string) $response->json('error.message'), 'Accounts do not currently support `card_payments` without `transfers`.')
            && data_get($frozen, 'payload.type') === 'standard'
            && data_get($frozen, 'payload.capabilities') === ['card_payments' => ['requested' => 'true']]) {
            DB::transaction(function () use ($tenant, $actor, $attempt): void {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $original = IntegrationConnection::query()->forTenant($tenant)->findOrFail($attempt->id);
                if ($original->status !== 'rejected') {
                    $original->update(['status' => 'rejected', 'metadata' => array_merge($original->metadata ?? [],
                        ['rejection' => 'stripe_missing_transfers_capability', 'rejected_at' => now()->toIso8601String()])]);
                    app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id,
                        'tenant_payments.stripe.account_request_rejected', targetType: 'integration_connection', targetId: $original->id,
                        result: ['provider_status' => 400, 'reason' => 'stripe_missing_transfers_capability', 'account_created' => false]);
                }
            });
            $attempt = $this->capabilityReplacement($tenant, $actor, $attempt->fresh());
            abort_if($attempt->created_at->lt(now()->subHours(23)), 409, 'This corrected payment setup attempt needs support review before retrying.');
            $frozen = json_decode($attempt->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
            $response = $this->sendAccountRequest($frozen);
        }
        if (! $this->isAccountsV2($frozen) && data_get($frozen, 'payload.type') === 'standard'
            && $response->status() === 400 && $response->json('error.type') === 'invalid_request_error'
            && str_starts_with((string) $response->json('error.message'), self::ACCOUNTS_V1_UNSUPPORTED)) {
            DB::transaction(function () use ($tenant, $actor, $attempt): void {
                Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
                $original = IntegrationConnection::query()->forTenant($tenant)->findOrFail($attempt->id);
                if ($original->status !== 'rejected') {
                    $original->update(['status' => 'rejected', 'metadata' => array_merge($original->metadata ?? [],
                        ['rejection' => 'stripe_accounts_v1_create_unsupported', 'rejected_at' => now()->toIso8601String()])]);
                    app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id,
                        'tenant_payments.stripe.account_request_rejected', targetType: 'integration_connection', targetId: $original->id,
                        result: ['provider_status' => 400, 'reason' => 'stripe_accounts_v1_create_unsupported', 'account_created' => false]);
                }
            });
            $attempt = $this->accountsV2Replacement($tenant, $actor, $attempt->fresh());
            abort_if($attempt->created_at->lt(now()->subHours(23)), 409, 'This corrected payment setup attempt needs support review before retrying.');
            $frozen = json_decode($attempt->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
            $response = $this->sendAccountRequest($frozen);
        }
        $this->assertSuccess($response);
        if ($this->isAccountsV2($frozen)) {
            $this->assertAccountsV2Response($response, $frozen);
        }
        $id = (string) $response->json('id');
        abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $id), 502, 'Stripe returned an invalid payment account.');

        return DB::transaction(function () use ($tenant, $actor, $id, $attempt, $frozen) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $account = $tenant->paymentAccount()->first();
            abort_if($account?->provider_account_id && $account->provider_account_id !== $id, 409, 'Payment account changed. Refresh before continuing.');
            abort_if(TenantPaymentAccount::withoutGlobalScopes()->where('provider_account_id', $id)->where('tenant_id', '!=', $tenant->id)->exists(), 409, 'This Stripe account is already bound to a workspace.');
            if ($account?->provider_account_id === $id) {
                return $account;
            }
            $account ??= new TenantPaymentAccount(['tenant_id' => $tenant->id]);
            $account->fill(['provider' => 'stripe_connect', 'provider_account_id' => $id, 'status' => 'onboarding',
                'onboarding_started_at' => now(), 'metadata' => array_merge($account->metadata ?? [], ['setup_fingerprint' => $this->fingerprint(),
                    'routing_mode' => $this->isAccountsV2($frozen) || $frozen['payload']['type'] === 'standard' ? 'direct' : 'destination',
                    'ownership_verified_via' => 'created_account', 'purposes' => ['payment_processing', 'payout_bank']],
                    $this->isAccountsV2($frozen) ? ['creation_api' => 'accounts_v2', 'creation_api_version' => self::ACCOUNTS_V2_VERSION] : [])])->save();
            $attempt->update(['status' => 'connected', 'connected_at' => now()]);
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, 'tenant_payments.stripe.connected', targetType: 'tenant_payment_account', targetId: $account->id);

            return $account;
        });
    }

    private function newAccountsV2Request(string $email, string $name, array $metadata): array
    {
        return ['key' => (string) Str::uuid(), 'api_family' => 'accounts_v2', 'endpoint' => self::ACCOUNTS_V2_ENDPOINT,
            'api_version' => self::ACCOUNTS_V2_VERSION, 'payload' => [
                'contact_email' => $email, 'display_name' => $name, 'identity' => ['country' => 'us'],
                'dashboard' => 'full', 'defaults' => ['responsibilities' => ['fees_collector' => 'stripe', 'losses_collector' => 'stripe']],
                'configuration' => ['merchant' => ['capabilities' => ['card_payments' => ['requested' => true]]],
                    'recipient' => ['capabilities' => ['stripe_balance' => ['stripe_transfers' => ['requested' => true]]]]],
                'metadata' => $metadata, 'include' => ['defaults', 'configuration.merchant', 'configuration.recipient', 'identity'],
            ]];
    }

    private function isAccountsV2(array $frozen): bool
    {
        abort_unless(! array_key_exists('api_family', $frozen) || $frozen['api_family'] === 'accounts_v2', 409, 'Review the original payment account request before retrying.');

        return ($frozen['api_family'] ?? null) === 'accounts_v2';
    }

    private function sendAccountRequest(array $frozen): Response
    {
        if (! $this->isAccountsV2($frozen)) {
            return $this->stripe()->withHeaders(['Idempotency-Key' => $frozen['key']])->post('https://api.stripe.com/v1/accounts', $frozen['payload']);
        }
        abort_unless(($frozen['endpoint'] ?? null) === self::ACCOUNTS_V2_ENDPOINT && ($frozen['api_version'] ?? null) === self::ACCOUNTS_V2_VERSION, 409, 'The original Stripe account request version changed.');

        return Http::asJson()->withToken((string) config('services.stripe.secret'))->withoutRedirecting()->connectTimeout(5)->timeout(20)
            ->withHeaders(['Stripe-Version' => $frozen['api_version'], 'Idempotency-Key' => $frozen['key']])
            ->post($frozen['endpoint'], $frozen['payload']);
    }

    private function assertAccountsV2Response(Response $response, array $frozen): void
    {
        abort_unless($response->json('object') === 'v2.core.account' && $response->json('livemode') === str_starts_with((string) config('services.stripe.secret'), 'sk_live_')
            && $response->json('dashboard') === 'full' && $response->json('defaults.responsibilities.fees_collector') === 'stripe'
            && $response->json('defaults.responsibilities.losses_collector') === 'stripe'
            && $response->json('defaults.responsibilities.requirements_collector') === 'stripe'
            && strtoupper((string) $response->json('identity.country')) === 'US'
            && (string) $response->json('metadata.tenant_id') === (string) data_get($frozen, 'payload.metadata.tenant_id')
            && $response->json('metadata.tenant_slug') === data_get($frozen, 'payload.metadata.tenant_slug')
            && in_array('merchant', (array) $response->json('applied_configurations'), true)
            && in_array('recipient', (array) $response->json('applied_configurations'), true), 502, 'Stripe returned a payment account with different ownership or responsibilities.');
    }

    private function accountsV2Replacement(Tenant $tenant, User $actor, IntegrationConnection $original): IntegrationConnection
    {
        $old = json_decode($original->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
        abort_unless($original->status === 'rejected' && data_get($original->metadata, 'rejection') === 'stripe_accounts_v1_create_unsupported'
            && ! $this->isAccountsV2($old) && data_get($old, 'payload.type') === 'standard'
            && in_array($original->external_account_id, [$this->fingerprint(), $this->fingerprint().':standard-capabilities-v2'], true)
            && (string) data_get($old, 'payload.metadata.tenant_id') === (string) $tenant->id, 409);

        return DB::transaction(function () use ($tenant, $actor, $original, $old): IntegrationConnection {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $next = IntegrationConnection::query()->forTenant($tenant)->firstOrCreate(
                ['tenant_id' => $tenant->id, 'provider' => 'tenant_stripe_onboarding', 'external_account_id' => $this->fingerprint().':accounts-v2:'.$original->id],
                ['status' => 'pending', 'connected_by_user_id' => $actor->id,
                    'external_account_secret' => json_encode($this->newAccountsV2Request((string) data_get($old, 'payload.email'),
                        (string) data_get($old, 'payload.business_profile.name', $tenant->name), (array) data_get($old, 'payload.metadata')), JSON_THROW_ON_ERROR),
                    'metadata' => ['replaces_rejected_attempt_id' => $original->id, 'replacement_reason' => 'stripe_accounts_v1_create_unsupported']]
            );
            if ($next->wasRecentlyCreated) {
                app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id,
                    'tenant_payments.stripe.account_request_replaced', targetType: 'integration_connection', targetId: $next->id,
                    result: ['replaces_rejected_attempt_id' => $original->id, 'creation_api' => 'accounts_v2']);
            }

            return $next;
        });
    }

    private function capabilityReplacement(Tenant $tenant, User $actor, IntegrationConnection $original): IntegrationConnection
    {
        $frozen = json_decode($original->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
        abort_unless($original->status === 'rejected' && data_get($original->metadata, 'rejection') === 'stripe_missing_transfers_capability'
            && $original->external_account_id === $this->fingerprint() && data_get($frozen, 'payload.type') === 'standard'
            && data_get($frozen, 'payload.metadata.tenant_id') === (string) $tenant->id
            && data_get($frozen, 'payload.capabilities') === ['card_payments' => ['requested' => 'true']], 409);

        return DB::transaction(function () use ($tenant, $actor, $original, $frozen): IntegrationConnection {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $payload = $frozen['payload'];
            $payload['capabilities']['transfers'] = ['requested' => 'true'];

            return IntegrationConnection::query()->forTenant($tenant)->firstOrCreate(
                ['tenant_id' => $tenant->id, 'provider' => 'tenant_stripe_onboarding', 'external_account_id' => $this->fingerprint().':standard-capabilities-v2'],
                ['status' => 'pending', 'connected_by_user_id' => $actor->id,
                    'external_account_secret' => json_encode(['key' => (string) Str::uuid(), 'payload' => $payload], JSON_THROW_ON_ERROR),
                    'metadata' => ['replaces_rejected_attempt_id' => $original->id]]
            );
        });
    }

    public function hosted(Tenant $tenant, User $actor, string $email, string $returnUrl): string
    {
        $account = $this->account($tenant, $actor, $email);
        $response = $this->stripe()->post('https://api.stripe.com/v1/account_links', [
            'account' => $account->provider_account_id, 'refresh_url' => $returnUrl, 'return_url' => $returnUrl, 'type' => 'account_onboarding',
        ]);
        $this->assertSuccess($response);
        $url = (string) $response->json('url');
        abort_unless(parse_url($url, PHP_URL_SCHEME) === 'https' && parse_url($url, PHP_URL_HOST) === 'connect.stripe.com' && ! parse_url($url, PHP_URL_USER) && ! parse_url($url, PHP_URL_PORT), 502, 'Stripe onboarding link is unavailable.');

        return $url;
    }

    public function embedded(Tenant $tenant, User $actor): string
    {
        abort_unless(config('tenant_payments.stripe_embedded_enabled') && filled(config('services.stripe.publishable_key')), 423);
        $account = $this->account($tenant, $actor, $actor->email);
        $response = $this->stripe()->post('https://api.stripe.com/v1/account_sessions', [
            'account' => $account->provider_account_id, 'components' => ['account_onboarding' => ['enabled' => 'true']],
        ]);
        $this->assertSuccess($response);
        abort_unless(is_string($response->json('client_secret')) && filled($response->json('client_secret')), 502, 'Payment setup session is unavailable.');

        return $response->json('client_secret');
    }

    public function refresh(Tenant $tenant): TenantPaymentAccount
    {
        abort_unless(app(TenantPaymentsReadinessService::class)->canStartOnboarding($tenant), 423);
        $account = $tenant->paymentAccount()->firstOrFail();
        abort_unless($account->provider === 'stripe_connect' && preg_match('/^acct_[A-Za-z0-9]+$/D', (string) $account->provider_account_id), 409);
        $response = $this->stripe()->get('https://api.stripe.com/v1/accounts/'.$account->provider_account_id);
        $this->assertSuccess($response);
        abort_unless($response->json('id') === $account->provider_account_id
            && (data_get($account->metadata, 'ownership_verified_via') === 'oauth'
                || (string) $response->json('metadata.tenant_id') === (string) $tenant->id), 502, 'Stripe account ownership could not be verified.');
        if ($account->routingMode() === 'direct') {
            abort_unless($response->json('type') === 'standard', 409, 'This business requires its own full Stripe Dashboard account.');
        }
        $ready = $response->json('charges_enabled') === true && $response->json('payouts_enabled') === true;
        $account->update(['status' => $ready ? 'ready' : 'restricted', 'charges_enabled' => $response->json('charges_enabled') === true,
            'payouts_enabled' => $response->json('payouts_enabled') === true, 'details_submitted' => $response->json('details_submitted') === true,
            'last_checked_at' => now(), 'onboarding_completed_at' => $ready ? ($account->onboarding_completed_at ?? now()) : null,
            'metadata' => array_merge($account->metadata ?? [], ['requirements' => array_values(array_filter((array) $response->json('requirements.currently_due'), 'is_string')),
                'bank_payments_ready' => $response->json('capabilities.us_bank_account_ach_payments') === 'active',
                'purposes' => ['payment_processing', 'payout_bank']])]);

        return $account->fresh();
    }

    public function oauthUrl(Tenant $tenant, string $state, string $returnUrl): string
    {
        abort_unless(app(TenantPaymentsReadinessService::class)->canStartOnboarding($tenant)
            && filled(config('tenant_payments.stripe_oauth_client_id')), 423, 'Connecting an existing Stripe account is awaiting provider setup.');
        abort_if($tenant->paymentAccount()->exists(), 409, 'Continue the account already connected to this business.');

        return 'https://connect.stripe.com/oauth/authorize?'.http_build_query([
            'response_type' => 'code', 'client_id' => config('tenant_payments.stripe_oauth_client_id'),
            'scope' => 'read_write', 'state' => $state, 'redirect_uri' => $returnUrl,
        ]);
    }

    public function connectExisting(Tenant $tenant, User $actor, string $code, string $stateHash): TenantPaymentAccount
    {
        abort_unless(app(TenantPaymentsReadinessService::class)->canStartOnboarding($tenant), 423);
        $attempt = DB::transaction(function () use ($tenant, $actor, $stateHash) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_if($tenant->paymentAccount()->exists(), 409, 'This business already has a payment account.');
            abort_if(IntegrationConnection::query()->forTenant($tenant)->where('provider', 'tenant_stripe_oauth')
                ->where('external_account_id', $this->fingerprint().':'.$stateHash)->exists(), 409, 'This connection attempt needs reconciliation before retrying.');

            return IntegrationConnection::create(['tenant_id' => $tenant->id, 'provider' => 'tenant_stripe_oauth',
                'external_account_id' => $this->fingerprint().':'.$stateHash, 'status' => 'requesting',
                'connected_by_user_id' => $actor->id, 'metadata' => ['purpose' => 'payment_processing']]);
        });
        $response = $this->stripe()->post('https://connect.stripe.com/oauth/token', ['grant_type' => 'authorization_code', 'code' => $code]);
        $this->assertSuccess($response);
        $id = (string) $response->json('stripe_user_id');
        abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $id)
            && $response->json('livemode') === str_starts_with((string) config('services.stripe.secret'), 'sk_live_')
            && $response->json('scope') === 'read_write', 502, 'Stripe did not authorize the requested business connection.');
        $attempt->update(['external_account_secret' => json_encode(['merchant_id' => $id, 'authorization' => $response->json()], JSON_THROW_ON_ERROR)]);
        $remote = $this->stripe()->get('https://api.stripe.com/v1/accounts/'.$id);
        $this->assertSuccess($remote);
        abort_unless($remote->json('id') === $id && $remote->json('type') === 'standard', 409, 'Connect a business-owned account with full Stripe Dashboard access.');

        $account = DB::transaction(function () use ($tenant, $actor, $id, $attempt) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            abort_if($tenant->paymentAccount()->exists() || TenantPaymentAccount::withoutGlobalScopes()->where('provider_account_id', $id)->exists(), 409, 'This Stripe account is already bound to a workspace.');
            $account = TenantPaymentAccount::create(['tenant_id' => $tenant->id, 'provider' => 'stripe_connect', 'provider_account_id' => $id,
                'status' => 'onboarding', 'onboarding_started_at' => now(), 'metadata' => ['setup_fingerprint' => $this->fingerprint(),
                    'routing_mode' => 'direct', 'ownership_verified_via' => 'oauth', 'purposes' => ['payment_processing', 'payout_bank']]]);
            $attempt->update(['status' => 'connected', 'connected_at' => now()]);
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, 'tenant_payments.stripe.oauth_connected', targetType: 'tenant_payment_account', targetId: $account->id);

            return $account;
        });

        return $this->refresh($tenant);
    }

    private function fingerprint(): string
    {
        $platform = (string) config('services.stripe.account_id');
        abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $platform), 423, 'Everbranch must configure its Stripe platform account before connecting businesses.');

        return hash('sha256', $platform.'|'.(str_starts_with((string) config('services.stripe.secret'), 'sk_live_') ? 'live' : 'test'));
    }

    private function assertSuccess(Response $response): void
    {
        $providerMessage = (string) $response->json('error.message');
        $message = match (true) {
            str_contains($providerMessage, 'signed up for Connect') => 'Everbranch must activate Stripe Connect before your business can connect. Your email address is not the problem.',
            str_contains($providerMessage, 'complete your platform profile') => 'Everbranch must complete its Stripe Connect platform profile before your business can connect. Your email address is not the problem.',
            default => 'Stripe could not complete payment setup. Please try again or contact Everbranch support.',
        };
        abort_unless($response->successful(), 502, $message);
    }
}
