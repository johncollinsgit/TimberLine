<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore;

use DomainException;

/** Resolved by a future authenticated server-side provisioner, never the app. */
final readonly class MerchantBinding
{
    public function __construct(
        public int $tenantId,
        public string $registerId,
        public string $provider,
        public string $merchantAccount,
        public string $locationId,
        public string $environment,
    ) {
        if ($tenantId < 1 || ! in_array($environment, ['test', 'live'], true)) {
            throw new DomainException('Invalid merchant binding.');
        }
        foreach ([$registerId, $provider, $merchantAccount, $locationId] as $identity) {
            Contract::id($identity);
        }
    }

    public function snapshot(): array
    {
        return ['tenant_id' => $this->tenantId, 'register_id' => $this->registerId,
            'provider' => $this->provider, 'merchant_account' => $this->merchantAccount,
            'location_id' => $this->locationId, 'environment' => $this->environment];
    }

    public function fingerprint(): string
    {
        return Contract::hash($this->snapshot());
    }

    public static function restore(array $value): self
    {
        return new self($value['tenant_id'], $value['register_id'], $value['provider'],
            $value['merchant_account'], $value['location_id'], $value['environment']);
    }
}
