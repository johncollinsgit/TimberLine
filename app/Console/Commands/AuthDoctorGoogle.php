<?php

namespace App\Console\Commands;

use App\Support\Auth\GoogleOAuthFailureClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class AuthDoctorGoogle extends Command
{
    protected $signature = 'auth:doctor-google
        {--token-smoke : Perform a safe OAuth token smoke request with an intentionally invalid authorization code}
        {--timeout=10 : HTTP timeout (seconds) for --token-smoke}';

    protected $description = 'Diagnose Google login OAuth configuration with masked output and optional token smoke test.';

    /**
     * @var array<int,string>
     */
    private array $errors = [];

    /**
     * @var array<int,string>
     */
    private array $warnings = [];

    public function handle(): int
    {
        $this->line('auth_doctor_google=1');

        $googleEnabled = (bool) config('services.google.enabled');
        $clientId = (string) config('services.google.client_id', '');
        $clientSecret = (string) config('services.google.client_secret', '');
        $redirect = (string) config('services.google.redirect', '');

        $gbpClientId = (string) config('services.google_gbp.client_id', '');
        $gbpClientSecret = (string) config('services.google_gbp.client_secret', '');

        $this->line('config.google_login_enabled=' . ($googleEnabled ? 'true' : 'false'));
        $this->printMaskedValue('config.client_id', $clientId);
        $this->printMaskedValue('config.client_secret', $clientSecret);
        $this->line('config.redirect_uri=' . ($redirect !== '' ? $redirect : '(empty)'));
        $this->printMaskedValue('config.redirect_uri', $redirect);

        $this->printMaskedValue('config.google_gbp_client_id', $gbpClientId);
        $this->printMaskedValue('config.google_gbp_client_secret', $gbpClientSecret);

        $envFilePath = app()->environmentFilePath();
        $this->line('raw_env.file_path=' . $envFilePath);
        $envFileReadable = is_readable($envFilePath);
        $this->line('raw_env.file_readable=' . ($envFileReadable ? 'true' : 'false'));

        $this->printRawEnvDiagnostics('GOOGLE_LOGIN_ENABLED');
        $this->printRawEnvDiagnostics('GOOGLE_CLIENT_ID');
        $this->printRawEnvDiagnostics('GOOGLE_CLIENT_SECRET');
        $this->printRawEnvDiagnostics('GOOGLE_REDIRECT_URI');
        $this->printRawEnvDiagnostics('GOOGLE_GBP_CLIENT_ID');
        $this->printRawEnvDiagnostics('GOOGLE_GBP_CLIENT_SECRET');

        if (! $googleEnabled) {
            $this->errors[] = 'services.google.enabled is false; Google login redirect/callback are disabled.';
        }

        if ($clientId === '') {
            $this->errors[] = 'services.google.client_id is empty.';
        }

        if ($clientSecret === '') {
            $this->errors[] = 'services.google.client_secret is empty.';
        }

        if ($redirect === '') {
            $this->errors[] = 'services.google.redirect is empty.';
        } elseif (filter_var($redirect, FILTER_VALIDATE_URL) === false) {
            $this->errors[] = 'services.google.redirect is not a valid URL.';
        } else {
            $expectedRedirect = $this->expectedCanonicalGoogleRedirect();
            if ($expectedRedirect !== null) {
                $actualHost = $this->normalizeHost((string) parse_url($redirect, PHP_URL_HOST));
                $expectedHost = $this->normalizeHost((string) parse_url($expectedRedirect, PHP_URL_HOST));

                if ($actualHost !== null && $expectedHost !== null && ! hash_equals($actualHost, $expectedHost)) {
                    $this->warnings[] = 'services.google.redirect host does not match canonical landlord host (expected '.$expectedHost.', got '.$actualHost.').';
                }
            }
        }

        if ($clientId !== '' && $gbpClientId !== '' && hash_equals($clientId, $gbpClientId)) {
            $this->errors[] = 'Google login client_id matches GOOGLE_GBP client_id; keep login and GBP credentials distinct.';
        }

        if ($clientSecret !== '' && $gbpClientSecret !== '' && hash_equals($clientSecret, $gbpClientSecret)) {
            $this->errors[] = 'Google login client_secret matches GOOGLE_GBP client_secret; keep login and GBP credentials distinct.';
        }

        $fileLoginClientId = $this->envFileValue('GOOGLE_CLIENT_ID');
        $fileGbpClientId = $this->envFileValue('GOOGLE_GBP_CLIENT_ID');
        if ($fileLoginClientId['value'] !== null && $fileGbpClientId['value'] !== null
            && hash_equals($fileLoginClientId['value'], $fileGbpClientId['value'])) {
            $this->warnings[] = 'Raw env file values for GOOGLE_CLIENT_ID and GOOGLE_GBP_CLIENT_ID are identical.';
        }

        $fileLoginClientSecret = $this->envFileValue('GOOGLE_CLIENT_SECRET');
        $fileGbpClientSecret = $this->envFileValue('GOOGLE_GBP_CLIENT_SECRET');
        if ($fileLoginClientSecret['value'] !== null && $fileGbpClientSecret['value'] !== null
            && hash_equals($fileLoginClientSecret['value'], $fileGbpClientSecret['value'])) {
            $this->warnings[] = 'Raw env file values for GOOGLE_CLIENT_SECRET and GOOGLE_GBP_CLIENT_SECRET are identical.';
        }

        if ((bool) $this->option('token-smoke')) {
            $this->runTokenSmoke($clientId, $clientSecret, $redirect);
        }

        foreach ($this->warnings as $warning) {
            $this->warn('warning=' . $warning);
        }

        foreach ($this->errors as $error) {
            $this->error('error=' . $error);
        }

        if ($this->errors !== []) {
            $this->line('status=failed');

            return self::FAILURE;
        }

        $this->info('status=ok');

        return self::SUCCESS;
    }

    private function runTokenSmoke(string $clientId, string $clientSecret, string $redirect): void
    {
        $timeout = max(1, (int) $this->option('timeout'));

        if ($clientId === '' || $clientSecret === '' || $redirect === '') {
            $this->errors[] = '--token-smoke requested but required login config is incomplete.';

            return;
        }

        try {
            $response = Http::asForm()
                ->acceptJson()
                ->timeout($timeout)
                ->post('https://oauth2.googleapis.com/token', [
                    'code' => 'doctor-google-intentionally-invalid-code',
                    'client_id' => $clientId,
                    'client_secret' => $clientSecret,
                    'redirect_uri' => $redirect,
                    'grant_type' => 'authorization_code',
                ]);
        } catch (\Throwable $e) {
            $this->errors[] = 'Token smoke request failed: ' . $this->sanitizeMessage($e->getMessage());

            return;
        }

        $errorCode = (string) ($response->json('error') ?? '');
        $errorDescription = (string) ($response->json('error_description') ?? '');

        if ($errorCode === '' && $errorDescription === '') {
            $body = (string) $response->body();
            $errorCode = $this->extractJsonValue('error', $body) ?? '';
            $errorDescription = $this->extractJsonValue('error_description', $body) ?? '';
        }

        $failureClass = GoogleOAuthFailureClassifier::classify($errorCode, $errorDescription);

        $this->line('token_smoke.http_status=' . $response->status());
        $this->line('token_smoke.error=' . ($errorCode !== '' ? $errorCode : '(empty)'));
        $this->line('token_smoke.error_description=' . ($errorDescription !== '' ? $this->sanitizeMessage($errorDescription) : '(empty)'));
        $this->line('token_smoke.failure_class=' . $failureClass);

        if ($failureClass === GoogleOAuthFailureClassifier::INVALID_GRANT) {
            $this->info('token_smoke.result=credentials_accepted_invalid_grant_expected');

            return;
        }

        if ($failureClass === GoogleOAuthFailureClassifier::INVALID_CLIENT) {
            $this->errors[] = 'Token smoke classified as invalid_client (client ID/secret pair is rejected).';

            return;
        }

        if ($failureClass === GoogleOAuthFailureClassifier::REDIRECT_URI_MISMATCH) {
            $this->errors[] = 'Token smoke classified as redirect_uri_mismatch (Google Console redirect URI mismatch).';

            return;
        }

        $this->errors[] = 'Token smoke returned an unclassified OAuth failure; inspect error/error_description.';
    }

    private function printRawEnvDiagnostics(string $key): void
    {
        $processValue = getenv($key);
        $processString = $processValue === false ? null : (string) $processValue;
        if ($processString !== null) {
            $this->printMaskedValue("raw_env.process.{$key}", $processString);
        } else {
            $this->line("raw_env.process.{$key}=unavailable");
        }

        $fromFile = $this->envFileValue($key);
        if ($fromFile['value'] !== null) {
            $this->printMaskedValue("raw_env.file.{$key}", $fromFile['value']);
        } else {
            $this->line("raw_env.file.{$key}=unavailable");
        }

        if (($fromFile['occurrences'] ?? 0) > 1) {
            $this->errors[] = "{$key} is defined multiple times in .env ({$fromFile['occurrences']} occurrences).";
        }
    }

    private function printMaskedValue(string $prefix, string $value): void
    {
        $this->line("{$prefix}.length=" . strlen($value));
        $this->line("{$prefix}.sha1=" . sha1($value));
    }

    /**
     * @return array{value:?string,occurrences:int}
     */
    private function envFileValue(string $key): array
    {
        $path = app()->environmentFilePath();
        if (! is_readable($path)) {
            return ['value' => null, 'occurrences' => 0];
        }

        $content = file_get_contents($path);
        if (! is_string($content)) {
            return ['value' => null, 'occurrences' => 0];
        }

        $occurrences = 0;
        $value = null;

        foreach (preg_split("/\\r\\n|\\r|\\n/", $content) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || Str::startsWith($line, '#')) {
                continue;
            }

            if (! Str::startsWith($line, $key . '=')) {
                continue;
            }

            $occurrences++;
            $raw = substr($line, strlen($key) + 1);
            $value = $this->normalizeEnvValue($raw);
        }

        return ['value' => $value, 'occurrences' => $occurrences];
    }

    private function normalizeEnvValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (
            (Str::startsWith($trimmed, '"') && Str::endsWith($trimmed, '"'))
            || (Str::startsWith($trimmed, '\'') && Str::endsWith($trimmed, '\''))
        ) {
            return substr($trimmed, 1, -1);
        }

        return $trimmed;
    }

    private function extractJsonValue(string $key, string $payload): ?string
    {
        if (! preg_match('/"' . preg_quote($key, '/') . '"\s*:\s*"([^"]+)"/i', $payload, $matches)) {
            return null;
        }

        return $matches[1] ?? null;
    }

    private function sanitizeMessage(string $message): string
    {
        $sanitized = $message;
        $candidates = array_filter([
            (string) config('services.google.client_id', ''),
            (string) config('services.google.client_secret', ''),
            (string) config('services.google_gbp.client_id', ''),
            (string) config('services.google_gbp.client_secret', ''),
        ], static fn (string $value): bool => $value !== '');

        foreach (array_unique($candidates) as $candidate) {
            $sanitized = str_replace($candidate, '[REDACTED]', $sanitized);
        }

        return $sanitized;
    }

    private function expectedCanonicalGoogleRedirect(): ?string
    {
        $scheme = strtolower(trim((string) config('tenancy.domains.canonical.scheme', 'https')));
        if (! in_array($scheme, ['http', 'https'], true)) {
            $scheme = 'https';
        }

        $host = $this->normalizeHost((string) config('tenancy.landlord.primary_host', ''));
        if ($host === null) {
            $host = $this->normalizeHost((string) config('tenancy.domains.canonical.landlord_host', ''));
        }

        return $host !== null ? $scheme.'://'.$host.'/auth/google/callback' : null;
    }

    private function normalizeHost(string $host): ?string
    {
        $normalized = strtolower(trim($host));

        return $normalized !== '' ? $normalized : null;
    }
}
