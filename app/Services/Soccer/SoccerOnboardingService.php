<?php

namespace App\Services\Soccer;

use App\Models\Tenant;
use App\Models\TenantPaymentAccount;
use App\Models\User;
use App\Services\Billing\TenantPaymentsReadinessService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class SoccerOnboardingService
{
    private function stripe()
    {
        return Http::asForm()->withBasicAuth(config('services.stripe.secret'), '')->timeout(20);
    }

    public function start(Tenant $tenant, User $actor, string $email): string
    {
        abort_unless(app(TenantPaymentsReadinessService::class)->canStartOnboarding($tenant), 423, 'Stripe Connect onboarding is waiting for the platform payment gates.');
        $account = DB::transaction(function () use ($tenant, $actor, $email) {
            Tenant::whereKey($tenant->id)->lockForUpdate()->firstOrFail();
            $a = $tenant->paymentAccount()->first();
            if (! $a?->provider_account_id) {
                $r = $this->stripe()->withHeaders(['Idempotency-Key' => 'wren-connect-account-'.$tenant->id])->post('https://api.stripe.com/v1/accounts', ['type' => 'express', 'country' => 'US', 'email' => $email, 'capabilities' => ['card_payments' => ['requested' => 'true'], 'transfers' => ['requested' => 'true']], 'business_profile' => ['url' => config('soccer.origin')], 'metadata' => ['tenant_id' => (string) $tenant->id, 'tenant_slug' => $tenant->slug]]);
                abort_unless($r->successful() && str_starts_with((string) $r->json('id'), 'acct_'), 502, 'Stripe could not start account onboarding.');
                $a = TenantPaymentAccount::query()->create(['tenant_id' => $tenant->id, 'provider' => 'stripe_connect', 'provider_account_id' => $r->json('id'), 'status' => 'onboarding', 'platform_fee_bps' => 0, 'onboarding_started_at' => now(), 'metadata' => ['source' => 'wren_soccer', 'actor' => $actor->id]]);
                app(SoccerEvents::class)->record($tenant->id, null, null, 'card_onboarding_started', ['account_id' => $a->provider_account_id, 'platform_fee_bps' => 0], $actor->id);
            }

            return $a;
        });
        $url = route('soccer.workspace', ['section' => 'settings', 'tenant' => $tenant->slug]);
        $r = $this->stripe()->post('https://api.stripe.com/v1/account_links', ['account' => $account->provider_account_id, 'refresh_url' => $url, 'return_url' => $url, 'type' => 'account_onboarding']);
        abort_unless($r->successful() && filter_var($r->json('url'), FILTER_VALIDATE_URL) && str_starts_with($r->json('url'), 'https://connect.stripe.com/'), 502, 'Stripe onboarding link is unavailable.');

        return $r->json('url');
    }

    public function refresh(Tenant $tenant, User $actor): void
    {
        $a = $tenant->paymentAccount()->firstOrFail();
        abort_unless($a->provider_account_id, 422);
        $r = $this->stripe()->get('https://api.stripe.com/v1/accounts/'.$a->provider_account_id);
        abort_unless($r->successful(), 502, 'Stripe account verification is temporarily unavailable.');
        $ready = (bool) $r->json('charges_enabled') && (bool) $r->json('payouts_enabled');
        $a->update(['charges_enabled' => (bool) $r->json('charges_enabled'), 'payouts_enabled' => (bool) $r->json('payouts_enabled'), 'details_submitted' => (bool) $r->json('details_submitted'), 'status' => $ready ? 'ready' : 'restricted', 'last_checked_at' => now(), 'onboarding_completed_at' => $ready ? now() : null]);
        app(SoccerEvents::class)->record($tenant->id, null, null, 'card_account_checked', ['ready' => $ready, 'account_id' => $a->provider_account_id], $actor->id);
    }
}
