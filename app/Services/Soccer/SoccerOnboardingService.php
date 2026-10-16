<?php

namespace App\Services\Soccer;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Billing\TenantStripeConnectionService;

class SoccerOnboardingService
{
    public function start(Tenant $tenant, User $actor, string $email): string
    {
        try {
            return app(TenantStripeConnectionService::class)->hosted($tenant, $actor, $email,
                route('soccer.workspace', ['section' => 'settings', 'tenant' => $tenant->slug]));
        } catch (\Illuminate\Http\Client\ConnectionException) {
            abort(502, 'Stripe could not be reached. Your setup is saved; please try again.');
        }
    }

    public function refresh(Tenant $tenant, User $actor): void
    {
        $account = app(TenantStripeConnectionService::class)->refresh($tenant);
        app(SoccerEvents::class)->record($tenant->id, null, null, 'card_account_checked',
            ['ready' => $account->isReady(), 'account_id' => $account->provider_account_id], $actor->id);
    }
}
