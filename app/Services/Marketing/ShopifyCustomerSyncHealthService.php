<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\IntegrationHealthEvent;
use App\Models\MarketingIdentityReview;
use App\Models\ShopifyStore;
use App\Services\Shopify\ShopifyStores;
use App\Services\Shopify\ShopifyWebhookSubscriptionService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class ShopifyCustomerSyncHealthService
{
    public function __construct(
        protected ShopifyWebhookSubscriptionService $webhookSubscriptionService
    ) {
    }

    /**
     * @return array{
     *   generated_at:CarbonInterface,
     *   window_hours:int,
     *   required_topics:array<int,string>,
     *   signals:array<string,bool>,
     *   totals:array<string,int>,
     *   stores:array<int,array<string,mixed>>,
     *   recent_events:array<int,array<string,mixed>>
     * }
     */
    public function report(bool $refreshWebhooks = false, int $lookbackHours = 72, ?int $tenantId = null): array
    {
        $windowHours = max(1, min(24 * 30, $lookbackHours));
        $windowStart = now()->subHours($windowHours)->toImmutable();
        $stores = ShopifyStore::query()
            ->forTenantId($tenantId)
            ->orderBy('store_key')
            ->get();

        if ($stores->isEmpty()) {
            return [
                'generated_at' => now(),
                'window_hours' => $windowHours,
                'required_topics' => array_keys($this->webhookSubscriptionService->requiredTopicsWithCallbacks()),
                'signals' => $this->signalAvailability(),
                'totals' => [
                    'stores' => 0,
                    'healthy' => 0,
                    'warning' => 0,
                    'failing' => 0,
                    'unknown' => 0,
                ],
                'stores' => [],
                'recent_events' => [],
            ];
        }

        $storeKeys = $stores->pluck('store_key')
            ->map(fn (mixed $value): ?string => $this->normalizeStoreKey($value))
            ->filter()
            ->values()
            ->all();

        $resolvedStoreContexts = collect(ShopifyStores::all(true))
            ->filter(fn (array $store): bool => $this->normalizeStoreKey($store['key'] ?? null) !== null)
            ->keyBy(fn (array $store): string => (string) $this->normalizeStoreKey($store['key'] ?? null));

        $lastWebhookByStore = $this->lastWebhookIngestionByStore($storeKeys);
        $persistedSignals = $this->persistedEventSignalsByStore($storeKeys, $windowStart);
        $failureSignals = $this->recentFailureSignalsByStore($storeKeys, $windowStart);
        $identityConflicts = $this->pendingIdentityConflictsByStore($storeKeys);

        $rows = [];
        foreach ($stores as $store) {
            $storeKey = $this->normalizeStoreKey($store->store_key);
            if ($storeKey === null) {
                continue;
            }

            $context = $this->buildStoreContext(
                $store,
                (array) ($resolvedStoreContexts->get($storeKey) ?? [])
            );

            $webhook = $this->verifyWebhookStatus($context, $refreshWebhooks);
            $auth = $this->authHealthForStore($store, $context, $webhook);
            $lastWebhookAt = $lastWebhookByStore[$storeKey] ?? null;
            $fallbackFailures = $failureSignals[$storeKey] ?? [
                'provisioning' => 0,
                'webhook_ingestion' => 0,
                'unresolved_context' => 0,
            ];
            $eventSignal = $persistedSignals[$storeKey] ?? [
                'recent_provisioning_failures' => 0,
                'recent_webhook_ingestion_failures' => 0,
                'recent_unresolved_context_failures' => 0,
                'open_warning_count' => 0,
                'open_error_count' => 0,
                'identity_conflicts_open' => 0,
            ];

            $storeFailures = [
                'provisioning' => (int) ($eventSignal['recent_provisioning_failures'] ?? 0)
                    ?: (int) ($fallbackFailures['provisioning'] ?? 0),
                'webhook_ingestion' => (int) ($eventSignal['recent_webhook_ingestion_failures'] ?? 0)
                    ?: (int) ($fallbackFailures['webhook_ingestion'] ?? 0),
                'unresolved_context' => (int) ($eventSignal['recent_unresolved_context_failures'] ?? 0)
                    ?: (int) ($fallbackFailures['unresolved_context'] ?? 0),
            ];
            $conflictCount = (int) ($eventSignal['identity_conflicts_open'] ?? 0)
                ?: (int) ($identityConflicts[$storeKey] ?? 0);
            $openWarningCount = (int) ($eventSignal['open_warning_count'] ?? 0);
            $openErrorCount = (int) ($eventSignal['open_error_count'] ?? 0);

            [$status, $statusHint] = $this->deriveOverallStatus(
                $auth,
                $webhook,
                (int) ($storeFailures['provisioning'] ?? 0),
                (int) ($storeFailures['webhook_ingestion'] ?? 0),
                (int) ($storeFailures['unresolved_context'] ?? 0),
                $conflictCount,
                $lastWebhookAt,
                $openWarningCount,
                $openErrorCount
            );

            $rows[] = [
                'store_key' => $storeKey,
                'store_name' => $this->storeName($storeKey, $store->shop_domain),
                'shop_domain' => (string) $store->shop_domain,
                'tenant_id' => $store->tenant_id ? (int) $store->tenant_id : null,
                'installed_at' => $store->installed_at,
                'status' => $status,
                'status_label' => $this->statusLabel($status),
                'status_hint' => $statusHint,
                'webhook' => $webhook,
                'auth' => $auth,
                'last_customer_webhook_ingested_at' => $lastWebhookAt,
                'recent_provisioning_failures' => (int) ($storeFailures['provisioning'] ?? 0),
                'recent_webhook_ingestion_failures' => (int) ($storeFailures['webhook_ingestion'] ?? 0),
                'recent_unresolved_context_failures' => (int) ($storeFailures['unresolved_context'] ?? 0),
                'unresolved_identity_conflicts' => $conflictCount,
                'open_warning_events' => $openWarningCount,
                'open_error_events' => $openErrorCount,
            ];
        }

        $totals = [
            'stores' => count($rows),
            'healthy' => 0,
            'warning' => 0,
            'failing' => 0,
            'unknown' => 0,
        ];

        foreach ($rows as $row) {
            $status = (string) ($row['status'] ?? 'unknown');
            if (array_key_exists($status, $totals)) {
                $totals[$status]++;
            } else {
                $totals['unknown']++;
            }
        }

        return [
            'generated_at' => now(),
            'window_hours' => $windowHours,
            'required_topics' => array_keys($this->webhookSubscriptionService->requiredTopicsWithCallbacks()),
            'signals' => $this->signalAvailability(),
            'totals' => $totals,
            'stores' => $rows,
            'recent_events' => $this->recentEventsFeed($storeKeys, $windowStart),
        ];
    }

    /**
     * @param  array<string,mixed>  $resolvedContext
     * @return array<string,mixed>
     */
    protected function buildStoreContext(ShopifyStore $store, array $resolvedContext): array
    {
        $shopDomain = trim((string) ($resolvedContext['shop'] ?? $store->shop_domain ?? ''));
        $token = trim((string) ($resolvedContext['token'] ?? $store->access_token ?? ''));
        $apiVersion = trim((string) ($resolvedContext['api_version'] ?? config('services.shopify.api_version', '2026-01')));

        return [
            'key' => (string) $store->store_key,
            'shop' => $shopDomain !== '' ? $shopDomain : null,
            'token' => $token !== '' ? $token : null,
            'api_version' => $apiVersion !== '' ? $apiVersion : '2026-01',
            'tenant_id' => $store->tenant_id ? (int) $store->tenant_id : null,
        ];
    }

    /**
     * @param  array<string,mixed>  $storeContext
     * @return array{
     *   status:string,
     *   label:string,
     *   summary:string,
     *   required_count:int,
     *   drift_count:int,
     *   missing_count:int,
     *   mismatch_count:int,
     *   failed_count:int,
     *   checked_at:CarbonInterface,
     *   error:?string
     * }
     */
    protected function verifyWebhookStatus(array $storeContext, bool $refresh): array
    {
        $storeKey = $this->normalizeStoreKey($storeContext['key'] ?? null) ?? 'unknown';
        $cacheKey = 'shopify_customer_sync_health:webhook:' . sha1($storeKey . '|' . ((string) ($storeContext['shop'] ?? '')));

        if ($refresh) {
            Cache::forget($cacheKey);
        }

        $verification = Cache::remember(
            $cacheKey,
            now()->addMinutes(5),
            fn (): array => $this->webhookSubscriptionService->verifyStore($storeContext, false)
        );

        $counts = is_array($verification['counts'] ?? null) ? $verification['counts'] : [];
        $status = (string) ($verification['status'] ?? 'unknown');
        $requiredCount = (int) ($verification['required_count'] ?? 0);
        $missingCount = (int) ($counts['missing'] ?? 0);
        $mismatchCount = (int) ($counts['mismatch'] ?? 0);
        $failedCount = (int) ($counts['failed'] ?? 0);
        $driftCount = (int) ($verification['drift_count'] ?? ($missingCount + $mismatchCount));
        $error = $this->nullableString($verification['error'] ?? null)
            ?? $this->nullableString($verification['error_message'] ?? null);

        $summary = match ($status) {
            'ok' => $requiredCount > 0
                ? "All required topics are registered ({$requiredCount}/{$requiredCount})."
                : 'No required topics are configured.',
            'drift' => "Webhook drift detected (missing {$missingCount}, mismatched {$mismatchCount}).",
            'repaired' => 'Required webhooks were recently repaired.',
            'failed' => $error !== null ? "Verification failed: {$error}." : 'Verification failed; check Shopify credentials and API access.',
            default => 'Webhook state is currently unknown.',
        };

        return [
            'status' => $status,
            'label' => Str::headline($status !== '' ? $status : 'unknown'),
            'summary' => $summary,
            'required_count' => $requiredCount,
            'drift_count' => $driftCount,
            'missing_count' => $missingCount,
            'mismatch_count' => $mismatchCount,
            'failed_count' => $failedCount,
            'checked_at' => now(),
            'error' => $error,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @param  array<string,mixed>  $webhook
     * @return array{status:string,label:string,hint:string}
     */
    protected function authHealthForStore(ShopifyStore $store, array $context, array $webhook): array
    {
        $token = trim((string) ($context['token'] ?? ''));
        $installedAt = $store->installed_at;
        $webhookStatus = (string) ($webhook['status'] ?? '');
        $webhookError = Str::lower((string) ($webhook['error'] ?? ''));

        if ($token === '') {
            return [
                'status' => 'failing',
                'label' => 'Failing',
                'hint' => 'Store token missing. Reinstall/reconnect this Shopify store.',
            ];
        }

        if ($webhookStatus === 'failed' && ($webhookError === 'store_credentials_missing' || str_contains($webhookError, 'credential'))) {
            return [
                'status' => 'failing',
                'label' => 'Failing',
                'hint' => 'Shopify credentials are incomplete for webhook verification.',
            ];
        }

        if ($webhookStatus === 'failed' && (str_contains($webhookError, '401') || str_contains($webhookError, '403') || str_contains($webhookError, 'unauthorized') || str_contains($webhookError, 'forbidden'))) {
            return [
                'status' => 'failing',
                'label' => 'Failing',
                'hint' => 'Shopify rejected authenticated API access for this store token.',
            ];
        }

        if ($installedAt === null) {
            return [
                'status' => 'warning',
                'label' => 'Warning',
                'hint' => 'Store is missing installed_at metadata; confirm install state.',
            ];
        }

        return [
            'status' => 'healthy',
            'label' => 'Healthy',
            'hint' => 'Store token is present and install metadata is available.',
        ];
    }

    /**
     * @return array{0:string,1:string}
     */
    protected function deriveOverallStatus(
        array $auth,
        array $webhook,
        int $provisioningFailures,
        int $webhookIngestionFailures,
        int $unresolvedContextFailures,
        int $identityConflicts,
        ?CarbonInterface $lastWebhookAt,
        int $openWarningEvents = 0,
        int $openErrorEvents = 0
    ): array {
        $authStatus = (string) ($auth['status'] ?? 'unknown');
        $webhookStatus = (string) ($webhook['status'] ?? 'unknown');
        $webhookFailed = (int) ($webhook['failed_count'] ?? 0);
        $driftCount = (int) ($webhook['drift_count'] ?? 0);

        if ($authStatus === 'failing') {
            return ['failing', (string) ($auth['hint'] ?? 'Store authentication is failing.')];
        }

        if ($webhookStatus === 'failed' || $webhookFailed > 0) {
            return ['failing', 'Webhook verification is failing; customer sync cannot be trusted for this store.'];
        }

        if ($openErrorEvents > 0) {
            return ['failing', 'Open Shopify sync error events are still unresolved for this store.'];
        }

        if ($provisioningFailures >= 3 || $webhookIngestionFailures >= 3) {
            return ['failing', 'Repeated provisioning/webhook ingestion job failures detected in the recent window.'];
        }

        if ($driftCount > 0) {
            return ['warning', 'Required Shopify webhooks are missing or mismatched; run verify/repair.'];
        }

        if ($provisioningFailures > 0 || $webhookIngestionFailures > 0 || $unresolvedContextFailures > 0) {
            return ['warning', 'Recent customer sync job failures were detected and should be reviewed.'];
        }

        if ($identityConflicts > 0) {
            return ['warning', 'Identity review queue has unresolved Shopify customer conflicts.'];
        }

        if ($openWarningEvents > 0) {
            return ['warning', 'Open Shopify sync warning events are still unresolved for this store.'];
        }

        if (! $lastWebhookAt) {
            return ['unknown', 'No successful customer webhook ingestion has been observed yet for this store.'];
        }

        return ['healthy', 'Required webhooks are aligned, auth is healthy, and no recent sync failures were detected.'];
    }

    /**
     * @param  array<int,string>  $storeKeys
     * @return array<string,CarbonImmutable>
     */
    protected function lastWebhookIngestionByStore(array $storeKeys): array
    {
        if ($storeKeys === [] || ! Schema::hasTable('customer_external_profiles')) {
            return [];
        }

        $latest = [];

        $rows = CustomerExternalProfile::query()
            ->select(['store_key', 'raw_metafields'])
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->whereIn('store_key', $storeKeys)
            ->get();

        foreach ($rows as $row) {
            $storeKey = $this->normalizeStoreKey($row->store_key);
            if ($storeKey === null) {
                continue;
            }

            $receivedAt = data_get($row->raw_metafields, 'shopify_customer_webhook.received_at');
            $receivedDate = $this->coerceDate($receivedAt);
            if (! $receivedDate) {
                continue;
            }

            if (! isset($latest[$storeKey]) || $receivedDate->greaterThan($latest[$storeKey])) {
                $latest[$storeKey] = $receivedDate;
            }
        }

        return $latest;
    }

    /**
     * @param  array<int,string>  $storeKeys
     * @return array<string,int>
     */
    protected function pendingIdentityConflictsByStore(array $storeKeys): array
    {
        if ($storeKeys === [] || ! Schema::hasTable('marketing_identity_reviews')) {
            return [];
        }

        $counts = [];
        foreach ($storeKeys as $storeKey) {
            $counts[$storeKey] = 0;
        }

        $rows = MarketingIdentityReview::query()
            ->select(['source_id', 'payload'])
            ->where('status', 'pending')
            ->where('source_type', 'shopify_customer')
            ->get();

        foreach ($rows as $row) {
            $storeKey = $this->extractStoreKeyFromIdentityReview($row->source_id, is_array($row->payload) ? $row->payload : []);
            if ($storeKey === null || ! array_key_exists($storeKey, $counts)) {
                continue;
            }

            $counts[$storeKey]++;
        }

        return $counts;
    }

    /**
     * @param  array<int,string>  $storeKeys
     * @return array<string,array{
     *   recent_provisioning_failures:int,
     *   recent_webhook_ingestion_failures:int,
     *   recent_unresolved_context_failures:int,
     *   open_warning_count:int,
     *   open_error_count:int,
     *   identity_conflicts_open:int
     * }>
     */
    protected function persistedEventSignalsByStore(array $storeKeys, CarbonImmutable $windowStart): array
    {
        $signals = [];
        foreach ($storeKeys as $storeKey) {
            $signals[$storeKey] = [
                'recent_provisioning_failures' => 0,
                'recent_webhook_ingestion_failures' => 0,
                'recent_unresolved_context_failures' => 0,
                'open_warning_count' => 0,
                'open_error_count' => 0,
                'identity_conflicts_open' => 0,
            ];
        }

        if ($storeKeys === [] || ! Schema::hasTable('integration_health_events')) {
            return $signals;
        }

        $rows = IntegrationHealthEvent::query()
            ->select(['store_key', 'event_type', 'severity', 'status', 'occurred_at'])
            ->forProvider('shopify')
            ->whereIn('store_key', $storeKeys)
            ->where(function (Builder $query) use ($windowStart): void {
                $query->where('status', 'open')
                    ->orWhere('occurred_at', '>=', $windowStart->toDateTimeString());
            })
            ->get();

        foreach ($rows as $row) {
            $storeKey = $this->normalizeStoreKey($row->store_key);
            if ($storeKey === null || ! array_key_exists($storeKey, $signals)) {
                continue;
            }

            $occurredAt = $this->coerceDate($row->occurred_at);
            $isRecent = $occurredAt && $occurredAt->greaterThanOrEqualTo($windowStart);
            $status = strtolower(trim((string) ($row->status ?? 'open')));
            $severity = strtolower(trim((string) ($row->severity ?? 'info')));
            $eventType = strtolower(trim((string) ($row->event_type ?? '')));

            if ($status === 'open') {
                if ($severity === 'error') {
                    $signals[$storeKey]['open_error_count']++;
                }
                if ($severity === 'warning') {
                    $signals[$storeKey]['open_warning_count']++;
                }
                if ($eventType === 'identity_conflict_pending') {
                    $signals[$storeKey]['identity_conflicts_open']++;
                }
            }

            if (! $isRecent) {
                continue;
            }

            if ($eventType === 'customer_provisioning_failed') {
                $signals[$storeKey]['recent_provisioning_failures']++;
            }
            if ($eventType === 'customer_webhook_ingestion_failed') {
                $signals[$storeKey]['recent_webhook_ingestion_failures']++;
            }
            if ($eventType === 'tenant_context_unresolved') {
                $signals[$storeKey]['recent_unresolved_context_failures']++;
            }
        }

        return $signals;
    }

    /**
     * @param  array<int,string>  $storeKeys
     * @return array<int,array<string,mixed>>
     */
    protected function recentEventsFeed(array $storeKeys, CarbonImmutable $windowStart, int $limit = 25): array
    {
        if ($storeKeys === [] || ! Schema::hasTable('integration_health_events')) {
            return [];
        }

        return IntegrationHealthEvent::query()
            ->select([
                'store_key',
                'tenant_id',
                'event_type',
                'severity',
                'status',
                'occurred_at',
                'resolved_at',
                'context',
            ])
            ->forProvider('shopify')
            ->whereIn('store_key', $storeKeys)
            ->where(function (Builder $query) use ($windowStart): void {
                $query->where('status', 'open')
                    ->orWhere('occurred_at', '>=', $windowStart->toDateTimeString());
            })
            ->orderByDesc('occurred_at')
            ->limit($limit)
            ->get()
            ->map(function (IntegrationHealthEvent $event): array {
                $context = is_array($event->context) ? $event->context : [];

                return [
                    'store_key' => $this->normalizeStoreKey($event->store_key) ?? 'unknown',
                    'tenant_id' => $event->tenant_id ? (int) $event->tenant_id : null,
                    'event_type' => (string) $event->event_type,
                    'severity' => (string) $event->severity,
                    'status' => (string) $event->status,
                    'occurred_at' => $event->occurred_at,
                    'resolved_at' => $event->resolved_at,
                    'topic' => $this->nullableString($context['topic'] ?? null),
                    'reason' => $this->nullableString($context['reason'] ?? null),
                    'message' => $this->nullableString($context['error_message'] ?? null),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $storeKeys
     * @return array<string,array{provisioning:int,webhook_ingestion:int,unresolved_context:int}>
     */
    protected function recentFailureSignalsByStore(array $storeKeys, CarbonImmutable $windowStart): array
    {
        $counts = [];
        foreach ($storeKeys as $storeKey) {
            $counts[$storeKey] = [
                'provisioning' => 0,
                'webhook_ingestion' => 0,
                'unresolved_context' => 0,
            ];
        }

        if ($storeKeys === [] || ! Schema::hasTable('failed_jobs')) {
            return $counts;
        }

        $failedJobs = DB::table('failed_jobs')
            ->select(['payload', 'exception'])
            ->where('failed_at', '>=', $windowStart->toDateTimeString())
            ->orderByDesc('id')
            ->limit(2000)
            ->get();

        foreach ($failedJobs as $failedJob) {
            $payload = (string) ($failedJob->payload ?? '');
            $exception = (string) ($failedJob->exception ?? '');
            $signal = $this->parseFailedJobSignal($payload, $exception);
            if ($signal === null) {
                continue;
            }

            $storeKey = $signal['store_key'] ?? null;
            if ($storeKey === null || ! array_key_exists($storeKey, $counts)) {
                continue;
            }

            $jobType = $signal['job_type'] ?? null;
            if ($jobType === 'provisioning') {
                $counts[$storeKey]['provisioning']++;
            }
            if ($jobType === 'webhook_ingestion') {
                $counts[$storeKey]['webhook_ingestion']++;
            }
            if (($signal['unresolved_context'] ?? false) === true) {
                $counts[$storeKey]['unresolved_context']++;
            }
        }

        return $counts;
    }

    /**
     * @return array{job_type:string,store_key:?string,unresolved_context:bool}|null
     */
    protected function parseFailedJobSignal(string $payload, string $exception): ?array
    {
        $payloadLower = Str::lower($payload);

        $jobType = null;
        if (str_contains($payloadLower, 'provisionshopifycustomerformarketingprofile')) {
            $jobType = 'provisioning';
        } elseif (str_contains($payloadLower, 'shopifysynccustomerfromwebhook')) {
            $jobType = 'webhook_ingestion';
        }

        if ($jobType === null) {
            return null;
        }

        $decoded = json_decode($payload, true);
        $decodedPayload = is_array($decoded) ? $decoded : [];
        $command = (string) data_get($decodedPayload, 'data.command', '');

        $storeKey = $this->extractStoreKeyFromPayload($payload, $decodedPayload, $command);

        $exceptionLower = Str::lower($exception);
        $unresolvedContext = str_contains($exceptionLower, 'tenant context')
            || str_contains($exceptionLower, 'store context')
            || str_contains($exceptionLower, 'unresolved tenant');

        return [
            'job_type' => $jobType,
            'store_key' => $storeKey,
            'unresolved_context' => $unresolvedContext,
        ];
    }

    /**
     * @param  array<string,mixed>  $decodedPayload
     */
    protected function extractStoreKeyFromPayload(string $rawPayload, array $decodedPayload, string $command): ?string
    {
        $candidates = [
            data_get($decodedPayload, 'store_key'),
            data_get($decodedPayload, 'storeKey'),
            data_get($decodedPayload, 'data.store_key'),
            data_get($decodedPayload, 'data.storeKey'),
        ];

        if (preg_match('/storeKey";s:\d+:"([^"]*)"/', $command, $matches) === 1) {
            $candidates[] = $matches[1];
        }

        if (preg_match('/store_key";s:\d+:"([^"]*)"/', $command, $matches) === 1) {
            $candidates[] = $matches[1];
        }

        if (preg_match('/storeContext";a:\d+:\{.*?s:3:"key";s:\d+:"([^"]*)"/s', $command, $matches) === 1) {
            $candidates[] = $matches[1];
        }

        if (preg_match('/"store_key"\s*:\s*"([^"]+)"/', $rawPayload, $matches) === 1) {
            $candidates[] = $matches[1];
        }

        if (preg_match('/"storeKey"\s*:\s*"([^"]+)"/', $rawPayload, $matches) === 1) {
            $candidates[] = $matches[1];
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeStoreKey($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function extractStoreKeyFromIdentityReview(?string $sourceId, array $payload): ?string
    {
        $sourceStoreKey = null;
        $sourceId = trim((string) $sourceId);
        if ($sourceId !== '' && str_contains($sourceId, ':')) {
            $sourceStoreKey = explode(':', $sourceId)[0] ?? null;
        }

        return $this->normalizeStoreKey($sourceStoreKey)
            ?? $this->normalizeStoreKey(data_get($payload, 'source_context.store_key'))
            ?? $this->normalizeStoreKey(data_get($payload, 'store_key'));
    }

    protected function storeName(string $storeKey, string $shopDomain): string
    {
        $domainLabel = trim($shopDomain);
        if ($domainLabel !== '') {
            return $domainLabel;
        }

        return Str::headline($storeKey);
    }

    protected function statusLabel(string $status): string
    {
        return match ($status) {
            'healthy' => 'Healthy',
            'warning' => 'Warning',
            'failing' => 'Failing',
            default => 'Unknown',
        };
    }

    /**
     * @return array<string,bool>
     */
    protected function signalAvailability(): array
    {
        return [
            'failed_jobs' => Schema::hasTable('failed_jobs'),
            'customer_external_profiles' => Schema::hasTable('customer_external_profiles'),
            'marketing_identity_reviews' => Schema::hasTable('marketing_identity_reviews'),
            'integration_health_events' => Schema::hasTable('integration_health_events'),
        ];
    }

    protected function coerceDate(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return $value->toImmutable();
        }

        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function normalizeStoreKey(mixed $value): ?string
    {
        $storeKey = strtolower(trim((string) $value));

        return $storeKey !== '' ? $storeKey : null;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }
}
