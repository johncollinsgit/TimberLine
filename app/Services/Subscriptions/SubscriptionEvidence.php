<?php

namespace App\Services\Subscriptions;

/** Private evidence stays encrypted; keyed digests are not public identifiers. */
class SubscriptionEvidence
{
    public static function readsEnabled(int $tenantId): bool
    {
        return (bool) config('subscriptions.provider_reads_enabled', false)
            && in_array($tenantId, (array) config('subscriptions.provider_read_tenant_ids', []), true);
    }

    public static function canonical(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item, SORT_STRING);
            }
            foreach ($item as $key => $child) {
                $item[$key] = $sort($child);
            }

            return $item;
        };

        return json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION | JSON_UNESCAPED_SLASHES);
    }

    public static function digest(string $value): string
    {
        return hash_hmac('sha256', $value, (string) config('app.key'));
    }

    public static function keyId(): string
    {
        return substr(hash('sha256', (string) config('app.key')), 0, 16);
    }

    public static function assertContent(array $value, int $maxBytes = 262144): void
    {
        abort_if(strlen(self::canonical($value)) > $maxBytes, 422, 'Subscription evidence exceeds its bound.');
        $check = function (array $data) use (&$check): void {
            foreach ($data as $key => $child) {
                $normalized = strtolower(preg_replace('/[^a-zA-Z0-9]/', '', (string) $key));
                abort_if(in_array($normalized, ['accesstoken', 'refreshtoken', 'clientsecret', 'secret', 'authorization', 'password', 'cardnumber', 'cvv', 'cvc'], true), 422, 'Secret material is not permitted in subscription evidence.');
                if (is_array($child)) {
                    $check($child);
                }
            }
        };
        $check($value);
    }
}
