<?php

namespace App\Services\Shopify;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use Symfony\Component\Process\Process;

class ShopifyCliAdminClient
{
    /**
     * @var array<string,bool>
     */
    protected array $preferDirectAuth = [];

    /**
     * @var array<string,array{token:string,expires_at:int}>
     */
    protected array $appSessions = [];

    public function __construct(
        protected ?string $projectPath = null,
        protected ?string $apiVersion = null,
    ) {
        $this->projectPath = $projectPath ?: base_path();
        $this->apiVersion = $apiVersion ?: (string) config('services.shopify.api_version', '2026-01');
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    public function query(string $storeDomain, string $query, array $variables = []): array
    {
        return $this->execute($storeDomain, $query, $variables);
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    public function mutation(string $storeDomain, string $query, array $variables = []): array
    {
        return $this->execute($storeDomain, $query, $variables);
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    protected function execute(string $storeDomain, string $query, array $variables = []): array
    {
        $normalizedStore = $this->normalizeDomain($storeDomain);

        if (($this->preferDirectAuth[$normalizedStore] ?? false) === true) {
            return $this->executeViaAppCredentials($normalizedStore, $query, $variables);
        }

        try {
            return $this->executeViaCli($normalizedStore, $query, $variables);
        } catch (RuntimeException $exception) {
            if (! $this->shouldFallbackToAppCredentials($exception)) {
                throw $exception;
            }

            $this->preferDirectAuth[$normalizedStore] = true;

            return $this->executeViaAppCredentials($normalizedStore, $query, $variables);
        }
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    protected function executeViaCli(string $storeDomain, string $query, array $variables = []): array
    {
        $queryFile = $this->temporaryFile('shopify-query-', '.graphql');
        $outputFile = $this->temporaryFile('shopify-output-', '.json');
        $variablesFile = $variables !== []
            ? $this->temporaryFile('shopify-vars-', '.json')
            : null;

        try {
            file_put_contents($queryFile, $query);

            if ($variablesFile !== null) {
                file_put_contents(
                    $variablesFile,
                    json_encode($variables, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
                );
            }

            $command = [
                'shopify',
                'app',
                'execute',
                '--path',
                $this->projectPath,
                '--store',
                $storeDomain,
                '--version',
                $this->apiVersion,
                '--query-file',
                $queryFile,
                '--output-file',
                $outputFile,
                '--no-color',
            ];

            if ($variablesFile !== null) {
                $command[] = '--variable-file';
                $command[] = $variablesFile;
            }

            $process = new Process($command, $this->projectPath, timeout: 300);
            $process->run();

            if (! $process->isSuccessful()) {
                throw new RuntimeException($this->failureMessage($process));
            }

            $raw = file_get_contents($outputFile);
            if (! is_string($raw) || trim($raw) === '') {
                throw new RuntimeException('Shopify CLI did not write a JSON response.');
            }

            $decoded = json_decode($raw, true);
            if (! is_array($decoded)) {
                throw new RuntimeException('Shopify CLI response was not valid JSON.');
            }

            return $decoded;
        } finally {
            $this->cleanupFile($queryFile);
            $this->cleanupFile($outputFile);
            $this->cleanupFile($variablesFile);
        }
    }

    /**
     * @param  array<string,mixed>  $variables
     * @return array<string,mixed>
     */
    protected function executeViaAppCredentials(string $storeDomain, string $query, array $variables = []): array
    {
        return $this->directApiClient($storeDomain)->query($query, $variables);
    }

    protected function directApiClient(string $storeDomain): ShopifyGraphqlClient
    {
        $store = ShopifyStores::findByShopDomain($storeDomain) ?? $this->configuredStoreCredentials($storeDomain);
        $clientId = trim((string) ($store['client_id'] ?? ''));
        $secret = trim((string) ($store['secret'] ?? ''));

        if ($clientId === '' || $secret === '') {
            throw new RuntimeException("No configured Shopify app credentials were found for {$storeDomain}.");
        }

        $session = $this->appSessions[$storeDomain] ?? null;
        if (
            is_array($session)
            && ($session['token'] ?? '') !== ''
            && (int) ($session['expires_at'] ?? 0) > (time() + 60)
        ) {
            return new ShopifyGraphqlClient($storeDomain, $session['token'], $this->apiVersion);
        }

        $response = Http::asJson()->post("https://{$storeDomain}/admin/oauth/access_token", [
            'client_id' => $clientId,
            'client_secret' => $secret,
            'grant_type' => 'client_credentials',
        ]);

        if ($response->failed()) {
            $body = $response->json();
            $details = is_array($body)
                ? json_encode($body, JSON_UNESCAPED_SLASHES)
                : trim((string) $response->body());

            throw new RuntimeException(sprintf(
                'Shopify app authentication failed for %s (HTTP %d): %s',
                $storeDomain,
                $response->status(),
                $details !== '' ? $details : 'no response body'
            ));
        }

        $payload = $response->json();
        $token = is_array($payload) ? trim((string) ($payload['access_token'] ?? '')) : '';

        if ($token === '') {
            throw new RuntimeException("Shopify app authentication did not return an access token for {$storeDomain}.");
        }

        $expiresIn = is_array($payload) ? (int) ($payload['expires_in'] ?? 0) : 0;
        $this->appSessions[$storeDomain] = [
            'token' => $token,
            'expires_at' => time() + max(300, $expiresIn),
        ];

        return new ShopifyGraphqlClient($storeDomain, $token, $this->apiVersion);
    }

    /**
     * @return array<string,mixed>
     */
    protected function configuredStoreCredentials(string $storeDomain): array
    {
        $stores = config('services.shopify.stores', []);
        if (! is_array($stores)) {
            return [];
        }

        foreach ($stores as $key => $store) {
            if (! is_array($store)) {
                continue;
            }

            $configuredDomain = $this->normalizeDomain((string) ($store['shop'] ?? ''));
            if ($configuredDomain === '' || $configuredDomain !== $storeDomain) {
                continue;
            }

            return [
                'key' => is_string($key) ? $key : null,
                'shop' => $configuredDomain,
                'client_id' => $store['client_id'] ?? null,
                'secret' => $store['client_secret'] ?? null,
                'api_version' => $store['api_version'] ?? $this->apiVersion,
            ];
        }

        return [];
    }

    protected function temporaryFile(string $prefix, string $extension): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        if ($path === false) {
            throw new RuntimeException('Unable to create a temporary file for Shopify CLI execution.');
        }

        $target = $path.$extension;
        if (! @rename($path, $target)) {
            @unlink($path);

            throw new RuntimeException('Unable to prepare a temporary file for Shopify CLI execution.');
        }

        return $target;
    }

    protected function cleanupFile(?string $path): void
    {
        if ($path === null || $path === '' || ! is_file($path)) {
            return;
        }

        @unlink($path);
    }

    protected function failureMessage(Process $process): string
    {
        $parts = array_filter([
            trim($process->getErrorOutput()),
            trim($process->getOutput()),
        ]);

        $message = implode(PHP_EOL, $parts);

        return $message !== ''
            ? "Shopify CLI execution failed: {$message}"
            : 'Shopify CLI execution failed with no additional output.';
    }

    protected function shouldFallbackToAppCredentials(RuntimeException $exception): bool
    {
        return str_contains($exception->getMessage(), 'IN operator is not supported for store_type filter');
    }

    protected function normalizeDomain(string $storeDomain): string
    {
        $normalized = strtolower((string) preg_replace('#^https?://#', '', $storeDomain));

        return rtrim($normalized, '/');
    }
}
