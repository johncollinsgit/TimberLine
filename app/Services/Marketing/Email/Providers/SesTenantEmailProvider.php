<?php

namespace App\Services\Marketing\Email\Providers;

use App\Services\Marketing\Email\EmailProvider;
use Aws\Credentials\Credentials;
use Aws\SesV2\SesV2Client;

class SesTenantEmailProvider implements EmailProvider
{
    public function key(): string
    {
        return 'ses_tenant';
    }

    public function label(): string
    {
        return 'Amazon SES';
    }

    public function sendEmail(array $message, array $config = []): array
    {
        $to = trim((string) ($message['to_email'] ?? ''));
        $subject = trim((string) ($message['subject'] ?? ''));
        $from = trim((string) (($message['from_email'] ?? null) ?: ($config['from_email'] ?? null)));
        $fromName = trim((string) (($message['from_name'] ?? null) ?: ($config['from_name'] ?? null)));
        $text = trim((string) ($message['text'] ?? ''));
        $html = trim((string) ($message['html'] ?? ''));
        $dryRun = (bool) ($message['dry_run'] ?? false);

        if ($to === '' || $subject === '' || $from === '' || ($text === '' && $html === '')) {
            return $this->failure('malformed_payload', 'Recipient, sender, subject, and message body are required.', false, $dryRun);
        }

        if ($dryRun) {
            return $this->success('DRYRUN-SES-'.strtoupper(bin2hex(random_bytes(6))), true);
        }

        $validation = $this->validateConfiguration($config);
        if (! $validation['valid']) {
            return $this->failure('invalid_configuration', implode(' ', $validation['issues']), false, false);
        }

        $body = [];
        if ($text !== '') {
            $body['Text'] = ['Data' => $text, 'Charset' => 'UTF-8'];
        }
        if ($html !== '') {
            $body['Html'] = ['Data' => $html, 'Charset' => 'UTF-8'];
        }

        $payload = array_filter([
            'FromEmailAddress' => $fromName !== '' ? sprintf('%s <%s>', $fromName, $from) : $from,
            'Destination' => ['ToAddresses' => [$to]],
            'ReplyToAddresses' => array_values(array_filter([(string) ($message['reply_to_email'] ?? '')])),
            'Content' => ['Simple' => [
                'Subject' => ['Data' => $subject, 'Charset' => 'UTF-8'],
                'Body' => $body,
            ]],
            'ConfigurationSetName' => $this->nullableString($config['configuration_set'] ?? null),
            'TenantName' => $this->nullableString($config['tenant_name'] ?? null),
            'EmailTags' => $this->emailTags((array) ($message['metadata'] ?? [])),
        ], fn (mixed $value): bool => $value !== null && $value !== []);

        try {
            $result = $this->client($config)->sendEmail($payload);

            return $this->success($this->nullableString($result->get('MessageId')), false);
        } catch (\Throwable $exception) {
            return $this->failure('provider_error', $exception->getMessage(), true, false);
        }
    }

    public function sendTestEmail(string $toEmail, array $config = [], array $context = []): array
    {
        return $this->sendEmail([
            'to_email' => $toEmail,
            'subject' => (string) ($context['subject'] ?? 'Email settings test'),
            'text' => 'This is an Everbranch email settings test.',
            'from_email' => $config['from_email'] ?? null,
            'from_name' => $config['from_name'] ?? null,
            'reply_to_email' => $config['reply_to_email'] ?? null,
            'dry_run' => (bool) ($context['dry_run'] ?? false),
            'metadata' => ['tenant_id' => (string) ($context['tenant_id'] ?? '')],
        ], $config);
    }

    public function validateConfiguration(array $config = []): array
    {
        $issues = [];
        foreach (['region', 'access_key', 'secret_key', 'tenant_name'] as $key) {
            if (trim((string) ($config[$key] ?? '')) === '') {
                $issues[] = "SES {$key} is missing.";
            }
        }

        return [
            'valid' => $issues === [],
            'status' => $issues === [] ? 'healthy' : 'unverified',
            'issues' => $issues,
            'details' => ['provider' => $this->key(), 'tenant_name' => $config['tenant_name'] ?? null],
        ];
    }

    public function getHealthStatus(array $config = []): array
    {
        $validation = $this->validateConfiguration($config);

        return [
            'status' => $validation['status'],
            'message' => $validation['valid'] ? 'SES tenant configuration is ready.' : implode(' ', $validation['issues']),
            'details' => $validation['details'],
        ];
    }

    protected function client(array $config): SesV2Client
    {
        return new SesV2Client([
            'version' => 'latest',
            'region' => (string) $config['region'],
            'credentials' => new Credentials(
                (string) $config['access_key'],
                (string) $config['secret_key'],
                $this->nullableString($config['session_token'] ?? null),
            ),
        ]);
    }

    /** @return array<int,array{Name:string,Value:string}> */
    protected function emailTags(array $metadata): array
    {
        return collect($metadata)->map(function (mixed $value, mixed $key): ?array {
            $name = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $key);
            $tagValue = preg_replace('/[^A-Za-z0-9_-]/', '-', (string) $value);

            return $name !== '' && $tagValue !== ''
                ? ['Name' => substr($name, 0, 256), 'Value' => substr($tagValue, 0, 256)]
                : null;
        })->filter()->values()->all();
    }

    protected function success(?string $messageId, bool $dryRun): array
    {
        return [
            'success' => true, 'provider' => $this->key(), 'status' => 'sent',
            'message_id' => $messageId, 'error_code' => null, 'error_message' => null,
            'retryable' => false, 'payload' => [], 'dry_run' => $dryRun,
        ];
    }

    protected function failure(string $code, string $message, bool $retryable, bool $dryRun): array
    {
        return [
            'success' => false, 'provider' => $this->key(), 'status' => 'failed',
            'message_id' => null, 'error_code' => $code, 'error_message' => $message,
            'retryable' => $retryable, 'payload' => [], 'dry_run' => $dryRun,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
