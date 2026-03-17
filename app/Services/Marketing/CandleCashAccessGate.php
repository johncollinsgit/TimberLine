<?php

namespace App\Services\Marketing;

use Illuminate\Http\Request;

class CandleCashAccessGate
{
    public function enabled(): bool
    {
        return (bool) config('marketing.candle_cash.password_protection.enabled', true);
    }

    public function unlockTtlMinutes(): int
    {
        return max(1, (int) config('marketing.candle_cash.password_protection.unlock_ttl_minutes', 480));
    }

    public function matchesPassword(?string $candidate): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $expected = trim((string) config('marketing.candle_cash.password_protection.password', 'johnnycash'));
        $actual = trim((string) $candidate);

        return $expected !== '' && hash_equals($expected, $actual);
    }

    public function issueStorefrontToken(): string
    {
        $payload = [
            'ctx' => 'candle_cash_unlock',
            'aud' => 'shopify_storefront',
            'exp' => now()->addMinutes($this->unlockTtlMinutes())->timestamp,
            'nonce' => bin2hex(random_bytes(12)),
        ];

        $encoded = $this->base64UrlEncode((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        $signature = hash_hmac('sha256', $encoded, $this->signingKey());

        return $encoded . '.' . $signature;
    }

    public function storefrontUnlocked(Request $request): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        return $this->validStorefrontToken($this->requestToken($request));
    }

    public function unlockPublicSession(Request $request): void
    {
        if (! $this->enabled()) {
            return;
        }

        $request->session()->put($this->sessionKey(), now()->addMinutes($this->unlockTtlMinutes())->timestamp);
    }

    public function publicSessionUnlocked(Request $request): bool
    {
        if (! $this->enabled()) {
            return true;
        }

        $expiresAt = (int) $request->session()->get($this->sessionKey(), 0);

        if ($expiresAt <= now()->timestamp) {
            $request->session()->forget($this->sessionKey());

            return false;
        }

        return true;
    }

    protected function validStorefrontToken(?string $token): bool
    {
        $token = trim((string) $token);
        if ($token === '' || ! str_contains($token, '.')) {
            return false;
        }

        [$encoded, $signature] = explode('.', $token, 2);
        if ($encoded === '' || $signature === '') {
            return false;
        }

        $expected = hash_hmac('sha256', $encoded, $this->signingKey());
        if (! hash_equals($expected, $signature)) {
            return false;
        }

        $payload = json_decode($this->base64UrlDecode($encoded), true);
        if (! is_array($payload)) {
            return false;
        }

        if (($payload['ctx'] ?? null) !== 'candle_cash_unlock' || ($payload['aud'] ?? null) !== 'shopify_storefront') {
            return false;
        }

        return (int) ($payload['exp'] ?? 0) >= now()->timestamp;
    }

    protected function requestToken(Request $request): ?string
    {
        $candidates = [
            $request->header('X-Forestry-Candle-Cash-Unlock'),
            $request->query('candle_cash_unlock'),
            $request->input('candle_cash_unlock'),
        ];

        foreach ($candidates as $candidate) {
            $value = trim((string) $candidate);
            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    protected function signingKey(): string
    {
        $key = (string) (config('app.key') ?: config('marketing.shopify.signing_secret') ?: 'candle-cash-unlock');

        if (str_starts_with($key, 'base64:')) {
            $decoded = base64_decode(substr($key, 7), true);
            if ($decoded !== false && $decoded !== '') {
                return $decoded;
            }
        }

        return $key;
    }

    protected function sessionKey(): string
    {
        return 'marketing.candle_cash.unlock_expires_at';
    }

    protected function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected function base64UrlDecode(string $value): string
    {
        $padding = strlen($value) % 4;
        if ($padding !== 0) {
            $value .= str_repeat('=', 4 - $padding);
        }

        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
