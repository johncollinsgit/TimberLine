<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfileLink;
use App\Models\Order;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Services\Pos\PosEventSales;
use App\Services\Pos\PosStore;
use App\Services\Team\Studio\EventCommerceReport;
use App\Services\Team\Studio\EventSalesAccess;
use App\Services\Team\Studio\EventSalesHistory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class TeamShowTextAudience
{
    public function __construct(
        private EventSalesAccess $salesAccess,
        private EventSalesHistory $history,
        private EventCommerceReport $commerce,
        private CanonicalMarketingProfileResolver $customers,
        private MarketingSmsEligibilityService $eligibility,
    ) {}

    public function context(TeamOperationRecord $show): array
    {
        $records = TeamOperationRecord::query()
            ->where('tenant_id', $show->tenant_id)->where('area_id', $show->area_id)
            ->whereIn('kind', ['event', 'financial', 'event_link', 'series'])
            ->where('status', '!=', 'withdrawn')->get()->toBase()->keyBy('id');
        $links = $records->where('kind', 'event_link')->where('status', 'accepted')
            ->filter(function (TeamOperationRecord $link) use ($records): bool {
                $record = $records->get($link->data['occurrence_id'] ?? '');

                return $record && filled($link->data['series_id'] ?? null)
                    && $this->salesAccess->exported($record, $link)
                    && ($link->data['starts_on'] ?? null) === $record->starts_on?->toDateString()
                    && ($link->data['ends_on'] ?? null) === $record->ends_on?->toDateString();
            });
        $byOccurrence = $links->groupBy(fn ($link) => $link->data['occurrence_id'])
            ->filter(fn (Collection $occurrences) => $occurrences->count() === 1)->map->first();
        $series = $byOccurrence->get($show->id)?->data['series_id'] ?? null;
        $nearby = $byOccurrence->groupBy(fn ($link) => $link->data['series_id'])
            ->except($series === null ? [] : [$series])
            ->map(fn (Collection $members, $seriesId) => $records->get($seriesId)?->title ?? $records->get($members->first()->data['occurrence_id'])->title)
            ->sort();

        return compact('records', 'byOccurrence', 'series', 'nearby');
    }

    public function settings(TeamOperationRecord $show, array $context): array
    {
        $area = TeamArea::where('tenant_id', $show->tenant_id)->findOrFail($show->area_id);
        $stored = $area->settings['show_text_audiences'][$this->scope($show, $context)] ?? null;

        return is_array($stored) ? $stored : [
            'nearby_series' => [],
            'cities' => filled($show->data['city'] ?? null) && filled($show->data['state'] ?? null)
                ? [$show->data['city']] : [],
            'state' => $show->data['state'] ?? '',
            'zips' => [],
        ];
    }

    public function save(TeamOperationRecord $show, array $context, array $settings): void
    {
        DB::transaction(function () use ($show, $context, $settings): void {
            $area = TeamArea::where('tenant_id', $show->tenant_id)->lockForUpdate()->findOrFail($show->area_id);
            $existing = $area->settings ?? [];
            $existing['show_text_audiences'][$this->scope($show, $context)] = $settings;
            $area->update(['settings' => $existing, 'version' => $area->version + 1]);
        }, 3);
    }

    public function build(Tenant $tenant, TeamOperationRecord $show, array $context, array $settings, array $groups): array
    {
        $evidence = [];
        $issues = [];
        $omitted = 0;
        if (in_array('show', $groups, true)) {
            $this->eventPurchases($tenant, $show, $context['byOccurrence']->get($show->id), 'show', $evidence, $issues);
            if ($context['series'] === null) {
                $issues[] = 'Link earlier editions in Event sales history to include their customers.';
            }
        }
        foreach ($context['byOccurrence']->except([$show->id]) as $id => $link) {
            $edition = $context['records']->get($id);
            if (! $edition->ends_on || $edition->ends_on->toDateString() >= ($show->starts_on?->toDateString() ?? today()->toDateString())) {
                continue;
            }
            $seriesId = $link->data['series_id'];
            if ($context['series'] === $seriesId && in_array('show', $groups, true)) {
                $this->eventPurchases($tenant, $edition, $link, 'show', $evidence, $issues);
            } elseif (in_array('nearby', $groups, true) && in_array($seriesId, $settings['nearby_series'], true)) {
                $this->eventPurchases($tenant, $edition, $link, 'nearby', $evidence, $issues);
            }
        }
        if (in_array('online', $groups, true)) {
            $this->onlinePurchases($tenant, $settings, $evidence, $issues);
        }
        $canonical = [];
        foreach ($evidence as $id => $reasons) {
            $profile = $this->customers->byId($tenant->id, (int) $id);
            if ($profile && ! $profile->archived_at) {
                $entry = $canonical[$profile->id]['reasons'] ?? [];
                $canonical[$profile->id] = ['profile' => $profile, 'reasons' => array_values(array_unique([...$entry, ...$reasons]))];
            } else {
                $omitted++;
            }
        }
        $evaluated = $this->eligibility->evaluateProfiles(array_column($canonical, 'profile'), $tenant->id);
        $suppressed = array_fill_keys($this->eligibility->recordedOptOutPhones($tenant->id, $evaluated->pluck('normalized_phone')->filter()->all()), true);
        $rows = [];
        $seen = [];
        ksort($canonical);
        foreach ($canonical as $id => $entry) {
            $profile = $entry['profile'];
            $evaluation = $evaluated->get($id, []);
            $phone = $evaluation['normalized_phone'] ?? null;
            if (! ($evaluation['eligible'] ?? false) || $profile->sms_opted_out_at || ! $phone || isset($seen[$phone]) || isset($suppressed[$phone])) {
                $omitted++;

                continue;
            }
            $seen[$phone] = true;
            $rows[] = ['id' => $id, 'phone' => $phone,
                'name' => trim($profile->first_name.' '.$profile->last_name) ?: 'Customer ending in '.substr($phone, -4),
                'reasons' => $entry['reasons']];
        }

        return ['rows' => $rows, 'omitted' => $omitted, 'issues' => array_values(array_unique($issues))];
    }

    private function eventPurchases(Tenant $tenant, TeamOperationRecord $event, ?TeamOperationRecord $link, string $group, array &$evidence, array &$issues): void
    {
        $label = ($group === 'nearby' ? 'Nearby show' : 'This show').' · '.$event->title;
        if ($link) {
            $result = $this->history->scopePurchases($tenant, $event, $link);
            if ($result['review_items'] || $result['customer_review_items'] || empty($link->data['square_scopes'])) {
                $issues[] = 'Some sales or customer matches need review for '.$event->title.'.';
            }
            $this->addPaid($result['purchases'], $label, $evidence);
        } else {
            $issues[] = 'Review sales mappings for '.$event->title.'.';
        }
        $commerce = $event->kind === 'event' && Schema::hasTable('commerce_stock_event_links')
            ? $this->commerce->commerceId($event) : null;
        if (! $commerce || ! Schema::hasTable('pos_live_sales')) {
            return;
        }
        $zone = $link?->data['timezone'] ?? $event->data['timezone'] ?? 'UTC';
        $zone = in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
        $sales = app(PosEventSales::class)->read($tenant->id, $commerce, $zone, true);
        foreach (array_chunk($sales['receipt_ids'] ?? [], 500) as $ids) {
            $payments = app(PosStore::class)->rows('sales', $tenant->id)->whereIn('id', $ids)->get()->keyBy('id');
            $links = MarketingProfileLink::forTenantId($tenant->id)->where('source_type', 'pos_sale')->whereIn('source_id', $ids)->get()->groupBy('source_id');
            foreach ($payments as $id => $sale) {
                $matches = $links->get($id, collect())->pluck('marketing_profile_id')->unique();
                if ($matches->count() !== 1 || (int) $sale->amount_minor <= app(\App\Services\Pos\PosCustomerPurchases::class)->confirmedRefunds($sale)) {
                    continue;
                }
                try {
                    $receipt = app(\App\Services\Pos\PosReceiptCustomers::class)->decorate($sale, app(\App\Services\Pos\PosService::class)->snapshot($sale));
                } catch (\App\Services\Pos\PosException) {
                    $issues[] = 'Some Everbranch POS customer links need review for '.$event->title.'.';

                    continue;
                }
                $profile = $this->customers->byId($tenant->id, (int) $matches->first());
                if ($profile && $profile->id === $this->customers->byId($tenant->id, (int) ($receipt['customer']['id'] ?? 0))?->id) {
                    $evidence[$profile->id][] = $label;
                }
            }
        }
        if ($sales['review_items'] ?? []) {
            $issues[] = 'Some Everbranch POS receipts need review for '.$event->title.'.';
        }
    }

    private function addPaid(array $purchases, string $label, array &$evidence): void
    {
        foreach ($purchases as $purchase) {
            if (! empty($purchase['customer_id']) && is_numeric($purchase['refunded_minor'] ?? null)
                && ($purchase['amount_minor'] ?? 0) > $purchase['refunded_minor']) {
                $evidence[(int) $purchase['customer_id']][] = $label;
            }
        }
    }

    private function onlinePurchases(Tenant $tenant, array $settings, array &$evidence, array &$issues): void
    {
        $cities = array_map(fn ($city) => Str::lower(trim($city)), $settings['cities']);
        $state = Str::lower(trim($settings['state']));
        $zips = $settings['zips'];
        if ((! $cities || $state === '') && ! $zips) {
            $issues[] = 'Set nearby cities or ZIP codes to include online buyers.';

            return;
        }
        Order::forTenantId($tenant->id)->whereNotNull('shopify_order_id')->whereNotNull('shopify_store_key')
            ->when($state !== '', fn ($query) => $query->where(fn ($region) => $region
                ->whereRaw('LOWER(TRIM(shipping_province_code)) = ?', [$state])
                ->orWhereRaw('LOWER(TRIM(shipping_province)) = ?', [$state])))
            ->where(function ($query) use ($cities, $state, $zips): void {
                if ($cities && $state !== '') {
                    $query->whereIn(DB::raw('LOWER(TRIM(shipping_city))'), $cities);
                }
                foreach ($zips as $zip) {
                    $query->orWhere('shipping_zip', $zip)->orWhere('shipping_zip', 'like', $zip.'-%');
                }
            })->chunkById(500, function (Collection $orders) use ($tenant, &$evidence): void {
                $links = MarketingProfileLink::forTenantId($tenant->id)->where('source_type', 'order')
                    ->whereIn('source_id', $orders->pluck('id')->map(fn ($id) => (string) $id))->get()->groupBy('source_id');
                foreach ($orders as $order) {
                    $fact = (array) data_get($order->attribution_meta, 'customer_commerce', []);
                    if (! in_array(strtolower((string) ($fact['financial_status'] ?? '')), ['paid', 'partially_refunded'], true)
                        || ($fact['test'] ?? false) || ($fact['conflicted'] ?? false)
                        || (int) ($fact['tenant_id'] ?? 0) !== (int) $tenant->id
                        || (string) ($fact['shopify_order_id'] ?? '') !== (string) $order->shopify_order_id
                        || ($fact['store_key'] ?? null) !== $order->shopify_store_key
                        || (float) ($fact['total_price'] ?? 0) <= (float) ($fact['refund_total'] ?? 0)
                        || ! in_array(strtolower((string) ($fact['source_name'] ?? '')), ['web', 'online_store', 'shopify_online_store', 'shop', 'shopify_draft_order'], true)) {
                        continue;
                    }
                    $ids = $links->get($order->id, collect())->pluck('marketing_profile_id')->unique();
                    if ($ids->count() === 1) {
                        $evidence[(int) $ids->first()][] = 'Online order · '.$order->shipping_city;
                    }
                }
            });
    }

    private function scope(TeamOperationRecord $show, array $context): string
    {
        return $context['series'] !== null ? 'series:'.$context['series'] : 'event:'.$show->id;
    }
}
