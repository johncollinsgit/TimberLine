<?php

namespace App\Services\Operations;

use App\Models\Agreement;
use App\Models\CustomerAccessRequest;
use App\Models\CustomModuleRequest;
use App\Models\OperatorAlertLog;
use App\Models\ServiceInquiry;
use App\Models\Tenant;
use App\Models\TenantModuleAccessRequest;
use App\Models\TenantSupportTicket;
use App\Services\Marketing\TwilioSmsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class OperatorAlertService
{
    public function __construct(private TwilioSmsService $sms) {}

    /** @param array<string,mixed> $context */
    public function notify(string $eventKey, string $message, array $context = []): void
    {
        $context = $this->hydratedContext($context);
        $dedupe = (string) ($context['dedupe_key'] ?? sha1($eventKey.'|'.$message));
        $destination = preg_replace('/\D+/', '', (string) config('everbranch.operator_alert_phone', ''));
        $log = $this->reserveAlert($eventKey, $dedupe, $message, $destination, $context);

        if (! $log instanceof OperatorAlertLog) {
            return;
        }

        $suppressionReasons = $this->nonRealEventReasons($eventKey, $message, $context);
        if ($suppressionReasons !== []) {
            $this->markAlert($log, 'suppressed', [
                'reason' => 'non_real_event',
                'reasons' => $suppressionReasons,
            ]);

            return;
        }

        if (! (bool) config('everbranch.operator_alert_sms_enabled', false)) {
            $this->markAlert($log, 'suppressed', ['reason' => 'operator_alert_sms_disabled']);

            return;
        }

        if (strlen((string) $destination) < 10) {
            $this->markAlert($log, 'suppressed', ['reason' => 'operator_alert_phone_missing']);

            return;
        }

        if ($this->hasRecentSimilarSmsAlert($log, $eventKey, $message, $context)) {
            $this->markAlert($log, 'suppressed', ['reason' => 'similar_alert_recently_sent']);

            return;
        }

        if (! Cache::add('operator-alert:'.$dedupe, true, now()->addDay())) {
            $this->markAlert($log, 'suppressed', ['reason' => 'cache_dedupe_hit']);

            return;
        }

        $result = $this->sms->sendSms($destination, $message, [
            'source_type' => 'operator_alert',
            'source_id' => $context['target_id'] ?? null,
            'idempotency_key' => 'operator-alert:'.$dedupe,
        ]);

        $this->markAlert($log, ($result['success'] ?? false) ? 'sent' : 'failed', [
            'provider' => $result['provider'] ?? null,
            'error' => $result['error_code'] ?? null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function reserveAlert(string $eventKey, string $dedupe, string $message, string $destination, array $context): OperatorAlertLog|false
    {
        if (! Schema::hasTable('operator_alert_logs')) {
            return false;
        }

        try {
            $log = OperatorAlertLog::query()->firstOrCreate(
                ['dedupe_key' => $dedupe],
                [
                    'event_key' => $eventKey,
                    'tenant_id' => $context['tenant_id'] ?? null,
                    'target_type' => $context['target_type'] ?? null,
                    'target_id' => $context['target_id'] ?? null,
                    'destination' => $destination !== '' ? $destination : 'unconfigured',
                    'status' => 'reserved',
                    'message' => $message,
                    'metadata' => [
                        'reserved_at' => now()->toIso8601String(),
                        'context' => $this->loggableContext($context),
                    ],
                ]
            );
        } catch (\Throwable) {
            return false;
        }

        return $log->wasRecentlyCreated ? $log : false;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function hydratedContext(array $context): array
    {
        $targetType = strtolower(trim((string) ($context['target_type'] ?? '')));
        $targetId = is_numeric($context['target_id'] ?? null) ? (int) $context['target_id'] : null;

        if ($targetType === 'agreement' && $targetId !== null) {
            $agreement = Agreement::withoutGlobalScopes()
                ->with(['tenant.accessProfile'])
                ->find($targetId);

            if ($agreement instanceof Agreement) {
                $context['tenant_id'] ??= (int) $agreement->tenant_id;
                $context['agreement_type'] ??= $agreement->agreement_type;
                $context['agreement_template_key'] ??= $agreement->template_key;
                $context['agreement_title'] ??= $agreement->title;

                if ($agreement->tenant instanceof Tenant) {
                    $context = $this->withTenantContext($context, $agreement->tenant);
                }
            }
        }

        if ($targetType === 'tenant_support_ticket' && $targetId !== null) {
            $ticket = TenantSupportTicket::withoutGlobalScopes()
                ->with(['tenant.accessProfile'])
                ->find($targetId);

            if ($ticket instanceof TenantSupportTicket) {
                $context['tenant_id'] ??= (int) $ticket->tenant_id;
                $context['ticket_priority'] ??= $ticket->priority;
                $context['ticket_subject'] ??= $ticket->subject;
                $context['ticket_source_type'] ??= $ticket->source_type;

                if ($ticket->tenant instanceof Tenant) {
                    $context = $this->withTenantContext($context, $ticket->tenant);
                }
            }
        }

        if ($targetType === 'customer_access_request' && $targetId !== null) {
            $accessRequest = CustomerAccessRequest::query()->find($targetId);

            if ($accessRequest instanceof CustomerAccessRequest) {
                $context['request_email'] ??= $accessRequest->email;
                $context['request_intent'] ??= $accessRequest->intent;
                $context['request_company'] ??= $accessRequest->company;
                $context['request_name'] ??= $accessRequest->name;
            }
        }

        if ($targetType === 'service_inquiry' && $targetId !== null) {
            $inquiry = ServiceInquiry::query()->find($targetId);

            if ($inquiry instanceof ServiceInquiry) {
                $context['request_email'] ??= $inquiry->email;
                $context['request_company'] ??= $inquiry->company;
                $context['request_name'] ??= $inquiry->name;
            }
        }

        if ($targetType === 'custom_module_request' && $targetId !== null) {
            $moduleRequest = CustomModuleRequest::query()
                ->with(['tenant.accessProfile', 'requester'])
                ->find($targetId);

            if ($moduleRequest instanceof CustomModuleRequest) {
                $context['tenant_id'] ??= (int) $moduleRequest->tenant_id;
                $context['request_title'] ??= $moduleRequest->title;
                $context['request_email'] ??= $moduleRequest->requester?->email;

                if ($moduleRequest->tenant instanceof Tenant) {
                    $context = $this->withTenantContext($context, $moduleRequest->tenant);
                }
            }
        }

        if ($targetType === 'tenant_module_access_request' && $targetId !== null) {
            $moduleAccessRequest = TenantModuleAccessRequest::query()
                ->with(['tenant.accessProfile', 'requester'])
                ->find($targetId);

            if ($moduleAccessRequest instanceof TenantModuleAccessRequest) {
                $context['tenant_id'] ??= (int) $moduleAccessRequest->tenant_id;
                $context['module_key'] ??= $moduleAccessRequest->module_key;
                $context['request_email'] ??= $moduleAccessRequest->requester?->email;

                if ($moduleAccessRequest->tenant instanceof Tenant) {
                    $context = $this->withTenantContext($context, $moduleAccessRequest->tenant);
                }
            }
        }

        if (($context['tenant_slug'] ?? null) === null && is_numeric($context['tenant_id'] ?? null)) {
            $tenant = Tenant::query()
                ->with('accessProfile')
                ->find((int) $context['tenant_id']);

            if ($tenant instanceof Tenant) {
                $context = $this->withTenantContext($context, $tenant);
            }
        }

        return $context;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function withTenantContext(array $context, Tenant $tenant): array
    {
        $context['tenant_name'] ??= $tenant->name;
        $context['tenant_slug'] ??= $tenant->slug;
        $context['tenant_account_mode'] ??= $tenant->accessProfile?->metadata['account_mode'] ?? null;
        $context['tenant_plan_key'] ??= $tenant->accessProfile?->plan_key ?? null;

        return $context;
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<int,string>
     */
    protected function nonRealEventReasons(string $eventKey, string $message, array $context): array
    {
        $reasons = [];
        $requestHost = strtolower(trim((string) ($context['request_host'] ?? '')));
        if ($requestHost !== '' && ($requestHost === 'localhost' || str_ends_with($requestHost, '.test'))) {
            $reasons[] = 'non_production_request_host';
        }

        $tenantMode = strtolower(trim((string) ($context['tenant_account_mode'] ?? '')));
        if (in_array($tenantMode, ['demo', 'sandbox', 'test'], true)) {
            $reasons[] = 'non_production_tenant_mode';
        }

        $tenantSlug = strtolower(trim((string) ($context['tenant_slug'] ?? '')));
        $tenantName = strtolower(trim((string) ($context['tenant_name'] ?? '')));
        if ($this->looksLikeTestTenant($tenantSlug, $tenantName)) {
            $reasons[] = 'test_tenant';
        }

        $signerEmail = strtolower(trim((string) ($context['signer_email'] ?? '')));
        if ($signerEmail !== '' && $this->looksLikeTestEmail($signerEmail)) {
            $reasons[] = 'test_signer_email';
        }

        $requestEmail = strtolower(trim((string) ($context['request_email'] ?? '')));
        if ($requestEmail !== '' && $this->looksLikeTestEmail($requestEmail)) {
            $reasons[] = 'test_request_email';
        }

        if ($eventKey === 'agreement.accepted') {
            $agreementType = strtolower(trim((string) ($context['agreement_type'] ?? '')));
            $templateKey = strtolower(trim((string) ($context['agreement_template_key'] ?? '')));
            $title = strtolower(trim((string) ($context['agreement_title'] ?? $message)));

            if ($agreementType === Agreement::TYPE_SANDBOX_VALIDATION) {
                $reasons[] = 'sandbox_validation_agreement';
            }

            if ($templateKey === Agreement::TEMPLATE_FRONT_YARD_SANDBOX_VALIDATION) {
                $reasons[] = 'sandbox_validation_template';
            }

            if (str_contains($title, 'test mode only')) {
                $reasons[] = 'test_mode_agreement';
            }
        }

        return array_values(array_unique($reasons));
    }

    protected function looksLikeTestTenant(string $slug, string $name): bool
    {
        $exactFakeSlugs = [
            'tenant-a',
            'tenant-b',
            'needs-help',
            'branch-preview-tenant',
            'front-yard-foods-expired',
        ];

        if ($slug !== '' && in_array($slug, $exactFakeSlugs, true)) {
            return true;
        }

        if (in_array($name, ['tenant a', 'tenant b', 'needs help'], true)) {
            return true;
        }

        $candidate = trim($slug.' '.$name);
        if ($candidate === '') {
            return false;
        }

        return preg_match('/(^|[-_\s])(test|testing|sandbox|demo|fixture|fake|dummy|sample|example)([-_\s]|$)/', $candidate) === 1;
    }

    protected function looksLikeTestEmail(string $email): bool
    {
        if (! str_contains($email, '@')) {
            return false;
        }

        $domain = substr(strrchr($email, '@') ?: '', 1);
        $domain = strtolower(trim($domain));

        return $domain === ''
            || str_ends_with($domain, '.test')
            || in_array($domain, ['example.com', 'example.net', 'example.org', 'example.test', 'test.com', 'invalid'], true);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    protected function hasRecentSimilarSmsAlert(?OperatorAlertLog $log, string $eventKey, string $message, array $context): bool
    {
        if (! $log instanceof OperatorAlertLog || ! Schema::hasTable('operator_alert_logs')) {
            return false;
        }

        $windowMinutes = max(1, (int) config('everbranch.operator_alert_sms_repeat_window_minutes', 360));
        $tenantId = is_numeric($context['tenant_id'] ?? null) ? (int) $context['tenant_id'] : null;

        return OperatorAlertLog::query()
            ->where('id', '!=', $log->id)
            ->where('event_key', $eventKey)
            ->where('message', $message)
            ->when(
                $tenantId !== null,
                fn ($query) => $query->where('tenant_id', $tenantId),
                fn ($query) => $query->whereNull('tenant_id')
            )
            ->whereIn('status', ['reserved', 'sent'])
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->exists();
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    protected function loggableContext(array $context): array
    {
        return collect($context)
            ->only([
                'tenant_id',
                'tenant_name',
                'tenant_slug',
                'tenant_account_mode',
                'target_type',
                'target_id',
                'agreement_type',
                'agreement_template_key',
                'agreement_title',
                'request_host',
                'ticket_priority',
                'ticket_subject',
                'ticket_source_type',
                'request_intent',
                'request_company',
                'request_name',
                'request_title',
                'module_key',
            ])
            ->filter(fn (mixed $value): bool => $value !== null && $value !== '')
            ->all();
    }

    /**
     * @param  array<string,mixed>  $metadata
     */
    protected function markAlert(?OperatorAlertLog $log, string $status, array $metadata): void
    {
        if (! $log instanceof OperatorAlertLog) {
            return;
        }

        $existing = is_array($log->metadata) ? $log->metadata : [];
        $log->forceFill([
            'status' => $status,
            'metadata' => array_merge($existing, $metadata),
        ])->save();
    }
}
