<?php

namespace App\Services\Marketing;

use App\Models\CustomerExternalProfile;
use App\Models\MarketingImportRun;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareCustomer;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\User;
use App\Services\PaymentsCore\Contract;

/** Restore existing source associations only when both original records prove the same tenant. */
final class CustomerSourceOwnershipReconciliation
{
    public function batch(int $tenant, User $actor, int $after = 0, int $limit = 500, bool $apply = false, ?string $expectedFingerprint = null): array
    {
        app(SquareCustomerIdentityService::class)->authorize($tenant, $actor);
        $limit = max(1, min(1000, $limit));
        $rows = MarketingProfileLink::whereNull('tenant_id')->where('id', '>', $after)
            ->whereIn('marketing_profile_id', MarketingProfile::forTenantId($tenant)->select('id'))->orderBy('id')->limit($limit + 1)->get();
        $fingerprint = Contract::hash($rows->take($limit)->map(fn ($row) => $row->getAttributes())->all());
        abort_if($apply && $expectedFingerprint !== null && ! hash_equals($fingerprint, $expectedFingerprint), 409, 'The retained links changed. Review the current batch before restoring it.');
        $result = ['scanned' => 0, 'restored' => 0, 'already_represented' => 0, 'review_required' => 0, 'issues' => [],
            'after_id' => $after, 'has_more' => $rows->count() > $limit, 'mode' => $apply ? 'apply' : 'preview', 'fingerprint' => $fingerprint];
        foreach ($rows->take($limit) as $row) {
            $work = function () use ($tenant, $actor, $row, $apply): string {
                if ($apply) {
                    app(SquareCustomerIdentityService::class)->authorize($tenant, User::findOrFail($actor->id));
                }
                $link = MarketingProfileLink::whereKey($row->id)->when($apply, fn ($q) => $q->lockForUpdate())->firstOrFail();
                if ($link->tenant_id !== null) {
                    return 'already_represented';
                }
                $canonical = app(CanonicalMarketingProfileResolver::class)->byId($tenant, $link->marketing_profile_id);
                if (! $canonical || $canonical->archived_at || ! $this->sourceOwned($tenant, $link)) {
                    return 'original_ownership_unproven';
                }
                $existing = MarketingProfileLink::forTenantId($tenant)->where('source_type', $link->source_type)->where('source_id', $link->source_id)->get();
                if ($existing->isNotEmpty()) {
                    return $existing->every(fn ($other) => app(CanonicalMarketingProfileResolver::class)->byId($tenant, $other->marketing_profile_id)?->id === $canonical->id)
                        ? 'already_represented' : 'conflicting_original_associations';
                }
                // Multiple old claims of the same source must agree before any claim is admitted.
                $claims = MarketingProfileLink::whereNull('tenant_id')->where('source_type', $link->source_type)->where('source_id', $link->source_id)->get();
                if ($claims->contains(fn ($claim) => app(CanonicalMarketingProfileResolver::class)->byId($tenant, $claim->marketing_profile_id)?->id !== $canonical->id)) {
                    return 'conflicting_original_associations';
                }
                if ($apply) {
                    $link->tenant_id = $tenant;
                    $link->source_meta = [...($link->source_meta ?? []), '_ownership_reconciliation' => ['version' => 1, 'reviewed_by' => $actor->id,
                        'reviewed_at' => now()->toIso8601String(), 'basis' => 'existing_profile_and_original_source_tenant', 'original_link_id' => $link->id]];
                    $link->save();
                }

                return 'restored';
            };
            $status = $apply ? app(CustomerAccountFence::class)->forTenant($tenant, $work) : $work();
            $result['scanned']++;
            $result['after_id'] = $row->id;
            if (array_key_exists($status, $result)) {
                $result[$status]++;
            } else {
                $result['review_required']++;
                $result['issues'][] = ['link_id' => $row->id, 'source_type' => $row->source_type, 'reason' => $status];
            }
        }
        if ($apply) {
            app(CustomerAccountFence::class)->forTenant($tenant, function () use ($tenant, $actor, $result): void {
                // Match source refresh authorization before the audit's user/tenant foreign keys.
                app(SquareCustomerIdentityService::class)->authorize($tenant, User::findOrFail($actor->id));
                MarketingImportRun::create(['tenant_id' => $tenant, 'type' => 'customer_source_ownership', 'status' => $result['review_required'] ? 'partial' : 'completed',
                    'source_label' => 'existing_original_source_links', 'created_by' => $actor->id, 'started_at' => now(), 'finished_at' => now(), 'summary' => $result]);
            });
        }

        return $result;
    }

    private function sourceOwned(int $tenant, MarketingProfileLink $link): bool
    {
        [$model, $key] = match ($link->source_type) {
            'square_customer' => [SquareCustomer::class, 'square_customer_id'], 'square_order' => [SquareOrder::class, 'square_order_id'],
            'square_payment' => [SquarePayment::class, 'square_payment_id'], 'order' => [Order::class, 'id'],
            default => [null, null],
        };
        if ($model) {
            return $model::forTenantId($tenant)->where($key, $link->source_id)->exists();
        }
        if ($link->source_type === 'shopify_order' && preg_match('/^([a-z0-9_-]+):([0-9]+)$/D', $link->source_id, $match)) {
            return Order::forTenantId($tenant)->where('shopify_store_key', $match[1])->where('shopify_order_id', $match[2])->exists();
        }
        if (in_array($link->source_type, ['shopify_customer', 'growave_customer'], true) && ($id = $link->source_meta['external_profile_id'] ?? null)) {
            $external = CustomerExternalProfile::forTenantId($tenant)->find($id);
            if (! $external || ! in_array($external->integration, $link->source_type === 'growave_customer' ? ['growave'] : ['growave', 'shopify_customer'], true)) {
                return false;
            }
            $source = trim((string) ($external->external_customer_id ?: $external->external_customer_gid));
            $source = $source !== '' ? ($external->store_key ? $external->store_key.':'.$source : $source) : 'external-profile:'.$external->id;

            return hash_equals($source, $link->source_id);
        }

        return false;
    }
}
