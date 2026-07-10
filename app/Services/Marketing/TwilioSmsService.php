<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Support\Tenancy\TenantContext;

class TwilioSmsService
{
    public function __construct(
        protected TenantMessagingGateway $gateway,
        protected TwilioProviderClient $legacyProvider,
        protected TenantContext $tenantContext,
    ) {}

    /** @param array<string,mixed> $options */
    public function sendSms(string $toPhone, string $message, array $options = []): array
    {
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null) ?: $this->tenantContext->id();
        if ($tenantId !== null && (bool) config('features.tenant_messaging_platform')) {
            return $this->gateway->sendSms($tenantId, $toPhone, $message, $options);
        }

        if (! (bool) config('features.tenant_messaging_platform')) {
            return $this->legacyProvider->sendSms($toPhone, $message, $options);
        }

        return [
            'success' => false,
            'provider' => 'tenant_gateway',
            'provider_message_id' => null,
            'status' => 'failed',
            'error_code' => 'missing_tenant',
            'error_message' => 'A tenant is required for SMS sending.',
            'sender_key' => null,
            'sender_label' => null,
            'from_identifier' => null,
            'delivery_mode' => 'sms',
            'requested_delivery_mode' => 'sms',
            'payload' => [],
            'dry_run' => (bool) ($options['dry_run'] ?? false),
        ];
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }
}
