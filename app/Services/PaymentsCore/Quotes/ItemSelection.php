<?php

declare(strict_types=1);

namespace App\Services\PaymentsCore\Quotes;

/** The only client-controlled terms. Prices, tax, kind and routing are never input. */
final readonly class ItemSelection
{
    public static function normalize(array $items): array
    {
        if (! array_is_list($items) || count($items) < 1 || count($items) > 50) {
            throw new QuoteException('invalid_selection');
        }
        $seen = [];
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                throw new QuoteException('invalid_selection');
            }
            $keys = array_keys($item);
            sort($keys);
            if ($keys !== ['expected_revision', 'quantity', 'variant_id']
                || ! is_int($item['variant_id']) || $item['variant_id'] < 1
                || ! is_int($item['quantity']) || $item['quantity'] < 1 || $item['quantity'] > 99
                || ! is_string($item['expected_revision']) || ! preg_match('/^[a-f0-9]{64}$/D', $item['expected_revision'])
                || isset($seen[$item['variant_id']])) {
                throw new QuoteException('invalid_selection');
            }
            $seen[$item['variant_id']] = true;
            $result[] = ['variant_id' => $item['variant_id'], 'quantity' => $item['quantity'], 'expected_revision' => $item['expected_revision']];
        }
        usort($result, fn (array $a, array $b) => $a['variant_id'] <=> $b['variant_id']);

        return $result;
    }

    public static function uuid(string $value): string
    {
        if (! preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/iD', $value)) {
            throw new QuoteException('invalid_identity');
        }

        return strtolower($value);
    }
}
