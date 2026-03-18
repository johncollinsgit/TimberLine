<?php

namespace App\Jobs;

use App\Models\MarketingProfile;
use App\Services\Marketing\IntegrationHealthEventRecorder;
use App\Services\Marketing\ShopifyCustomerProvisioningService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProvisionShopifyCustomerForMarketingProfile implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @var int
     */
    public $tries = 3;

    /**
     * @var array<int,int>
     */
    public $backoff = [30, 120, 300];

    public function __construct(
        public int $marketingProfileId,
        public ?string $storeKey = null,
        public ?int $tenantId = null,
        public ?string $trigger = null
    ) {
    }

    public function handle(
        ShopifyCustomerProvisioningService $provisioningService,
        IntegrationHealthEventRecorder $healthEventRecorder
    ): void
    {
        $profile = MarketingProfile::query()->find($this->marketingProfileId);
        if (! $profile) {
            return;
        }

        $result = $provisioningService->provisionForProfile($profile, [
            'store_key' => $this->storeKey,
            'tenant_id' => $this->tenantId,
            'trigger' => $this->trigger,
        ]);

        Log::info('shopify customer provisioning processed', [
            'marketing_profile_id' => $this->marketingProfileId,
            'store_key' => $this->storeKey,
            'tenant_id' => $this->tenantId,
            'trigger' => $this->trigger,
            'status' => $result['status'] ?? null,
            'shopify_customer_id' => $result['shopify_customer_id'] ?? null,
            'source_id' => $result['source_id'] ?? null,
        ]);

        $status = strtolower(trim((string) ($result['status'] ?? '')));
        if (in_array($status, [
            'created_remote_customer',
            'linked_existing_remote_customer',
            'linked_existing_profile_link',
            'linked_existing_external_profile',
        ], true)) {
            $healthEventRecorder->resolve([
                'provider' => 'shopify',
                'tenant_id' => $this->tenantId ?: ($profile->tenant_id ? (int) $profile->tenant_id : null),
                'store_key' => $this->storeKey,
                'event_type' => 'customer_provisioning_failed',
            ]);
        }

        if (in_array($status, ['skipped_store_context_missing', 'skipped_tenant_context_missing'], true)) {
            $healthEventRecorder->record([
                'provider' => 'shopify',
                'tenant_id' => $this->tenantId ?: ($profile->tenant_id ? (int) $profile->tenant_id : null),
                'store_key' => $this->storeKey,
                'event_type' => 'tenant_context_unresolved',
                'severity' => 'warning',
                'status' => 'open',
                'related_model_type' => MarketingProfile::class,
                'related_model_id' => (int) $profile->id,
                'context' => [
                    'job' => self::class,
                    'reason' => $status,
                    'trigger' => $this->trigger,
                ],
            ]);
        }
    }

    public function failed(Throwable $exception): void
    {
        app(IntegrationHealthEventRecorder::class)->record([
            'provider' => 'shopify',
            'tenant_id' => $this->tenantId,
            'store_key' => $this->storeKey,
            'event_type' => 'customer_provisioning_failed',
            'severity' => 'error',
            'status' => 'open',
            'related_model_type' => MarketingProfile::class,
            'related_model_id' => $this->marketingProfileId,
            'context' => [
                'job' => self::class,
                'trigger' => $this->trigger,
                'error_message' => $exception->getMessage(),
            ],
        ]);
    }
}
