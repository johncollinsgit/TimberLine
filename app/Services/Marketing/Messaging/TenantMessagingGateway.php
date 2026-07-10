<?php

namespace App\Services\Marketing\Messaging;

use App\Models\TenantMessagingAccount;
use App\Services\Marketing\Email\Providers\SendGridEmailProvider;
use App\Services\Marketing\Email\Providers\SesTenantEmailProvider;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\SmsMessageSafetyService;
use App\Services\Marketing\TwilioSenderConfigService;
use App\Services\Marketing\TwilioProviderClient;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class TenantMessagingGateway
{
    public function __construct(
        protected TenantMessagingAccountResolver $accountResolver,
        protected TenantMessagingSenderProfileService $senderProfiles,
        protected TenantMessagingUsageService $usage,
        protected TenantEmailDispatchService $legacyEmail,
        protected SendGridEmailProvider $sendGrid,
        protected SesTenantEmailProvider $ses,
        protected TwilioProviderClient $legacySms,
        protected TwilioSenderConfigService $twilioSenderConfig,
        protected SmsMessageSafetyService $smsSafety,
    ) {
    }

    /** @param array<string,mixed> $options */
    public function sendEmail(int $tenantId, string $to, string $subject, ?string $textBody, array $options = []): array
    {
        try {
            $resolution = $this->accountResolver->resolve($tenantId, 'email');
            if ($resolution['mode'] === 'legacy') {
                return $this->legacyEmail->sendEmail($to, $subject, $textBody, [...$options, 'tenant_id' => $tenantId]);
            }

            /** @var TenantMessagingAccount $account */
            $account = $resolution['account'];
            $idempotencyKey = $this->idempotencyKey($options, 'email');
            if ($settled = $this->usage->settledEntry($tenantId, $idempotencyKey)) {
                return $this->replayedResult($settled->metadata, $tenantId, 'email');
            }
            $sender = $this->senderProfiles->resolveEmailSender(
                $account,
                $this->positiveInt($options['sender_profile_id'] ?? null),
                $this->nullableString($options['store_key'] ?? null),
                $this->positiveInt($options['delivery_id'] ?? null),
            );
            $dryRun = (bool) ($options['dry_run'] ?? false);
            if (! $dryRun) {
                $this->usage->reserve(
                    $tenantId,
                    'email',
                    1,
                    $idempotencyKey,
                    $account->provider,
                    $this->nullableString($options['ledger_source_type'] ?? $options['source_type'] ?? null),
                    $this->positiveInt($options['source_id'] ?? null),
                );
            }

            $provider = match ($account->provider) {
                'ses_tenant' => $this->ses,
                'sendgrid_subuser' => $this->sendGrid,
                default => throw new \RuntimeException('Unsupported tenant email provider.'),
            };
            $config = [
                ...($account->provider === 'ses_tenant' ? [
                    'access_key' => config('services.ses.key'),
                    'secret_key' => config('services.ses.secret'),
                    'region' => config('services.ses.region'),
                ] : []),
                ...(array) $account->credentials,
                ...(array) $account->provider_config,
                'from_email' => $sender['from_email'],
                'from_name' => $sender['from_name'],
                'reply_to_email' => $sender['reply_to_email'],
                'provider_status' => 'healthy',
                'sender_mode' => 'domain_authenticated',
            ];
            $result = $provider->sendEmail([
                'to_email' => trim($to),
                'subject' => trim($subject),
                'text' => $this->nullableString($options['text_body'] ?? null) ?? $this->nullableString($textBody),
                'html' => $this->nullableString($options['html_body'] ?? null),
                'from_email' => $sender['from_email'],
                'from_name' => $sender['from_name'],
                'reply_to_email' => $sender['reply_to_email'],
                'headers' => (array) ($options['headers'] ?? []),
                'metadata' => [...(array) ($options['metadata'] ?? []), 'tenant_id' => (string) $tenantId],
                'custom_args' => (array) ($options['custom_args'] ?? []),
                'categories' => (array) ($options['categories'] ?? []),
                'dry_run' => $dryRun,
            ], $config);

            if (! $dryRun) {
                if ((bool) ($result['success'] ?? false)) {
                    try {
                        $this->usage->settle($tenantId, $idempotencyKey, ['provider_result' => $result]);
                    } catch (\Throwable $exception) {
                        Log::critical('tenant messaging email settlement failed after provider acceptance', [
                            'tenant_id' => $tenantId,
                            'idempotency_key' => $idempotencyKey,
                            'message' => $exception->getMessage(),
                        ]);
                        $result['payload'] = [...(array) ($result['payload'] ?? []), 'metering_status' => 'settlement_pending'];
                    }
                } else {
                    try {
                        $this->usage->refund($tenantId, $idempotencyKey, (string) ($result['error_code'] ?? 'provider_failed'));
                    } catch (\Throwable $exception) {
                        Log::error('tenant messaging email reservation refund failed', ['tenant_id' => $tenantId, 'message' => $exception->getMessage()]);
                    }
                }
            }

            return [...$result, 'tenant_id' => $tenantId, 'reply_mode' => $sender['reply_mode']];
        } catch (\Throwable $exception) {
            return $this->emailFailure($tenantId, $exception->getMessage());
        }
    }

    /** @param array<string,mixed> $options */
    public function sendSms(int $tenantId, string $to, string $body, array $options = []): array
    {
        try {
            $resolution = $this->accountResolver->resolve($tenantId, 'sms');
            if ($resolution['mode'] === 'legacy') {
                return $this->legacySms->sendSms($to, $body, $options);
            }

            /** @var TenantMessagingAccount $account */
            $account = $resolution['account'];
            if ($account->provider !== 'twilio_subaccount') {
                throw new \RuntimeException('Unsupported tenant SMS provider.');
            }

            $credentials = (array) $account->credentials;
            $providerConfig = (array) $account->provider_config;
            $safety = $this->smsSafety->analyzeRecipient($body, $to);
            $sendAsMms = (bool) ($options['send_as_mms'] ?? false);
            $channel = $sendAsMms ? 'mms' : 'sms';
            $units = $sendAsMms ? 1 : max(1, (int) ($safety['sms_segments'] ?? 1));
            $dryRun = (bool) ($options['dry_run'] ?? false);
            $idempotencyKey = $this->idempotencyKey($options, $channel);
            if ($settled = $this->usage->settledEntry($tenantId, $idempotencyKey)) {
                return $this->replayedResult($settled->metadata, $tenantId, $channel);
            }
            if (! $dryRun) {
                $this->usage->reserve(
                    $tenantId,
                    $channel,
                    $units,
                    $idempotencyKey,
                    $account->provider,
                    $this->nullableString($options['ledger_source_type'] ?? $options['source_type'] ?? null),
                    $this->positiveInt($options['source_id'] ?? null),
                );
            }

            $provider = new TwilioProviderClient(
                $this->twilioSenderConfig,
                (string) $account->provider_account_id,
                (string) ($credentials['auth_token'] ?? ''),
                $this->sidWithPrefix($account->provider_resource_id, 'MG'),
                $this->nullableString($account->sender_identifier),
                $this->nullableString($providerConfig['status_callback_url'] ?? null),
            );
            $senderOverride = [
                'key' => 'tenant-'.$tenantId,
                'label' => 'Tenant messaging service',
                'messaging_service_sid' => $this->sidWithPrefix($account->provider_resource_id, 'MG'),
                'from_number' => $this->nullableString($account->sender_identifier),
                'from_identifier' => $this->nullableString($account->provider_resource_id) ?: $this->nullableString($account->sender_identifier),
            ];
            $result = $provider->sendSms($to, (string) ($safety['normalized_body'] ?? $body), [
                ...$options,
                'send_as_mms' => $sendAsMms,
                'platform_managed' => true,
                'sender_override' => $senderOverride,
            ]);

            if (! $dryRun) {
                if ((bool) ($result['success'] ?? false)) {
                    try {
                        $this->usage->settle($tenantId, $idempotencyKey, ['provider_result' => $result]);
                    } catch (\Throwable $exception) {
                        Log::critical('tenant messaging sms settlement failed after provider acceptance', [
                            'tenant_id' => $tenantId,
                            'idempotency_key' => $idempotencyKey,
                            'message' => $exception->getMessage(),
                        ]);
                        $result['payload'] = [...(array) ($result['payload'] ?? []), 'metering_status' => 'settlement_pending'];
                    }
                } else {
                    try {
                        $this->usage->refund($tenantId, $idempotencyKey, (string) ($result['error_code'] ?? 'provider_failed'));
                    } catch (\Throwable $exception) {
                        Log::error('tenant messaging sms reservation refund failed', ['tenant_id' => $tenantId, 'message' => $exception->getMessage()]);
                    }
                }
            }

            return [...$result, 'tenant_id' => $tenantId, 'units' => $units];
        } catch (\Throwable $exception) {
            return $this->smsFailure($tenantId, $exception->getMessage(), (bool) ($options['dry_run'] ?? false));
        }
    }

    public function validateTwilioCallback(string $accountSid, string $url, array $payload, ?string $signature): ?TenantMessagingAccount
    {
        try {
            $account = $this->accountResolver->resolveTwilioCallback($accountSid);
            $credentials = (array) $account->credentials;
            $provider = new TwilioProviderClient(
                $this->twilioSenderConfig,
                (string) $account->provider_account_id,
                (string) ($credentials['auth_token'] ?? ''),
            );

            return $provider->validateSignature($url, $payload, $signature) ? $account : null;
        } catch (\Throwable) {
            return null;
        }
    }

    protected function idempotencyKey(array $options, string $channel): string
    {
        return $this->nullableString($options['idempotency_key'] ?? null)
            ?: $channel.':'.Str::uuid()->toString();
    }

    protected function replayedResult(mixed $metadata, int $tenantId, string $channel): array
    {
        $result = is_array(data_get($metadata, 'provider_result'))
            ? (array) data_get($metadata, 'provider_result')
            : [];
        if ($result === []) {
            return $channel === 'email'
                ? $this->emailFailure($tenantId, 'The send was already settled, but its provider receipt is unavailable.')
                : $this->smsFailure($tenantId, 'The send was already settled, but its provider receipt is unavailable.', false);
        }

        return [...$result, 'tenant_id' => $tenantId, 'idempotent_replay' => true];
    }

    protected function emailFailure(int $tenantId, string $message): array
    {
        return [
            'success' => false, 'provider' => 'tenant_gateway', 'status' => 'failed', 'message_id' => null,
            'error_code' => 'tenant_messaging_unavailable', 'error_message' => $message, 'retryable' => false,
            'payload' => [], 'dry_run' => false, 'tenant_id' => $tenantId,
        ];
    }

    protected function smsFailure(int $tenantId, string $message, bool $dryRun): array
    {
        return [
            'success' => false, 'provider' => 'tenant_gateway', 'provider_message_id' => null,
            'status' => 'failed', 'error_code' => 'tenant_messaging_unavailable', 'error_message' => $message,
            'sender_key' => null, 'sender_label' => null, 'from_identifier' => null,
            'delivery_mode' => 'sms', 'requested_delivery_mode' => 'sms', 'payload' => [],
            'dry_run' => $dryRun, 'tenant_id' => $tenantId,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function sidWithPrefix(mixed $value, string $prefix): ?string
    {
        $value = $this->nullableString($value);

        return $value !== null && str_starts_with(strtoupper($value), strtoupper($prefix)) ? $value : null;
    }
}
