<?php

namespace App\Services\Automation\V2;

/**
 * Hash only the customer-visible Calendar mapping. Runtime-specific private
 * extended properties intentionally differ between the v1 and v2 engines and
 * are excluded from behavioral parity.
 */
class CalendarEventParityFingerprint
{
    public function __construct(protected PayloadFingerprint $fingerprints) {}

    /** @param array<string,mixed> $payload */
    public function hash(array $payload): string
    {
        return $this->fingerprints->hash($this->semanticPayload($payload));
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function semanticPayload(array $payload): array
    {
        $extended = is_array($payload['extendedProperties'] ?? null)
            ? (array) $payload['extendedProperties']
            : [];
        unset($extended['private']);
        if ($extended === []) {
            unset($payload['extendedProperties']);
        } else {
            $payload['extendedProperties'] = $extended;
        }

        return $payload;
    }
}
