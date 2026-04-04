<?php

namespace App\Support\Auth;

use Illuminate\Http\Request;

class PasswordResetUrlFactory
{
    public function make(string $token, string $email, ?Request $request = null): string
    {
        $path = route('password.reset', [
            'token' => $token,
            'email' => $email,
        ], false);

        $request ??= request();
        $requestHost = $this->resolveRequestHost($request);
        if ($requestHost !== null) {
            $scheme = $this->resolveRequestScheme($request) ?? $this->resolveAppScheme();

            return $scheme.'://'.$requestHost.$path;
        }

        $host = $this->resolveFallbackHost();
        if ($host === null) {
            return url($path);
        }

        $scheme = $this->resolveAppScheme();

        return $scheme.'://'.$host.$path;
    }

    protected function resolveRequestHost(?Request $request): ?string
    {
        $host = strtolower(trim((string) $request?->getHost()));
        if ($host !== '') {
            return $host;
        }

        return null;
    }

    protected function resolveFallbackHost(): ?string
    {
        $flagshipHost = collect((array) config('tenancy.auth.flagship_hosts', []))
            ->map(fn (mixed $candidate): string => strtolower(trim((string) $candidate)))
            ->first(fn (string $candidate): bool => $candidate !== '');
        if (is_string($flagshipHost) && $flagshipHost !== '') {
            return $flagshipHost;
        }

        $appHost = parse_url((string) config('app.url', ''), PHP_URL_HOST);
        $appHost = strtolower(trim((string) $appHost));

        return $appHost !== '' ? $appHost : null;
    }

    protected function resolveRequestScheme(?Request $request): ?string
    {
        $requestScheme = strtolower(trim((string) $request?->getScheme()));
        if (in_array($requestScheme, ['http', 'https'], true)) {
            return $requestScheme;
        }

        return null;
    }

    protected function resolveAppScheme(): string
    {
        $appScheme = parse_url((string) config('app.url', ''), PHP_URL_SCHEME);
        $appScheme = strtolower(trim((string) $appScheme));

        return in_array($appScheme, ['http', 'https'], true) ? $appScheme : 'https';
    }
}
