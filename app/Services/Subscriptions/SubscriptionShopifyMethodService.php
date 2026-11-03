<?php

namespace App\Services\Subscriptions;

use App\Models\SubscriptionBillingAction;
use App\Models\SubscriptionBillingAgreement;
use App\Models\SubscriptionRechargeConnection;
use App\Models\SubscriptionRechargeCredentialRevision;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Current Shopify vault access plus exact original Recharge assignment; never last-four/email matching. */
class SubscriptionShopifyMethodService
{
    public function verify(int $tenantId, User $actor, int $agreementId, string $orderId, bool $persist = true): array
    {
        $row = SubscriptionBillingAgreement::query()->forTenantId($tenantId)->findOrFail($agreementId);
        $access = app(SubscriptionReplacementAccess::class);
        $access->owner($tenantId, $actor, $row->member_id, 'setup');
        $member = $access->member($row);
        abort_unless($row->processor === 'shopify' && (($row->status === 'prepared' && ! $row->activated_at)
            || (! $persist && $row->activated_at && $member->billing_owner === 'everbranch' && in_array($row->status, ['active', 'paused'], true))), 409);
        $original = SubscriptionBillingAuthorization::original($row);
        $sourceContext = $this->sourceContext($row);
        $transport = app(SubscriptionShopifyBillingTransport::class);
        $context = $transport->context($row);
        $data = $transport->method($row);
        $method = $data['customerPaymentMethod'] ?? [];
        $instrument = $method['instrument'] ?? [];
        $type = $instrument['__typename'] ?? null;
        $today = now($row->terms['timezone']);
        abort_unless(($method['id'] ?? null) === $row->method_binding['payment_method_gid']
            && data_get($method, 'customer.id') === 'gid://shopify/Customer/'.$row->terms['shopify_customer_id']
            && array_key_exists('revokedAt', $method) && $method['revokedAt'] === null
            && in_array($type, ['CustomerCreditCard', 'CustomerShopPayAgreement'], true)
            && ($type !== 'CustomerShopPayAgreement' || ($instrument['inactive'] ?? null) === false)
            && is_int($instrument['expiryMonth'] ?? null) && $instrument['expiryMonth'] >= 1 && $instrument['expiryMonth'] <= 12
            && is_int($instrument['expiryYear'] ?? null) && $instrument['expiryYear'] <= 9999
            && ($instrument['expiryYear'] > $today->year || ($instrument['expiryYear'] === $today->year && $instrument['expiryMonth'] >= $today->month))
            && is_string($instrument['lastDigits'] ?? null) && preg_match('/\A[0-9]{4}\z/', $instrument['lastDigits']), 409, 'The original saved Shopify payment method is unavailable, revoked, inactive or expired.');
        $order = $transport->originalOrder($row, $orderId)['order'] ?? [];
        $this->order($row, $order, $orderId);
        $source = $this->source($row, $order);
        abort_unless($source['credential_context'] === $sourceContext, 409, 'The original source credential changed.');
        $access->owner($tenantId, $actor->fresh(), $row->member_id, 'setup');
        $access->member($row->fresh());
        abort_unless($context === $transport->context($row->fresh()), 409);
        $proof = ['provider_verified' => true, 'original_method_accessible' => true, 'reauthorization_required' => null, 'checked_at' => now()->toISOString(),
            'method_type' => $type === 'CustomerShopPayAgreement' ? 'shop_pay' : 'card', 'last_four' => $instrument['lastDigits'],
            'processor_context' => $context, 'source' => $source + ['source_order_id' => $orderId, 'source_updated_at' => $order['updatedAt']],
            'terms_hash' => $row->terms_hash, 'original_terms_hash' => $original['terms_hash'], 'contract_adopted' => false, 'collection_enabled' => false];
        if ($persist) {
            DB::transaction(function () use ($row, $actor, $proof, $context, $source) {
                $current = SubscriptionBillingAgreement::query()->forTenantId($row->tenant_id)->whereKey($row->id)->lockForUpdate()->firstOrFail();
                app(SubscriptionReplacementAccess::class)->owner($row->tenant_id, $actor->fresh(), $row->member_id, 'setup');
                abort_unless($current->status === 'prepared' && $current->revision === $row->revision && $current->terms_hash === $row->terms_hash
                    && app(SubscriptionShopifyBillingTransport::class)->context($current) === $context
                    && $this->sourceContext($current) === $source['credential_context'], 409, 'The original Shopify/source mapping changed during verification.');
                $evidence = $current->authorization_evidence;
                $evidence['status'] = 'method_verified';
                $evidence['provider_verified'] = true;
                $evidence['provider_proof'] = $proof;
                $current->update(['method_verified_at' => now(), 'authorization_evidence' => $evidence]);
                SubscriptionBillingAction::create(['tenant_id' => $row->tenant_id, 'agreement_id' => $row->id, 'request_uuid' => (string) Str::uuid(),
                    'kind' => 'method_verified', 'actor_type' => 'user', 'actor_reference' => (string) $actor->id, 'expected_revision' => $current->revision,
                    'request' => ['source_order_id' => $proof['source']['source_order_id'], 'terms_hash' => $current->terms_hash],
                    'before_state' => ['method_verified' => $row->method_verified_at !== null], 'after_state' => $proof]);
            });
        }

        return $proof;
    }

    private function order(SubscriptionBillingAgreement $row, array $order, string $id): void
    {
        $terms = SubscriptionBillingAuthorization::original($row)['terms'];
        $attributes = collect($order['customAttributes'] ?? [])->pluck('value', 'key');
        $ids = array_filter(array_map('trim', explode(',', (string) $attributes->get('rc_subscription_ids', ''))));
        $expected = array_column($terms['lines'], 'subscription_id');
        sort($ids);
        sort($expected);
        abort_unless(($order['id'] ?? null) === 'gid://shopify/Order/'.$id && data_get($order, 'customer.id') === 'gid://shopify/Customer/'.$terms['shopify_customer_id']
            && ($order['test'] ?? null) === ($row->method_binding['environment'] === 'sandbox') && empty($order['cancelledAt'])
            && ($order['displayFinancialStatus'] ?? null) === 'PAID' && $ids === $expected && $expected !== []
            && data_get($order, 'lineItems.pageInfo.hasNextPage') === false, 409, 'The paid source order differs from the original subscription.');
        foreach (['totalPriceSet' => 'amount_cents', 'totalShippingPriceSet' => 'shipping_cents', 'totalTaxSet' => 'tax_cents'] as $field => $term) {
            abort_unless(data_get($order, $field.'.shopMoney.currencyCode') === $terms['currency']
                && SubscriptionPilotShopifyBridge::cents((string) data_get($order, $field.'.shopMoney.amount')) === $terms[$term], 409, 'Source order economics differ from the reviewed terms.');
        }
        $lines = collect(data_get($order, 'lineItems.nodes', []))->map(fn ($line) => [data_get($line, 'variant.id'), $line['quantity'],
            SubscriptionPilotShopifyBridge::cents((string) data_get($line, 'originalUnitPriceSet.shopMoney.amount'))])->sort()->values()->all();
        $wanted = collect($terms['lines'])->map(fn ($line) => ['gid://shopify/ProductVariant/'.$line['variant_id'], $line['quantity'], $line['unit_price_cents']])->sort()->values()->all();
        $address = $order['shippingAddress'] ?? [];
        $address['countryCode'] = $address['countryCodeV2'] ?? null;
        unset($address['countryCodeV2'], $address['province']);
        abort_unless($lines === $wanted && SubscriptionBillingShopifySource::address($address) === SubscriptionBillingShopifySource::address($terms['shipping_address']), 409, 'Source products or delivery address differ from the reviewed terms.');
        abort_unless(is_string($order['updatedAt'] ?? null) && CarbonImmutable::parse($order['updatedAt'])->lte(now()->addMinute()), 409);
    }

    private function source(SubscriptionBillingAgreement $row, array $order): array
    {
        $terms = SubscriptionBillingAuthorization::original($row)['terms'];
        $context = $this->sourceContext($row);
        $connection = SubscriptionRechargeConnection::query()->forTenantId($row->tenant_id)->findOrFail($context['connection_id']);
        $revision = SubscriptionRechargeCredentialRevision::query()->forTenantId($row->tenant_id)->findOrFail($context['revision_id']);
        $transport = app(SubscriptionRechargeTransport::class);
        $store = $transport->read($connection, $revision, 'store')['store'] ?? [];
        abort_unless((string) ($store['id'] ?? '') === $connection->provider_store_id && ($store['identifier'] ?? null) === $terms['shop_domain']
            && ($store['external_platform'] ?? null) === 'shopify' && ($store['checkout_platform'] ?? null) === 'shopify', 409, 'The original source is not the same Shopify checkout store.');
        $customer = $transport->read($connection, $revision, 'customer', ['id' => $terms['recharge_customer_id']])['customer'] ?? [];
        abort_unless((string) ($customer['id'] ?? '') === $terms['recharge_customer_id']
            && (string) data_get($customer, 'external_customer_id.ecommerce') === $terms['shopify_customer_id']
            && ($customer['has_valid_payment_method'] ?? null) === true, 409, 'The source customer or saved payment assignment needs review.');
        $addressId = null;
        foreach ($terms['lines'] as $line) {
            $subscription = $transport->read($connection, $revision, 'subscription', ['id' => $line['subscription_id']])['subscription'] ?? [];
            abort_unless((string) ($subscription['id'] ?? '') === $line['subscription_id']
                && (string) ($subscription['customer_id'] ?? '') === $terms['recharge_customer_id']
                && (string) data_get($subscription, 'external_variant_id.ecommerce') === $line['variant_id']
                && ($subscription['quantity'] ?? null) === $line['quantity']
                && SubscriptionPilotShopifyBridge::cents((string) ($subscription['price'] ?? '')) === $line['unit_price_cents']
                && ($subscription['presentment_currency'] ?? null) === $terms['currency']
                && ($subscription['order_interval_unit'] ?? null) === 'month'
                && ($subscription['order_interval_frequency'] ?? null) === $terms['billing_months']
                && ($subscription['charge_interval_frequency'] ?? null) === $terms['billing_months']
                && ($subscription['is_prepaid'] ?? null) === false
                && array_key_exists('expire_after_specific_number_of_charges', $subscription) && $subscription['expire_after_specific_number_of_charges'] === null
                && array_key_exists('order_day_of_month', $subscription)
                && in_array($subscription['order_day_of_month'], [null, 0, $row->billing_anchor_date->day], true)
                && in_array($subscription['status'] ?? null, ['active', 'cancelled', 'expired'], true), 409, 'The original source subscription terms differ.');
            if ($subscription['status'] === 'active') {
                abort_unless(is_string($subscription['next_charge_scheduled_at'] ?? null)
                    && CarbonImmutable::parse($subscription['next_charge_scheduled_at'], $terms['timezone'])
                        ->setTimezone($terms['timezone'])->toDateString() === $terms['first_billing_date'], 409, 'The original source billing date changed.');
            }
            $current = SubscriptionRechargeTransport::id($subscription['address_id'] ?? null);
            abort_unless($addressId === null || $addressId === $current, 409, 'Multiple source delivery addresses need separate agreements.');
            $addressId = $current;
        }
        $address = $transport->read($connection, $revision, 'address', ['id' => $addressId])['address'] ?? [];
        abort_unless((string) ($address['id'] ?? '') === $addressId && (string) ($address['customer_id'] ?? '') === $terms['recharge_customer_id']
            && (string) ($address['payment_method_id'] ?? '') === $row->method_binding['source_payment_method_id'], 409, 'The original source address uses another payment method.');
        foreach (['first_name' => 'firstName', 'last_name' => 'lastName', 'address1' => 'address1', 'address2' => 'address2', 'city' => 'city', 'zip' => 'zip', 'country_code' => 'countryCode'] as $source => $term) {
            abort_unless(($address[$source] ?? '') === ($terms['shipping_address'][$term] ?? ''), 409, 'The source delivery address changed.');
        }
        abort_unless(in_array($address['province'] ?? null, [$terms['shipping_address']['provinceCode'], data_get($order, 'shippingAddress.province')], true), 409);
        $payment = $transport->read($connection, $revision, 'payment_method', ['id' => $row->method_binding['source_payment_method_id']])['payment_method'] ?? [];
        abort_unless((string) ($payment['id'] ?? '') === $row->method_binding['source_payment_method_id']
            && (string) ($payment['customer_id'] ?? '') === $terms['recharge_customer_id'] && ($payment['processor_name'] ?? null) === 'shopify_payments'
            && array_key_exists('status', $payment) && in_array($payment['status'], ['valid', null], true)
            && is_string($payment['processor_payment_method_token'] ?? null)
            && hash_equals($row->method_binding['payment_method_gid'], $payment['processor_payment_method_token']), 409, 'Source payment mapping is unavailable or identifies a different vaulted method.');
        abort_unless($context === $this->sourceContext($row), 409, 'The original source credential changed during verification.');

        return ['connection_id' => $connection->id, 'customer_id' => $terms['recharge_customer_id'], 'address_id' => $addressId,
            'payment_method_id' => $row->method_binding['source_payment_method_id'], 'subscription_ids' => array_column($terms['lines'], 'subscription_id'),
            'credential_context' => $context, 'checked_at' => now()->toISOString()];
    }

    private function sourceContext(SubscriptionBillingAgreement $row): array
    {
        abort_unless(SubscriptionRechargeOperationService::enabled($row->tenant_id), 423, 'Source subscription reads are paused.');
        $binding = $row->method_binding;
        $connection = SubscriptionRechargeConnection::query()->forTenantId($row->tenant_id)->where('platform', 'shopify')
            ->where('identifier', $row->terms['shop_domain'])->where('status', 'verified')->findOrFail($binding['source_connection_id']);
        abort_unless($connection->verified_at && ! $connection->read_pause_reason && $connection->active_credential_revision_id === $binding['source_credential_revision_id'], 409);
        $revision = SubscriptionRechargeCredentialRevision::query()->forTenantId($row->tenant_id)->where('connection_id', $connection->id)
            ->where('status', 'active')->findOrFail($binding['source_credential_revision_id']);
        abort_unless($revision->verified_at && ! $revision->revoked_at && ! $revision->expires_at?->isPast()
            && ! array_diff(['read_store', 'read_customers', 'read_subscriptions', 'read_payment_methods'], $revision->scopes ?? []), 409, 'The original source read credential needs verification.');

        return ['connection_id' => $connection->id, 'revision_id' => $revision->id,
            'credential_fingerprint' => SubscriptionEvidence::digest((string) ($revision->credentials['access_token'] ?? ''))];
    }
}
