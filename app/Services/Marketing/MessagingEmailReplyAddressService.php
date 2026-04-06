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

        return sprintf('reply+t%dd%d@%s', $tenantId, $deliveryId, $domain);
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

        if (! preg_match('/reply\+t(?P<tenant>\d+)d(?P<delivery>\d+)@/i', $address, $matches)) {
            return null;
        }

        $tenantId = (int) ($matches['tenant'] ?? 0);
        $deliveryId = (int) ($matches['delivery'] ?? 0);
        if ($tenantId <= 0 || $deliveryId <= 0) {
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
}
