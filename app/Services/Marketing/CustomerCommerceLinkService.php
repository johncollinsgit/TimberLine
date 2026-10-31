<?php

namespace App\Services\Marketing;

use App\Models\MarketingIdentityReview;
use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\SquareOrder;
use App\Models\SquarePayment;
use App\Models\User;
use App\Models\WebsiteCustomer;
use App\Models\WebsiteOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** An explicit identity association; never a source merge or consent grant. */
class CustomerCommerceLinkService
{
    public function __construct(private CanonicalMarketingProfileResolver $identities) {}

    public function canLink(?User $actor, int $tenantId): bool
    {
        if (! $actor || ! $actor->is_active || $tenantId <= 0) {
            return false;
        }
        if ($actor->role === 'platform_admin') {
            return true;
        }
        $membership = $actor->tenants()->whereKey($tenantId)->wherePivot('membership_active', true)->first();

        return in_array($membership?->pivot->role, ['owner', 'tenant_owner', 'admin'], true);
    }

    public function link(User $actor, int $tenantId, MarketingProfile $profile, string $type, string $sourceId, string $reason): MarketingProfileLink
    {
        abort_unless($this->canLink($actor, $tenantId), 403);
        $canonical = $this->identities->canonical($profile, $tenantId);
        abort_unless($canonical, 404);
        $reason = trim($reason);
        if (strlen($reason) < 8 || strlen($reason) > 1000) {
            throw ValidationException::withMessages(['reason' => 'Record how you verified this customer association (8–1000 characters).']);
        }

        return DB::transaction(function () use ($actor, $tenantId, $canonical, $type, $sourceId, $reason): MarketingProfileLink {
            [$model, $key] = match ($type) {
                'order' => [Order::class, 'id'],
                'square_order' => [SquareOrder::class, 'square_order_id'],
                'square_payment' => [SquarePayment::class, 'square_payment_id'],
                'website_customer' => [WebsiteCustomer::class, 'id'],
                'website_order' => [WebsiteOrder::class, 'id'],
                default => throw ValidationException::withMessages(['source_type' => 'Choose a supported customer or purchase record.']),
            };
            $source = $model::query()->forTenantId($tenantId)->where($key, $sourceId)->lockForUpdate()->firstOrFail();
            $sourceId = (string) $source->getAttribute($key);
            if ($source instanceof WebsiteOrder) {
                $source->assertNotPosSimulation();
            }
            MarketingProfile::query()->forTenantId($tenantId)->whereKey($canonical->id)->lockForUpdate()->firstOrFail();
            // Recheck the surviving identity after acquiring locks.
            abort_unless($this->identities->byId($tenantId, $canonical->id)?->id === $canonical->id, 409);
            $existing = MarketingProfileLink::query()->forTenantId($tenantId)
                ->where('source_type', $type)->where('source_id', $sourceId)->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                if ($existing->contains(fn ($link) => $this->identities->byId($tenantId, (int) $link->marketing_profile_id)?->id !== $canonical->id)) {
                    throw ValidationException::withMessages(['source_id' => 'This source is already associated with another customer. Review the identity conflict first.']);
                }

                return $existing->first();
            }
            $link = MarketingProfileLink::query()->create(['tenant_id' => $tenantId, 'marketing_profile_id' => $canonical->id,
                'source_type' => $type, 'source_id' => $sourceId, 'match_method' => 'owner_reviewed_commerce',
                'source_meta' => ['reviewed_by' => $actor->id, 'reviewed_at' => now()->toIso8601String(), 'purpose' => 'customer_purchase_history']]);
            MarketingIdentityReview::query()->create(['status' => 'resolved', 'proposed_marketing_profile_id' => $canonical->id,
                'source_type' => $type, 'source_id' => $sourceId, 'reviewed_by' => $actor->id, 'reviewed_at' => now(),
                'resolution_notes' => $reason, 'payload' => ['tenant_id' => $tenantId, 'action' => 'link_customer_commerce_source', 'link_id' => $link->id]]);

            return $link;
        });
    }
}
