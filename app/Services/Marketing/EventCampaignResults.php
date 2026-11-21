<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaign;
use App\Models\MarketingCampaignConversion;
use App\Models\MarketingImportRun;
use App\Models\MarketingMessageDelivery;
use App\Models\MarketingMessageEngagementEvent;
use App\Models\SquareOrder;
use App\Models\TeamOperationRecord;
use App\Services\Team\Studio\EventSalesAccess;
use Carbon\CarbonImmutable;

/** Shared campaign reads; original deliveries, confirmed purchases and reviewed occurrences remain authoritative. */
class EventCampaignResults
{
    public function eventForOrder(int $tenant, SquareOrder $order): ?TeamOperationRecord
    {
        $events = TeamOperationRecord::where('tenant_id', $tenant)->where('kind', 'event')->where('status', '!=', 'withdrawn')
            ->where('starts_on', '<=', $order->closed_at->copy()->addDay()->toDateString())
            ->where('ends_on', '>=', $order->closed_at->copy()->subDay()->toDateString())->get()->keyBy('id');
        $matches = [];
        foreach (TeamOperationRecord::where('tenant_id', $tenant)->where('kind', 'event_link')->where('status', 'accepted')->get() as $mapping) {
            $data = $mapping->data;
            $event = $events->get($data['occurrence_id'] ?? '');
            if (! $event || ! app(EventSalesAccess::class)->exported($event, $mapping)) {
                continue;
            }
            $zone = $data['timezone'] ?? 'UTC';
            $date = $order->closed_at->copy()->setTimezone($zone)->toDateString();
            foreach ($data['square_scopes'] ?? [] as $scope) {
                $binding = MarketingImportRun::forTenantId($tenant)->find($scope['orders_run_id'] ?? 0)?->summary['source_binding'] ?? [];
                if (($scope['location_id'] ?? '') === $order->location_id && $date >= ($scope['starts_on'] ?? '9999') && $date <= ($scope['ends_on'] ?? '')
                    && ($binding['merchant_id'] ?? null) === data_get($order->raw_payload, '_source_binding.merchant_id')
                    && ($binding['environment'] ?? null) === data_get($order->raw_payload, '_source_binding.environment')) {
                    $matches[$event->id] = $event;
                }
            }
            $explicit = $event->data['_teams_enrichment']['square_orders'] ?? [];
            if (isset($explicit['reviewed_by'], $explicit['reviewed_at']) && in_array($order->square_order_id, $explicit['ids'] ?? [], true)) {
                $matches[$event->id] = $event;
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    public function campaignIds(int $tenant, string $event): array
    {
        $ids = TeamOperationRecord::where('tenant_id', $tenant)->where('kind', 'campaign_link')->where('status', 'accepted')->get()
            ->filter(fn ($r) => ($r->data['event_id'] ?? null) === $event)->map(fn ($r) => (int) ($r->data['campaign_id'] ?? 0))->all();

        return MarketingCampaign::forTenantId($tenant)->where(fn ($q) => $q->whereIn('id', $ids)->orWhere('target_snapshot->team_show_text->event_id', $event))->pluck('id')->all();
    }

    public function forEvent(int $tenant, string $event): array
    {
        return ['campaigns' => MarketingCampaign::forTenantId($tenant)->whereIn('id', $this->campaignIds($tenant, $event))->get()
            ->map(fn ($campaign) => $this->forCampaign($campaign))->all(), 'basis' => 'Observed campaign activity and modeled last text attribution. Causal lift is unknown.'];
    }

    public function forCampaign(MarketingCampaign $campaign): array
    {
        $tenant = (int) $campaign->tenant_id;
        $tenantSlug = \App\Models\Tenant::whereKey($tenant)->value('slug');
        $deliveries = MarketingMessageDelivery::forTenantId($tenant)->where('campaign_id', $campaign->id)->orderByDesc('id')->get();
        $identities = app(CanonicalMarketingProfileResolver::class);
        $latest = $deliveries->groupBy(fn ($d) => $identities->byId($tenant, (int) $d->marketing_profile_id)?->id ?? 'unresolved:'.$d->marketing_profile_id)->map->first();
        $accepted = $latest->whereIn('send_status', ['sent', 'delivered']);
        $conversions = MarketingCampaignConversion::where('campaign_id', $campaign->id)->where('attribution_type', 'last_touch')
            ->whereHas('profile', fn ($q) => $q->where('tenant_id', $tenant))->get()->unique(fn ($c) => $c->source_type.':'.$c->source_id);
        $known = $conversions->filter(fn ($c) => $c->order_total !== null);
        $net = (int) round($known->sum('order_total') * 100);
        $buyers = $conversions->filter(fn ($c) => $c->order_total > 0)->map(fn ($c) => $identities->byId($tenant, (int) $c->marketing_profile_id)?->id)->filter()->unique()->count();
        $clicked = MarketingMessageEngagementEvent::forTenantId($tenant)->whereIn('marketing_message_delivery_id', $deliveries->modelKeys())
            ->whereIn('event_type', ['click', 'clicked', 'link_clicked'])->distinct()->count('marketing_profile_id');
        $reward = 0;
        $rows = [];
        foreach ($conversions as $conversion) {
            $profile = $identities->byId($tenant, (int) $conversion->marketing_profile_id);
            if (! $profile || $profile->archived_at) {
                continue;
            }
            $effects = $conversion->attribution_snapshot['purchase_effects'] ?? [];
            if ($conversion->source_type === 'square_order') {
                $link = \App\Models\MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->where('source_id', $conversion->source_id)
                    ->where('marketing_profile_id', $profile->id)->first();
                $reward += (int) data_get($link?->source_meta, 'purchase_effects.reward_posted_minor', 0);
            }
            $rows[] = ['customer_id' => $profile->id, 'customer_name' => trim($profile->first_name.' '.$profile->last_name),
                'customer_url' => route('customers.profile', ['profile' => $profile->id, 'tenant' => $tenantSlug], false),
                'source_type' => $conversion->source_type, 'source_id' => $conversion->source_id, 'occurred_at' => $conversion->converted_at?->toIso8601String(),
                'net_sales_minor' => $conversion->order_total === null ? null : (int) round($conversion->order_total * 100),
                'basis' => $effects['basis'] ?? 'recorded_last_touch', 'event_record_id' => $effects['event_record_id'] ?? null];
        }
        $responses = \App\Models\MessagingConversationMessage::forTenantId($tenant)->whereIn('marketing_message_delivery_id', $deliveries->modelKeys())->where('direction', 'inbound')->get();

        return ['id' => $campaign->id, 'name' => $campaign->name, 'url' => route('marketing.campaigns.show', ['campaign' => $campaign->id, 'tenant' => $tenantSlug], false),
            'activity_status' => $deliveries->contains(fn ($d) => $d->sent_at !== null) ? 'Messages sent' : ucfirst($campaign->status),
            'audience_count' => $campaign->recipients()->count(), 'accepted_sends' => $accepted->count(),
            'delivered' => $latest->where('send_status', 'delivered')->count(), 'sent_only' => $latest->where('send_status', 'sent')->count(),
            'failed' => $latest->whereIn('send_status', ['failed', 'undelivered', 'canceled'])->count(), 'clicked_customers' => $clicked,
            'replies' => $responses->where('message_type', 'normal')->unique('marketing_profile_id')->count(),
            'opt_outs' => $responses->whereIn('message_type', ['opt_out', 'stop', 'unsubscribe'])->unique('marketing_profile_id')->count(),
            'attributed_purchases' => $conversions->count(), 'attributed_buyers' => $buyers, 'known_net_sales_minor' => $net,
            'net_sales_minor' => $known->count() === $conversions->count() ? $net : null, 'reward_minor' => $reward,
            'modeled_conversion_rate' => $accepted->count() ? round($buyers / $accepted->count() * 100, 2) : null,
            'purchases' => $rows, 'as_of' => CarbonImmutable::now()->toIso8601String(), 'causal_credit' => false];
    }
}
