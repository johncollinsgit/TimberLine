<?php

namespace App\Services\Marketing\Email;

class TenantEmailDispatchService
{
    public function __construct(
        protected TenantEmailProviderResolver $providerResolver,
    ) {
    }

    /**
     * @param array<string,mixed> $options
     * @return array{
     *   success:bool,
     *   provider:string,
     *   status:string,
     *   message_id:?string,
     *   error_code:?string,
     *   error_message:?string,
     *   retryable:bool,
     *   payload:array<string,mixed>,
     *   dry_run:bool,
     *   tenant_id:?int
     * }
     */
    public function sendEmail(string $toEmail, string $subject, ?string $textBody, array $options = []): array
    {
        $resolution = $this->providerResolution($options);
        $provider = $resolution['provider'];
        $settings = $resolution['settings'];
        $tenantId = $resolution['tenant_id'];

        $dryRun = (bool) ($options['dry_run'] ?? false);
        if (! (bool) ($settings['email_enabled'] ?? false) && ! $dryRun) {
            return $this->failure(
                provider: $provider->key(),
                code: 'email_disabled',
                message: 'Email sending is disabled for this tenant.',
                retryable: false,
                payload: [
                    'tenant_id' => $tenantId,
                    'provider' => $provider->key(),
                ],
                dryRun: false,
                tenantId: $tenantId,
            );
        }

        $message = $this->messagePayload($toEmail, $subject, $textBody, $settings, $options, $tenantId);
        $result = $provider->sendEmail($message, $this->providerConfig($settings, $options));

        return $this->normalizedResult($result, $provider->key(), $tenantId);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{
     *   success:bool,
     *   provider:string,
     *   status:string,
     *   message_id:?string,
     *   error_code:?string,
     *   error_message:?string,
     *   retryable:bool,
     *   payload:array<string,mixed>,
     *   dry_run:bool,
     *   tenant_id:?int
     * }
     */
    public function sendTestEmail(string $toEmail, array $options = []): array
    {
        $resolution = $this->providerResolution($options);
        $provider = $resolution['provider'];
        $settings = $resolution['settings'];
        $tenantId = $resolution['tenant_id'];

        $result = $provider->sendTestEmail(
            $toEmail,
            $this->providerConfig($settings, $options),
            [
                'tenant_id' => $tenantId,
                'tenant_label' => (string) ($options['tenant_label'] ?? ('Tenant #' . ($tenantId ?? 'unknown'))),
                'subject' => $this->nullableString($options['subject'] ?? null),
                'dry_run' => (bool) ($options['dry_run'] ?? false),
            ]
        );

        return $this->normalizedResult($result, $provider->key(), $tenantId);
    }

    /**
     * @param array<string,mixed> $options
     * @return array{provider:string,tenant_id:?int,valid:bool,status:string,issues:array<int,string>,details:array<string,mixed>}
     */
    public function validateConfiguration(array $options = []): array
    {
        $resolution = $this->providerResolution($options);
        $provider = $resolution['provider'];
        $settings = $resolution['settings'];
        $tenantId = $resolution['tenant_id'];

        $validation = $provider->validateConfiguration([
            ...$this->providerConfig($settings, $options),
            'perform_live_check' => (bool) ($options['perform_live_check'] ?? true),
        ]);

        return [
            'provider' => $provider->key(),
            'tenant_id' => $tenantId,
            'valid' => (bool) ($validation['valid'] ?? false),
            'status' => (string) ($validation['status'] ?? 'error'),
            'issues' => array_values((array) ($validation['issues'] ?? [])),
            'details' => is_array($validation['details'] ?? null) ? $validation['details'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{provider:string,tenant_id:?int,status:string,message:string,details:array<string,mixed>}
     */
    public function healthStatus(array $options = []): array
    {
        $resolution = $this->providerResolution($options);
        $provider = $resolution['provider'];
        $settings = $resolution['settings'];
        $tenantId = $resolution['tenant_id'];

        $health = $provider->getHealthStatus([
            ...$this->providerConfig($settings, $options),
            'perform_live_check' => (bool) ($options['perform_live_check'] ?? true),
        ]);

        return [
            'provider' => $provider->key(),
            'tenant_id' => $tenantId,
            'status' => (string) ($health['status'] ?? 'error'),
            'message' => (string) ($health['message'] ?? 'Provider health status unavailable.'),
            'details' => is_array($health['details'] ?? null) ? $health['details'] : [],
        ];
    }

    /**
     * @param array<string,mixed> $options
     * @return array{tenant_id:?int,provider:EmailProvider,settings:array<string,mixed>,configuration_issues:array<int,string>}
     */
    protected function providerResolution(array $options): array
    {
        $tenantId = $this->positiveInt($options['tenant_id'] ?? null);
        if ($tenantId !== null) {
            return $this->providerResolver->getEmailProviderForTenant($tenantId);
        }

        $storeContext = is_array($options['store_context'] ?? null)
            ? $options['store_context']
            : [];

        $storeKey = $this->nullableString($options['store_key'] ?? null);
        if ($storeKey !== null && ! array_key_exists('key', $storeContext)) {
            $storeContext['key'] = $storeKey;
        }

        if ($storeContext !== []) {
            return $this->providerResolver->getEmailProviderForStore($storeContext);
        }

        return $this->providerResolver->getEmailProviderForTenant(null);
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $options
     * @return array<string,mixed>
     */
    protected function providerConfig(array $settings, array $options): array
    {
        $providerConfig = is_array($settings['provider_config'] ?? null)
            ? $settings['provider_config']
            : [];

        $providerConfig['from_email'] = $this->nullableString($options['from_email'] ?? null)
            ?? $this->nullableString($settings['from_email'] ?? null)
            ?? $this->nullableString($providerConfig['verified_sender_email'] ?? null);

        $providerConfig['from_name'] = $this->nullableString($options['from_name'] ?? null)
            ?? $this->nullableString($settings['from_name'] ?? null)
            ?? $this->nullableString($providerConfig['verified_sender_name'] ?? null);

        $providerConfig['reply_to_email'] = $this->nullableString($options['reply_to_email'] ?? null)
            ?? $this->nullableString($settings['reply_to_email'] ?? null)
            ?? $this->nullableString($providerConfig['reply_to_email'] ?? null);

        if (array_key_exists('tracking_enabled', $options)) {
            $providerConfig['tracking_enabled'] = (bool) $options['tracking_enabled'];
        }

        return $providerConfig;
    }

    /**
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $options
     * @return array{
     *   to_email:string,
     *   subject:string,
     *   text:?string,
     *   html:?string,
     *   from_email:?string,
     *   from_name:?string,
     *   reply_to_email:?string,
     *   metadata:array<string,mixed>,
     *   custom_args:array<string,mixed>,
     *   categories:array<int,string>,
     *   dry_run:bool
     * }
     */
    protected function messagePayload(
        string $toEmail,
        string $subject,
        ?string $textBody,
        array $settings,
        array $options,
        ?int $tenantId
    ): array {
        $metadata = is_array($options['metadata'] ?? null)
            ? $options['metadata']
            : [];

        $metadata = array_merge($metadata, [
            'tenant_id' => $metadata['tenant_id'] ?? $tenantId,
            'customer_id' => $metadata['customer_id'] ?? $this->positiveInt($options['customer_id'] ?? null),
            'campaign_type' => $metadata['campaign_type'] ?? $this->nullableString($options['campaign_type'] ?? null),
            'coupon_code' => $metadata['coupon_code'] ?? $this->nullableString($options['coupon_code'] ?? null),
            'template_key' => $metadata['template_key'] ?? $this->nullableString($options['template_key'] ?? null),
        ]);

        return [
            'to_email' => trim($toEmail),
            'subject' => trim($subject),
            'text' => $this->nullableString($options['text_body'] ?? null)
                ?? $this->nullableString($textBody),
            'html' => $this->nullableString($options['html_body'] ?? null),
            'from_email' => $this->nullableString($options['from_email'] ?? null)
                ?? $this->nullableString($settings['from_email'] ?? null),
            'from_name' => $this->nullableString($options['from_name'] ?? null)
                ?? $this->nullableString($settings['from_name'] ?? null),
            'reply_to_email' => $this->nullableString($options['reply_to_email'] ?? null)
                ?? $this->nullableString($settings['reply_to_email'] ?? null),
            'metadata' => $metadata,
            'custom_args' => is_array($options['custom_args'] ?? null) ? $options['custom_args'] : [],
            'categories' => is_array($options['categories'] ?? null) ? $options['categories'] : [],
            'dry_run' => (bool) ($options['dry_run'] ?? false),
        ];
    }

    /**
     * @param array<string,mixed> $result
     * @return array{
     *   success:bool,
     *   provider:string,
     *   status:string,
     *   message_id:?string,
     *   error_code:?string,
     *   error_message:?string,
     *   retryable:bool,
     *   payload:array<string,mixed>,
     *   dry_run:bool,
     *   tenant_id:?int
     * }
     */
    protected function normalizedResult(array $result, string $fallbackProvider, ?int $tenantId): array
    {
        return [
            'success' => (bool) ($result['success'] ?? false),
            'provider' => (string) ($result['provider'] ?? $fallbackProvider),
            'status' => (string) ($result['status'] ?? ((bool) ($result['success'] ?? false) ? 'sent' : 'failed')),
            'message_id' => $this->nullableString($result['message_id'] ?? null),
            'error_code' => $this->nullableString($result['error_code'] ?? null),
            'error_message' => $this->nullableString($result['error_message'] ?? null),
            'retryable' => (bool) ($result['retryable'] ?? false),
            'payload' => is_array($result['payload'] ?? null) ? $result['payload'] : [],
            'dry_run' => (bool) ($result['dry_run'] ?? false),
            'tenant_id' => $tenantId,
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{
     *   success:false,
     *   provider:string,
     *   status:string,
     *   message_id:null,
     *   error_code:string,
     *   error_message:string,
     *   retryable:bool,
     *   payload:array<string,mixed>,
     *   dry_run:bool,
     *   tenant_id:?int
     * }
     */
    protected function failure(
        string $provider,
        string $code,
        string $message,
        bool $retryable,
        array $payload,
        bool $dryRun,
        ?int $tenantId,
    ): array {
        return [
            'success' => false,
            'provider' => $provider,
            'status' => 'failed',
            'message_id' => null,
            'error_code' => $code,
            'error_message' => $message,
            'retryable' => $retryable,
            'payload' => $payload,
            'dry_run' => $dryRun,
            'tenant_id' => $tenantId,
        ];
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function positiveInt(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int > 0 ? $int : null;
    }
}
