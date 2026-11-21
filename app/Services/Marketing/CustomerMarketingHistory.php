<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignConversion;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\MarketingProfile;

class CustomerMarketingHistory
{
    public function allowed(int $tenant, ?\App\Models\User $actor): bool
    {
        if (! app(CustomerProfileAccess::class)->allows($actor, $tenant)
            || ! app(\App\Services\Tenancy\TenantModuleAccessResolver::class)->canAccess($tenant, 'messaging')) {
            return false;
        }
        $membership = $actor->tenants()->whereKey($tenant)->wherePivot('membership_active', true)->first();

        return $membership && (in_array($actor->role, ['admin', 'platform_admin', 'manager', 'marketing_manager'], true)
            || in_array($membership->pivot->role, ['owner', 'tenant_owner', 'admin', 'manager', 'marketing_manager'], true));
    }

    public function aliasIds(int $tenant, int $profile): array
    {
        $root = app(CanonicalMarketingProfileResolver::class)->byId($tenant, $profile);
        abort_unless($root && ! $root->archived_at, 404);
        $ids = [$root->id];
        $frontier = $ids;
        for ($depth = 0; $frontier && $depth < 32; $depth++) {
            $frontier = MarketingProfile::forTenantId($tenant)->whereIn('merged_into_profile_id', $frontier)->whereNotIn('id', $ids)->limit(1001)->pluck('id')->all();
            $ids = [...$ids, ...$frontier];
            abort_if(count($ids) > 1000, 409);
        }
        abort_if($frontier, 409);

        return $ids;
    }

    /** Callers must authorize private marketing activity separately from Core customer access. */
    public function read(int $tenant, int $profile, int $page = 1): array
    {
        $ids = $this->aliasIds($tenant, $profile);
        $deliveries = MarketingMessageDelivery::forTenantId($tenant)->whereIn('marketing_profile_id', $ids)->with('campaign:id,name')
            ->orderByDesc('sent_at')->orderByDesc('id')->offset(($page - 1) * 25)->limit(26)->get();
        $shown = $deliveries->take(25);
        $clicks = MarketingMessageEngagementEvent::forTenantId($tenant)->whereIn('marketing_message_delivery_id', $shown->modelKeys())
            ->whereIn('event_type', ['click', 'clicked', 'link_clicked'])->get()->groupBy('marketing_message_delivery_id');
        $conversions = MarketingCampaignConversion::whereIn('marketing_profile_id', $ids)->whereHas('campaign', fn ($q) => $q->where('tenant_id', $tenant))
            ->where('attribution_type', '!=', 'superseded')->orderByDesc('converted_at')->limit(100)->get();

        return ['page' => $page, 'has_more' => $deliveries->count() > 25, 'messages' => $shown->map(fn ($d) => ['id' => $d->id,
            'campaign_id' => $d->campaign_id, 'campaign_name' => $d->campaign?->name ?? $d->message_subject,
            'channel' => $d->channel, 'message' => $d->rendered_message, 'status' => $d->send_status,
            'sent_at' => $d->sent_at?->toIso8601String(), 'delivered_at' => $d->delivered_at?->toIso8601String(),
            'recorded_clicks' => $clicks->get($d->id, collect())->count()])->values()->all(),
            'conversions' => $conversions->map(fn ($c) => ['campaign_id' => $c->campaign_id, 'source_type' => $c->source_type, 'source_id' => $c->source_id,
                'occurred_at' => $c->converted_at?->toIso8601String(), 'net_sales_minor' => $c->order_total === null ? null : (int) round($c->order_total * 100),
                'basis' => data_get($c->attribution_snapshot, 'purchase_effects.basis', $c->attribution_type)])->all()];
    }
}
