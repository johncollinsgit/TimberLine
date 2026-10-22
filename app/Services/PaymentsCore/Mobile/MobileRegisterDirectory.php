<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Mobile;

use App\Services\PaymentsCore\MerchantBinding;

/** Server-owned provisioned definitions, empty until separately reviewed setup. */
final class MobileRegisterDirectory
{
    public function all(int $tenantId): array
    {
        return array_filter((array) config('evergrove_payments.registers', []),
            fn ($entry) => is_array($entry) && ($entry['tenant_id'] ?? null) === $tenantId);
    }

    public function get(int $tenantId, string $registerId): array
    {
        $entry = $this->all($tenantId)[$registerId] ?? null;
        if (! is_array($entry) || ($entry['active'] ?? false) !== true
            || ! is_int($entry['site_id'] ?? null) || $entry['site_id'] < 1) {
            throw new MobilePaymentException('register_unavailable', 404);
        }

        return $entry;
    }

    public function binding(int $tenantId, string $registerId): MerchantBinding
    {
        $entry = $this->get($tenantId, $registerId);
        $binding = $entry['binding'] ?? [];
        try {
            return new MerchantBinding($tenantId, $registerId, $binding['provider'] ?? '',
                $binding['merchant_account'] ?? '', $binding['location_id'] ?? '', $binding['environment'] ?? '');
        } catch (\DomainException|\TypeError) {
            throw new MobilePaymentException('merchant_binding_unavailable', 503);
        }
    }
}
