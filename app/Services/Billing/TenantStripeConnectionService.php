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
                ['status' => 'pending', 'connected_by_user_id' => $actor->id, 'external_account_secret' => json_encode([
                    'key' => (string) Str::uuid(),
                    'payload' => ['type' => $express ? 'express' : 'standard', 'country' => 'US', 'email' => $email,
                        'capabilities' => $express ? ['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']] : ['card_payments' => ['requested' => 'true']],
                        'business_profile' => ['name' => $tenant->name, 'product_description' => 'Customer payments for '.$tenant->name],
                        'metadata' => ['tenant_id' => (string) $tenant->id, 'tenant_slug' => $tenant->slug]],
                ], JSON_THROW_ON_ERROR)]
            );

            return $attempt;
        });
        if ($attempt instanceof TenantPaymentAccount) {
            return $attempt;
        }

        abort_if($attempt->created_at->lt(now()->subHours(23)), 409, 'This payment setup attempt needs support review before retrying. This prevents creating a duplicate account.');

        $frozen = json_decode($attempt->external_account_secret, true, flags: JSON_THROW_ON_ERROR);
        $response = $this->stripe()->withHeaders(['Idempotency-Key' => $frozen['key']])->post('https://api.stripe.com/v1/accounts', $frozen['payload']);
        $this->assertSuccess($response);
        $id = (string) $response->json('id');
        abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $id), 502, 'Stripe returned an invalid payment account.');

        return DB::transaction(function () use ($tenant, $actor, $id, $attempt, $frozen) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $account = $tenant->paymentAccount()->first();
            abort_if($account?->provider_account_id && $account->provider_account_id !== $id, 409, 'Payment account changed. Refresh before continuing.');
            if ($account?->provider_account_id === $id) {
                return $account;
            }
            $account ??= new TenantPaymentAccount(['tenant_id' => $tenant->id]);
            $account->fill(['provider' => 'stripe_connect', 'provider_account_id' => $id, 'status' => 'onboarding',
                'onboarding_started_at' => now(), 'metadata' => array_merge($account->metadata ?? [], ['setup_fingerprint' => $this->fingerprint(),
                    'routing_mode' => $frozen['payload']['type'] === 'standard' ? 'direct' : 'destination',
                    'ownership_verified_via' => 'created_account', 'purposes' => ['payment_processing', 'payout_bank']])])->save();
            $attempt->update(['status' => 'connected', 'connected_at' => now()]);
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $actor->id, 'tenant_payments.stripe.connected', targetType: 'tenant_payment_account', targetId: $account->id);

            return $account;
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
