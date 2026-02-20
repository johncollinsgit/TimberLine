<?php

namespace App\Support\Shopify;

class InfiniteOptionsParser
{
    /**
     * @param array<string, mixed> $lineItem
     * @return array<int, array{scent_name: string, qty: int, slot: int}>
     */
    public function parseBundleSelections(array $lineItem): array
    {
        $properties = $lineItem['properties'] ?? [];
        $pairs = $this->normalizeProperties($properties);

        $selections = [];
        $slot = 1;

        foreach ($pairs as $pair) {
            $name = strtolower((string) ($pair['name'] ?? ''));
            $value = trim((string) ($pair['value'] ?? ''));

            if ($value === '' || strtolower($value) === 'none') {
                continue;
            }

            if (!str_contains($name, 'scent') && !str_contains($name, 'fragrance')) {
                continue;
            }

            $split = $this->splitValues($value);
            foreach ($split as $item) {
                $clean = trim($item);
                if ($clean === '' || strtolower($clean) === 'none') {
                    continue;
                }
                $selections[] = [
                    'scent_name' => $clean,
                    'qty' => 1,
                    'slot' => $slot++,
                ];
            }
        }

        return $selections;
    }

    /**
     * @param mixed $properties
     * @return array<int, array{name: string, value: string}>
     */
    protected function normalizeProperties($properties): array
    {
        if (!is_array($properties)) {
            return [];
        }

        $pairs = [];
        $isAssoc = array_keys($properties) !== range(0, count($properties) - 1);

        if ($isAssoc) {
            foreach ($properties as $name => $value) {
                $pairs[] = [
                    'name' => (string) $name,
                    'value' => is_scalar($value) ? (string) $value : json_encode($value),
                ];
            }
            return $pairs;
        }

        foreach ($properties as $prop) {
            if (!is_array($prop)) {
                continue;
            }
            $pairs[] = [
                'name' => (string) ($prop['name'] ?? ''),
                'value' => is_scalar($prop['value'] ?? null) ? (string) ($prop['value'] ?? '') : json_encode($prop['value'] ?? ''),
            ];
        }

        return $pairs;
    }

    /**
     * @return array<int, string>
     */
    protected function splitValues(string $value): array
    {
        if (str_contains($value, ',')) {
            return array_map('trim', explode(',', $value));
        }
        return [$value];
    }
}
