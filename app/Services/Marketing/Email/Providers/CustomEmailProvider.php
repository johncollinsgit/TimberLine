<?php

namespace App\Services\Marketing\Email\Providers;

use App\Services\Marketing\Email\EmailProvider;

class CustomEmailProvider implements EmailProvider
{
    public function key(): string
    {
        return 'custom';
    }

    public function label(): string
    {
        return 'Custom Provider';
    }

    public function sendEmail(array $message, array $config = []): array
    {
        return [
            'success' => false,
            'provider' => $this->key(),
            'status' => 'failed',
            'message_id' => null,
            'error_code' => 'not_implemented',
            'error_message' => 'Custom provider sending is not implemented yet.',
            'retryable' => false,
            'payload' => [
                'supports_app_sends' => false,
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
            'error_code' => 'not_implemented',
            'error_message' => 'Custom provider test sending is not implemented yet.',
            'retryable' => false,
            'payload' => [
                'supports_app_sends' => false,
            ],
            'dry_run' => (bool) ($context['dry_run'] ?? false),
        ];
    }

    public function validateConfiguration(array $config = []): array
    {
        $apiEndpoint = trim((string) ($config['api_endpoint'] ?? ''));

        return [
            'valid' => false,
            'status' => $apiEndpoint !== '' ? 'configured' : 'not_configured',
            'issues' => ['Custom provider execution is not implemented yet.'],
            'details' => [
                'provider' => $this->key(),
                'api_endpoint_present' => $apiEndpoint !== '',
                'supports_app_sends' => false,
            ],
        ];
    }

    public function getHealthStatus(array $config = []): array
    {
        return [
            'status' => 'error',
            'message' => 'Custom provider support is scaffolded but not implemented.',
            'details' => [
                'provider' => $this->key(),
                'supports_app_sends' => false,
            ],
        ];
    }
}
