<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Str;

class TwilioSmsService
{
    public function __construct(
        protected TenantMessagingGateway $gateway,
        protected TwilioProviderClient $legacyProvider,
        protected TenantContext $tenantContext,
        protected TenantMessagingUsageService $usage,
        protected SmsMessageSafetyService $smsSafety,
    ) {}

    /** @param array<string,mixed> $options */
    public function sendSms(string $toPhone, string $message, array $options = []): array
    {
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null) ?: $this->tenantContext->id();
        if ($tenantId !== null && (bool) config('features.tenant_messaging_platform')) {
            return $this->gateway->sendSms($tenantId, $toPhone, $message, $options);
        }

        if (! (bool) config('features.tenant_messaging_platform')) {
            if ($tenantId !== null && $this->usage->hasUsageContract($tenantId)) {
                return $this->sendMeteredLegacy($tenantId, $toPhone, $message, $options);
            }

            return $this->legacyProvider->sendSms($toPhone, $message, $options);
        }

        return [
            'success' => false,
            'provider' => 'tenant_gateway',
            'provider_message_id' => null,
            'status' => 'failed',
            'error_code' => 'missing_tenant',
            'error_message' => 'A tenant is required for SMS sending.',
            'sender_key' => null,
            'sender_label' => null,
            'from_identifier' => null,
            'delivery_mode' => 'sms',
            'requested_delivery_mode' => 'sms',
            'payload' => [],
            'dry_run' => (bool) ($options['dry_run'] ?? false),
        ];
    }

    protected function positiveInt(mixed $value): ?int
    {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    /** @param array<string,mixed> $options */
    protected function sendMeteredLegacy(int $tenantId, string $toPhone, string $message, array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false) || (bool) config('marketing.sms.dry_run');
        $sendAsMms = (bool) ($options['send_as_mms'] ?? false);
        $channel = $sendAsMms ? 'mms' : 'sms';
        $safety = $this->smsSafety->analyzeRecipient($message, $toPhone);
        $units = $sendAsMms ? 1 : max(1, (int) ($safety['sms_segments'] ?? 1));
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? '')) ?: $channel.':'.Str::uuid();

        if ($settled = $this->usage->settledEntry($tenantId, $idempotencyKey)) {
            $result = (array) data_get($settled->metadata, 'provider_result', []);

            return [...$result, 'tenant_id' => $tenantId, 'units' => $units, 'idempotent_replay' => true];
        }

        try {
            if (! $dryRun) {
                $this->usage->reserve(
                    $tenantId,
                    $channel,
                    $units,
                    $idempotencyKey,
                    'twilio',
                    $this->nullableString($options['ledger_source_type'] ?? $options['source_type'] ?? null),
                    $this->positiveInt($options['source_id'] ?? null),
                );
            }

            $result = $this->legacyProvider->sendSms($toPhone, $message, $options);
            if (! $dryRun) {
                if ((bool) ($result['success'] ?? false)) {
                    $this->usage->settle($tenantId, $idempotencyKey, ['provider_result' => $result]);
                } else {
                    $this->usage->refund($tenantId, $idempotencyKey, (string) ($result['error_code'] ?? 'provider_failed'));
                }
            }

            return [...$result, 'tenant_id' => $tenantId, 'units' => $units];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'provider' => 'twilio',
                'provider_message_id' => null,
                'status' => 'failed',
                'error_code' => 'messaging_usage_unavailable',
                'error_message' => $exception->getMessage(),
                'sender_key' => null,
                'sender_label' => null,
                'from_identifier' => null,
                'delivery_mode' => $channel,
                'requested_delivery_mode' => $channel,
                'payload' => [],
                'dry_run' => $dryRun,
                'tenant_id' => $tenantId,
                'units' => $units,
            ];
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
