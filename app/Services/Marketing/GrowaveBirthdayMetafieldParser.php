<?php

namespace App\Services\Marketing;

use Carbon\CarbonImmutable;

class GrowaveBirthdayMetafieldParser
{
    /**
     * @param  array<int,array{namespace:string,key:string,value:string,type:?string}>  $metafields
     * @return array{
     *   raw_metafields:array<int,array{namespace:string,key:string,value:string,type:?string}>,
     *   birth_month:?int,
     *   birth_day:?int,
     *   birth_year:?int,
     *   birthday_full_date:?string,
     *   is_partial:bool,
     *   is_uncertain:bool,
     *   source:string
     * }
     */
    public function parse(array $metafields): array
    {
        $detected = [];
        $month = null;
        $day = null;
        $year = null;
        $fullDate = null;
        $uncertain = false;

        foreach ($metafields as $metafield) {
            $normalized = $this->normalize($metafield);
            if (! $normalized) {
                continue;
            }

            if (! $this->isBirthdayMetafield($normalized['namespace'], $normalized['key'])) {
                continue;
            }

            $detected[] = $normalized;

            $key = strtolower($normalized['key']);
            $parsedDate = $this->parseDateValue($normalized['value']);

            if ($this->looksLikeMonthKey($key)) {
                [$month, $uncertain] = $this->assignInt($month, $this->parseInt($normalized['value']), $uncertain);
            }

            if ($this->looksLikeDayKey($key)) {
                [$day, $uncertain] = $this->assignInt($day, $this->parseInt($normalized['value']), $uncertain);
            }

            if ($this->looksLikeYearKey($key)) {
                [$year, $uncertain] = $this->assignInt($year, $this->parseInt($normalized['value']), $uncertain);
            }

            if ($parsedDate) {
                [$month, $uncertain] = $this->assignInt($month, $parsedDate['month'], $uncertain);
                [$day, $uncertain] = $this->assignInt($day, $parsedDate['day'], $uncertain);
                [$year, $uncertain] = $this->assignInt($year, $parsedDate['year'], $uncertain);
                if ($parsedDate['full_date']) {
                    [$fullDate, $uncertain] = $this->assignString($fullDate, $parsedDate['full_date'], $uncertain);
                }
            }
        }

        if ($month !== null && ($month < 1 || $month > 12)) {
            $month = null;
            $uncertain = true;
        }
        if ($day !== null && ($day < 1 || $day > 31)) {
            $day = null;
            $uncertain = true;
        }
        if ($year !== null && ($year < 1900 || $year > ((int) now()->year + 1))) {
            $year = null;
            $uncertain = true;
        }

        if ($fullDate === null && $month !== null && $day !== null && $year !== null && checkdate($month, $day, $year)) {
            $fullDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
        }

        if ($month !== null && $day !== null && $year !== null && ! checkdate($month, $day, $year)) {
            $uncertain = true;
            $fullDate = null;
        }

        $isPartial = ($month === null || $day === null);

        return [
            'raw_metafields' => $detected,
            'birth_month' => $month,
            'birth_day' => $day,
            'birth_year' => $year,
            'birthday_full_date' => $fullDate,
            'is_partial' => $isPartial,
            'is_uncertain' => $uncertain || $isPartial,
            'source' => $this->sourceForDetected($detected),
        ];
    }

    protected function normalize(array $metafield): ?array
    {
        $namespace = trim((string) ($metafield['namespace'] ?? ''));
        $key = trim((string) ($metafield['key'] ?? ''));

        if ($namespace === '' || $key === '') {
            return null;
        }

        return [
            'namespace' => $namespace,
            'key' => $key,
            'value' => (string) ($metafield['value'] ?? ''),
            'type' => $this->nullableString($metafield['type'] ?? null),
        ];
    }

    protected function isBirthdayMetafield(string $namespace, string $key): bool
    {
        $namespace = strtolower($namespace);
        $key = strtolower($key);

        foreach (['growave', 'ssw', 'socialshopwave'] as $token) {
            if (str_contains($namespace, $token) || str_contains($key, $token)) {
                return true;
            }
        }

        return preg_match('/(birth|birthday|dob|date_of_birth)/', $key) === 1;
    }

    protected function looksLikeMonthKey(string $key): bool
    {
        return preg_match('/(birth.*month|birthday.*month|month_of_birth|dob_month)/', $key) === 1;
    }

    protected function looksLikeDayKey(string $key): bool
    {
        return preg_match('/(birth.*day|birthday.*day|day_of_birth|dob_day)/', $key) === 1;
    }

    protected function looksLikeYearKey(string $key): bool
    {
        return preg_match('/(birth.*year|birthday.*year|year_of_birth|dob_year)/', $key) === 1;
    }

    /**
     * @return array{month:int,day:int,year:?int,full_date:?string}|null
     */
    protected function parseDateValue(string $value): ?array
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $month = $this->parseInt($decoded['month'] ?? $decoded['birth_month'] ?? null);
            $day = $this->parseInt($decoded['day'] ?? $decoded['birth_day'] ?? null);
            $year = $this->parseInt($decoded['year'] ?? $decoded['birth_year'] ?? null);
            if ($month !== null && $day !== null) {
                $fullDate = null;
                if ($year !== null && checkdate($month, $day, $year)) {
                    $fullDate = sprintf('%04d-%02d-%02d', $year, $month, $day);
                }

                return [
                    'month' => $month,
                    'day' => $day,
                    'year' => $year,
                    'full_date' => $fullDate,
                ];
            }
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) === 1) {
            $year = (int) $matches[1];
            $month = (int) $matches[2];
            $day = (int) $matches[3];

            return [
                'month' => $month,
                'day' => $day,
                'year' => $year,
                'full_date' => checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null,
            ];
        }

        if (preg_match('/^(\d{1,2})[\/-](\d{1,2})(?:[\/-](\d{2,4}))?$/', $value, $matches) === 1) {
            $month = (int) $matches[1];
            $day = (int) $matches[2];
            $year = isset($matches[3]) && $matches[3] !== '' ? (int) $matches[3] : null;

            if ($year !== null && $year < 100) {
                $year += 2000;
            }

            return [
                'month' => $month,
                'day' => $day,
                'year' => $year,
                'full_date' => ($year !== null && checkdate($month, $day, $year))
                    ? sprintf('%04d-%02d-%02d', $year, $month, $day)
                    : null,
            ];
        }

        try {
            $parsed = CarbonImmutable::parse($value);

            return [
                'month' => (int) $parsed->month,
                'day' => (int) $parsed->day,
                'year' => (int) $parsed->year,
                'full_date' => $parsed->toDateString(),
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '' || ! preg_match('/^-?\d+$/', $value)) {
            return null;
        }

        return (int) $value;
    }

    /**
     * @return array{0:?int,1:bool}
     */
    protected function assignInt(?int $current, ?int $candidate, bool $uncertain): array
    {
        if ($candidate === null) {
            return [$current, $uncertain];
        }

        if ($current === null) {
            return [$candidate, $uncertain];
        }

        if ($current !== $candidate) {
            return [$current, true];
        }

        return [$current, $uncertain];
    }

    /**
     * @return array{0:?string,1:bool}
     */
    protected function assignString(?string $current, ?string $candidate, bool $uncertain): array
    {
        $candidate = $this->nullableString($candidate);
        if ($candidate === null) {
            return [$current, $uncertain];
        }

        if ($current === null) {
            return [$candidate, $uncertain];
        }

        if ($current !== $candidate) {
            return [$current, true];
        }

        return [$current, $uncertain];
    }

    /**
     * @param array<int,array{namespace:string,key:string,value:string,type:?string}> $detected
     */
    protected function sourceForDetected(array $detected): string
    {
        foreach ($detected as $row) {
            $namespace = strtolower((string) ($row['namespace'] ?? ''));
            if (str_contains($namespace, 'growave') || str_contains($namespace, 'ssw') || str_contains($namespace, 'socialshopwave')) {
                return 'growave_import';
            }
        }

        return 'shopify_metafield';
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
