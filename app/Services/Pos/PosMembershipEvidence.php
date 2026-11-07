<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionPilotMember;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeResource;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use Illuminate\Support\Facades\Schema;

/** Native subscription authority only. Tags, names and old purchases cannot grant a larger spending limit. */
final class PosMembershipEvidence
{
    public function active(MarketingProfile $profile): ?array
    {
        if (! Schema::hasTable('subscription_billing_agreements') || ! Schema::hasTable('subscription_pilot_members')) {
            return null;
        }
        $tenant = (int) $profile->tenant_id;
        foreach (SubscriptionPilotMember::query()->forTenantId($tenant)->whereNotNull('marketing_profile_id')->where('status', 'active')->limit(1000)->get() as $member) {
            $canonical = app(CanonicalMarketingProfileResolver::class)->byId($tenant, (int) $member->marketing_profile_id);
            if (! $canonical || (int) $canonical->id !== (int) $profile->id || $member->billing_owner !== 'everbranch') {
                continue;
            }
            $agreement = SubscriptionBillingAgreement::query()->forTenantId($tenant)->where('member_id', $member->id)
                ->where('status', 'active')->whereNotNull('activated_at')->whereNotNull('method_verified_at')->first();
            if ($agreement) {
                return ['source' => 'subscription_billing_agreement', 'id' => $agreement->public_id,
                    'revision' => (int) $agreement->revision, 'observed_at' => now()->toIso8601String()];
            }
        }

        return null;
    }

    /** Only provider-created dates with an explicit tenant/store/customer mapping establish a join. */
    public function history(MarketingProfile $profile): array
    {
        $dates = [];
        $complete = true;
        $mapped = 0;
        if (! Schema::hasTable('subscription_recharge_connections') || ! Schema::hasTable('subscription_recharge_resources')) {
            return ['joined_at' => [], 'coverage_complete' => false];
        }
        $tenant = (int) $profile->tenant_id;
        $members = SubscriptionPilotMember::query()->forTenantId($tenant)->whereNotNull('marketing_profile_id')->limit(1001)->get();
        if ($members->count() > 1000) {
            return ['joined_at' => [], 'coverage_complete' => false];
        }
        foreach ($members as $member) {
            if (app(CanonicalMarketingProfileResolver::class)->byId($tenant, (int) $member->marketing_profile_id)?->id !== $profile->id) {
                continue;
            }
            $mapped++;
            $memberComplete = false;
            foreach (SubscriptionRechargeConnection::query()->forTenantId($tenant)->where('shopify_store_id', $member->shopify_store_id)
                ->where('status', 'verified')->whereNotNull('last_complete_sync_at')->get() as $connection) {
                $generation = data_get($connection->sync_state, 'last_complete.generation_id');
                if (! is_string($generation) || $generation === '') {
                    continue;
                }
                $subscriptions = SubscriptionRechargeResource::query()->forTenantId($tenant)->where('connection_id', $connection->id)
                    ->where('generation_id', $generation)->where('customer_provider_id', $member->recharge_customer_id)->where('resource_type', 'subscription')->get();
                $valid = $subscriptions->isNotEmpty();
                foreach ($subscriptions as $subscription) {
                    $source = $subscription->snapshot;
                    $date = $source['created_at'] ?? null;
                    if ($subscription->completeness !== 'complete' || $subscription->stale_reason !== null
                        || (string) ($source['id'] ?? '') !== (string) $subscription->provider_id
                        || ! is_string($date) || strtotime($date) === false || strtotime($date) > time()) {
                        $valid = false;

                        continue;
                    }
                    $dates[] = $date;
                }
                $memberComplete = $memberComplete || $valid;
            }
            $complete = $complete && $memberComplete;
        }

        return ['joined_at' => array_values(array_unique($dates)), 'coverage_complete' => $mapped > 0 && $complete];
    }
}
