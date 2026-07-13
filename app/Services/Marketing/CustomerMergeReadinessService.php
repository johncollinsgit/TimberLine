<?php

namespace App\Services\Marketing;

use App\Services\Shopify\ShopifyWebhookSubscriptionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class CustomerMergeReadinessService
{
    public function __construct(private readonly ShopifyWebhookSubscriptionService $webhooks) {}

    /** @return array{ready:bool,issues:array<int,string>} */
    public function inspect(array $store): array
    {
        $issues = [];
        $scopes = collect(preg_split('/[\s,]+/', (string) ($store['scopes'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $scope): string => strtolower(trim($scope)));
        foreach (['read_customer_merge', 'write_customer_merge'] as $scope) {
            if (! $scopes->contains($scope)) {
                $issues[] = "Retail must be reauthorized with {$scope}.";
            }
        }

        foreach (['customer_merge_operations', 'customer_merge_operation_members'] as $table) {
            if (! Schema::hasTable($table)) {
                $issues[] = 'The Everbranch customer merge database migration has not been applied.';
                break;
            }
        }

        if (app()->environment('production') && (string) config('queue.default') === 'sync') {
            $issues[] = 'A background queue must be running before customer merges can be enabled.';
        }

        if ((bool) config('customer_merge.require_webhook_verification') && ! app()->environment('testing')) {
            $storeKey = trim((string) ($store['key'] ?? 'unknown'));
            $verified = Cache::remember(
                'customer_merge:webhook_readiness:'.$storeKey,
                now()->addMinutes(5),
                fn (): array => $this->webhooks->verifyStore($store, false)
            );
            $mergeTopic = collect((array) ($verified['topics'] ?? []))->firstWhere('topic', 'customers/merge');
            if (! is_array($mergeTopic) || (string) ($mergeTopic['status'] ?? '') !== 'ok') {
                $issues[] = 'The Shopify customers/merge webhook is missing or could not be verified. Run the webhook verification repair before merging.';
            }
        }

        return ['ready' => $issues === [], 'issues' => array_values(array_unique($issues))];
    }

    public function assertReady(array $store): void
    {
        $readiness = $this->inspect($store);
        if (! $readiness['ready']) {
            throw new CustomerMergeException(implode(' ', $readiness['issues']), 'customer_merge_not_ready');
        }
    }
}
