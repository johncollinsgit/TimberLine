<?php

namespace App\Jobs;

use App\Models\CustomerMergeOperation;
use App\Models\MarketingProfile;
use App\Services\Marketing\CustomerMergeCoordinator;
use App\Services\Marketing\CustomerMergeService;
use App\Services\Shopify\ShopifyStores;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class ProcessShopifyCustomerMergeWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $tenantId, public string $storeKey, public array $payload) {}

    public function handle(CustomerMergeService $mergeService, CustomerMergeCoordinator $coordinator): void
    {
        $kept = trim((string) ($this->payload['admin_graphql_api_customer_kept_id'] ?? ''));
        $deleted = trim((string) ($this->payload['admin_graphql_api_customer_deleted_id'] ?? ''));
        $jobId = trim((string) ($this->payload['admin_graphql_api_job_id'] ?? ''));
        $idempotency = 'shopify-webhook:'.($jobId ?: hash('sha256', $kept.'|'.$deleted));
        $existing = CustomerMergeOperation::query()->where('tenant_id', $this->tenantId)
            ->where(fn ($query) => $query->where('idempotency_key', $idempotency)->when($jobId !== '', fn ($q) => $q->orWhere('shopify_job_id', $jobId)))
            ->first();
        if ($existing?->status === 'completed') {
            return;
        }

        if ($existing && count((array) data_get($existing->shopify_preview, 'sequence', [])) > 1) {
            $preview = (array) $existing->shopify_preview;
            $preview['webhook_events'] = [...((array) ($preview['webhook_events'] ?? [])), $this->payload];
            $failed = strtolower((string) ($this->payload['status'] ?? '')) === 'failed';
            $existing->forceFill([
                'shopify_preview' => $preview,
                'status' => $failed ? 'partial_failure' : $existing->status,
                'errors' => $failed ? (array) ($this->payload['errors'] ?? []) : $existing->errors,
            ])->save();

            if (! $failed) {
                $store = ShopifyStores::find($this->storeKey);
                if (is_array($store) && (int) ($store['tenant_id'] ?? 0) === $this->tenantId) {
                    $coordinator->advance($existing->fresh(), $store);
                } else {
                    $existing->forceFill([
                        'status' => 'reconciliation_required',
                        'errors' => [['code' => 'store_context_missing', 'message' => 'Shopify completed a merge, but Everbranch could not resolve the tenant store context.']],
                    ])->save();
                }
            }

            return;
        }

        if (strtolower((string) ($this->payload['status'] ?? '')) === 'failed') {
            $blocked = $existing ?: CustomerMergeOperation::query()->firstOrNew(
                ['tenant_id' => $this->tenantId, 'idempotency_key' => $idempotency]
            );
            $blocked->forceFill([
                'store_key' => $this->storeKey, 'source' => 'shopify_webhook', 'status' => 'blocked',
                'shopify_kept_customer_gid' => $kept ?: null, 'shopify_deleted_customer_gid' => $deleted ?: null,
                'shopify_job_id' => $jobId ?: null, 'errors' => (array) ($this->payload['errors'] ?? []),
                'before_state' => ['webhook_payload' => $this->payload],
            ])->save();

            return;
        }

        $numericIds = collect([$kept, $deleted])->map(function (string $gid): ?string {
            preg_match('/(\d+)$/', $gid, $matches);

            return $matches[1] ?? null;
        })->filter()->values();
        $rows = DB::table('customer_external_profiles')->where('provider', 'shopify')
            ->where('integration', 'shopify_customer')->where('store_key', $this->storeKey)
            ->where(fn ($query) => $query->whereIn('external_customer_gid', [$kept, $deleted])->orWhereIn('external_customer_id', $numericIds))
            ->get();
        $linkedProfileIds = DB::table('marketing_profile_links')->whereIn('source_type', ['shopify_customer', 'growave_customer'])
            ->whereIn('source_id', $numericIds)->pluck('marketing_profile_id');
        $profiles = MarketingProfile::query()->whereIn('id', $rows->pluck('marketing_profile_id')->merge($linkedProfileIds)->unique())
            ->where(fn ($query) => $query->where('tenant_id', $this->tenantId)->orWhereNull('tenant_id'))
            ->whereNull('merged_at')->get();
        preg_match('/(\d+)$/', $kept, $keptMatch);
        $keptNumeric = $keptMatch[1] ?? null;
        $keptProfileIds = $rows->filter(fn ($row): bool => $row->external_customer_gid === $kept || (string) $row->external_customer_id === (string) $keptNumeric)
            ->pluck('marketing_profile_id')
            ->merge(DB::table('marketing_profile_links')->whereIn('source_type', ['shopify_customer', 'growave_customer'])->where('source_id', $keptNumeric)->pluck('marketing_profile_id'))
            ->map('intval')->unique();
        $survivor = $profiles->first(fn (MarketingProfile $profile): bool => (int) $profile->tenant_id === $this->tenantId
            && $keptProfileIds->contains((int) $profile->id));
        if (! $survivor || $profiles->count() < 2) {
            CustomerMergeOperation::query()->updateOrCreate(
                ['tenant_id' => $this->tenantId, 'idempotency_key' => $idempotency],
                ['store_key' => $this->storeKey, 'source' => 'shopify_webhook', 'status' => 'blocked', 'errors' => [['message' => 'Shopify merged customers, but Everbranch could not safely resolve both tenant profiles.']], 'before_state' => ['webhook_payload' => $this->payload]]
            );

            return;
        }

        $operation = $existing ?: $mergeService->createOperation(
            $this->tenantId, $profiles->pluck('id')->all(), (int) $survivor->id, $this->storeKey, $idempotency, [],
            ['source' => 'shopify_webhook']
        );
        $operation->forceFill(['shopify_job_id' => $jobId ?: $operation->shopify_job_id])->save();
        $mergeService->apply($operation, $kept, $deleted);
    }
}
