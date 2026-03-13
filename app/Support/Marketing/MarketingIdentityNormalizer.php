<?php

namespace App\Support\Marketing;

class MarketingIdentityNormalizer
{
    public function normalizeEmail(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $email = strtolower(trim($value));
        if ($email === '') {
            return null;
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    public function normalizePhone(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return null;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        return strlen($digits) === 10 ? $digits : null;
    }

    /**
     * @return array<int,string>
     */
    public function phoneMatchCandidates(?string $value): array
    {
        $normalized = $this->normalizePhone($value);
        if ($normalized === null) {
            return [];
        }

        return array_values(array_unique([$normalized, '+1' . $normalized]));
    }

    public function toE164(?string $value): ?string
    {
        $normalized = $this->normalizePhone($value);
        if ($normalized === null) {
            return null;
        }

        return '+1' . $normalized;
    }

    /**
     * @return array{0:?string,1:?string}
     */
    public function splitName(?string $fullName): array
    {
        $name = trim((string) $fullName);
        if ($name === '') {
            return [null, null];
        }

        $parts = preg_split('/\s+/', $name) ?: [];
        $first = trim((string) ($parts[0] ?? ''));
        if ($first === '') {
            return [null, null];
        }

        if (count($parts) === 1) {
            return [$first, null];
        }

        $last = trim(implode(' ', array_slice($parts, 1)));

        return [$first, $last !== '' ? $last : null];
    }
}
