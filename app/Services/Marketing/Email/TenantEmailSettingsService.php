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
        $fallback = $this->fallbackFromConfig($tenantId);

        return $this->resolvedRuntimeSettings($tenantId, $fallback);
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
    public function resolvedForRuntime(?int $tenantId): array
    {
        $fallback = $this->fallbackFromConfig($tenantId);

        return $this->resolvedRuntimeSettings($tenantId, $fallback);
    }

    /**
     * @param  array<string,mixed>  $fallback
     * @return array<string,mixed>
     */
    protected function resolvedRuntimeSettings(?int $tenantId, array $fallback): array
    {
        if ($tenantId === null || ! Schema::hasTable('tenant_email_settings')) {
            return $fallback;
        }

        $setting = TenantEmailSetting::query()->where('tenant_id', $tenantId)->first();
        if (! $setting) {
            return $fallback;
        }

        return $this->normalizedFromModel($setting, $fallback);
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
        $fallback = $this->fallbackFromConfig($tenantId);
        $resolved = $this->withAdminContext($this->resolvedRuntimeSettings($tenantId, $fallback), $fallback);

        return [
            ...$resolved,
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
        $senderMode = $this->normalizedSenderMode(data_get($payload, 'provider_config.sender_mode'));

        $existingConfig = is_array($setting->provider_config) ? $setting->provider_config : [];
        $incomingConfig = is_array($payload['provider_config'] ?? null) ? $payload['provider_config'] : [];

        $setting->forceFill([
            'email_provider' => $provider,
            'email_enabled' => (bool) ($payload['email_enabled'] ?? $setting->email_enabled ?? false),
            'from_name' => $this->nullableString($payload['from_name'] ?? $setting->from_name),
            'from_email' => $this->nullableString($payload['from_email'] ?? $setting->from_email),
            'reply_to_email' => $this->nullableString($payload['reply_to_email'] ?? $setting->reply_to_email),
            'provider_status' => $this->normalizedProviderStatus(
                (string) ($payload['provider_status'] ?? $setting->provider_status ?? 'unknown'),
                $senderMode
            ),
            'provider_status_checked_at' => $payload['provider_status_checked_at']
                ?? $payload['last_tested_at']
                ?? $setting->provider_status_checked_at
                ?? $setting->last_tested_at,
            'provider_status_message' => $this->nullableString(
                $payload['provider_status_message'] ?? $payload['last_error'] ?? $setting->provider_status_message ?? $setting->last_error
            ),
            'provider_config' => $this->normalizedProviderConfig($provider, $incomingConfig, $existingConfig),
            'analytics_enabled' => (bool) ($payload['analytics_enabled'] ?? $setting->analytics_enabled ?? true),
            'last_error' => $this->nullableString(
                $payload['last_error'] ?? $payload['provider_status_message'] ?? $setting->last_error
            ),
            'last_tested_at' => $payload['last_tested_at']
                ?? $payload['provider_status_checked_at']
                ?? $setting->last_tested_at,
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
                'provider_status' => 'unknown',
                'provider_config' => [
                    'sender_mode' => 'global_fallback',
                ],
            ]
        );

        $senderMode = $this->normalizedSenderMode(data_get($setting->provider_config, 'sender_mode'));
        $message = $this->nullableString($lastError);

        $setting->provider_status = $this->normalizedProviderStatus($providerStatus, $senderMode);
        $setting->provider_status_message = $message;
        $setting->last_error = $message;
        if ($markTested) {
            $setting->provider_status_checked_at = now();
            $setting->last_tested_at = now();
        }

        $setting->save();

        return $setting->fresh();
    }

    /**
     * @return array<string,mixed>
     */
    protected function normalizedFromModel(TenantEmailSetting $setting, array $fallback): array
    {
        $provider = $this->normalizedProvider((string) $setting->email_provider);
        $resolvedFromName = $this->nullableString($setting->from_name) ?? $this->nullableString($fallback['from_name'] ?? null);
        $resolvedFromEmail = $this->nullableString($setting->from_email) ?? $this->nullableString($fallback['from_email'] ?? null);
        $resolvedReplyToEmail = $this->nullableString($setting->reply_to_email) ?? $this->nullableString($fallback['reply_to_email'] ?? null);

        $providerConfig = $this->mergedProviderConfig(
            $provider,
            is_array($setting->provider_config) ? $setting->provider_config : [],
            is_array($fallback['provider_config'] ?? null) ? $fallback['provider_config'] : [],
            [
                'from_name' => $resolvedFromName,
                'from_email' => $resolvedFromEmail,
                'reply_to_email' => $resolvedReplyToEmail,
            ],
        );

        $senderMode = $this->normalizedSenderMode($providerConfig['sender_mode'] ?? null);
        $providerStatus = $this->resolvedProviderStatus(
            (string) $setting->provider_status,
            $senderMode,
            (string) ($fallback['provider_status'] ?? 'unknown'),
        );

        $statusCheckedAt = $setting->provider_status_checked_at ?? $setting->last_tested_at;
        $statusMessage = $this->nullableString($setting->provider_status_message ?? $setting->last_error);

        return [
            'tenant_id' => (int) $setting->tenant_id,
            'id' => (int) $setting->id,
            'email_provider' => $provider,
            'email_enabled' => (bool) $setting->email_enabled,
            'from_name' => $resolvedFromName,
            'from_email' => $resolvedFromEmail,
            'reply_to_email' => $resolvedReplyToEmail,
            'provider_status' => $providerStatus,
            'provider_status_checked_at' => optional($statusCheckedAt)->toIso8601String(),
            'provider_status_message' => $statusMessage,
            'provider_config' => $providerConfig,
            'analytics_enabled' => (bool) $setting->analytics_enabled,
            'last_tested_at' => optional($statusCheckedAt)->toIso8601String(),
            'last_error' => $statusMessage,
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
            'provider_status' => ($apiKey !== '' && $fromEmail !== null) ? 'healthy' : 'unknown',
            'provider_status_checked_at' => null,
            'provider_status_message' => null,
            'provider_config' => [
                'api_key' => $apiKey,
                'api_key_source' => 'global',
                'sender_mode' => 'global_fallback',
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
                'sender_mode' => $this->normalizedSenderMode($incomingConfig['sender_mode'] ?? $existingConfig['sender_mode'] ?? null),
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
                'api_key_source' => in_array(
                    (string) ($providerConfig['api_key_source'] ?? 'tenant'),
                    ['tenant', 'global'],
                    true
                ) ? (string) $providerConfig['api_key_source'] : 'tenant',
                'sender_mode' => $this->normalizedSenderMode($providerConfig['sender_mode'] ?? null),
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

    protected function normalizedProviderStatus(string $status, ?string $senderMode = null): string
    {
        return $this->resolvedProviderStatus($status, $this->normalizedSenderMode($senderMode), 'unknown');
    }

    protected function normalizedSenderMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['global_fallback', 'single_sender', 'domain_authenticated'], true)
            ? $mode
            : 'global_fallback';
    }

    /**
     * @param  array<string,mixed>  $tenantConfig
     * @param  array<string,mixed>  $fallbackConfig
     * @param  array<string,?string>  $resolvedFields
     * @return array<string,mixed>
     */
    protected function mergedProviderConfig(
        string $provider,
        array $tenantConfig,
        array $fallbackConfig,
        array $resolvedFields = [],
    ): array {
        $provider = $this->normalizedProvider($provider);

        if ($provider === 'sendgrid') {
            $tenantApiKey = $this->nullableString($tenantConfig['api_key'] ?? null);
            $fallbackApiKey = $this->nullableString($fallbackConfig['api_key'] ?? null);
            $resolvedApiKey = $tenantApiKey ?? $fallbackApiKey;
            $resolvedReplyTo = $this->nullableString($tenantConfig['reply_to_email'] ?? null)
                ?? $this->nullableString($resolvedFields['reply_to_email'] ?? null)
                ?? $this->nullableString($fallbackConfig['reply_to_email'] ?? null);

            return [
                'api_key' => $resolvedApiKey,
                'api_key_source' => $tenantApiKey !== null ? 'tenant' : 'global',
                'sender_mode' => $this->normalizedSenderMode($tenantConfig['sender_mode'] ?? null),
                'verified_sender_email' => $this->nullableString($tenantConfig['verified_sender_email'] ?? null)
                    ?? $this->nullableString($resolvedFields['from_email'] ?? null)
                    ?? $this->nullableString($fallbackConfig['verified_sender_email'] ?? null),
                'verified_sender_name' => $this->nullableString($tenantConfig['verified_sender_name'] ?? null)
                    ?? $this->nullableString($resolvedFields['from_name'] ?? null)
                    ?? $this->nullableString($fallbackConfig['verified_sender_name'] ?? null),
                'reply_to_email' => $resolvedReplyTo,
                'tracking_enabled' => array_key_exists('tracking_enabled', $tenantConfig)
                    ? (bool) $tenantConfig['tracking_enabled']
                    : (bool) ($fallbackConfig['tracking_enabled'] ?? true),
                'template_defaults' => is_array($tenantConfig['template_defaults'] ?? null)
                    ? $tenantConfig['template_defaults']
                    : (is_array($fallbackConfig['template_defaults'] ?? null) ? $fallbackConfig['template_defaults'] : []),
                'has_api_key' => $resolvedApiKey !== null,
            ];
        }

        return $tenantConfig !== [] ? $tenantConfig : $fallbackConfig;
    }

    protected function resolvedProviderStatus(string $status, string $senderMode, string $fallbackStatus): string
    {
        $status = strtolower(trim($status));
        $senderMode = $this->normalizedSenderMode($senderMode);
        $fallbackStatus = strtolower(trim($fallbackStatus));

        $normalized = match ($status) {
            'configured', 'healthy' => 'healthy',
            'error', 'unhealthy' => 'unhealthy',
            'testing' => 'testing',
            'not_configured', 'unknown', '' => 'unknown',
            'unverified' => 'unverified',
            default => 'unknown',
        };

        if ($normalized === 'unknown' && in_array($senderMode, ['single_sender', 'domain_authenticated'], true)) {
            $normalized = 'unverified';
        }

        if (
            $senderMode === 'global_fallback'
            && in_array($normalized, ['unknown', 'unverified'], true)
            && in_array($fallbackStatus, ['configured', 'healthy'], true)
        ) {
            return 'healthy';
        }

        if ($fallbackStatus === 'unhealthy' && $normalized === 'unknown') {
            return 'unhealthy';
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>  $settings
     * @param  array<string,mixed>  $fallback
     * @return array<string,mixed>
     */
    protected function withAdminContext(array $settings, array $fallback): array
    {
        $provider = $this->normalizedProvider((string) ($settings['email_provider'] ?? 'sendgrid'));
        $providerConfig = is_array($settings['provider_config'] ?? null) ? $settings['provider_config'] : [];
        $sanitizedProviderConfig = $this->sanitizedProviderConfig($provider, $providerConfig);

        return [
            ...$settings,
            'provider_config' => $sanitizedProviderConfig,
            'provider_status_checked_at' => $settings['provider_status_checked_at'] ?? $settings['last_tested_at'] ?? null,
            'provider_status_message' => $settings['provider_status_message'] ?? $settings['last_error'] ?? null,
            'last_tested_at' => $settings['provider_status_checked_at'] ?? $settings['last_tested_at'] ?? null,
            'last_error' => $settings['provider_status_message'] ?? $settings['last_error'] ?? null,
            'resolved_preview' => [
                'from_email' => $this->nullableString($settings['from_email'] ?? null),
                'from_name' => $this->nullableString($settings['from_name'] ?? null),
                'reply_to_email' => $this->nullableString($settings['reply_to_email'] ?? null),
                'api_key_source' => (string) ($sanitizedProviderConfig['api_key_source'] ?? 'global'),
                'sender_mode' => (string) ($sanitizedProviderConfig['sender_mode'] ?? 'global_fallback'),
            ],
            'global_defaults' => [
                'from_name' => $this->nullableString($fallback['from_name'] ?? null),
                'from_email' => $this->nullableString($fallback['from_email'] ?? null),
                'reply_to_email' => $this->nullableString($fallback['reply_to_email'] ?? null),
                'provider_status' => (string) ($fallback['provider_status'] ?? 'unknown'),
                'provider_config' => $this->sanitizedProviderConfig(
                    (string) ($fallback['email_provider'] ?? 'sendgrid'),
                    is_array($fallback['provider_config'] ?? null) ? $fallback['provider_config'] : [],
                ),
            ],
        ];
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
