<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\TeamOperationRecord;
use App\Services\Team\Studio\EventCommerceReport;
use Illuminate\Support\Facades\Cache;

/** Rebuildable five-second projection indexed by tenant and canonical customer; original journals remain authoritative. */
final class ConfirmedCustomerPurchaseProjection
{
    public function read(MarketingProfile $profile, int $tenant): array
    {
        $current = app(CanonicalMarketingProfileResolver::class)->byId($tenant, (int) $profile->id);
        abort_unless($current && $current->archived_at === null, 404);
        $key = 'customer-confirmed-purchases-v1:'.hash('sha256', (string) config('pos_live.runtime_id')).':'.$tenant.':'.$current->id;

        return Cache::remember($key, 5, function () use ($current, $tenant): array {
            $value = app(CustomerEventValueService::class)->forProfile($current, $tenant, true);
            $purchases = collect($value['purchase_journey'])->where('confirmed_purchase', true);
            $events = $purchases->pluck('purchase_event.event')->filter()->unique('id')->values();
            $series = [];
            foreach ($events as $event) {
                $occurrence = app(EventCommerceReport::class)->candidate($tenant, $event['id']);
                if (! $occurrence) {
                    continue;
                }
                $ids = TeamOperationRecord::where(['tenant_id' => $tenant, 'kind' => 'event_link', 'status' => 'accepted'])
                    ->get()->filter(fn ($link) => ($link->data['occurrence_id'] ?? null) === $occurrence->id)->pluck('data.series_id')->filter()->unique()->values();
                if ($ids->count() === 1 && TeamOperationRecord::where(['tenant_id' => $tenant, 'area_id' => $occurrence->area_id, 'kind' => 'series'])->where('status', '!=', 'withdrawn')->whereKey($ids->first())->exists()) {
                    $series[] = (string) $ids->first();
                }
            }
            $money = [];
            foreach ($purchases as $purchase) {
                $currency = $purchase['currency'];
                $money[$currency] ??= ['currency' => $currency, 'gross_minor' => 0, 'refunded_minor' => 0, 'net_minor' => 0, 'refunds_known' => true];
                $money[$currency]['gross_minor'] += $purchase['amount_minor'];
                if (! is_int($purchase['refunded_minor'])) {
                    $money[$currency]['refunds_known'] = false;

                    continue;
                }
                $money[$currency]['refunded_minor'] += $purchase['refunded_minor'];
                $money[$currency]['net_minor'] += max(0, $purchase['amount_minor'] - $purchase['refunded_minor']);
            }
            foreach ($money as &$currency) {
                if (! $currency['refunds_known']) {
                    $currency['net_minor'] = null;
                }
            }
            unset($currency);

            return ['contract_version' => 1, 'tenant_id' => $tenant, 'customer_id' => (int) $current->id,
                'confirmed_orders' => $purchases->count(), 'confirmed_source_keys' => $purchases->map(fn ($row) => $row['source'].':'.$row['source_id'])->values()->all(), 'currencies' => array_values($money),
                'purchased_event_ids' => $events->pluck('id')->map('strval')->all(), 'purchased_event_names' => $events->pluck('name')->all(),
                'purchased_series_ids' => array_values(array_unique($series)),
                'captured_event_ids' => collect($value['captures'])->pluck('event.id')->map('strval')->unique()->values()->all(),
                'acquisition_event_id' => isset($value['first_recorded_capture']['event']['id']) ? (string) $value['first_recorded_capture']['event']['id'] : null,
                'last_purchase_at' => $purchases->pluck('occurred_at')->filter()->max(),
                'behavior' => $value['behavior'], 'has_more' => $value['history_has_more'], 'issues' => $value['issues'],
                'as_of' => $value['as_of'], 'basis' => 'Explicit customer associations to confirmed original purchases. Acquisition capture is separate from purchases and repeat attendance.'];
        });
    }
}
