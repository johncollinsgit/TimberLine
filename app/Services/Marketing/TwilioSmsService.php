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
        $serviceTwilio = (array) config('services.twilio', []);

        $this->accountSid = $this->accountSid
            ?: (string) ($serviceTwilio['account_sid'] ?? config('marketing.twilio.account_sid'));
        $this->authToken = $this->authToken
            ?: (string) ($serviceTwilio['auth_token'] ?? config('marketing.twilio.auth_token'));
        $this->messagingServiceSid = $this->messagingServiceSid
            ?: $this->nullableString($serviceTwilio['messaging_service_sid'] ?? config('marketing.twilio.messaging_service_sid'));
        $this->fromNumber = $this->fromNumber
            ?: $this->nullableString($serviceTwilio['from'] ?? $serviceTwilio['from_number'] ?? config('marketing.twilio.from_number'));
        $this->statusCallbackUrl = $this->statusCallbackUrl
            ?: $this->nullableString($serviceTwilio['status_callback_url'] ?? config('marketing.twilio.status_callback_url'));
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
                    'error_message' => $this->nullableString($json['message'] ?? null) ?: 'Twilio request failed.',
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
                'error_message' => $e->getMessage(),
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
        return $this->messagingServiceSid ?: $this->fromNumber;
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
}
