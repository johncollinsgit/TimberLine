<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Services\Shopify\ShopifyClient;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyCustomerSyncService
{
    public function __construct(
        protected ShopifyCustomerWebhookIngestor $ingestor
    ) {
    }

    /**
     * @param  array<string,mixed>  $store
     * @param  array<string,mixed>  $options
     * @return array{status:string,run_id:int,summary:array<string,int|string|null>}
     */
    public function syncStore(array $store, array $options = []): array
    {
        $storeKey = $this->requiredString($store['key'] ?? null, 'Shopify store key is missing.');
        $shopDomain = $this->requiredString($store['shop'] ?? null, "Shopify shop domain missing for store '{$storeKey}'.");
        $token = $this->requiredString($store['token'] ?? null, "Shopify token missing for store '{$storeKey}'.");
        $apiVersion = $this->nullableString($store['api_version'] ?? null) ?: '2026-01';
        $tenantId = $this->resolvedTenantIdFromStore($store);

        $limit = $this->nullableInt($options['limit'] ?? null);
        $pageSize = min(max(1, (int) ($options['page_size'] ?? 250)), 250);
        $nextUrl = $this->nullableString($options['next_url'] ?? null);
        $dryRun = (bool) ($options['dry_run'] ?? false);

        $summary = [
            'processed' => 0,
            'linked' => 0,
            'review_required' => 0,
            'snapshot_only' => 0,
            'skipped' => 0,
            'created' => 0,
            'updated' => 0,
            'links_created' => 0,
            'links_updated' => 0,
            'link_conflicts' => 0,
            'pages_processed' => 0,
            'errors' => 0,
            'next_url' => $nextUrl,
        ];

        $run = MarketingImportRun::query()->create([
            'type' => 'shopify_customers_sync',
            'status' => 'running',
            'source_label' => 'shopify_customers:' . $storeKey,
            'started_at' => now(),
            'tenant_id' => $tenantId,
            'summary' => [
                'mode' => $dryRun ? 'dry-run' : 'live-sync',
                'store_key' => $storeKey,
                'limit' => $limit,
                'page_size' => $pageSize,
                'next_url' => $nextUrl,
            ],
        ]);

        Log::info('shopify customer sync started', [
            'store_key' => $storeKey,
            'dry_run' => $dryRun,
            'limit' => $limit,
            'page_size' => $pageSize,
            'run_id' => $run->id,
        ]);

        try {
            $client = new ShopifyClient($shopDomain, $token, $apiVersion);
            $remaining = $limit;

            do {
                $pageLimit = $remaining === null ? $pageSize : min($pageSize, $remaining);
                if ($pageLimit <= 0) {
                    break;
                }

                $page = $client->getPage($nextUrl ?: 'customers.json', $nextUrl ? [] : [
                    'limit' => $pageLimit,
                    'fields' => implode(',', [
                        'id',
                        'admin_graphql_api_id',
                        'email',
                        'phone',
                        'first_name',
                        'last_name',
                        'orders_count',
                        'total_spent',
                        'created_at',
                        'updated_at',
                        'tags',
                        'verified_email',
                        'accepts_marketing',
                        'email_marketing_consent',
                        'sms_marketing_consent',
                    ]),
                ]);

                $summary['pages_processed']++;
                $customers = is_array($page['items'] ?? null) ? $page['items'] : [];

                foreach ($customers as $customer) {
                    if ($remaining !== null && $remaining <= 0) {
                        break 2;
                    }

                    if ($remaining !== null) {
                        $remaining--;
                    }

                    $summary['processed']++;

                    if ($dryRun) {
                        $summary[$this->estimatedSnapshotAction($tenantId, $storeKey, $customer)]++;

                        continue;
                    }

                    $result = $this->ingestor->ingest($store, $customer, [
                        'tenant_id' => $tenantId,
                        'topic' => 'customers/sync',
                    ]);

                    $this->mergeIngestSummary($summary, $result);
                }

                $nextUrl = $this->nullableString($page['next_url'] ?? null);
                $summary['next_url'] = $nextUrl;
            } while ($nextUrl !== null && ($remaining === null || $remaining > 0));

            $status = $summary['errors'] > 0
                ? ($summary['processed'] > 0 ? 'partial' : 'failed')
                : 'completed';

            $run->forceFill([
                'status' => $status,
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $dryRun ? 'Dry-run executed; no customer rows were written.' : null,
            ])->save();

            Log::info('shopify customer sync completed', [
                'store_key' => $storeKey,
                'run_id' => $run->id,
                'summary' => $summary,
            ]);

            return [
                'status' => (string) $run->status,
                'run_id' => (int) $run->id,
                'summary' => $summary,
            ];
        } catch (\Throwable $e) {
            $summary['errors']++;

            $run->forceFill([
                'status' => 'failed',
                'finished_at' => now(),
                'summary' => $summary,
                'notes' => $e->getMessage(),
            ])->save();

            Log::error('shopify customer sync failed', [
                'store_key' => $storeKey,
                'run_id' => $run->id,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string,int|string|null>  $summary
     * @param  array<string,mixed>  $result
     */
    protected function mergeIngestSummary(array &$summary, array $result): void
    {
        $status = strtolower(trim((string) ($result['status'] ?? '')));
        if (array_key_exists($status, $summary)) {
            $summary[$status] = (int) $summary[$status] + 1;
        } else {
            $summary['skipped'] = (int) $summary['skipped'] + 1;
        }

        $externalStatus = strtolower(trim((string) ($result['external_status'] ?? '')));
        if (in_array($externalStatus, ['created', 'updated'], true)) {
            $summary[$externalStatus] = (int) $summary[$externalStatus] + 1;
        }

        $linkStatus = strtolower(trim((string) ($result['link_status'] ?? '')));
        if ($linkStatus === 'created') {
            $summary['links_created'] = (int) $summary['links_created'] + 1;
        } elseif ($linkStatus === 'updated') {
            $summary['links_updated'] = (int) $summary['links_updated'] + 1;
        } elseif ($linkStatus === 'conflict') {
            $summary['link_conflicts'] = (int) $summary['link_conflicts'] + 1;
        }
    }

    /**
     * @param  array<string,mixed>  $customer
     * @return 'created'|'updated'
     */
    protected function estimatedSnapshotAction(?int $tenantId, string $storeKey, array $customer): string
    {
        $shopifyCustomerId = trim((string) ($customer['id'] ?? ''));
        if ($shopifyCustomerId === '') {
            return 'updated';
        }

        $exists = CustomerExternalProfile::query()
            ->where('tenant_id', $tenantId)
            ->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')
            ->where('store_key', $storeKey)
            ->where('external_customer_id', $shopifyCustomerId)
            ->exists();

        return $exists ? 'updated' : 'created';
    }

    protected function tenantIdFromStore(array $store): ?int
    {
        $tenantId = is_numeric($store['tenant_id'] ?? null) ? (int) $store['tenant_id'] : null;
        if ($tenantId === null) {
            throw new RuntimeException('Tenant context missing for Shopify customer sync store.');
        }

        return $tenantId;
    }

    protected function resolvedTenantIdFromStore(array $store): ?int
    {
        try {
            return $this->tenantIdFromStore($store);
        } catch (RuntimeException) {
            return Tenant::query()->exists() ? null : null;
        }
    }

    protected function requiredString(mixed $value, string $message): string
    {
        $string = trim((string) $value);
        if ($string === '') {
            throw new RuntimeException($message);
        }

        return $string;
    }

    protected function nullableString(mixed $value): ?string
    {
        $string = trim((string) $value);

        return $string !== '' ? $string : null;
    }

    protected function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        return max(1, (int) $value);
    }
}
