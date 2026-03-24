<?php

namespace App\Services\Marketing\Email;

use App\Services\Marketing\Email\Providers\CustomEmailProvider;
use App\Services\Marketing\Email\Providers\SendGridEmailProvider;
use App\Services\Marketing\Email\Providers\ShopifyEmailProvider;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Log;

class TenantEmailProviderResolver
{
    public function __construct(
        protected TenantEmailSettingsService $settingsService,
        protected TenantResolver $tenantResolver,
        protected SendGridEmailProvider $sendGridProvider,
        protected ShopifyEmailProvider $shopifyProvider,
        protected CustomEmailProvider $customProvider,
    ) {
    }

    /**
     * @return array{
     *   tenant_id:?int,
     *   provider:EmailProvider,
     *   settings:array<string,mixed>,
     *   configuration_issues:array<int,string>
     * }
     */
    public function getEmailProviderForTenant(?int $tenantId): array
    {
        $settings = $this->settingsService->resolvedForTenant($tenantId);
        $providerKey = strtolower(trim((string) ($settings['email_provider'] ?? 'sendgrid')));
        $provider = $this->providerForKey($providerKey);

        if ($provider->key() !== $providerKey) {
            Log::warning('tenant email provider resolver fallback provider selected', [
                'tenant_id' => $tenantId,
                'requested_provider' => $providerKey,
                'fallback_provider' => $provider->key(),
            ]);
        }

        $issues = $this->configurationIssues($provider->key(), $settings);
        if ($issues !== []) {
            Log::warning('tenant email provider configuration issues detected', [
                'tenant_id' => $tenantId,
                'provider' => $provider->key(),
                'issues' => $issues,
            ]);
        }

        return [
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'settings' => $settings,
            'configuration_issues' => $issues,
        ];
    }

    /**
     * @param array<string,mixed> $storeContext
     * @return array{
     *   tenant_id:?int,
     *   provider:EmailProvider,
     *   settings:array<string,mixed>,
     *   configuration_issues:array<int,string>
     * }
     */
    public function getEmailProviderForStore(array $storeContext): array
    {
        $tenantId = $this->tenantResolver->resolveTenantIdForStoreContext($storeContext);

        return $this->getEmailProviderForTenant($tenantId);
    }

    protected function providerForKey(string $providerKey): EmailProvider
    {
        return match ($providerKey) {
            'shopify_email' => $this->shopifyProvider,
            'custom' => $this->customProvider,
            default => $this->sendGridProvider,
        };
    }

    /**
     * @param array<string,mixed> $settings
     * @return array<int,string>
     */
    protected function configurationIssues(string $providerKey, array $settings): array
    {
        $issues = [];

        if (! (bool) ($settings['email_enabled'] ?? false)) {
            $issues[] = 'Email is disabled for this tenant.';
        }

        if ($providerKey === 'sendgrid') {
            $providerConfig = (array) ($settings['provider_config'] ?? []);

            if (trim((string) ($providerConfig['api_key'] ?? '')) === '') {
                $issues[] = 'SendGrid API key is missing.';
            }

            $fromEmail = trim((string) (($settings['from_email'] ?? null) ?: ($providerConfig['verified_sender_email'] ?? null)));
            if ($fromEmail === '') {
                $issues[] = 'From email is missing.';
            }

            $fromName = trim((string) (($settings['from_name'] ?? null) ?: ($providerConfig['verified_sender_name'] ?? null)));
            if ($fromName === '') {
                $issues[] = 'From name is missing.';
            }
        }

        if ($providerKey === 'custom') {
            $issues[] = 'Custom provider execution is not implemented yet.';
        }

        if ($providerKey === 'shopify_email') {
            $issues[] = 'Shopify Email is selected, but app-driven sends are not supported in this architecture.';
        }

        return $issues;
    }
}
