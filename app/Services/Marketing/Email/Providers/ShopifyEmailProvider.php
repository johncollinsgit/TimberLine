<?php

namespace App\Services\Marketing\Email\Providers;

use App\Services\Marketing\Email\EmailProvider;

class ShopifyEmailProvider implements EmailProvider
{
    public function key(): string
    {
        return 'shopify_email';
    }

    public function label(): string
    {
        return 'Shopify Email';
    }

    public function sendEmail(array $message, array $config = []): array
    {
        return [
            'success' => false,
            'provider' => $this->key(),
            'status' => 'failed',
            'message_id' => null,
            'error_code' => 'unsupported_provider_action',
            'error_message' => 'Shopify Email is store-native and cannot be sent directly from this app flow yet.',
            'retryable' => false,
            'payload' => [
                'supports_app_sends' => false,
                'recommended_action' => 'Use SendGrid or another app-driven provider for transactional/campaign sends.',
            ],
            'dry_run' => (bool) ($message['dry_run'] ?? false),
        ];
    }

    public function sendTestEmail(string $toEmail, array $config = [], array $context = []): array
    {
        return [
            'success' => false,
            'provider' => $this->key(),
            'status' => 'failed',
            'message_id' => null,
            'error_code' => 'unsupported_provider_action',
            'error_message' => 'Shopify Email test sends are not supported from this app context.',
            'retryable' => false,
            'payload' => [
                'supports_app_sends' => false,
                'recipient_attempted' => trim($toEmail) !== '',
            ],
            'dry_run' => (bool) ($context['dry_run'] ?? false),
        ];
    }

    public function validateConfiguration(array $config = []): array
    {
        return [
            'valid' => true,
            'status' => 'configured',
            'issues' => [],
            'details' => [
                'provider' => $this->key(),
                'supports_app_sends' => false,
                'notes' => 'Selection is saved, but app-driven sending is intentionally unsupported.',
            ],
        ];
    }

    public function getHealthStatus(array $config = []): array
    {
        return [
            'status' => 'configured',
            'message' => 'Shopify Email is selected. App-driven sends remain unsupported in the current architecture.',
            'details' => [
                'provider' => $this->key(),
                'supports_app_sends' => false,
            ],
        ];
    }
}
