<?php

namespace App\Services\Marketing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TwilioSmsService
{
    public function __construct(
        protected ?string $accountSid = null,
        protected ?string $authToken = null,
        protected ?string $messagingServiceSid = null,
        protected ?string $fromNumber = null,
        protected ?string $statusCallbackUrl = null
    ) {
        $this->accountSid = $this->accountSid ?: (string) config('marketing.twilio.account_sid');
        $this->authToken = $this->authToken ?: (string) config('marketing.twilio.auth_token');
        $this->messagingServiceSid = $this->messagingServiceSid ?: $this->nullableString(config('marketing.twilio.messaging_service_sid'));
        $this->fromNumber = $this->fromNumber ?: $this->nullableString(config('marketing.twilio.from_number'));
        $this->statusCallbackUrl = $this->statusCallbackUrl ?: $this->nullableString(config('marketing.twilio.status_callback_url'));
    }

    /**
     * @param array<string,mixed> $options
     * @return array{
     *   success:bool,
     *   provider:string,
     *   provider_message_id:?string,
     *   status:string,
     *   error_code:?string,
     *   error_message:?string,
     *   from_identifier:?string,
     *   payload:array<string,mixed>,
     *   dry_run:bool
     * }
     */
    public function sendSms(string $toPhone, string $message, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false) || (bool) config('marketing.sms.dry_run');
        $enabled = (bool) config('marketing.sms.enabled');
        $twilioEnabled = (bool) config('marketing.twilio.enabled');

        $toPhone = trim($toPhone);
        $message = trim($message);
        if ($toPhone === '' || $message === '') {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => 'invalid_payload',
                'error_message' => 'Missing destination phone or message body.',
                'from_identifier' => $this->fromIdentifier(),
                'payload' => [],
                'dry_run' => $dryRun,
            ];
        }

        if ($dryRun) {
            return [
                'success' => true,
                'provider' => 'twilio',
                'provider_message_id' => 'DRYRUN-' . Str::upper(Str::random(20)),
                'status' => 'sent',
                'error_code' => null,
                'error_message' => null,
                'from_identifier' => $this->fromIdentifier(),
                'payload' => [
                    'dry_run' => true,
                    'to' => $toPhone,
                    'body' => $message,
                ],
                'dry_run' => true,
            ];
        }

        if (!$enabled || !$twilioEnabled) {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => 'sms_disabled',
                'error_message' => 'SMS sending is disabled by configuration.',
                'from_identifier' => $this->fromIdentifier(),
                'payload' => [],
                'dry_run' => false,
            ];
        }

        if (!$this->accountSid || !$this->authToken) {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => 'missing_credentials',
                'error_message' => 'Twilio credentials are not configured.',
                'from_identifier' => $this->fromIdentifier(),
                'payload' => [],
                'dry_run' => false,
            ];
        }

        $senderValidation = $this->validateSenderConfiguration();
        if ($senderValidation !== null) {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => $senderValidation['code'],
                'error_message' => $senderValidation['message'],
                'from_identifier' => $this->fromIdentifier(),
                'payload' => [],
                'dry_run' => false,
            ];
        }

        $payload = array_filter([
            'To' => $toPhone,
            'Body' => $message,
            'MessagingServiceSid' => $this->messagingServiceSid,
            'From' => $this->messagingServiceSid ? null : $this->fromNumber,
            'StatusCallback' => $this->nullableString((string) ($options['status_callback_url'] ?? '')) ?: $this->statusCallbackUrl,
        ], fn ($value) => $value !== null && $value !== '');

        try {
            $response = $this->request()->post($this->messagesUrl(), $payload);
            $json = is_array($response->json()) ? $response->json() : [];

            if ($response->failed()) {
                return [
                    'success' => false,
                    'provider' => 'twilio',
                    'provider_message_id' => $this->nullableString($json['sid'] ?? null),
                    'status' => $this->normalizeTwilioStatus($json['status'] ?? 'failed'),
                    'error_code' => $this->nullableString($json['code'] ?? null) ?: (string) $response->status(),
                    'error_message' => $this->sanitizeTwilioErrorMessage($json['message'] ?? null) ?: 'Twilio request failed.',
                    'from_identifier' => $this->fromIdentifier(),
                    'payload' => $json,
                    'dry_run' => false,
                ];
            }

            return [
                'success' => true,
                'provider' => 'twilio',
                'provider_message_id' => $this->nullableString($json['sid'] ?? null),
                'status' => $this->normalizeTwilioStatus($json['status'] ?? 'sent'),
                'error_code' => null,
                'error_message' => null,
                'from_identifier' => $this->fromIdentifier(),
                'payload' => $json,
                'dry_run' => false,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => 'exception',
                'error_message' => $this->sanitizeTwilioErrorMessage($e->getMessage()) ?: 'Twilio request exception.',
                'from_identifier' => $this->fromIdentifier(),
                'payload' => [],
                'dry_run' => false,
            ];
        }
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function validateSignature(string $requestUrl, array $payload, ?string $signatureHeader): bool
    {
        if (!(bool) config('marketing.twilio.verify_signature')) {
            return true;
        }

        $signatureHeader = trim((string) $signatureHeader);
        if ($signatureHeader === '' || !$this->authToken) {
            return false;
        }

        ksort($payload);
        $data = $requestUrl;
        foreach ($payload as $key => $value) {
            $data .= (string) $key . (string) $value;
        }

        $expected = base64_encode(hash_hmac('sha1', $data, $this->authToken, true));

        return hash_equals($expected, $signatureHeader);
    }

    protected function request(): PendingRequest
    {
        return Http::asForm()
            ->acceptJson()
            ->timeout(20)
            ->retry(2, 200, throw: false)
            ->withBasicAuth((string) $this->accountSid, (string) $this->authToken);
    }

    protected function messagesUrl(): string
    {
        return 'https://api.twilio.com/2010-04-01/Accounts/' . urlencode((string) $this->accountSid) . '/Messages.json';
    }

    protected function fromIdentifier(): ?string
    {
        $identifier = $this->messagingServiceSid ?: $this->fromNumber;
        if ($identifier === null) {
            return null;
        }

        if ($this->authToken !== null && hash_equals($this->authToken, $identifier)) {
            return null;
        }

        return $identifier;
    }

    protected function normalizeTwilioStatus(mixed $status): string
    {
        return match (strtolower(trim((string) $status))) {
            'accepted', 'queued' => 'queued',
            'sending' => 'sending',
            'sent' => 'sent',
            'delivered' => 'delivered',
            'undelivered' => 'undelivered',
            'failed' => 'failed',
            'canceled', 'cancelled' => 'canceled',
            default => 'sent',
        };
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    /**
     * @return array{code:string,message:string}|null
     */
    protected function validateSenderConfiguration(): ?array
    {
        if (! $this->sidHasPrefix($this->accountSid, 'AC')) {
            return [
                'code' => 'invalid_account_sid',
                'message' => 'Twilio Account SID must start with AC.',
            ];
        }

        if ($this->messagingServiceSid !== null) {
            if (! $this->sidHasPrefix($this->messagingServiceSid, 'MG')) {
                $message = 'Twilio Messaging Service SID must start with MG.';
                if ($this->looksLikeSidBody($this->messagingServiceSid)) {
                    $message .= ' It looks like only the 32-character SID body was provided.';
                }
                if ($this->authToken !== null && hash_equals($this->authToken, $this->messagingServiceSid)) {
                    $message .= ' The configured value matches TWILIO_AUTH_TOKEN.';
                }

                return [
                    'code' => 'invalid_messaging_service_sid',
                    'message' => $message,
                ];
            }

            return null;
        }

        if ($this->fromNumber === null) {
            return [
                'code' => 'missing_sender_identity',
                'message' => 'Configure TWILIO_MESSAGING_SERVICE_SID (preferred) or TWILIO_FROM_NUMBER before sending SMS.',
            ];
        }

        return null;
    }

    protected function sidHasPrefix(?string $value, string $prefix): bool
    {
        $value = strtoupper(trim((string) $value));
        $prefix = strtoupper(trim($prefix));

        return $value !== '' && $prefix !== '' && str_starts_with($value, $prefix);
    }

    protected function looksLikeSidBody(string $value): bool
    {
        return (bool) preg_match('/^[A-Fa-f0-9]{32}$/', trim($value));
    }

    protected function sanitizeTwilioErrorMessage(mixed $value): ?string
    {
        $message = $this->nullableString($value);
        if ($message === null) {
            return null;
        }

        $sensitiveValues = [
            (string) $this->authToken => '[REDACTED_AUTH_TOKEN]',
            (string) $this->accountSid => '[REDACTED_ACCOUNT_SID]',
            (string) $this->messagingServiceSid => '[REDACTED_MESSAGING_SERVICE_SID]',
        ];

        foreach ($sensitiveValues as $sensitive => $replacement) {
            if (trim($sensitive) === '') {
                continue;
            }

            if (str_contains($message, $sensitive)) {
                $message = str_replace($sensitive, $replacement, $message);
            }
        }

        return $message;
    }
}
