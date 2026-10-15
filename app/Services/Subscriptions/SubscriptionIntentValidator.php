<?php

namespace App\Services\Subscriptions;

use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Explicit allowlists keep card data and arbitrary client metadata out of durable evidence. */
class SubscriptionIntentValidator
{
    public function validate(string $action, array $payload): array
    {
        $rules = match ($action) {
            'pause' => ['duration_months' => ['required', 'integer', 'min:1', 'max:12']],
            'resume', 'send_payment_update_email' => [],
            'cancel' => ['reason' => ['required', 'string', 'max:2000']],
            'admin_note' => ['note' => ['required', 'string', 'max:2000']],
            'update_next_billing_date' => ['next_billing_at' => ['required', 'date_format:Y-m-d\TH:i:sP', 'after:now']],
            'swap_product' => [
                'product_variant_gid' => ['required', 'string', 'regex:/^gid:\/\/shopify\/ProductVariant\/[0-9]+$/D'],
                'scent' => ['nullable', 'string', 'max:190'],
            ],
            'update_shipping_address' => [
                'address' => ['required', 'array:first_name,last_name,address1,address2,city,province_code,zip,country_code,phone'],
                'address.first_name' => ['required', 'string', 'max:100'],
                'address.last_name' => ['required', 'string', 'max:100'],
                'address.address1' => ['required', 'string', 'max:190'],
                'address.address2' => ['nullable', 'string', 'max:190'],
                'address.city' => ['required', 'string', 'max:100'],
                'address.province_code' => ['nullable', 'string', 'max:20'],
                'address.zip' => ['required', 'string', 'max:20'],
                'address.country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/D'],
                'address.phone' => ['nullable', 'string', 'max:30', 'regex:/^[+0-9 ().-]+$/D'],
            ],
            'vote' => ['poll_id' => ['required', 'integer', 'min:1'], 'option_id' => ['required', 'integer', 'min:1']],
            default => throw ValidationException::withMessages(['action' => 'Unsupported subscription request.']),
        };
        // Older mobile clients include these transport hints. Validate and discard them.
        $rules['source'] = ['sometimes', 'string', 'max:40'];
        $rules['action'] = ['sometimes', 'string', 'max:80'];
        Validator::make(['payload' => $payload], ['payload' => ['array:'.implode(',', array_keys($rules))]])->validate();
        $clean = Validator::make($payload, $rules)->validate();
        unset($clean['source'], $clean['action']);

        return $clean;
    }
}
