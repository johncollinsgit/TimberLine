<?php

declare(strict_types=1);

namespace App\Services\Pos;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Inventory\StockLedger;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerCommerceSnapshotService;

/** First recorded event acquisition followed by explicitly linked commerce. Never inferred from payment cards. */
final class PosEventAttribution
{
    public function __construct(private PosStore $pos, private StockLedger $stock, private CanonicalMarketingProfileResolver $identities) {}

    public function capture(array $scope, MarketingProfile $profile, string $request, bool $newRecord = false): void
    {
        if ((int) $profile->tenant_id !== (int) $scope['tenant_id']) {
            throw new PosException('scope_invalid', 403);
        }
        if (MarketingProfileLink::query()->forTenantId($scope['tenant_id'])->where('marketing_profile_id', $profile->id)
            ->where('source_type', 'pos_event_capture')->exists()) {
            return;
        }
        $settings = $this->pos->decrypt($scope['register']->configuration);
        if (! isset($settings['inventory_pool_id'])) {
            return;
        }
        $pool = $this->stock->rows('pools', $scope['tenant_id'])->where('id', $settings['inventory_pool_id'])->where('active', true)->where('kind', 'show')->first();
        if (! $pool || ! preg_match('/^event:([1-9][0-9]*)$/D', (string) $pool->event_key, $match)) {
            return;
        }
        if (! $this->stock->rows('bindings', $scope['tenant_id'])->where('channel', 'pos')
            ->where('channel_key', $this->pos->runtime().':'.$scope['register']->id)->where('pool_id', $pool->id)->where('active', true)->exists()) {
            return;
        }
        $link = $this->stock->rows('event_links', $scope['tenant_id'])->where('event_id', (int) $match[1])->whereNotNull('reviewed_by')->first();
        $event = $link ? json_decode($link->metadata, true, 64, JSON_THROW_ON_ERROR) : null;
        $today = now()->timezone($event['timezone'] ?? ($scope['register']->timezone ?? config('app.timezone')))->toDateString();
        if (! $event || ($event['start_on'] ?? '9999') > $today || ($event['end_on'] ?? '') < $today) {
            return;
        }
        MarketingProfileLink::query()->create(['tenant_id' => $scope['tenant_id'], 'marketing_profile_id' => $profile->id,
            'source_type' => 'pos_event_capture', 'source_id' => $this->pos->runtime().':'.$request,
            'match_method' => $newRecord ? 'register_customer_created' : 'register_customer_selected',
            'source_meta' => ['capture_evidence_version' => 1, 'record_origin' => $newRecord ? 'created' : 'selected', 'event_id' => (int) $match[1], 'event_name' => $event['name'],
                'register_id' => $scope['register']->id, 'actor_id' => $scope['actor_id'], 'captured_local_on' => $today, 'captured_at' => now()->toIso8601String()]]);
    }

    public function report(array $scope, string $from, string $to): array
    {
        if (! ($scope['admin'] ?? false) || config('enterprise.runtime')) {
            throw new PosException('organization_admin_required', 403);
        }
        // Bounded, read-only cohort. The oldest capture wins even after a reviewed customer merge.
        $links = MarketingProfileLink::query()->forTenantId($scope['tenant_id'])->where('source_type', 'pos_event_capture')
            ->orderBy('created_at')->orderBy('id')->limit(5001)->get();
        $issues = $links->count() > 5000 ? ['capture_coverage_incomplete'] : [];
        $seen = [];
        $events = [];
        foreach ($links->take(5000) as $link) {
            $profile = $this->identities->byId($scope['tenant_id'], (int) $link->marketing_profile_id);
            if (! $profile || isset($seen[$profile->id])) {
                continue;
            }
            $seen[$profile->id] = true;
            $meta = $link->source_meta;
            $captured = $meta['captured_at'] ?? null;
            if (! is_string($captured) || empty($meta['event_id']) || empty($meta['event_name'])
                || ($meta['captured_local_on'] ?? substr($captured, 0, 10)) < $from || ($meta['captured_local_on'] ?? substr($captured, 0, 10)) > $to) {
                continue;
            }
            $eventId = (int) $meta['event_id'];
            $events[$eventId] ??= ['event_id' => $eventId, 'event_name' => $meta['event_name'], 'new_customer_records' => 0,
                'captured_customer_records' => 0, 'selected_existing_customer_records' => 0, 'record_origin_unknown' => 0,
                'existing_before_event' => 0, 'online_customers_after_event' => 0, 'online_orders_after_event' => 0,
                'online_net_minor' => 0, 'memberships_after_event' => 0, 'membership_timing_unknown' => 0, 'customers' => []];
            $row = &$events[$eventId];
            $origin = ($meta['capture_evidence_version'] ?? null) === 1 && in_array($meta['record_origin'] ?? null, ['created', 'selected'], true) ? $meta['record_origin'] : 'unknown';
            $row['captured_customer_records']++;
            $row['selected_existing_customer_records'] += $origin === 'selected' ? 1 : 0;
            $row['record_origin_unknown'] += $origin === 'unknown' ? 1 : 0;
            try {
                $history = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $scope['tenant_id'], 100);
            } catch (\Throwable) {
                $history = ['purchases' => [], 'has_more' => false, 'issues' => ['customer_history_incomplete']];
            }
            if ($history['has_more'] || $history['issues']) {
                $issues[] = 'customer_history_incomplete';
            }
            $paid = collect($history['purchases'])->filter(fn ($p) => $p['confirmed'] && $p['amount_minor'] !== null && ! empty($p['occurred_at']) && strtotime($p['occurred_at']) !== false && strtotime($p['occurred_at']) <= now()->timestamp);
            if ($paid->contains(fn ($p) => strtotime($p['occurred_at']) < strtotime($captured))) {
                $row['existing_before_event']++;

                continue;
            }
            $row['new_customer_records'] += $origin === 'created' ? 1 : 0;
            $online = $paid->filter(fn ($p) => $p['channel'] === 'online' && $p['currency'] === 'USD'
                && strtotime($p['occurred_at']) > strtotime($captured) && ($p['refunded_minor'] ?? 0) < $p['amount_minor']);
            $row['online_customers_after_event'] += $online->isNotEmpty() ? 1 : 0;
            $row['online_orders_after_event'] += $online->count();
            $net = $online->sum(fn ($p) => $p['amount_minor'] - ($p['refunded_minor'] ?? 0));
            $row['online_net_minor'] += $net;
            $membership = app(PosMembershipEvidence::class)->history($profile);
            $joined = collect($membership['joined_at'])->contains(fn ($date) => strtotime($date) > strtotime($captured));
            // An existing membership is not an event conversion just because billing moved to Everbranch.
            $priorMembership = collect($membership['joined_at'])->contains(fn ($date) => strtotime($date) <= strtotime($captured));
            $row['memberships_after_event'] += $membership['coverage_complete'] && $joined && ! $priorMembership ? 1 : 0;
            $row['membership_timing_unknown'] += $membership['coverage_complete'] ? 0 : 1;
            $row['customers'][] = ['customer_id' => (int) $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name),
                'record_origin' => $origin, 'new_customer_record' => $origin === 'created',
                'captured_at' => $captured, 'online_orders_after_event' => $online->count(), 'online_net_minor' => $net,
                'membership_after_event' => $membership['coverage_complete'] ? ($joined && ! $priorMembership) : null];
            unset($row);
        }

        return ['events' => array_values($events), 'from' => $from, 'to' => $to, 'currency' => 'USD',
            'issues' => array_values(array_unique($issues)), 'attribution_model' => 'first_recorded_event_capture',
            'basis' => 'Observed linked activity after event capture; association does not prove the event caused the purchase. New record counts require versioned created provenance; legacy capture origin and unconnected purchase history remain unknown. A new record is not proof of a first lifetime purchase. Memberships require original subscription dates.'];
    }
}
