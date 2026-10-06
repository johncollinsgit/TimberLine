<?php

namespace App\Services\Shopify;

use App\Models\ModernForestryFundraiserOrder;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ModernForestryFundraiserOrderIntakeService
{
    /** @return array{order:ModernForestryFundraiserOrder,duplicate:bool} */
    public function receive(array $payload): array
    {
        $tenant = $this->modernForestryTenant();
        $normalized = $this->normalize($payload);

        return DB::transaction(function () use ($tenant, $normalized): array {
            $existing = ModernForestryFundraiserOrder::query()
                ->forTenant($tenant)
                ->where('source', 'zapier')
                ->where('external_order_id', $normalized['external_order_id'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if (! hash_equals((string) $existing->fingerprint, $normalized['fingerprint'])) {
                    throw ValidationException::withMessages([
                        'external_order_id' => ['This Zapier order ID was already received with different financial details. Review it in the fundraiser desk instead of creating another invoice package.'],
                    ]);
                }

                return ['order' => $existing, 'duplicate' => true];
            }

            return [
                'order' => ModernForestryFundraiserOrder::query()->create([
                    'tenant_id' => $tenant->id,
                    'source' => 'zapier',
                    ...$normalized,
                    'status' => 'needs_review',
                    'received_at' => now(),
                ]),
                'duplicate' => false,
            ];
        });
    }

    public function modernForestryTenant(): Tenant
    {
        return Tenant::query()->where('slug', 'modern-forestry')->firstOrFail();
    }

    /** @return array<string,mixed> */
    protected function normalize(array $payload): array
    {
        $lines = collect((array) ($payload['items'] ?? []))
            ->map(function (mixed $line, int $index): array {
                $line = is_array($line) ? $line : [];
                $quantity = (int) ($line['quantity'] ?? 0);
                $unitAmount = (int) ($line['unit_amount_cents'] ?? -1);
                if ($quantity < 1 || $unitAmount < 0) {
                    throw ValidationException::withMessages([
                        "items.{$index}" => ['Every fundraiser item needs a positive quantity and a non-negative unit_amount_cents.'],
                    ]);
                }

                return [
                    'sku' => $this->text($line['sku'] ?? null, 160),
                    'description' => $this->requiredText($line['description'] ?? null, 500, "items.{$index}.description"),
                    'quantity' => $quantity,
                    'unit_amount_cents' => $unitAmount,
                    'line_total_cents' => $quantity * $unitAmount,
                ];
            })
            ->values()
            ->all();

        if ($lines === []) {
            throw ValidationException::withMessages(['items' => ['At least one fundraiser item is required.']]);
        }

        $subtotal = array_sum(array_column($lines, 'line_total_cents'));
        $suppliedSubtotal = $this->amount($payload['subtotal_cents'] ?? null, 'subtotal_cents');
        if ($subtotal !== $suppliedSubtotal) {
            throw ValidationException::withMessages([
                'subtotal_cents' => ['subtotal_cents must equal the sum of Zapier item quantities multiplied by unit_amount_cents.'],
            ]);
        }

        $discount = $this->amount($payload['discount_cents'] ?? 0, 'discount_cents');
        $shipping = $this->amount($payload['shipping_cents'] ?? 0, 'shipping_cents');
        $tax = $this->amount($payload['tax_cents'] ?? 0, 'tax_cents');
        $total = $this->amount($payload['total_cents'] ?? null, 'total_cents');
        $expectedTotal = $subtotal - $discount + $shipping + $tax;
        if ($discount > $subtotal || $total !== $expectedTotal) {
            throw ValidationException::withMessages([
                'total_cents' => ['total_cents must equal subtotal_cents minus discount_cents plus shipping_cents and tax_cents.'],
            ]);
        }

        $sourceCreatedAt = null;
        if (filled($payload['source_created_at'] ?? null)) {
            try {
                $sourceCreatedAt = CarbonImmutable::parse((string) $payload['source_created_at']);
            } catch (\Throwable) {
                throw ValidationException::withMessages(['source_created_at' => ['source_created_at must be a valid ISO-8601 timestamp.']]);
            }
        }

        $shippingAddress = $this->shippingAddress((array) data_get($payload, 'shipping_address', []));
        $currency = strtolower($this->requiredText($payload['currency'] ?? null, 3, 'currency'));
        if (! preg_match('/^[a-z]{3}$/', $currency)) {
            throw ValidationException::withMessages(['currency' => ['currency must be a three-letter ISO currency code.']]);
        }

        $record = [
            'external_order_id' => $this->requiredText($payload['external_order_id'] ?? null, 190, 'external_order_id'),
            'order_reference' => $this->text($payload['order_reference'] ?? null, 190),
            'recipient_name' => $this->requiredText(data_get($payload, 'recipient.name'), 190, 'recipient.name'),
            'recipient_email' => $this->email(data_get($payload, 'recipient.email'), 'recipient.email'),
            'recipient_phone' => $this->text(data_get($payload, 'recipient.phone'), 80),
            'shipping_address' => $shippingAddress,
            'currency' => $currency,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'shipping_cents' => $shipping,
            'tax_cents' => $tax,
            'total_cents' => $total,
            'line_items' => $lines,
            'source_payload' => [
                'external_order_id' => $payload['external_order_id'],
                'order_reference' => $payload['order_reference'] ?? null,
                'recipient' => [
                    'name' => data_get($payload, 'recipient.name'),
                    'email' => data_get($payload, 'recipient.email'),
                    'phone' => data_get($payload, 'recipient.phone'),
                ],
                'shipping_address' => $shippingAddress,
                'currency' => $currency,
                'subtotal_cents' => $subtotal,
                'discount_cents' => $discount,
                'shipping_cents' => $shipping,
                'tax_cents' => $tax,
                'total_cents' => $total,
                'items' => $lines,
                'source_created_at' => $sourceCreatedAt?->toIso8601String(),
            ],
            'source_created_at' => $sourceCreatedAt,
        ];
        $record['fingerprint'] = hash('sha256', json_encode($record['source_payload'], JSON_THROW_ON_ERROR));

        return $record;
    }

    protected function amount(mixed $value, string $field): int
    {
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0 || (int) $value > 999999999) {
            throw ValidationException::withMessages([$field => ["{$field} must be a whole-number amount in cents."]]);
        }

        return (int) $value;
    }

    protected function requiredText(mixed $value, int $max, string $field): string
    {
        $text = $this->text($value, $max);
        if ($text === null) {
            throw ValidationException::withMessages([$field => ["{$field} is required."]]);
        }

        return $text;
    }

    protected function text(mixed $value, int $max): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw ValidationException::withMessages(['payload' => ["A fundraiser field may not exceed {$max} characters."]]);
        }

        return $value;
    }

    protected function email(mixed $value, string $field): ?string
    {
        $email = $this->text($value, 255);
        if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([$field => ["{$field} must be a valid email address when provided."]]);
        }

        return $email;
    }

    /** @return array<string,string|null> */
    protected function shippingAddress(array $address): array
    {
        $line1 = $this->requiredText($address['line1'] ?? null, 190, 'shipping_address.line1');
        $city = $this->requiredText($address['city'] ?? null, 120, 'shipping_address.city');
        $region = $this->requiredText($address['region'] ?? null, 120, 'shipping_address.region');
        $postalCode = $this->requiredText($address['postal_code'] ?? null, 40, 'shipping_address.postal_code');
        $country = strtoupper($this->requiredText($address['country_code'] ?? null, 2, 'shipping_address.country_code'));

        return [
            'line1' => $line1,
            'line2' => $this->text($address['line2'] ?? null, 190),
            'city' => $city,
            'region' => $region,
            'postal_code' => $postalCode,
            'country_code' => $country,
        ];
    }
}
