<?php

namespace App\Services\Subscriptions;

/** Project allowlists BEFORE persistence; even encrypted shadow records never copy vault/portal credentials. */
class SubscriptionRechargeSnapshot
{
    private const FIELDS = [
        'store' => ['id', 'identifier', 'external_platform', 'checkout_platform', 'currency', 'enabled_presentment_currencies', 'disabled_currencies_historical', 'timezone', 'default_api_version'],
        'customer' => ['id', 'external_customer_id', 'first_name', 'last_name', 'email', 'phone', 'tax_exempt', 'created_at', 'updated_at', 'first_charge_processed_at', 'has_valid_payment_method', 'has_payment_method_in_dunning', 'subscriptions_active_count', 'subscriptions_total_count', 'apply_credit_to_next_recurring_charge'],
        'address' => ['id', 'customer_id', 'payment_method_id', 'address1', 'address2', 'city', 'company', 'country_code', 'first_name', 'last_name', 'phone', 'province', 'zip', 'presentment_currency', 'discounts', 'order_attributes', 'order_note', 'shipping_lines_override', 'shipping_lines_conserved', 'created_at', 'updated_at'],
        'subscription' => ['id', 'customer_id', 'address_id', 'status', 'external_product_id', 'external_variant_id', 'quantity', 'price', 'presentment_currency', 'product_title', 'variant_title', 'sku', 'sku_override', 'properties', 'order_interval_unit', 'order_interval_frequency', 'charge_interval_frequency', 'order_day_of_month', 'order_day_of_week', 'next_charge_scheduled_at', 'is_prepaid', 'is_skippable', 'is_swappable', 'has_queued_charges', 'max_retries_reached', 'expire_after_specific_number_of_charges', 'cancellation_reason', 'cancellation_reason_comments', 'cancelled_at', 'created_at', 'updated_at'],
        'payment_method' => ['id', 'customer_id', 'default', 'payment_type', 'processor_name', 'status', 'status_reason', 'payment_details', 'created_at', 'updated_at'],
    ];

    public function sanitize(string $type, array $source): array
    {
        if (! isset(self::FIELDS[$type])) {
            throw new SubscriptionProviderReadException('recharge_unknown_resource');
        }
        $snapshot = array_intersect_key($source, array_flip(self::FIELDS[$type]));
        $this->types($type, $snapshot);
        $snapshot['id'] = SubscriptionRechargeTransport::id($source['id'] ?? null);
        foreach (['customer_id', 'address_id', 'payment_method_id'] as $field) {
            if (array_key_exists($field, $snapshot) && $snapshot[$field] !== null) {
                $snapshot[$field] = SubscriptionRechargeTransport::id($snapshot[$field]);
            }
        }
        foreach (['external_customer_id', 'external_product_id', 'external_variant_id'] as $field) {
            if (isset($snapshot[$field])) {
                if (! is_array($snapshot[$field])) {
                    throw new SubscriptionProviderReadException('recharge_invalid_external_identity');
                }
                $snapshot[$field] = array_intersect_key($snapshot[$field], ['ecommerce' => true]);
                if (isset($snapshot[$field]['ecommerce'])) {
                    $snapshot[$field]['ecommerce'] = SubscriptionRechargeTransport::id($snapshot[$field]['ecommerce']);
                }
            }
        }
        if (isset($snapshot['timezone'])) {
            $snapshot['timezone'] = is_array($snapshot['timezone']) ? array_intersect_key($snapshot['timezone'], ['iana_name' => true]) : null;
        }
        $redactedAttributes = 0;
        foreach (['properties', 'order_attributes'] as $field) {
            if (isset($snapshot[$field])) {
                $count = is_array($snapshot[$field]) ? count($snapshot[$field]) : 0;
                $snapshot[$field] = $this->nested($snapshot[$field], ['name', 'value'], true);
                $redactedAttributes += $count - count($snapshot[$field]);
            }
        }
        foreach (['shipping_lines_override', 'shipping_lines_conserved'] as $field) {
            if (isset($snapshot[$field])) {
                $snapshot[$field] = $this->nested($snapshot[$field], ['title', 'price', 'code', 'source']);
            }
        }
        if (isset($snapshot['discounts'])) {
            $snapshot['discounts'] = $this->nested($snapshot['discounts'], ['id', 'code', 'type', 'value', 'value_type', 'status', 'created_at', 'updated_at']);
        }
        if (isset($snapshot['payment_details'])) {
            if (! is_array($snapshot['payment_details'])) {
                throw new SubscriptionProviderReadException('recharge_invalid_payment_details');
            }
            $snapshot['payment_details'] = array_intersect_key($snapshot['payment_details'], array_flip(['brand', 'last4', 'exp_month', 'exp_year']));
            foreach ($snapshot['payment_details'] as $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw new SubscriptionProviderReadException('recharge_invalid_payment_details');
                }
            }
            $details = $snapshot['payment_details'];
            if (isset($details['last4']) && ! ((is_int($details['last4']) && $details['last4'] >= 0 && $details['last4'] <= 9999)
                || (is_string($details['last4']) && preg_match('/^[0-9]{1,4}$/D', $details['last4'])))) {
                throw new SubscriptionProviderReadException('recharge_invalid_masked_payment');
            }
            if (isset($details['brand']) && (! is_string($details['brand']) || ! preg_match('/^(?=.*[a-zA-Z])[a-zA-Z0-9 _-]{1,64}$/D', $details['brand']))) {
                throw new SubscriptionProviderReadException('recharge_invalid_masked_payment');
            }
            if (isset($details['exp_month']) && (! is_int($details['exp_month']) || $details['exp_month'] < 1 || $details['exp_month'] > 12)) {
                throw new SubscriptionProviderReadException('recharge_invalid_masked_payment');
            }
            if (isset($details['exp_year']) && (! is_int($details['exp_year']) || $details['exp_year'] < 1900 || $details['exp_year'] > 9999)) {
                throw new SubscriptionProviderReadException('recharge_invalid_masked_payment');
            }
        }
        if ($type === 'subscription') {
            if (! in_array($snapshot['status'] ?? null, ['active', 'cancelled', 'expired'], true) || ! isset($snapshot['customer_id'], $snapshot['address_id'])) {
                throw new SubscriptionProviderReadException('recharge_incomplete_subscription');
            }
            if (! is_int($snapshot['quantity'] ?? null) || $snapshot['quantity'] < 1 || ! is_string($snapshot['price'] ?? null) || ! preg_match('/^[0-9]+(?:\.[0-9]+)?$/D', $snapshot['price'])) {
                throw new SubscriptionProviderReadException('recharge_invalid_subscription_value');
            }
        }
        if ($type === 'address' && ! isset($snapshot['customer_id'])) {
            throw new SubscriptionProviderReadException('recharge_incomplete_address');
        }
        if ($type === 'payment_method' && ! isset($snapshot['customer_id'])) {
            throw new SubscriptionProviderReadException('recharge_incomplete_payment_method');
        }
        $omitted = array_values(array_diff(array_keys($source), self::FIELDS[$type]));
        $names = array_map(fn ($name) => preg_replace('/[^a-zA-Z0-9_.-]/', '_', substr((string) $name, 0, 100)), array_slice($omitted, 0, 64));
        sort($names, SORT_STRING);
        $snapshot['_coverage'] = ['omitted_fields' => $names, 'omitted_field_count' => count($omitted), 'omitted_field_names_truncated' => count($omitted) > 64, 'redacted_named_attribute_count' => $redactedAttributes, 'projection' => 'allowlisted_source_shadow'];
        SubscriptionEvidence::assertContent($snapshot);

        return $snapshot;
    }

    private function nested(mixed $source, array $keys, bool $named = false): array
    {
        if (! is_array($source) || ! array_is_list($source)) {
            throw new SubscriptionProviderReadException('recharge_invalid_nested_snapshot');
        }
        $result = [];
        foreach ($source as $row) {
            if (! is_array($row)) {
                throw new SubscriptionProviderReadException('recharge_invalid_nested_snapshot');
            }
            if ($named && preg_match('/token|secret|password|hash|cvv|cvc|card.?number/i', (string) ($row['name'] ?? ''))) {
                continue;
            }
            $item = array_intersect_key($row, array_flip($keys));
            foreach ($item as $value) {
                if (! is_scalar($value) && $value !== null) {
                    throw new SubscriptionProviderReadException('recharge_invalid_nested_snapshot');
                }
            }
            $result[] = $item;
        }

        return $result;
    }

    private function types(string $type, array $snapshot): void
    {
        $objects = ['external_customer_id', 'external_product_id', 'external_variant_id', 'timezone', 'payment_details'];
        $lists = ['properties', 'order_attributes', 'discounts', 'shipping_lines_override', 'shipping_lines_conserved', 'enabled_presentment_currencies', 'disabled_currencies_historical'];
        $booleans = ['tax_exempt', 'has_valid_payment_method', 'has_payment_method_in_dunning', 'apply_credit_to_next_recurring_charge', 'default', 'sku_override', 'is_prepaid', 'is_skippable', 'is_swappable', 'has_queued_charges', 'max_retries_reached'];
        $integers = ['subscriptions_active_count', 'subscriptions_total_count', 'quantity'];
        $numeric = ['order_interval_frequency', 'charge_interval_frequency', 'order_day_of_month', 'order_day_of_week', 'expire_after_specific_number_of_charges'];
        foreach ($snapshot as $field => $value) {
            if ($value === null) {
                continue;
            }
            if (in_array($field, $objects, true)) {
                if (! is_array($value) || ($value !== [] && array_is_list($value))) {
                    $this->invalidShape();
                }
            } elseif (in_array($field, $lists, true)) {
                if (! is_array($value) || ! array_is_list($value) || count($value) > 1000) {
                    $this->invalidShape();
                }
                if (in_array($field, ['enabled_presentment_currencies', 'disabled_currencies_historical'], true)) {
                    foreach ($value as $currency) {
                        if (! is_string($currency) || ! preg_match('/^[A-Z]{3}$/D', $currency)) {
                            $this->invalidShape();
                        }
                    }
                }
            } elseif (in_array($field, $booleans, true)) {
                if (! is_bool($value)) {
                    $this->invalidShape();
                }
            } elseif (in_array($field, $integers, true)) {
                if (! is_int($value) || $value < 0) {
                    $this->invalidShape();
                }
            } elseif (in_array($field, $numeric, true)) {
                if (! ((is_int($value) && $value >= 0) || (is_string($value) && preg_match('/^[0-9]{1,9}$/D', $value)))) {
                    $this->invalidShape();
                }
            } elseif (in_array($field, ['id', 'customer_id', 'address_id', 'payment_method_id'], true)) {
                SubscriptionRechargeTransport::id($value);
            } elseif (! is_string($value) || strlen($value) > (in_array($field, ['created_at', 'updated_at', 'cancelled_at', 'first_charge_processed_at', 'next_charge_scheduled_at'], true) ? 64 : 8192)) {
                $this->invalidShape();
            }
        }
        if (isset($snapshot['timezone']['iana_name']) && (! is_string($snapshot['timezone']['iana_name']) || strlen($snapshot['timezone']['iana_name']) > 100)) {
            $this->invalidShape();
        }
        foreach (['currency', 'presentment_currency'] as $field) {
            if (isset($snapshot[$field]) && ! preg_match('/^[A-Z]{3}$/D', $snapshot[$field])) {
                $this->invalidShape();
            }
        }
    }

    private function invalidShape(): never
    {
        throw new SubscriptionProviderReadException('recharge_invalid_field_shape');
    }
}
