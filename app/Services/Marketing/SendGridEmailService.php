<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Email\TenantEmailDispatchService;

class SendGridEmailService
{
    public function __construct(
        protected TenantEmailDispatchService $dispatchService
    ) {
    }

    /**
     * @param array<string,mixed> $options
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
        $result = $this->dispatchService->sendEmail(
            toEmail: $toEmail,
            subject: $subject,
            textBody: $bodyText,
            options: $options,
        );

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
}
