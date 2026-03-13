<?php

namespace App\Services\Marketing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class SendGridEmailService
{
    /**
     * @param array<string,mixed> $options
     * @return array{
     *   success:bool,
     *   provider:string,
     *   message_id:?string,
     *   status:string,
     *   error_message:?string,
     *   payload:array<string,mixed>,
     *   dry_run:bool
     * }
     */
    public function sendEmail(string $toEmail, string $subject, string $bodyText, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false) || (bool) config('marketing.email.dry_run', false);
        $enabled = (bool) config('marketing.email.enabled', false);
        $apiKey = trim((string) (config('services.sendgrid.api_key') ?: config('services.sendgrid_api_key')));

        $toEmail = trim($toEmail);
        $subject = trim($subject);
        $bodyText = trim($bodyText);
        if ($toEmail === '' || $subject === '' || $bodyText === '') {
            return [
                'success' => false,
                'provider' => 'sendgrid',
                'message_id' => null,
                'status' => 'failed',
                'error_message' => 'Missing destination email, subject, or body.',
                'payload' => [],
                'dry_run' => $dryRun,
            ];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'provider' => 'sendgrid',
                'message_id' => 'DRYRUN-SG-' . strtoupper(bin2hex(random_bytes(6))),
                'status' => 'sent',
                'error_message' => null,
                'payload' => [
                    'dry_run' => true,
                    'to' => $toEmail,
                    'subject' => $subject,
                ],
                'dry_run' => true,
            ];
        }

        if (! $enabled) {
            return [
                'success' => false,
                'provider' => 'sendgrid',
                'message_id' => null,
                'status' => 'failed',
                'error_message' => 'Email sending is disabled by configuration.',
                'payload' => [],
                'dry_run' => false,
            ];
        }

        if ($apiKey === '') {
            return [
                'success' => false,
                'provider' => 'sendgrid',
                'message_id' => null,
                'status' => 'failed',
                'error_message' => 'SendGrid API key is not configured.',
                'payload' => [],
                'dry_run' => false,
            ];
        }

        $fromEmail = trim((string) config('marketing.email.from_email', ''));
        $fromName = trim((string) config('marketing.email.from_name', 'Timberline'));
        if ($fromEmail === '') {
            return [
                'success' => false,
                'provider' => 'sendgrid',
                'message_id' => null,
                'status' => 'failed',
                'error_message' => 'SendGrid from email is not configured.',
                'payload' => [],
                'dry_run' => false,
            ];
        }

        $customArgs = is_array($options['custom_args'] ?? null) ? $options['custom_args'] : [];

        $payload = [
            'personalizations' => [[
                'to' => [['email' => $toEmail]],
                'subject' => $subject,
                'custom_args' => $customArgs,
            ]],
            'from' => [
                'email' => $fromEmail,
                'name' => $fromName,
            ],
            'content' => [[
                'type' => 'text/plain',
                'value' => $bodyText,
            ]],
        ];

        try {
            $response = $this->request($apiKey)->post('https://api.sendgrid.com/v3/mail/send', $payload);
            $messageId = trim((string) $response->header('X-Message-Id', '')) ?: null;

            if ($response->failed()) {
                return [
                    'success' => false,
                    'provider' => 'sendgrid',
                    'message_id' => $messageId,
                    'status' => 'failed',
                    'error_message' => 'SendGrid request failed with status ' . $response->status(),
                    'payload' => [
                        'request' => $payload,
                        'response' => $response->json() ?: ['body' => $response->body()],
                    ],
                    'dry_run' => false,
                ];
            }

            return [
                'success' => true,
                'provider' => 'sendgrid',
                'message_id' => $messageId,
                'status' => 'sent',
                'error_message' => null,
                'payload' => [
                    'request' => $payload,
                    'status_code' => $response->status(),
                ],
                'dry_run' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'provider' => 'sendgrid',
                'message_id' => null,
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'payload' => ['request' => $payload],
                'dry_run' => false,
            ];
        }
    }

    protected function request(string $apiKey): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 200, throw: false)
            ->withToken($apiKey);
    }
}
