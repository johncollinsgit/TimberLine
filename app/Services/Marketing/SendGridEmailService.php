<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\Messaging\TenantMessagingGateway;
use App\Services\Marketing\Messaging\TenantMessagingUsageService;
use Illuminate\Support\Str;

class SendGridEmailService
{
    public function __construct(
        protected TenantEmailDispatchService $dispatchService,
        protected TenantMessagingGateway $gateway,
        protected TenantMessagingUsageService $usage,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array{
     *   success:bool,
     *   provider:string,
     *   message_id:?string,
     *   status:string,
     *   error_code:?string,
     *   error_message:?string,
     *   payload:array<string,mixed>,
     *   dry_run:bool,
     *   retryable:bool,
     *   tenant_id:?int
     * }
     */
    public function sendEmail(string $toEmail, string $subject, string $bodyText, array $options = []): array
    {
        $tenantId = is_numeric($options['tenant_id'] ?? null) ? (int) $options['tenant_id'] : 0;
        $result = $tenantId > 0 && (bool) config('features.tenant_messaging_platform')
            ? $this->gateway->sendEmail($tenantId, $toEmail, $subject, $bodyText, $options)
            : ($tenantId > 0 && $this->usage->hasUsageContract($tenantId)
                ? $this->sendMeteredLegacy($tenantId, $toEmail, $subject, $bodyText, $options)
                : $this->dispatchService->sendEmail(
                    toEmail: $toEmail,
                    subject: $subject,
                    textBody: $bodyText,
                    options: $options,
                ));

        return [
            'success' => (bool) ($result['success'] ?? false),
            'provider' => (string) ($result['provider'] ?? 'sendgrid'),
            'message_id' => $result['message_id'] ?? null,
            'status' => (string) ($result['status'] ?? 'failed'),
            'error_code' => $result['error_code'] ?? null,
            'error_message' => $result['error_message'] ?? null,
            'payload' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
            'dry_run' => (bool) ($result['dry_run'] ?? false),
            'retryable' => (bool) ($result['retryable'] ?? false),
            'tenant_id' => isset($result['tenant_id']) && is_numeric($result['tenant_id'])
                ? (int) $result['tenant_id']
                : null,
        ];
    }

    /** @param array<string,mixed> $options */
    protected function sendMeteredLegacy(int $tenantId, string $toEmail, string $subject, string $bodyText, array $options): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false) || (bool) config('marketing.email.dry_run');
        $idempotencyKey = trim((string) ($options['idempotency_key'] ?? '')) ?: 'email:'.Str::uuid();
        if ($settled = $this->usage->settledEntry($tenantId, $idempotencyKey)) {
            return [...(array) data_get($settled->metadata, 'provider_result', []), 'tenant_id' => $tenantId, 'idempotent_replay' => true];
        }

        try {
            if (! $dryRun) {
                $this->usage->reserve(
                    $tenantId,
                    'email',
                    1,
                    $idempotencyKey,
                    'sendgrid',
                    $this->nullableString($options['ledger_source_type'] ?? $options['source_type'] ?? null),
                    $this->positiveInt($options['source_id'] ?? null),
                );
            }
            $result = $this->dispatchService->sendEmail(
                toEmail: $toEmail,
                subject: $subject,
                textBody: $bodyText,
                options: $options,
            );
            if (! $dryRun) {
                if ((bool) ($result['success'] ?? false)) {
                    $this->usage->settle($tenantId, $idempotencyKey, ['provider_result' => $result]);
                } else {
                    $this->usage->refund($tenantId, $idempotencyKey, (string) ($result['error_code'] ?? 'provider_failed'));
                }
            }

            return [...$result, 'tenant_id' => $tenantId];
        } catch (\Throwable $exception) {
            return [
                'success' => false,
                'provider' => 'sendgrid',
                'message_id' => null,
                'status' => 'failed',
                'error_code' => 'messaging_usage_unavailable',
                'error_message' => $exception->getMessage(),
                'payload' => [],
                'dry_run' => $dryRun,
                'retryable' => false,
                'tenant_id' => $tenantId,
            ];
        }
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
}
