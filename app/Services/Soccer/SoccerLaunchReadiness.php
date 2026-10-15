<?php

namespace App\Services\Soccer;

use App\Models\Tenant;
use App\Models\TenantMessagingAccount;

class SoccerLaunchReadiness
{
    public function blockers(Tenant $t): array
    {
        $b = app(\App\Services\Billing\TenantPaymentsReadinessService::class)->readinessFor($t)['blockers'];
        if (strlen((string) config('soccer.bridge_secret')) < 32) {
            $b[] = 'Website bridge is not configured.';
        }
        if (strlen((string) config('soccer.stripe_webhook_secret')) < 16) {
            $b[] = 'Soccer Stripe webhook is not configured.';
        }
        if (! config('soccer.sms_enabled') || ! TenantMessagingAccount::query()->forAllTenants()->where('tenant_id', $t->id)->where('channel', 'sms')->get()->contains(fn ($a) => $a->isReady())) {
            $b[] = 'Wren SMS sender is not ready.';
        }
        if (! config('marketing.sms.enabled') || config('marketing.sms.dry_run')) {
            $b[] = 'Live SMS delivery must be enabled and dry-run disabled.';
        }
        $usage = app(\App\Services\Marketing\Messaging\TenantMessagingUsageService::class);
        $balance = $usage->summary($t->id, 'sms');
        if ($balance['included_units'] - $balance['used_units'] - $balance['reserved_units'] < 3 && $balance['credit_available_micros'] < 3 * $balance['overage_rate_micros'] && ! $usage->postpaidAuthorizedForChannel($t->id, 'sms')) {
            $b[] = 'Wren needs available SMS units or messaging credit.';
        }
        if (in_array(config('mail.default'), ['log', 'array', 'failover'], true)) {
            $b[] = 'Sign-in email delivery must use a verified live mail transport.';
        }
        $s = app(SoccerStore::class)->settings($t->id);
        if (count(array_filter($s['groupme_groups'] ?? [])) !== 3) {
            $b[] = 'Connect the three existing GroupMe groups.';
        }
        $groupActor = \App\Models\User::find($s['verified_group_user_id'] ?? 0);
        if (($s['verified_group_ids'] ?? []) !== ($s['groupme_groups'] ?? []) || ! $groupActor || ! app(SoccerAccess::class)->admin($t, $groupActor) || ! app(SoccerStore::class)->query('groupme_connections', $t->id)->where('user_id', $groupActor->id)->exists()) {
            $b[] = 'An admin must connect GroupMe and verify membership in all three mapped groups.';
        }
        if (! (bool) ($s['launch_verified'] ?? false)) {
            $b[] = 'Admin delivery and payment smoke verification is incomplete.';
        }

        return $b;
    }
}
