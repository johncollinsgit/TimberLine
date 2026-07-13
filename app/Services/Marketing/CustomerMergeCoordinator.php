<?php

namespace App\Services\Marketing;

use App\Models\CustomerMergeOperation;
use App\Services\Shopify\ShopifyCustomerMergeApi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class CustomerMergeCoordinator
{
    public function __construct(
        private readonly CustomerMergeService $mergeService,
        private readonly ShopifyCustomerMergeApi $shopifyApi,
    ) {}

    /** @return array<string,mixed> */
    public function prepare(
        int $tenantId,
        string $storeKey,
        array $store,
        array $profileIds,
        int $survivorProfileId,
        array $fieldSources,
        array $shopifyOverrides,
        string $idempotencyKey,
        array $context = [],
        array $rewardResolution = []
    ): array {
        $operation = $this->mergeService->createOperation(
            $tenantId, $profileIds, $survivorProfileId, $storeKey, $idempotencyKey, $fieldSources, $context
        );
        $gids = $operation->members->pluck('shopify_customer_gid')->filter()->unique()->values();
        $operation->forceFill(['reward_resolution' => array_merge((array) $operation->reward_resolution, $rewardResolution)])->save();
        $sequence = [];
        $previews = [];

        if ($gids->count() > 1) {
            $anchor = (string) $gids->first();
            foreach ($gids->slice(1) as $other) {
                try {
                    $preview = $this->shopifyApi->preview(
                        $store,
                        $anchor,
                        (string) $other,
                        $this->overridesForPair($shopifyOverrides, [$anchor, (string) $other])
                    );
                } catch (CustomerMergeException $exception) {
                    $operation->forceFill([
                        'status' => 'blocked',
                        'errors' => [['code' => $exception->publicCode(), 'message' => $exception->getMessage()]],
                    ])->save();
                    throw $exception;
                }
                $sequence[] = ['customer_one_id' => $anchor, 'customer_two_id' => (string) $other, 'status' => 'pending'];
                $previews[] = $preview;
                $anchor = (string) ($preview['resultingCustomerId'] ?? $anchor);
            }
        }

        $blockers = collect($previews)->flatMap(fn (array $preview): array => [
            ...(array) ($preview['customerMergeErrors'] ?? []),
            ...($this->blockingFieldErrors((array) ($preview['blockingFields'] ?? []))),
        ])->values()->all();

        $operation->forceFill([
            'status' => $blockers === [] ? 'previewed' : 'blocked',
            'shopify_preview' => [
                'previews' => $previews,
                'sequence' => $sequence,
                'current_index' => 0,
                'override_fields' => $shopifyOverrides,
                'completed_deleted_gids' => [],
                'consent_result' => 'Shopify controls the resulting customer and consent result.',
            ],
            'errors' => $blockers ?: null,
        ])->save();

        return ['operation' => $operation->fresh('members'), 'blockers' => $blockers, 'everbranch_only' => $gids->count() <= 1];
    }

    public function execute(CustomerMergeOperation $operation, array $store, int $approverId): CustomerMergeOperation
    {
        $locked = DB::transaction(function () use ($operation, $approverId): CustomerMergeOperation {
            $locked = CustomerMergeOperation::query()->lockForUpdate()->findOrFail($operation->id);
            if ($locked->status === 'completed') {
                return $locked;
            }
            if ($locked->status === 'blocked') {
                throw new CustomerMergeException('Shopify reported blockers. Resolve them and preview again.', 'shopify_merge_blocked');
            }
            $locked->forceFill(['approved_by' => $approverId, 'status' => 'processing', 'started_at' => $locked->started_at ?: now()])->save();

            return $locked;
        });

        return $this->advance($locked, $store);
    }

    public function advance(CustomerMergeOperation $operation, array $store): CustomerMergeOperation
    {
        try {
            return $this->advanceUnsafe($operation, $store);
        } catch (CustomerMergeException $exception) {
            $fresh = $operation->fresh();
            $completed = collect((array) data_get($fresh->shopify_preview, 'sequence', []))->contains('status', 'completed');
            $fresh->forceFill([
                'status' => $completed ? 'partial_failure' : 'blocked',
                'errors' => [['code' => $exception->publicCode(), 'message' => $exception->getMessage()]],
            ])->save();

            return $fresh->fresh();
        } catch (Throwable $exception) {
            Log::error('Everbranch customer merge reconciliation failed.', [
                'operation_id' => (int) $operation->id,
                'tenant_id' => (int) $operation->tenant_id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            $fresh = $operation->fresh();
            $fresh->forceFill([
                'status' => 'reconciliation_required',
                'errors' => [[
                    'code' => 'everbranch_reconciliation_failed',
                    'message' => 'Shopify may have completed this merge, but Everbranch still needs to reconcile the customer records. It is safe to retry this operation.',
                ]],
            ])->save();

            return $fresh->fresh();
        }
    }

    private function advanceUnsafe(CustomerMergeOperation $operation, array $store): CustomerMergeOperation
    {
        $state = (array) $operation->shopify_preview;
        $sequence = (array) ($state['sequence'] ?? []);
        if ($sequence === []) {
            return $this->mergeService->apply($operation);
        }

        $index = (int) ($state['current_index'] ?? 0);
        if ($operation->shopify_job_id) {
            $job = $this->shopifyApi->jobStatus($store, (string) $operation->shopify_job_id);
            $status = strtoupper((string) ($job['status'] ?? 'IN_PROGRESS'));
            if ($status === 'IN_PROGRESS') {
                return $operation->fresh();
            }
            if ($status !== 'COMPLETED' || (array) ($job['customerMergeErrors'] ?? []) !== []) {
                return $this->markPartialFailure($operation, (array) ($job['customerMergeErrors'] ?? [['message' => 'Shopify merge job failed.']]));
            }
            $resulting = (string) ($job['resultingCustomerId'] ?? '');
            $state = $this->completeSequenceMember($state, $index, $resulting);
            $index++;
            $operation->forceFill(['shopify_job_id' => null, 'shopify_kept_customer_gid' => $resulting, 'shopify_preview' => $state])->save();
        }

        while ($index < count($sequence)) {
            $pair = (array) $state['sequence'][$index];
            if ($index > 0) {
                $pair['customer_one_id'] = (string) ($operation->shopify_kept_customer_gid ?: $pair['customer_one_id']);
                $state['sequence'][$index] = $pair;
            }
            $result = $this->shopifyApi->merge(
                $store,
                (string) $pair['customer_one_id'],
                (string) $pair['customer_two_id'],
                $this->overridesForPair((array) ($state['override_fields'] ?? []), [(string) $pair['customer_one_id'], (string) $pair['customer_two_id']])
            );
            if ((array) ($result['userErrors'] ?? []) !== []) {
                return $this->markPartialFailure($operation, (array) $result['userErrors']);
            }
            $resulting = (string) ($result['resultingCustomerId'] ?? '');
            $job = (array) ($result['job'] ?? []);
            if (! (bool) ($job['done'] ?? false)) {
                $operation->forceFill([
                    'shopify_job_id' => (string) ($job['id'] ?? ''),
                    'shopify_kept_customer_gid' => $resulting ?: null,
                    'shopify_preview' => array_merge($state, ['current_index' => $index]),
                ])->save();

                return $operation->fresh();
            }
            $state = $this->completeSequenceMember($state, $index, $resulting);
            $operation->forceFill(['shopify_kept_customer_gid' => $resulting, 'shopify_preview' => $state])->save();
            $index++;
        }

        return $this->mergeService->apply($operation->fresh(), (string) $operation->shopify_kept_customer_gid);
    }

    private function completeSequenceMember(array $state, int $index, string $resulting): array
    {
        $pair = (array) $state['sequence'][$index];
        $deleted = $resulting === (string) $pair['customer_one_id'] ? (string) $pair['customer_two_id'] : (string) $pair['customer_one_id'];
        $state['sequence'][$index]['status'] = 'completed';
        $state['sequence'][$index]['resulting_customer_id'] = $resulting;
        $state['current_index'] = $index + 1;
        $state['completed_deleted_gids'] = array_values(array_unique([...((array) ($state['completed_deleted_gids'] ?? [])), $deleted]));

        return $state;
    }

    private function markPartialFailure(CustomerMergeOperation $operation, array $errors): CustomerMergeOperation
    {
        $completed = collect((array) data_get($operation->shopify_preview, 'sequence', []))->contains('status', 'completed');
        $operation->forceFill(['status' => $completed ? 'partial_failure' : 'blocked', 'errors' => $errors])->save();

        return $operation->fresh();
    }

    private function blockingFieldErrors(array $fields): array
    {
        $errors = [];
        if (trim((string) ($fields['note'] ?? '')) !== '') {
            $errors[] = ['field' => 'note', 'message' => 'The merged Shopify note exceeds its limit.'];
        }
        if ((array) ($fields['tags'] ?? []) !== []) {
            $errors[] = ['field' => 'tags', 'message' => 'The merged Shopify tags exceed their limit.'];
        }

        return $errors;
    }

    private function overridesForPair(array $overrides, array $pair): array
    {
        return collect($overrides)->filter(function ($value, string $field) use ($pair): bool {
            if (in_array($field, ['note', 'tags'], true)) {
                return true;
            }

            return in_array((string) $value, $pair, true);
        })->all();
    }
}
