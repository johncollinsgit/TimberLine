<?php

namespace App\Services\Marketing;

use App\Services\Marketing\Email\TenantEmailDispatchService;
use App\Services\Marketing\Email\TenantEmailProviderResolver;
use Illuminate\Support\Facades\Log;

class MarketingEmailReadiness
{
    public function __construct(
        protected TenantEmailProviderResolver $providerResolver,
        protected TenantEmailDispatchService $dispatchService
    ) {
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public function summary(?int $tenantId = null, array $context = []): array
    {
        return $this->getReadinessForTenant($tenantId, $context);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   tenant_id:?int,
     *   provider:string,
     *   status:string,
     *   can_send:bool,
     *   can_send_live:bool,
     *   provider_status:string,
     *   config_status:string,
     *   missing_requirements:array<int,string>,
     *   warnings:array<int,string>,
     *   notes:array<int,string>,
     *   using_fallback_config:bool,
     *   resolution_source:string,
     *   supports_runtime_sends:bool,
     *   enabled:bool,
     *   dry_run:bool,
     *   smoke_test_recipient_email:string,
     *   smoke_test_configured:bool,
     *   missing_reasons:array<int,string>,
     *   legacy_status:string,
     *   sendgrid_key_present:bool,
     *   from_email_present:bool,
     *   from_name_present:bool,
     *   provider_health:array<string,mixed>
     * }
     */
    public function getReadinessForTenant(?int $tenantId, array $context = []): array
    {
        $dryRun = (bool) config('marketing.email.dry_run', false);
        $smokeTestEmail = trim((string) config('marketing.email.smoke_test_recipient_email', ''));

        try {
            $resolution = $this->providerResolution($tenantId, $context);
            $resolvedTenantId = isset($resolution['tenant_id']) && is_numeric($resolution['tenant_id'])
                ? (int) $resolution['tenant_id']
                : $tenantId;
            $provider = $resolution['provider'];
            $settings = is_array($resolution['settings'] ?? null) ? $resolution['settings'] : [];
            $providerConfig = is_array($settings['provider_config'] ?? null) ? $settings['provider_config'] : [];
            $providerKey = strtolower(trim((string) $provider->key()));
            $emailEnabled = (bool) ($settings['email_enabled'] ?? false);
            $source = strtolower(trim((string) ($settings['source'] ?? '')));
            $usingFallbackConfig = $source === 'config_fallback';
            $resolutionSource = match ($source) {
                'tenant_email_settings' => 'tenant',
                'config_fallback' => 'fallback',
                default => 'none',
            };

            $providerValidation = $provider->validateConfiguration([
                ...$providerConfig,
                'from_email' => $this->nullableString($settings['from_email'] ?? null),
                'from_name' => $this->nullableString($settings['from_name'] ?? null),
                'reply_to_email' => $this->nullableString($settings['reply_to_email'] ?? null),
                'provider_status' => (string) ($settings['provider_status'] ?? 'unknown'),
                'perform_live_check' => false,
            ]);

            $supportsRuntimeSends = $providerKey === 'sendgrid'
                ? true
                : (bool) data_get($providerValidation, 'details.supports_app_sends', false);

            $fromEmail = $this->nullableString($settings['from_email'] ?? null)
                ?? $this->nullableString($providerConfig['verified_sender_email'] ?? null);
            $fromName = $this->nullableString($settings['from_name'] ?? null)
                ?? $this->nullableString($providerConfig['verified_sender_name'] ?? null);
            $sendGridApiKey = $providerKey === 'sendgrid'
                ? trim((string) ($providerConfig['api_key'] ?? ''))
                : '';

            $missingRequirements = [];
            if (! $emailEnabled) {
                $missingRequirements[] = 'Email sending is disabled for this tenant.';
            }

            if ($providerKey === 'sendgrid') {
                if ($sendGridApiKey === '') {
                    $missingRequirements[] = 'SendGrid API key is missing.';
                }
                if ($fromEmail === null) {
                    $missingRequirements[] = 'From email is missing.';
                }
            }

            $validationIssues = collect((array) ($providerValidation['issues'] ?? []))
                ->filter(fn ($value): bool => is_string($value) && trim($value) !== '')
                ->map(fn ($value): string => trim((string) $value))
                ->values()
                ->all();

            $notes = [];
            $warnings = [];
            if ($usingFallbackConfig) {
                $warnings[] = 'Using fallback global email configuration because tenant-specific settings were not found.';
            }
            if ($dryRun) {
                $warnings[] = 'Global marketing email dry-run mode is enabled.';
            }

            if ($providerKey === 'shopify_email') {
                $notes[] = 'Shopify Email is selectable, but app-driven runtime sending is not supported in this architecture.';
            } elseif ($providerKey === 'custom') {
                $notes[] = 'Custom provider is scaffolded, but runtime sending is not implemented.';
            }

            if (in_array((string) ($settings['provider_status'] ?? ''), ['error', 'unhealthy'], true) && $validationIssues !== []) {
                $warnings[] = 'Latest provider diagnostics reported errors.';
            }

            $status = 'error';
            $configStatus = 'error';
            $canSend = false;

            if (! $emailEnabled) {
                $status = 'not_configured';
                $configStatus = 'disabled';
            } elseif (! $supportsRuntimeSends) {
                $status = 'unsupported';
                $configStatus = $this->configStatusFromValidation($providerValidation, $missingRequirements);
            } elseif ($providerKey === 'sendgrid' && $missingRequirements !== []) {
                $status = count($missingRequirements) >= 3 ? 'not_configured' : 'incomplete';
                $configStatus = $status;
            } elseif (! (bool) ($providerValidation['valid'] ?? false)) {
                $validationStatus = strtolower(trim((string) ($providerValidation['status'] ?? 'error')));
                if (in_array($validationStatus, ['not_configured', 'unknown', 'unverified'], true)) {
                    $status = 'incomplete';
                    $configStatus = 'incomplete';
                } else {
                    $status = 'error';
                    $configStatus = 'error';
                }
            } else {
                $status = 'ready';
                $configStatus = 'configured';
                $canSend = true;
            }

            $missingRequirements = array_values(array_unique([
                ...$missingRequirements,
                ...($status === 'error' ? $validationIssues : []),
            ]));
            $warnings = array_values(array_unique($warnings));
            $notes = array_values(array_unique($notes));

            $providerHealthStatus = (string) ($providerValidation['status'] ?? ($status === 'ready' ? 'healthy' : 'unhealthy'));
            $providerHealth = [
                'provider' => $providerKey,
                'tenant_id' => $resolvedTenantId,
                'status' => $providerHealthStatus,
                'message' => (bool) ($providerValidation['valid'] ?? false)
                    ? 'Provider configuration validated for runtime readiness checks.'
                    : ((string) ($validationIssues[0] ?? ($missingRequirements[0] ?? 'Provider readiness issues detected.'))),
                'details' => is_array($providerValidation['details'] ?? null) ? $providerValidation['details'] : [],
            ];

            $result = [
                'tenant_id' => $resolvedTenantId,
                'provider' => $providerKey,
                'status' => $status,
                'can_send' => $canSend,
                'can_send_live' => $canSend && ! $dryRun,
                'provider_status' => strtolower(trim((string) ($settings['provider_status'] ?? 'unknown'))),
                'config_status' => $configStatus,
                'missing_requirements' => $missingRequirements,
                'warnings' => $warnings,
                'notes' => $notes,
                'using_fallback_config' => $usingFallbackConfig,
                'resolution_source' => $resolutionSource,
                'supports_runtime_sends' => $supportsRuntimeSends,
                'enabled' => $emailEnabled,
                'dry_run' => $dryRun,
                'smoke_test_recipient_email' => $smokeTestEmail,
                'smoke_test_configured' => $smokeTestEmail !== '',
                'missing_reasons' => $missingRequirements,
                'legacy_status' => $this->legacyStatus($status, $canSend, $dryRun),
                'sendgrid_key_present' => $providerKey === 'sendgrid' && $sendGridApiKey !== '',
                'from_email_present' => $fromEmail !== null,
                'from_name_present' => $fromName !== null,
                'provider_health' => $providerHealth,
            ];

            Log::info('marketing email readiness evaluated', [
                'tenant_id' => $resolvedTenantId,
                'provider' => $providerKey,
                'status' => $status,
                'config_status' => $configStatus,
                'can_send' => $canSend,
                'resolution_source' => $resolutionSource,
                'using_fallback_config' => $usingFallbackConfig,
            ]);

            return $result;
        } catch (\Throwable $exception) {
            Log::error('marketing email readiness evaluation failed', [
                'tenant_id' => $tenantId,
                'error' => $exception->getMessage(),
            ]);

            return [
                'tenant_id' => $tenantId,
                'provider' => 'unknown',
                'status' => 'error',
                'can_send' => false,
                'can_send_live' => false,
                'provider_status' => 'unhealthy',
                'config_status' => 'error',
                'missing_requirements' => ['Unable to evaluate email readiness.'],
                'warnings' => [],
                'notes' => ['Readiness evaluation failed unexpectedly.'],
                'using_fallback_config' => false,
                'resolution_source' => 'none',
                'supports_runtime_sends' => false,
                'enabled' => false,
                'dry_run' => $dryRun,
                'smoke_test_recipient_email' => $smokeTestEmail,
                'smoke_test_configured' => $smokeTestEmail !== '',
                'missing_reasons' => ['Unable to evaluate email readiness.'],
                'legacy_status' => 'misconfigured',
                'sendgrid_key_present' => false,
                'from_email_present' => false,
                'from_name_present' => false,
                'provider_health' => [
                    'provider' => 'unknown',
                    'tenant_id' => $tenantId,
                    'status' => 'error',
                    'message' => 'Readiness evaluation failed.',
                    'details' => [],
                ],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   provider:string,
     *   tenant_id:?int,
     *   status:string,
     *   message:string,
     *   details:array<string,mixed>
     * }
     */
    public function getProviderHealthForTenant(?int $tenantId, array $context = []): array
    {
        try {
            $options = [
                ...$context,
                'tenant_id' => $tenantId,
                'perform_live_check' => (bool) ($context['perform_live_check'] ?? false),
            ];

            return $this->dispatchService->healthStatus($options);
        } catch (\Throwable $exception) {
            Log::warning('marketing email provider health evaluation failed', [
                'tenant_id' => $tenantId,
                'error' => $exception->getMessage(),
            ]);

            return [
                'provider' => 'unknown',
                'tenant_id' => $tenantId,
                'status' => 'error',
                'message' => 'Provider health check failed.',
                'details' => [
                    'exception' => get_class($exception),
                ],
            ];
        }
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public function canSendForTenant(?int $tenantId, array $context = []): bool
    {
        return (bool) ($this->getReadinessForTenant($tenantId, $context)['can_send'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   tenant_id:?int,
     *   provider:string,
     *   readiness_status:string,
     *   provider_status:string,
     *   config_status:string,
     *   can_send:bool,
     *   can_send_live:bool,
     *   supports_runtime_sends:bool,
     *   resolution_source:string,
     *   using_fallback_config:bool,
     *   missing_requirements:array<int,string>,
     *   warnings:array<int,string>,
     *   notes:array<int,string>
     * }
     */
    public function providerContextForDelivery(?int $tenantId, array $context = []): array
    {
        $summary = $this->getReadinessForTenant($tenantId, $context);

        return [
            'tenant_id' => isset($summary['tenant_id']) && is_numeric($summary['tenant_id'])
                ? (int) $summary['tenant_id']
                : $tenantId,
            'provider' => (string) ($summary['provider'] ?? 'unknown'),
            'readiness_status' => (string) ($summary['status'] ?? 'error'),
            'provider_status' => (string) ($summary['provider_status'] ?? 'not_configured'),
            'config_status' => (string) ($summary['config_status'] ?? 'error'),
            'can_send' => (bool) ($summary['can_send'] ?? false),
            'can_send_live' => (bool) ($summary['can_send_live'] ?? false),
            'supports_runtime_sends' => (bool) ($summary['supports_runtime_sends'] ?? false),
            'resolution_source' => (string) ($summary['resolution_source'] ?? 'none'),
            'using_fallback_config' => (bool) ($summary['using_fallback_config'] ?? false),
            'missing_requirements' => array_values((array) ($summary['missing_requirements'] ?? [])),
            'warnings' => array_values((array) ($summary['warnings'] ?? [])),
            'notes' => array_values((array) ($summary['notes'] ?? [])),
        ];
    }

    public function isLiveReady(array $summary): bool
    {
        return (bool) ($summary['can_send_live'] ?? false);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array{
     *   tenant_id:?int,
     *   provider:\App\Services\Marketing\Email\EmailProvider,
     *   settings:array<string,mixed>,
     *   configuration_issues:array<int,string>
     * }
     */
    protected function providerResolution(?int $tenantId, array $context): array
    {
        if ($tenantId !== null) {
            return $this->providerResolver->getEmailProviderForTenant($tenantId);
        }

        $storeContext = is_array($context['store_context'] ?? null)
            ? $context['store_context']
            : [];

        $storeKey = $this->nullableString($context['store_key'] ?? null);
        if ($storeKey !== null && ! array_key_exists('key', $storeContext)) {
            $storeContext['key'] = $storeKey;
        }

        if ($storeContext !== []) {
            return $this->providerResolver->getEmailProviderForStore($storeContext);
        }

        return $this->providerResolver->getEmailProviderForTenant(null);
    }

    /**
     * @param  array<string,mixed>  $validation
     * @param  array<int,string>  $missingRequirements
     */
    protected function configStatusFromValidation(array $validation, array $missingRequirements): string
    {
        if ($missingRequirements !== []) {
            return 'incomplete';
        }

        if ((bool) ($validation['valid'] ?? false)) {
            return 'configured';
        }

        $status = strtolower(trim((string) ($validation['status'] ?? 'error')));
        if (in_array($status, ['healthy', 'configured'], true)) {
            return 'configured';
        }

        return in_array($status, ['not_configured', 'unknown', 'unverified'], true) ? 'incomplete' : 'error';
    }

    protected function legacyStatus(string $normalizedStatus, bool $canSend, bool $dryRun): string
    {
        if (! $canSend) {
            return $normalizedStatus === 'not_configured' ? 'disabled' : 'misconfigured';
        }

        return $dryRun ? 'dry_run_only' : 'ready_for_live_send';
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
