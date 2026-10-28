<?php

namespace App\Services\Subscriptions;

use App\Models\ShopifyStore;
use App\Models\SubscriptionPilotCycle;
use App\Models\SubscriptionPilotEnrollment;
use App\Services\Mobile\ModernForestryMobileCustomerSession;
use Illuminate\Support\Facades\Schema;

/** The authenticated Shopify customer identity, never name/email/merged CRM permission, selects the subscription. */
class SubscriptionPilotMobileAccess
{
    public function enrollment(ModernForestryMobileCustomerSession $session): ?SubscriptionPilotEnrollment
    {
        if (($session->identity['source_type'] ?? null) !== 'shopify_customer' || ! Schema::hasTable('subscription_pilot_enrollments')) {
            return null;
        }
        $tenantId = (int) $session->profile->tenant_id;
        $id = (string) ($session->identity['shopify_customer_id'] ?? '');
        if (preg_match('#^gid://shopify/Customer/([1-9][0-9]*)$#D', $id, $match)) {
            $id = $match[1];
        }
        if (! preg_match('/^[1-9][0-9]*$/D', $id)) {
            return null;
        }
        $store = ShopifyStore::query()->forTenantId($tenantId)->where('store_key', 'retail')->first();
        if (! $store) {
            return null;
        }
        $row = SubscriptionPilotEnrollment::query()->forTenantId($tenantId)->join('subscription_pilot_members as pilot_member', 'pilot_member.id', '=', 'subscription_pilot_enrollments.member_id')
            ->where('pilot_member.tenant_id', $tenantId)->where('pilot_member.shopify_store_id', $store->id)
            ->where('pilot_member.shopify_customer_id', $id)->where('pilot_member.billing_owner', 'stripe')
            ->select('subscription_pilot_enrollments.*')->first();
        if (! $row) {
            return null;
        }
        $terms = $row->terms;
        abort_unless($terms['shopify_store_id'] === $store->id && $terms['shop_domain'] === $store->shop_domain
            && $terms['shopify_customer_id'] === $id && $row->tenant_id === $tenantId, 409, 'The original subscription customer or store changed.');

        return $row;
    }

    public function payload(ModernForestryMobileCustomerSession $session, array $legacy): array
    {
        $row = $this->enrollment($session);
        if (! $row) {
            return $legacy;
        }
        $terms = $row->terms;
        $paused = $row->status === 'paused' || ($row->paused_until && $row->paused_until->toDateString() > now($terms['timezone'])->toDateString());
        $processing = $row->next_billing_date && SubscriptionPilotCycle::query()->forTenantId($row->tenant_id)->where('enrollment_id', $row->id)
            ->whereDate('cycle_date', $row->next_billing_date->toDateString())->whereNotNull('payment_started_at')->exists();
        $next = $row->next_billing_date?->format('F j, Y');
        $message = $row->status === 'cancelled' ? 'Future subscription charges are cancelled.' : ($paused ? 'Subscription paused. '.($row->paused_until ? 'Scheduled to resume '.$next.'.' : 'Use your secure subscription page for support.')
            : $terms['currency'].' '.SubscriptionPilotShopifyBridge::decimal($terms['amount_cents']).' every '.$terms['billing_months'].' month(s). '.($next ? 'Next billing: '.$next.'.' : ''));

        return array_replace($legacy, ['eligible' => $row->status === 'active' && ! $paused, 'status' => $paused ? 'paused' : $row->status, 'message' => $message,
            'contract' => null, 'commitment' => null, 'payment_method' => null, 'action_mode' => 'everbranch_subscription',
            'actions' => ['can_vote' => false, 'can_pause' => $row->status === 'active' && ! $paused && ! $processing, 'can_cancel' => in_array($row->status, ['active', 'paused', 'authorized'], true),
                'can_update_address' => false, 'can_update_card' => false, 'can_swap_to_active_16oz_scent' => false],
            'action_menus' => ['swap_options' => [], 'pause_duration_options' => [$terms['billing_months'], 2 * $terms['billing_months']]],
            'everbranch_subscription' => ['status' => $row->status, 'next_billing_date' => $row->next_billing_date?->toDateString(),
                'amount_cents' => $terms['amount_cents'], 'currency' => $terms['currency'], 'billing_months' => $terms['billing_months'],
                'manage_endpoint' => route('subscriptions.pilot.mobile-manage')]]);
    }

    public function action(ModernForestryMobileCustomerSession $session, array $input): ?array
    {
        $row = $this->enrollment($session);
        if (! $row) {
            return null;
        }
        $action = $input['action'] ?? '';
        abort_unless(in_array($action, ['pause', 'cancel'], true), 422, 'Use the secure subscription page to review this change.');
        $execution = app(SubscriptionPilotExecutionService::class);
        $current = $action === 'pause' ? $execution->skipCycles($row, (int) ($input['duration_months'] ?? 0)) : $execution->stop($row, 'cancel');

        return ['ok' => true, 'status' => 'completed', 'action' => $action, 'message' => $action === 'pause' ? 'Subscription paused until '.$current->next_billing_date->format('F j, Y').'.' : 'Future subscription charges are cancelled.',
            'shopify_mode' => 'fulfillment', 'shopify_mutation' => null, 'candle_club' => $this->payload($session, [])];
    }
}
