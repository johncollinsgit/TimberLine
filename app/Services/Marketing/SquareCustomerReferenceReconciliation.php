<?php

namespace App\Services\Marketing;

use App\Models\TenantMarketingSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Reconcile original provider references; contact or name candidates remain identity reviews. */
class SquareCustomerReferenceReconciliation
{
    public const CHECKPOINT_KEY = 'square_customer_reference_checkpoint';

    public function scheduled(int $tenant, User $actor, string $version, string $requestId, int $limit = 500): array
    {
        app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        app(SquareSourceRefresh::class)->assertPolicyCurrent($tenant, $version);
        $checkpoint = TenantMarketingSetting::forTenantId($tenant)->where('key', self::CHECKPOINT_KEY)->first()?->value ?? [];
        if (($checkpoint['policy_version'] ?? null) === $version && ($checkpoint['request_id'] ?? null) === $requestId) {
            return ($checkpoint['result'] ?? []) + ['replayed' => true];
        }
        $after = ($checkpoint['policy_version'] ?? null) === $version ? ($checkpoint['after'] ?? null) : null;
        $result = $this->batch($tenant, $actor, $version, $requestId, $after, $limit, true);
        $wrapped = ['status' => $result['has_more'] ? 'partial' : 'completed', 'run_id' => collect($result['run_ids'])->last(), 'summary' => $result];
        app(CustomerAccountFence::class)->forTenant($tenant, function () use ($tenant, $actor, $version, $requestId, $result, $wrapped): void {
            app(SquareCustomerIdentityService::class)->authorize($tenant, $actor->fresh());
            app(SquareSourceRefresh::class)->assertPolicyCurrent($tenant, $version);
            TenantMarketingSetting::updateOrCreate(['tenant_id' => $tenant, 'key' => self::CHECKPOINT_KEY], ['value' => [
                'policy_version' => $version, 'request_id' => $requestId, 'after' => $result['has_more'] ? $result['after'] : null,
                'checked_at' => now()->toIso8601String(), 'result' => $wrapped]]);
        });

        return $wrapped;
    }

    public function batch(int $tenant, User $actor, string $version, string $requestId, ?string $after = null, int $limit = 500, bool $apply = false): array
    {
        app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        abort_unless(Str::isUuid($requestId) && $limit >= 1 && $limit <= 500, 422);
        abort_unless($after === null || (strlen($after) <= 192 && ! preg_match('/[\x00-\x20\x7F]/', $after)), 422);
        $policy = app(SquareSourceRefresh::class)->assertPolicyCurrent($tenant, $version);
        $payments = DB::table('square_payments')->where('tenant_id', $tenant)->whereNotNull('square_customer_id')->where('square_customer_id', '!=', '')->select('square_customer_id');
        $orders = DB::table('square_orders')->where('tenant_id', $tenant)->whereNotNull('square_customer_id')->where('square_customer_id', '!=', '')->select('square_customer_id');
        $ids = DB::query()->fromSub($payments->union($orders), 'original_customers')
            ->when($after, fn ($q) => $q->where('square_customer_id', '>', $after))
            ->orderBy('square_customer_id')->limit($limit + 1)->pluck('square_customer_id');
        $hasMore = $ids->count() > $limit;
        $ids = $ids->take($limit)->values();
        $result = ['processed' => 0, 'redirects' => 0, 'reviews_created' => 0, 'links_created' => 0, 'run_ids' => [],
            'has_more' => $hasMore, 'after' => $after, 'mode' => $apply ? 'import' : 'preview', 'messages_sent' => 0, 'rewards_earned' => 0];
        foreach ($ids->chunk(100) as $chunk) {
            $key = hash('sha256', $requestId.'|'.json_encode($chunk->values()->all(), JSON_THROW_ON_ERROR));
            $request = substr($key, 0, 8).'-'.substr($key, 8, 4).'-4'.substr($key, 13, 3).'-a'.substr($key, 17, 3).'-'.substr($key, 20, 12);
            $run = app(SquareMarketingSyncService::class)->reconcileCustomerReferences(['tenant_id' => $tenant, 'manual_refresh' => true,
                'created_by' => $actor->id, 'request_id' => $request, 'connection_id' => $policy['binding']['connection_id'],
                'source_refresh_policy_version' => $version, 'customer_ids' => $chunk->values()->all(),
                'create_unmatched' => $policy['create_unmatched'], 'dry_run' => ! $apply]);
            $result['run_ids'][] = $run['run_id'];
            abort_unless(($run['status'] ?? '') === 'completed' && empty($run['summary']['errors']), 409, 'The original source batch needs review before continuing. Its audit and earlier source records are retained.');
            $result['processed'] += (int) ($run['summary']['processed'] ?? 0);
            $result['redirects'] += count($run['summary']['provider_customer_redirects'] ?? []);
            $result['reviews_created'] += (int) ($run['summary']['reviews_created'] ?? 0);
            $result['links_created'] += (int) ($run['summary']['links_created'] ?? 0);
            $result['after'] = $chunk->last();
        }

        return $result;
    }
}
