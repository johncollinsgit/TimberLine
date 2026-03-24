<?php

namespace App\Services\Marketing;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class TwilioSmsService
{
    public function __construct(
        protected TwilioSenderConfigService $senderConfigService,
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
     *   sender_key:?string,
     *   sender_label:?string,
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
        $senderResolution = $this->resolveSenderConfiguration($options);
        $sender = $senderResolution['sender'] ?? null;
        if ($toPhone === '' || $message === '') {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => 'invalid_payload',
                'error_message' => 'Missing destination phone or message body.',
                'sender_key' => $sender['key'] ?? null,
                'sender_label' => $sender['label'] ?? null,
                'from_identifier' => $this->fromIdentifier($sender),
                'payload' => [],
                'dry_run' => $dryRun,
            ];
        }

        if ($senderResolution['error'] !== null) {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => $senderResolution['error']['code'],
                'error_message' => $senderResolution['error']['message'],
                'sender_key' => $sender['key'] ?? null,
                'sender_label' => $sender['label'] ?? null,
                'from_identifier' => $this->fromIdentifier($sender),
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
                'sender_key' => $sender['key'] ?? null,
                'sender_label' => $sender['label'] ?? null,
                'from_identifier' => $this->fromIdentifier($sender),
                'payload' => [
                    'dry_run' => true,
                    'to' => $toPhone,
                    'body' => $message,
                    'sender_key' => $sender['key'] ?? null,
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
                'error_message' => 'SMS sending is disabled by configuration. Set MARKETING_SMS_ENABLED=true.',
                'sender_key' => $sender['key'] ?? null,
                'sender_label' => $sender['label'] ?? null,
                'from_identifier' => $this->fromIdentifier($sender),
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
                'sender_key' => $sender['key'] ?? null,
                'sender_label' => $sender['label'] ?? null,
                'from_identifier' => $this->fromIdentifier($sender),
                'payload' => [],
                'dry_run' => false,
            ];
        }

        $senderValidation = $this->validateSenderConfiguration($sender);
        if ($senderValidation !== null) {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => $senderValidation['code'],
                'error_message' => $senderValidation['message'],
                'sender_key' => $sender['key'] ?? null,
                'sender_label' => $sender['label'] ?? null,
                'from_identifier' => $this->fromIdentifier($sender),
                'payload' => [],
                'dry_run' => false,
            ];
        }

        $payload = array_filter([
            'To' => $toPhone,
            'Body' => $message,
            'MessagingServiceSid' => $sender['messaging_service_sid'] ?? null,
            'From' => ! empty($sender['messaging_service_sid']) ? null : ($sender['from_number'] ?? null),
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
                    'error_message' => $this->sanitizeTwilioErrorMessage($json['message'] ?? null, $sender) ?: 'Twilio request failed.',
                    'sender_key' => $sender['key'] ?? null,
                    'sender_label' => $sender['label'] ?? null,
                    'from_identifier' => $this->fromIdentifier($sender),
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
                'sender_key' => $sender['key'] ?? null,
                'sender_label' => $sender['label'] ?? null,
                'from_identifier' => $this->fromIdentifier($sender),
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
                'error_message' => $this->sanitizeTwilioErrorMessage($e->getMessage(), $sender) ?: 'Twilio request exception.',
                'sender_key' => $sender['key'] ?? null,
                'sender_label' => $sender['label'] ?? null,
                'from_identifier' => $this->fromIdentifier($sender),
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

    /**
     * @param array<string,mixed>|null $sender
     */
    protected function fromIdentifier(?array $sender = null): ?string
    {
        $identifier = $this->nullableString($sender['from_identifier'] ?? null)
            ?: $this->messagingServiceSid
            ?: $this->fromNumber;
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
     * @param array<string,mixed> $options
     * @return array{sender:?array,error:?array{code:string,message:string}}
     */
    protected function resolveSenderConfiguration(array $options): array
    {
        $requestedKey = $this->nullableString($options['sender_key'] ?? null);

        return $this->senderConfigService->resolveForSend($requestedKey);
    }

    /**
     * @return array{code:string,message:string}|null
     */
    protected function validateSenderConfiguration(?array $sender): ?array
    {
        if (! $this->sidHasPrefix($this->accountSid, 'AC')) {
            return [
                'code' => 'invalid_account_sid',
                'message' => 'Twilio Account SID must start with AC.',
            ];
        }

        $messagingServiceSid = $this->nullableString($sender['messaging_service_sid'] ?? null);
        $fromNumber = $this->nullableString($sender['from_number'] ?? null);

        if ($messagingServiceSid !== null) {
            if (! $this->sidHasPrefix($messagingServiceSid, 'MG')) {
                $message = 'Twilio Messaging Service SID must start with MG.';
                if ($this->looksLikeSidBody($messagingServiceSid)) {
                    $message .= ' It looks like only the 32-character SID body was provided.';
                }
                if ($this->authToken !== null && hash_equals($this->authToken, $messagingServiceSid)) {
                    $message .= ' The configured value matches TWILIO_AUTH_TOKEN.';
                }

                return [
                    'code' => 'invalid_messaging_service_sid',
                    'message' => $message,
                ];
            }

            return null;
        }

        if ($fromNumber === null) {
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

    /**
     * @param array<string,mixed>|null $sender
     */
    protected function sanitizeTwilioErrorMessage(mixed $value, ?array $sender = null): ?string
    {
        $message = $this->nullableString($value);
        if ($message === null) {
            return null;
        }

        $sensitiveValues = [
            (string) $this->authToken => '[REDACTED_AUTH_TOKEN]',
            (string) $this->accountSid => '[REDACTED_ACCOUNT_SID]',
            (string) $this->messagingServiceSid => '[REDACTED_MESSAGING_SERVICE_SID]',
            (string) ($sender['messaging_service_sid'] ?? '') => '[REDACTED_MESSAGING_SERVICE_SID]',
            (string) ($sender['phone_number_sid'] ?? '') => '[REDACTED_PHONE_NUMBER_SID]',
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
