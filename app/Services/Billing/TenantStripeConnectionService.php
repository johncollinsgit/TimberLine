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
            $attempt = IntegrationConnection::query()->forTenant($tenant)->firstOrCreate(
                ['tenant_id' => $tenant->id, 'provider' => 'tenant_stripe_onboarding', 'external_account_id' => $this->fingerprint()],
                ['status' => 'pending', 'connected_by_user_id' => $actor->id, 'external_account_secret' => json_encode([
                    'key' => (string) Str::uuid(),
                    'payload' => ['type' => 'express', 'country' => 'US', 'email' => $email,
                        'capabilities' => ['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']],
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

        return DB::transaction(function () use ($tenant, $actor, $id, $attempt) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $account = $tenant->paymentAccount()->first();
            abort_if($account?->provider_account_id && $account->provider_account_id !== $id, 409, 'Payment account changed. Refresh before continuing.');
            if ($account?->provider_account_id === $id) {
                return $account;
            }
            $account ??= new TenantPaymentAccount(['tenant_id' => $tenant->id]);
            $account->fill(['provider' => 'stripe_connect', 'provider_account_id' => $id, 'status' => 'onboarding',
                'onboarding_started_at' => now(), 'metadata' => array_merge($account->metadata ?? [], ['setup_fingerprint' => $this->fingerprint()])])->save();
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
            && (string) $response->json('metadata.tenant_id') === (string) $tenant->id, 502, 'Stripe account ownership could not be verified.');
        $ready = $response->json('charges_enabled') === true && $response->json('payouts_enabled') === true;
        $account->update(['status' => $ready ? 'ready' : 'restricted', 'charges_enabled' => $response->json('charges_enabled') === true,
            'payouts_enabled' => $response->json('payouts_enabled') === true, 'details_submitted' => $response->json('details_submitted') === true,
            'last_checked_at' => now(), 'onboarding_completed_at' => $ready ? ($account->onboarding_completed_at ?? now()) : null]);

        return $account->fresh();
    }

    private function fingerprint(): string
    {
        $platform = (string) config('services.stripe.account_id');
        abort_unless(preg_match('/^acct_[A-Za-z0-9]+$/D', $platform), 423, 'Everbranch must configure its Stripe platform account before connecting businesses.');

        return hash('sha256', $platform.'|'.(str_starts_with((string) config('services.stripe.secret'), 'sk_live_') ? 'live' : 'test'));
    }

    private function assertSuccess(Response $response): void
    {
        $message = str_contains((string) $response->json('error.message'), 'signed up for Connect')
            ? 'Everbranch must activate Stripe Connect before your business can connect. Your email address is not the problem.'
            : 'Stripe could not complete payment setup. Please try again or contact Everbranch support.';
        abort_unless($response->successful(), 502, $message);
    }
}
