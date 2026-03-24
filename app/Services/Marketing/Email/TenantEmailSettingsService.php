<?php

namespace App\Services\Marketing\Email;

use App\Models\TenantEmailSetting;
use Illuminate\Support\Facades\Schema;

class TenantEmailSettingsService
{
    /**
     * @return array<int,array{key:string,label:string,implemented:bool,description:string}>
     */
    public function availableProviders(): array
    {
        return [
            [
                'key' => 'shopify_email',
                'label' => 'Shopify Email',
                'implemented' => false,
                'description' => 'Store-native Shopify marketing email selection. App-driven sends are currently limited.',
            ],
            [
                'key' => 'sendgrid',
                'label' => 'SendGrid',
                'implemented' => true,
                'description' => 'App-driven transactional and campaign email delivery through SendGrid.',
            ],
            [
                'key' => 'custom',
                'label' => 'Custom Provider',
                'implemented' => false,
                'description' => 'Scaffolded provider slot for future custom email integrations.',
            ],
        ];
    }

    /**
     * @return array{
     *   tenant_id:?int,
     *   id:?int,
     *   email_provider:string,
     *   email_enabled:bool,
     *   from_name:?string,
     *   from_email:?string,
     *   reply_to_email:?string,
     *   provider_status:string,
     *   provider_config:array<string,mixed>,
     *   analytics_enabled:bool,
     *   last_tested_at:?string,
     *   last_error:?string,
     *   source:string
     * }
     */
    public function resolvedForTenant(?int $tenantId): array
    {
        if ($tenantId !== null && Schema::hasTable('tenant_email_settings')) {
            $setting = TenantEmailSetting::query()->where('tenant_id', $tenantId)->first();
            if ($setting) {
                return $this->normalizedFromModel($setting);
            }
        }

        return $this->fallbackFromConfig($tenantId);
    }

    /**
     * @return array{
     *   tenant_id:?int,
     *   id:?int,
     *   email_provider:string,
     *   email_enabled:bool,
     *   from_name:?string,
     *   from_email:?string,
     *   reply_to_email:?string,
     *   provider_status:string,
     *   provider_config:array<string,mixed>,
     *   analytics_enabled:bool,
     *   last_tested_at:?string,
     *   last_error:?string,
     *   source:string,
     *   available_providers:array<int,array{key:string,label:string,implemented:bool,description:string}>
     * }
     */
    public function forAdmin(?int $tenantId): array
    {
        $resolved = $this->resolvedForTenant($tenantId);

        return [
            ...$resolved,
            'provider_config' => $this->sanitizedProviderConfig(
                (string) $resolved['email_provider'],
                (array) $resolved['provider_config']
            ),
            'available_providers' => $this->availableProviders(),
        ];
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function saveForTenant(int $tenantId, array $payload): TenantEmailSetting
    {
        if (! Schema::hasTable('tenant_email_settings')) {
            throw new \RuntimeException('Tenant email settings table is missing.');
        }

        $setting = TenantEmailSetting::query()->firstOrNew(['tenant_id' => $tenantId]);
        $provider = $this->normalizedProvider((string) ($payload['email_provider'] ?? $setting->email_provider ?? 'sendgrid'));

        $existingConfig = is_array($setting->provider_config) ? $setting->provider_config : [];
        $incomingConfig = is_array($payload['provider_config'] ?? null) ? $payload['provider_config'] : [];

        $setting->forceFill([
            'email_provider' => $provider,
            'email_enabled' => (bool) ($payload['email_enabled'] ?? $setting->email_enabled ?? false),
            'from_name' => $this->nullableString($payload['from_name'] ?? $setting->from_name),
            'from_email' => $this->nullableString($payload['from_email'] ?? $setting->from_email),
            'reply_to_email' => $this->nullableString($payload['reply_to_email'] ?? $setting->reply_to_email),
            'provider_status' => $this->normalizedProviderStatus(
                (string) ($payload['provider_status'] ?? $setting->provider_status ?? 'not_configured')
            ),
            'provider_config' => $this->normalizedProviderConfig($provider, $incomingConfig, $existingConfig),
            'analytics_enabled' => (bool) ($payload['analytics_enabled'] ?? $setting->analytics_enabled ?? true),
            'last_error' => $this->nullableString($payload['last_error'] ?? $setting->last_error),
            'last_tested_at' => $payload['last_tested_at'] ?? $setting->last_tested_at,
        ])->save();

        return $setting->fresh();
    }

    public function setProviderDiagnostics(
        int $tenantId,
        string $providerStatus,
        ?string $lastError = null,
        bool $markTested = false
    ): ?TenantEmailSetting {
        if (! Schema::hasTable('tenant_email_settings')) {
            return null;
        }

        $setting = TenantEmailSetting::query()->firstOrCreate(
            ['tenant_id' => $tenantId],
            [
                'email_provider' => 'sendgrid',
                'provider_status' => 'not_configured',
                'provider_config' => [],
            ]
        );

        $setting->provider_status = $this->normalizedProviderStatus($providerStatus);
        $setting->last_error = $this->nullableString($lastError);
        if ($markTested) {
            $setting->last_tested_at = now();
        }

        $setting->save();

        return $setting->fresh();
    }

    /**
     * @return array<string,mixed>
     */
    protected function normalizedFromModel(TenantEmailSetting $setting): array
    {
        return [
            'tenant_id' => (int) $setting->tenant_id,
            'id' => (int) $setting->id,
            'email_provider' => $this->normalizedProvider((string) $setting->email_provider),
            'email_enabled' => (bool) $setting->email_enabled,
            'from_name' => $this->nullableString($setting->from_name),
            'from_email' => $this->nullableString($setting->from_email),
            'reply_to_email' => $this->nullableString($setting->reply_to_email),
            'provider_status' => $this->normalizedProviderStatus((string) $setting->provider_status),
            'provider_config' => is_array($setting->provider_config) ? $setting->provider_config : [],
            'analytics_enabled' => (bool) $setting->analytics_enabled,
            'last_tested_at' => optional($setting->last_tested_at)->toIso8601String(),
            'last_error' => $this->nullableString($setting->last_error),
            'source' => 'tenant_email_settings',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    protected function fallbackFromConfig(?int $tenantId): array
    {
        $apiKey = trim((string) (config('services.sendgrid.api_key') ?? config('services.sendgrid_api_key') ?? ''));
        $fromEmail = $this->nullableString(config('marketing.email.from_email'));
        $fromName = $this->nullableString(config('marketing.email.from_name'));
        $replyToEmail = $this->nullableString(config('marketing.email.reply_to_email'));

        return [
            'tenant_id' => $tenantId,
            'id' => null,
            'email_provider' => 'sendgrid',
            'email_enabled' => (bool) config('marketing.email.enabled', false),
            'from_name' => $fromName,
            'from_email' => $fromEmail,
            'reply_to_email' => $replyToEmail,
            'provider_status' => ($apiKey !== '' && $fromEmail !== null && $fromName !== null) ? 'configured' : 'not_configured',
            'provider_config' => [
                'api_key' => $apiKey,
                'verified_sender_email' => $fromEmail,
                'verified_sender_name' => $fromName,
                'reply_to_email' => $replyToEmail,
                'tracking_enabled' => true,
            ],
            'analytics_enabled' => true,
            'last_tested_at' => null,
            'last_error' => null,
            'source' => 'config_fallback',
        ];
    }

    /**
     * @param array<string,mixed> $incomingConfig
     * @param array<string,mixed> $existingConfig
     * @return array<string,mixed>
     */
    protected function normalizedProviderConfig(string $provider, array $incomingConfig, array $existingConfig): array
    {
        $provider = $this->normalizedProvider($provider);

        if ($provider === 'sendgrid') {
            $incomingApiKey = trim((string) ($incomingConfig['api_key'] ?? ''));
            $clearApiKey = (bool) ($incomingConfig['clear_api_key'] ?? false);
            $apiKey = $clearApiKey
                ? null
                : ($incomingApiKey !== ''
                    ? $incomingApiKey
                    : $this->nullableString($existingConfig['api_key'] ?? null));

            return [
                'api_key' => $apiKey,
                'verified_sender_email' => $this->nullableString(
                    $incomingConfig['verified_sender_email'] ?? $existingConfig['verified_sender_email'] ?? null
                ),
                'verified_sender_name' => $this->nullableString(
                    $incomingConfig['verified_sender_name'] ?? $existingConfig['verified_sender_name'] ?? null
                ),
                'reply_to_email' => $this->nullableString(
                    $incomingConfig['reply_to_email'] ?? $existingConfig['reply_to_email'] ?? null
                ),
                'tracking_enabled' => array_key_exists('tracking_enabled', $incomingConfig)
                    ? (bool) $incomingConfig['tracking_enabled']
                    : (bool) ($existingConfig['tracking_enabled'] ?? true),
                'template_defaults' => is_array($incomingConfig['template_defaults'] ?? null)
                    ? $incomingConfig['template_defaults']
                    : (is_array($existingConfig['template_defaults'] ?? null) ? $existingConfig['template_defaults'] : []),
            ];
        }

        if ($provider === 'shopify_email') {
            return [
                'use_shopify_native_email' => true,
                'supports_app_sends' => false,
                'notes' => $this->nullableString($incomingConfig['notes'] ?? $existingConfig['notes'] ?? null),
            ];
        }

        $incomingApiKey = trim((string) ($incomingConfig['api_key'] ?? ''));
        $clearApiKey = (bool) ($incomingConfig['clear_api_key'] ?? false);

        return [
            'driver' => $this->nullableString($incomingConfig['driver'] ?? $existingConfig['driver'] ?? null),
            'api_endpoint' => $this->nullableString($incomingConfig['api_endpoint'] ?? $existingConfig['api_endpoint'] ?? null),
            'auth_scheme' => $this->nullableString($incomingConfig['auth_scheme'] ?? $existingConfig['auth_scheme'] ?? null),
            'api_key' => $clearApiKey
                ? null
                : ($incomingApiKey !== '' ? $incomingApiKey : $this->nullableString($existingConfig['api_key'] ?? null)),
            'notes' => $this->nullableString($incomingConfig['notes'] ?? $existingConfig['notes'] ?? null),
            'supports_app_sends' => false,
        ];
    }

    /**
     * @param array<string,mixed> $providerConfig
     * @return array<string,mixed>
     */
    protected function sanitizedProviderConfig(string $provider, array $providerConfig): array
    {
        $provider = $this->normalizedProvider($provider);

        if ($provider === 'sendgrid') {
            $apiKey = $this->nullableString($providerConfig['api_key'] ?? null);

            return [
                'has_api_key' => $apiKey !== null,
                'api_key_masked' => $this->maskedSecret($apiKey),
                'verified_sender_email' => $this->nullableString($providerConfig['verified_sender_email'] ?? null),
                'verified_sender_name' => $this->nullableString($providerConfig['verified_sender_name'] ?? null),
                'reply_to_email' => $this->nullableString($providerConfig['reply_to_email'] ?? null),
                'tracking_enabled' => (bool) ($providerConfig['tracking_enabled'] ?? true),
                'template_defaults' => is_array($providerConfig['template_defaults'] ?? null)
                    ? $providerConfig['template_defaults']
                    : [],
            ];
        }

        if ($provider === 'shopify_email') {
            return [
                'use_shopify_native_email' => true,
                'supports_app_sends' => false,
                'notes' => $this->nullableString($providerConfig['notes'] ?? null),
            ];
        }

        $apiKey = $this->nullableString($providerConfig['api_key'] ?? null);

        return [
            'driver' => $this->nullableString($providerConfig['driver'] ?? null),
            'api_endpoint' => $this->nullableString($providerConfig['api_endpoint'] ?? null),
            'auth_scheme' => $this->nullableString($providerConfig['auth_scheme'] ?? null),
            'has_api_key' => $apiKey !== null,
            'api_key_masked' => $this->maskedSecret($apiKey),
            'notes' => $this->nullableString($providerConfig['notes'] ?? null),
            'supports_app_sends' => false,
        ];
    }

    protected function normalizedProvider(string $provider): string
    {
        $provider = strtolower(trim($provider));

        return in_array($provider, ['shopify_email', 'sendgrid', 'custom'], true)
            ? $provider
            : 'sendgrid';
    }

    protected function normalizedProviderStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return in_array($status, ['not_configured', 'configured', 'error', 'testing'], true)
            ? $status
            : 'not_configured';
    }

    protected function maskedSecret(?string $value): ?string
    {
        $value = $this->nullableString($value);
        if ($value === null) {
            return null;
        }

        if (strlen($value) <= 8) {
            return str_repeat('*', strlen($value));
        }

        return substr($value, 0, 4) . str_repeat('*', max(4, strlen($value) - 8)) . substr($value, -4);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
