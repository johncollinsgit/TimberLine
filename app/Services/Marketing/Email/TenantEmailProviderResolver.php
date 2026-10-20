<?php

namespace App\Services\Marketing\Email;

use App\Services\Marketing\Email\Providers\CustomEmailProvider;
use App\Services\Marketing\Email\Providers\SendGridEmailProvider;
use App\Services\Marketing\Email\Providers\SesTenantEmailProvider;
use App\Services\Marketing\Email\Providers\ShopifyEmailProvider;
use App\Services\Tenancy\TenantResolver;
use Illuminate\Support\Facades\Log;

class TenantEmailProviderResolver
{
    public function __construct(
        protected TenantEmailSettingsService $settingsService,
        protected TenantResolver $tenantResolver,
        protected SendGridEmailProvider $sendGridProvider,
        protected SesTenantEmailProvider $sesTenantProvider,
        protected ShopifyEmailProvider $shopifyProvider,
        protected CustomEmailProvider $customProvider,
    ) {}

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
        if ($tenantId && (config('features.tenant_messaging_platform')
            || (config('ses_migration.tenant_enabled') && in_array($tenantId, config('ses_migration.tenant_ids', []), true)))) {
            $account = \App\Models\TenantMessagingAccount::query()->forAllTenants()->where('tenant_id', $tenantId)
                ->where('channel', 'email')->where('provider', 'ses_tenant')->first();
            if ($account) {
                $issues = [];
                $sender = [];
                try {
                    $sender = app(\App\Services\Marketing\Messaging\TenantMessagingSenderProfileService::class)->resolveEmailSender($account);
                } catch (\Throwable) {
                    $issues[] = 'A verified tenant sender and reply destination are required.';
                }
                $enabled = $account->isReady() && config('ses_migration.tenant_enabled')
                    && in_array($tenantId, config('ses_migration.tenant_ids', []), true);
                $config = [
                    'access_key' => config('services.ses.key'), 'secret_key' => config('services.ses.secret'),
                    'session_token' => config('services.ses.token'), 'region' => config('services.ses.region'),
                    ...(array) $account->credentials, ...(array) $account->provider_config,
                ];

                return ['tenant_id' => $tenantId, 'provider' => $this->sesTenantProvider,
                    'configuration_issues' => $issues, 'settings' => [
                        'email_provider' => 'ses_tenant', 'email_enabled' => $enabled && $issues === [],
                        'from_email' => $sender['from_email'] ?? null, 'from_name' => $sender['from_name'] ?? null,
                        'reply_to_email' => $sender['reply_to_email'] ?? null,
                        'provider_status' => $enabled ? 'configured' : 'unverified',
                        'provider_config' => $config, 'source' => 'tenant_email_settings',
                    ]];
            }
        }
        $settings = $this->settingsService->resolvedForRuntime($tenantId);
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
     * @param  array<string,mixed>  $storeContext
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
            'ses_tenant' => $this->sesTenantProvider,
            'sendgrid_subuser' => $this->sendGridProvider,
            'shopify_email' => $this->shopifyProvider,
            'custom' => $this->customProvider,
            default => $this->sendGridProvider,
        };
    }

    /**
     * @param  array<string,mixed>  $settings
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
            $senderMode = strtolower(trim((string) ($providerConfig['sender_mode'] ?? 'global_fallback')));
            $providerStatus = strtolower(trim((string) ($settings['provider_status'] ?? 'unknown')));

            if (trim((string) ($providerConfig['api_key'] ?? '')) === '') {
                $issues[] = 'SendGrid API key is missing.';
            }

            $fromEmail = trim((string) (($settings['from_email'] ?? null) ?: ($providerConfig['verified_sender_email'] ?? null)));
            if ($fromEmail === '') {
                $issues[] = 'From email is missing.';
            }

            if (
                in_array($senderMode, ['single_sender', 'domain_authenticated'], true)
                && ! in_array($providerStatus, ['healthy', 'configured'], true)
            ) {
                $issues[] = 'Sender identity verification is incomplete for this tenant.';
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
