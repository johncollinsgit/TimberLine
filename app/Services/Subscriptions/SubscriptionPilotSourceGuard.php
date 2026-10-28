<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionPilotEvent;
use App\Models\SubscriptionPilotMember;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\User;
use Carbon\CarbonImmutable;

/** Fresh provider reads, never a manual checkbox or a retained shadow, authorize cutover. */
class SubscriptionPilotSourceGuard
{
    public function stopped(SubscriptionPilotMember $member, int $connectionId, string $cycleDate, User $actor, ?string $ownOrderId = null): array
    {
        $started = microtime(true);
        app(SubscriptionAccessService::class)->assertManager($member->tenant_id, $actor);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $cycleDate, $member->timezone);
        abort_unless($date && $date->format('Y-m-d') === $cycleDate && $date->gte(now($member->timezone)->subDays(7)->startOfDay()), 409, 'A recent or future cycle is required.');
        $connection = SubscriptionRechargeConnection::query()->forTenantId($member->tenant_id)->findOrFail($connectionId);
        $ready = app(SubscriptionRechargeReadService::class)->readiness($member->tenant_id, $connectionId);
        abort_unless($ready['identity_verified'] && $ready['credential_ready'] && $ready['reads_enabled'] && ! $connection->read_pause_reason, 423, 'Verified Recharge reads are required before a pilot can take over billing.');
        $store = ShopifyStore::query()->forTenantId($member->tenant_id)->findOrFail($member->shopify_store_id);
        abort_unless($connection->platform === 'shopify' && $connection->identifier === $store->shop_domain && $store->shop_domain === $member->details['shop_domain'], 409, 'The source store identity changed.');
        $revision = SubscriptionRechargeCredentialRevision::query()->forTenantId($member->tenant_id)
            ->where('connection_id', $connection->id)->findOrFail($connection->active_credential_revision_id);
        $transport = app(SubscriptionRechargeTransport::class);
        $source = $transport->read($connection, $revision, 'store')['store'] ?? [];
        abort_unless((string) ($source['id'] ?? '') === $connection->provider_store_id
            && ($source['identifier'] ?? null) === $store->shop_domain && ($source['external_platform'] ?? null) === 'shopify', 409, 'Recharge store ownership changed.');
        $information = $transport->read($connection, $revision, 'token_information')['token_information'] ?? [];
        abort_unless(is_array($information['scopes'] ?? null) && ! array_diff(['read_store', 'read_customers', 'read_subscriptions', 'read_orders'], $information['scopes']), 423, 'Recharge customer, subscription and charge history read access is required.');
        $customer = $transport->read($connection, $revision, 'customer', ['id' => $member->recharge_customer_id])['customer'] ?? [];
        abort_unless((string) ($customer['id'] ?? '') === $member->recharge_customer_id
            && (string) data_get($customer, 'external_customer_id.ecommerce') === $member->shopify_customer_id
            && ($customer['subscriptions_active_count'] ?? null) === 0, 409, 'Recharge still has active or unverified customer billing.');
        $subscriptions = [];
        foreach ($member->details['lines'] as $line) {
            abort_unless(! empty($line['subscription_id']), 409, 'Exact source subscriptions are required.');
            $row = $transport->read($connection, $revision, 'subscription', ['id' => $line['subscription_id']])['subscription'] ?? [];
            abort_unless((string) ($row['id'] ?? '') === $line['subscription_id'] && (string) ($row['customer_id'] ?? '') === $member->recharge_customer_id
                && in_array($row['status'] ?? null, ['cancelled', 'expired'], true) && ($row['has_queued_charges'] ?? null) === false
                && array_key_exists('next_charge_scheduled_at', $row) && $row['next_charge_scheduled_at'] === null, 409, 'Recharge billing or queued activity has not been confirmed stopped.');
            $subscriptions[] = ['id' => $line['subscription_id'], 'status' => $row['status'], 'has_queued_charges' => false, 'next_charge_scheduled_at' => null];
        }
        foreach (['queued,error,pending,pending_manual_payment', 'success,refunded,partially_refunded'] as $statuses) {
            $parameters = ['customer_id' => $member->recharge_customer_id, 'status' => $statuses];
            if (str_starts_with($statuses, 'success')) {
                $parameters['processed_at_min'] = $date->utc()->toDateString();
            }
            $page = $transport->read($connection, $revision, 'pilot_charges', $parameters);
            abort_unless(isset($page['charges']) && is_array($page['charges']) && array_is_list($page['charges'])
                && array_key_exists('next_cursor', $page) && ! $page['next_cursor'] && $page['charges'] === [], 409, 'Recharge charge history is not clear or complete for this pilot cycle.');
        }
        app(SubscriptionPilotShopifyReadService::class)->refresh($member->tenant_id, $actor, $member->id, $cycleDate);
        abort_if(SubscriptionPilotEvent::query()->forTenantId($member->tenant_id)->where('member_id', $member->id)
            ->where('provider', 'shopify')->where('kind', 'order')->where('status', 'success')->whereDate('cycle_date', $cycleDate)
            ->when($ownOrderId, fn ($query) => $query->where('provider_reference', '!=', $ownOrderId))->exists(), 409, 'A paid Shopify subscription order already exists for this cycle.');
        $fresh = $connection->fresh();
        abort_unless($fresh->status === 'verified' && ! $fresh->read_pause_reason && $fresh->active_credential_revision_id === $revision->id
            && ! $revision->fresh()->revoked_at && microtime(true) - $started < 60, 409, 'Source billing proof changed during review.');
        app(SubscriptionAccessService::class)->assertManager($member->tenant_id, $actor->fresh());

        return ['checked_at' => now()->toIso8601String(), 'connection_id' => $connection->id, 'credential_revision_id' => $revision->id,
            'source_customer_id' => $member->recharge_customer_id, 'shopify_customer_id' => $member->shopify_customer_id,
            'subscriptions' => $subscriptions, 'pending_charges' => 0, 'collected_cycle_charges' => 0, 'paid_shopify_cycle_orders' => 0];
    }
}
