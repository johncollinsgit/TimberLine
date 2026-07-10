<?php

namespace App\Services\Marketing;

class MessagingEmailReplyAddressService
{
    public function replyAddressForDelivery(int $tenantId, int $deliveryId): ?string
    {
        $domain = $this->inboundDomain();
        if ($domain === null || $tenantId <= 0 || $deliveryId <= 0) {
            return null;
        }

        $signature = $this->signature($tenantId, $deliveryId);

        return sprintf('reply+t%dd%ds%s@%s', $tenantId, $deliveryId, $signature, $domain);
    }

    /**
     * @return array{tenant_id:int,delivery_id:int}|null
     */
    public function parseReplyAddress(?string $value): ?array
    {
        $address = strtolower(trim((string) $value));
        if ($address === '') {
            return null;
        }

        if (! preg_match('/reply\+t(?P<tenant>\d+)d(?P<delivery>\d+)(?:s(?P<signature>[a-f0-9]{20}))?@/i', $address, $matches)) {
            return null;
        }

        $tenantId = (int) ($matches['tenant'] ?? 0);
        $deliveryId = (int) ($matches['delivery'] ?? 0);
        if ($tenantId <= 0 || $deliveryId <= 0) {
            return null;
        }

        $signature = strtolower(trim((string) ($matches['signature'] ?? '')));
        if ($signature === '') {
            $legacyTenantIds = array_map('intval', (array) config('marketing.messaging.platform.legacy_tenant_ids', [1]));
            if (! in_array($tenantId, $legacyTenantIds, true)) {
                return null;
            }
        } elseif (! hash_equals($this->signature($tenantId, $deliveryId), $signature)) {
            return null;
        }

        return [
            'tenant_id' => $tenantId,
            'delivery_id' => $deliveryId,
        ];
    }

    protected function inboundDomain(): ?string
    {
        $domain = strtolower(trim((string) config('marketing.messaging.responses.email_inbound_domain')));

        return $domain !== '' ? $domain : null;
    }

    protected function signature(int $tenantId, int $deliveryId): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7), true);
        }

        return substr(hash_hmac('sha256', "{$tenantId}:{$deliveryId}", $key), 0, 20);
    }
}
