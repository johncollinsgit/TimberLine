<?php

namespace App\Jobs;

use App\Services\Marketing\IntegrationHealthEventRecorder;
use App\Services\Marketing\ShopifyCustomerWebhookIngestor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ShopifySyncCustomerFromWebhook implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  array<string,mixed>  $storeContext
     * @param  array<string,mixed>  $customerPayload
     */
    public function __construct(
        public array $storeContext,
        public array $customerPayload,
        public ?int $tenantId = null,
        public string $topic = 'customers/create'
    ) {
    }

    public function handle(
        ShopifyCustomerWebhookIngestor $ingestor,
        IntegrationHealthEventRecorder $healthEventRecorder
    ): void
    {
        $result = $ingestor->ingest($this->storeContext, $this->customerPayload, [
            'tenant_id' => $this->tenantId,
            'topic' => $this->topic,
        ]);

        Log::info('shopify customer webhook processed', [
            'topic' => $this->topic,
            'store_key' => $this->storeContext['key'] ?? null,
            'tenant_id' => $this->tenantId,
            'shopify_customer_id' => $result['shopify_customer_id'] ?? null,
            'source_id' => $result['source_id'] ?? null,
            'marketing_profile_id' => $result['marketing_profile_id'] ?? null,
            'status' => $result['status'] ?? null,
        ]);

        $storeKey = trim((string) ($this->storeContext['key'] ?? ''));
        $sourceId = trim((string) ($result['source_id'] ?? ''));
        $status = strtolower(trim((string) ($result['status'] ?? '')));

        if ($status === 'linked') {
            $healthEventRecorder->resolve([
                'provider' => 'shopify',
                'tenant_id' => $this->tenantId,
                'store_key' => $storeKey,
                'event_types' => ['customer_webhook_ingestion_failed', 'tenant_context_unresolved'],
            ]);

            if ($sourceId !== '') {
                $healthEventRecorder->resolve([
                    'provider' => 'shopify',
                    'tenant_id' => $this->tenantId,
                    'store_key' => $storeKey,
                    'event_type' => 'identity_conflict_pending',
                    'dedupe_key' => sha1(json_encode([
                        'provider' => 'shopify',
                        'event_type' => 'identity_conflict_pending',
                        'store_key' => $storeKey,
                        'source_id' => $sourceId,
                    ])),
                ]);
            }
        }

        if ($status === 'review_required') {
            $healthEventRecorder->record([
                'provider' => 'shopify',
                'tenant_id' => $this->tenantId,
                'store_key' => $storeKey,
                'event_type' => 'identity_conflict_pending',
                'severity' => 'warning',
                'status' => 'open',
                'related_model_type' => null,
                'related_model_id' => null,
                'context' => [
                    'topic' => $this->topic,
                    'source_id' => $sourceId !== '' ? $sourceId : null,
                    'reason' => $result['sync_reason'] ?? null,
                ],
                'dedupe_key' => sha1(json_encode([
                    'provider' => 'shopify',
                    'event_type' => 'identity_conflict_pending',
                    'store_key' => $storeKey,
                    'source_id' => $sourceId !== '' ? $sourceId : null,
                ])),
            ]);
        }

        if (in_array($status, ['skipped_store_context_missing', 'skipped_tenant_context_missing'], true)) {
            $healthEventRecorder->record([
                'provider' => 'shopify',
                'tenant_id' => $this->tenantId,
                'store_key' => $storeKey !== '' ? $storeKey : null,
                'event_type' => 'tenant_context_unresolved',
                'severity' => 'warning',
                'status' => 'open',
                'context' => [
                    'topic' => $this->topic,
                    'reason' => $status,
                ],
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(IntegrationHealthEventRecorder::class)->record([
            'provider' => 'shopify',
            'tenant_id' => $this->tenantId,
            'store_key' => trim((string) ($this->storeContext['key'] ?? '')) ?: null,
            'event_type' => 'customer_webhook_ingestion_failed',
            'severity' => 'error',
            'status' => 'open',
            'context' => [
                'topic' => $this->topic,
                'error_message' => $exception->getMessage(),
            ],
        ]);
    }
}
