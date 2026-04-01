<?php

namespace App\Services\Marketing\Email\Providers;

use App\Services\Marketing\Email\EmailProvider;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class SendGridEmailProvider implements EmailProvider
{
    public function key(): string
    {
        return 'sendgrid';
    }

    public function label(): string
    {
        return 'SendGrid';
    }

    public function sendEmail(array $message, array $config = []): array
    {
        $toEmail = trim((string) ($message['to_email'] ?? ''));
        $subject = trim((string) ($message['subject'] ?? ''));
        $textBody = $this->nullableString($message['text'] ?? null);
        $htmlBody = $this->nullableString($message['html'] ?? null);
        $fromEmail = $this->nullableString($message['from_email'] ?? null)
            ?? $this->nullableString($config['from_email'] ?? null)
            ?? $this->nullableString($config['verified_sender_email'] ?? null);
        $fromName = $this->nullableString($message['from_name'] ?? null)
            ?? $this->nullableString($config['from_name'] ?? null)
            ?? $this->nullableString($config['verified_sender_name'] ?? null)
            ?? 'Timberline';
        $replyTo = $this->nullableString($message['reply_to_email'] ?? null)
            ?? $this->nullableString($config['reply_to_email'] ?? null);
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $dryRun = (bool) ($message['dry_run'] ?? false);
        $trackingEnabled = array_key_exists('tracking_enabled', $config)
            ? (bool) $config['tracking_enabled']
            : true;

        if ($toEmail === '' || $subject === '') {
            return $this->failure('malformed_payload', 'Missing destination email or subject.', false, [
                'to_email_present' => $toEmail !== '',
                'subject_present' => $subject !== '',
            ], $dryRun);
        }

        if ($fromEmail === '') {
            return $this->failure('missing_from_email', 'A from email is required for SendGrid sending.', false, [
                'from_email_present' => false,
            ], $dryRun);
        }

        $content = $this->contentBlocks($textBody, $htmlBody);
        if ($content === []) {
            return $this->failure('malformed_payload', 'Email body is required.', false, [
                'text_present' => $textBody !== null,
                'html_present' => $htmlBody !== null,
            ], $dryRun);
        }

        if ($dryRun) {
            return [
                'success' => true,
                'provider' => $this->key(),
                'status' => 'sent',
                'message_id' => 'DRYRUN-SG-' . strtoupper(bin2hex(random_bytes(6))),
                'error_code' => null,
                'error_message' => null,
                'retryable' => false,
                'payload' => [
                    'dry_run' => true,
                    'to' => $toEmail,
                    'subject' => $subject,
                ],
                'dry_run' => true,
            ];
        }

        if ($apiKey === '') {
            return $this->failure('missing_api_key', 'SendGrid API key is not configured.', false, [
                'api_key_present' => false,
            ], false);
        }

        $payload = [
            'personalizations' => [[
                'to' => [['email' => $toEmail]],
                'subject' => $subject,
                'custom_args' => $this->normalizedCustomArgs(
                    array_merge(
                        (array) ($message['metadata'] ?? []),
                        (array) ($message['custom_args'] ?? [])
                    )
                ),
            ]],
            'from' => [
                'email' => $fromEmail,
                'name' => $fromName,
            ],
            'content' => $content,
            'tracking_settings' => [
                'click_tracking' => [
                    'enable' => $trackingEnabled,
                    'enable_text' => $trackingEnabled,
                ],
                'open_tracking' => [
                    'enable' => $trackingEnabled,
                ],
            ],
        ];

        $categories = $this->normalizedCategories(
            (array) ($message['categories'] ?? []),
            (array) ($message['metadata'] ?? [])
        );
        if ($categories !== []) {
            $payload['categories'] = $categories;
        }

        if ($replyTo !== null) {
            $payload['reply_to'] = [
                'email' => $replyTo,
                'name' => $fromName,
            ];
        }

        try {
            $response = $this->request($apiKey)->post('https://api.sendgrid.com/v3/mail/send', $payload);
        } catch (\Throwable $exception) {
            return $this->failure('provider_unreachable', $exception->getMessage(), true, [
                'exception' => get_class($exception),
            ], false);
        }

        $messageId = $this->nullableString($response->header('X-Message-Id'));
        if ($response->successful()) {
            return [
                'success' => true,
                'provider' => $this->key(),
                'status' => 'sent',
                'message_id' => $messageId,
                'error_code' => null,
                'error_message' => null,
                'retryable' => false,
                'payload' => [
                    'http_status' => $response->status(),
                    'response_headers' => [
                        'x-message-id' => $messageId,
                    ],
                ],
                'dry_run' => false,
            ];
        }

        [$errorCode, $errorMessage, $retryable] = $this->errorFromResponse($response);

        return $this->failure($errorCode, $errorMessage, $retryable, [
            'http_status' => $response->status(),
            'response' => $response->json() ?? ['body' => $response->body()],
            'message_id' => $messageId,
        ], false, $messageId);
    }

    public function sendTestEmail(string $toEmail, array $config = [], array $context = []): array
    {
        $toEmail = trim($toEmail);
        if ($toEmail === '') {
            return $this->failure('malformed_payload', 'Test email recipient is required.', false, [
                'recipient_present' => false,
            ], (bool) ($context['dry_run'] ?? false));
        }

        $subject = trim((string) ($context['subject'] ?? 'Email settings test'));
        if ($subject === '') {
            $subject = 'Email settings test';
        }

        $tenantLabel = trim((string) ($context['tenant_label'] ?? 'Current tenant'));
        $timestamp = now()->toDateTimeString();

        return $this->sendEmail([
            'to_email' => $toEmail,
            'subject' => $subject,
            'text' => "This is a SendGrid test email for {$tenantLabel}.\n\nSent at {$timestamp}.",
            'html' => '<p>This is a <strong>SendGrid test email</strong> for ' . e($tenantLabel) . '.</p>'
                . '<p>Sent at ' . e($timestamp) . '.</p>',
            'from_email' => $config['from_email'] ?? null,
            'from_name' => $config['from_name'] ?? null,
            'reply_to_email' => $config['reply_to_email'] ?? null,
            'metadata' => [
                'email_test' => 'true',
                'tenant_id' => (string) ($context['tenant_id'] ?? ''),
                'campaign_type' => 'email_settings_test',
            ],
            'custom_args' => [
                'email_test' => 'true',
                'tenant_id' => (string) ($context['tenant_id'] ?? ''),
                'template_key' => 'email_settings_test',
            ],
            'categories' => ['email-settings-test'],
            'dry_run' => (bool) ($context['dry_run'] ?? false),
        ], $config);
    }

    public function validateConfiguration(array $config = []): array
    {
        $apiKey = trim((string) ($config['api_key'] ?? ''));
        $fromEmail = $this->nullableString($config['from_email'] ?? null)
            ?? $this->nullableString($config['verified_sender_email'] ?? null);
        $performLiveCheck = (bool) ($config['perform_live_check'] ?? true);
        $senderMode = $this->normalizedSenderMode($config['sender_mode'] ?? null);
        $providerStatus = $this->normalizedProviderStatus($config['provider_status'] ?? null, $senderMode);

        $issues = [];
        if ($apiKey === '') {
            $issues[] = 'SendGrid API key is missing.';
        }
        if ($fromEmail === null) {
            $issues[] = 'From email is missing.';
        }

        if ($issues !== []) {
            return [
                'valid' => false,
                'status' => $senderMode === 'global_fallback' ? 'unknown' : 'unverified',
                'issues' => $issues,
                'details' => [
                    'provider' => $this->key(),
                    'live_check' => false,
                    'sender_mode' => $senderMode,
                ],
            ];
        }

        if (! $performLiveCheck) {
            if (
                in_array($senderMode, ['single_sender', 'domain_authenticated'], true)
                && $providerStatus !== 'healthy'
            ) {
                return [
                    'valid' => false,
                    'status' => 'unverified',
                    'issues' => [$this->verificationGuidance($senderMode)],
                    'details' => [
                        'provider' => $this->key(),
                        'live_check' => false,
                        'sender_mode' => $senderMode,
                        'requires_sender_verification' => true,
                    ],
                ];
            }

            return [
                'valid' => true,
                'status' => 'healthy',
                'issues' => [],
                'details' => [
                    'provider' => $this->key(),
                    'live_check' => false,
                    'sender_mode' => $senderMode,
                ],
            ];
        }

        try {
            $response = $this->request($apiKey)->get('https://api.sendgrid.com/v3/user/account');
        } catch (\Throwable $exception) {
            return [
                'valid' => false,
                'status' => 'unhealthy',
                'issues' => ['SendGrid health check failed: ' . $exception->getMessage()],
                'details' => [
                    'provider' => $this->key(),
                    'live_check' => true,
                    'exception' => get_class($exception),
                    'sender_mode' => $senderMode,
                ],
            ];
        }

        if ($response->failed()) {
            [$errorCode, $errorMessage] = $this->errorFromResponse($response);

            return [
                'valid' => false,
                'status' => 'unhealthy',
                'issues' => [$errorMessage],
                'details' => [
                    'provider' => $this->key(),
                    'live_check' => true,
                    'error_code' => $errorCode,
                    'http_status' => $response->status(),
                    'sender_mode' => $senderMode,
                ],
            ];
        }

        if (
            in_array($senderMode, ['single_sender', 'domain_authenticated'], true)
            && $providerStatus !== 'healthy'
        ) {
            return [
                'valid' => false,
                'status' => 'unverified',
                'issues' => [$this->verificationGuidance($senderMode)],
                'details' => [
                    'provider' => $this->key(),
                    'live_check' => true,
                    'http_status' => $response->status(),
                    'sender_mode' => $senderMode,
                    'requires_sender_verification' => true,
                ],
            ];
        }

        return [
            'valid' => true,
            'status' => 'healthy',
            'issues' => [],
            'details' => [
                'provider' => $this->key(),
                'live_check' => true,
                'http_status' => $response->status(),
                'sender_mode' => $senderMode,
            ],
        ];
    }

    public function getHealthStatus(array $config = []): array
    {
        $validation = $this->validateConfiguration([
            ...$config,
            'perform_live_check' => (bool) ($config['perform_live_check'] ?? true),
        ]);

        if (! (bool) ($validation['valid'] ?? false)) {
            return [
                'status' => (string) ($validation['status'] ?? 'error'),
                'message' => (string) (($validation['issues'][0] ?? 'SendGrid is not healthy.')),
                'details' => [
                    ...((array) ($validation['details'] ?? [])),
                    'issues' => $validation['issues'] ?? [],
                    'supports_app_sends' => true,
                ],
            ];
        }

        return [
            'status' => 'healthy',
            'message' => 'SendGrid configuration is healthy.',
            'details' => [
                ...((array) ($validation['details'] ?? [])),
                'supports_app_sends' => true,
            ],
        ];
    }

    /**
     * @return array<int,array{type:string,value:string}>
     */
    protected function contentBlocks(?string $textBody, ?string $htmlBody): array
    {
        $content = [];

        if ($textBody !== null) {
            $content[] = [
                'type' => 'text/plain',
                'value' => $textBody,
            ];
        }

        if ($htmlBody !== null) {
            $content[] = [
                'type' => 'text/html',
                'value' => $htmlBody,
            ];
        }

        if ($content === [] && $htmlBody !== null) {
            $content[] = [
                'type' => 'text/plain',
                'value' => trim(strip_tags($htmlBody)),
            ];
        }

        if ($content === [] && $textBody !== null) {
            $content[] = [
                'type' => 'text/plain',
                'value' => $textBody,
            ];
        }

        return $content;
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,string>
     */
    protected function normalizedCustomArgs(array $data): array
    {
        $args = [];

        foreach ($data as $key => $value) {
            $normalizedKey = strtolower(trim((string) $key));
            if ($normalizedKey === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            } elseif (is_scalar($value) || $value === null) {
                $value = (string) $value;
            } else {
                $value = json_encode($value);
            }

            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }

            $args[$normalizedKey] = mb_substr($value, 0, 1000);
        }

        return $args;
    }

    /**
     * @param array<int,string> $categories
     * @param array<string,mixed> $metadata
     * @return array<int,string>
     */
    protected function normalizedCategories(array $categories, array $metadata): array
    {
        $values = collect($categories)
            ->map(fn ($value) => strtolower(trim((string) $value)))
            ->filter()
            ->values();

        $campaignType = strtolower(trim((string) ($metadata['campaign_type'] ?? '')));
        if ($campaignType !== '') {
            $values->push('campaign-' . str_replace('_', '-', $campaignType));
        }

        $templateKey = strtolower(trim((string) ($metadata['template_key'] ?? '')));
        if ($templateKey !== '') {
            $values->push('template-' . str_replace('_', '-', $templateKey));
        }

        return $values
            ->map(fn (string $value): string => mb_substr($value, 0, 255))
            ->unique()
            ->take(10)
            ->values()
            ->all();
    }

    /**
     * @return array{0:string,1:string,2:bool}
     */
    protected function errorFromResponse(Response $response): array
    {
        $status = $response->status();
        $errors = collect((array) data_get($response->json() ?? [], 'errors', []));
        $message = trim((string) ($errors->first()['message'] ?? ''));

        if ($message === '') {
            $message = 'SendGrid request failed with status ' . $status . '.';
        }

        $lower = strtolower($message);

        if (in_array($status, [401, 403], true)) {
            return ['invalid_api_key', 'SendGrid API key is invalid or unauthorized.', false];
        }

        if ($status === 429) {
            return ['rate_limited', 'SendGrid rate limit reached. Try again shortly.', true];
        }

        if ($status >= 500) {
            return ['provider_failure', 'SendGrid experienced a provider error.', true];
        }

        if (str_contains($lower, 'verified sender') || str_contains($lower, 'sender identity')) {
            return ['unauthorized_sender', $message, false];
        }

        return ['malformed_payload', $message, false];
    }

    protected function normalizedSenderMode(mixed $mode): string
    {
        $mode = strtolower(trim((string) $mode));

        return in_array($mode, ['global_fallback', 'single_sender', 'domain_authenticated'], true)
            ? $mode
            : 'global_fallback';
    }

    protected function normalizedProviderStatus(mixed $status, string $senderMode): string
    {
        $status = strtolower(trim((string) $status));

        return match ($status) {
            'configured', 'healthy' => 'healthy',
            'error', 'unhealthy' => 'unhealthy',
            'unverified' => 'unverified',
            default => $senderMode === 'global_fallback' ? 'unknown' : 'unverified',
        };
    }

    protected function verificationGuidance(string $senderMode): string
    {
        return $senderMode === 'domain_authenticated'
            ? 'Authenticate the tenant domain in SendGrid with SPF/DKIM, then run a test send to confirm delivery.'
            : 'Verify the sender address in SendGrid, then run a test send to confirm delivery.';
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   success:false,
     *   provider:string,
     *   status:string,
     *   message_id:?string,
     *   error_code:string,
     *   error_message:string,
     *   retryable:bool,
     *   payload:array<string,mixed>,
     *   dry_run:bool
     * }
     */
    protected function failure(
        string $errorCode,
        string $message,
        bool $retryable,
        array $payload,
        bool $dryRun,
        ?string $messageId = null
    ): array {
        return [
            'success' => false,
            'provider' => $this->key(),
            'status' => 'failed',
            'message_id' => $messageId,
            'error_code' => $errorCode,
            'error_message' => $message,
            'retryable' => $retryable,
            'payload' => $payload,
            'dry_run' => $dryRun,
        ];
    }

    protected function request(string $apiKey): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->timeout(20)
            ->retry(2, 250, throw: false)
            ->withToken($apiKey);
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
