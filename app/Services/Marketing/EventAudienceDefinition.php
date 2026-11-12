<?php

namespace App\Services\Marketing;

use App\Models\Event;
use App\Models\EventInstance;
use App\Models\MarketingOrderEventAttribution;
use App\Models\Order;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Stable dynamic rules over the shared purchase projection; saving never creates a send. */
final class EventAudienceDefinition
{
    public function options(int $tenant): array
    {
        return Cache::remember('event-audience-options-v2:'.hash('sha256', (string) config('pos_live.runtime_id')).':'.$tenant, 5, fn () => $this->buildOptions($tenant));
    }

    /** Typed event filters need reviewed event identities, not the lifetime product catalog. */
    public function optionsForInput(int $tenant, array $input): array
    {
        return in_array($input['kind'] ?? null, ['event_buyers', 'captured_contacts'], true)
            ? ['events' => app(EventCustomerCohort::class)->options($tenant), 'series' => [], 'legacy' => [], 'products' => [], 'scents' => []]
            : $this->options($tenant);
    }

    private function buildOptions(int $tenant): array
    {
        $events = app(EventCustomerCohort::class)->options($tenant);
        $series = TeamOperationRecord::where(['tenant_id' => $tenant, 'kind' => 'series'])->where('status', '!=', 'withdrawn')->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->title])->all();
        $legacy = [];
        $ids = Order::forTenantId($tenant)->whereNotNull('event_id')->distinct()->pluck('event_id');
        foreach (Event::whereIn('id', $ids)->get() as $event) {
            $legacy[] = ['id' => 'event:'.$event->id, 'name' => ($event->display_name ?: $event->name).' · historical orders'];
        }
        $ids = MarketingOrderEventAttribution::forTenantId($tenant)->distinct()->pluck('event_instance_id');
        foreach (EventInstance::whereIn('id', $ids)->get() as $event) {
            $legacy[] = ['id' => 'event_instance:'.$event->id, 'name' => $event->title.' · '.($event->starts_at?->format('Y') ?: 'historical Square')];
        }
        $products = app(StockLedger::class)->rows('items', $tenant)->orderBy('title')->limit(5000)->get()->map(fn ($r) => ['id' => 'stock:'.$r->id, 'name' => $r->title.($r->sku ? ' · '.$r->sku : '').($r->active ? '' : ' · retired')])->all();
        $squareProducts = [];
        foreach (\App\Models\SquareOrder::forTenantId($tenant)->where('state', 'COMPLETED')->orderBy('id')->limit(5000)->cursor() as $order) {
            $lines = $order->raw_payload['line_items'] ?? [];
            foreach (is_array($lines) ? array_slice($lines, 0, 501) : [] as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $id = trim((string) ($line['catalog_object_id'] ?? ''));
                if ($id === '' || isset($squareProducts[$id])) {
                    continue;
                }
                $squareProducts[$id] = ['id' => 'square_catalog:'.$id, 'name' => trim(($line['name'] ?? 'Recorded item').' '.($line['variation_name'] ?? '')).' · Square history'];
                if (count($squareProducts) >= 5000) {
                    break 2;
                }
            }
        }
        $shopifyProducts = [];
        foreach (DB::table('order_lines as l')->join('orders as o', 'o.id', '=', 'l.order_id')->where('o.tenant_id', $tenant)
            ->whereNotNull('o.shopify_store_key')->where(fn ($q) => $q->whereNotNull('l.shopify_product_id')->orWhereNotNull('l.shopify_variant_id'))
            ->select('o.shopify_store_key', 'l.shopify_product_id', 'l.shopify_variant_id', 'l.raw_title')->distinct()->limit(5000)->cursor() as $line) {
            $id = $line->shopify_product_id ? 'shopify:'.$line->shopify_store_key.':'.$line->shopify_product_id : 'shopify_variant:'.$line->shopify_store_key.':'.$line->shopify_variant_id;
            $shopifyProducts[$id] ??= ['id' => $id, 'name' => ($line->raw_title ?: 'Recorded Shopify product').' · Shopify history'];
        }
        $websiteProducts = [];
        $websiteScents = [];
        foreach (\App\Models\WebsiteOrderLine::forTenantId($tenant)->select('website_product_variant_id', 'title', 'snapshot')->orderBy('id')->limit(5000)->cursor() as $line) {
            if ($line->website_product_variant_id) {
                $id = 'website_variant:'.$line->website_product_variant_id;
                $websiteProducts[$id] ??= ['id' => $id, 'name' => ($line->title ?: 'Recorded product').' · Website history'];
            }
            if (! empty($line->snapshot['scent_key']) && ! empty($line->snapshot['scent_name'])) {
                $id = 'website_scent:'.$line->snapshot['scent_key'];
                $websiteScents[$id] ??= ['id' => $id, 'name' => $line->snapshot['scent_name'].' · Website history'];
            }
        }
        $products = [...$products, ...array_values($squareProducts), ...array_values($shopifyProducts), ...array_values($websiteProducts)];
        $scents = DB::table('order_lines as l')->join('orders as o', 'o.id', '=', 'l.order_id')->where('o.tenant_id', $tenant)->whereNotNull('l.scent_id')
            ->whereNotNull('l.scent_name')->select('l.scent_id', 'l.scent_name')->distinct()->orderBy('l.scent_name')->limit(2000)->get()->unique('scent_id')->map(fn ($r) => ['id' => 'scent:'.$r->scent_id, 'name' => $r->scent_name])->values()->all();
        $scents = [...$scents, ...array_values($websiteScents)];

        return compact('events', 'series', 'legacy', 'products', 'scents');
    }

    public function candidateIds(int $tenant, array $input, ?array &$cohort = null): array
    {
        $ids = [];
        $query = \App\Models\MarketingProfile::forTenantId($tenant)->whereNull('archived_at')->whereNull('merged_into_profile_id');
        if (in_array($input['kind'], ['event_buyers', 'captured_contacts'], true)) {
            $cohort = app(EventCustomerCohort::class)->read($tenant, (int) $input['event_id'], selectedEvidence: true);
            $ids = $cohort['profile_ids'];

            return $query->whereIn('id', $ids)->orderBy('id')->pluck('id')->all();
        }
        if ($input['kind'] === 'previous_show_nearby') {
            $links = TeamOperationRecord::where(['tenant_id' => $tenant, 'kind' => 'event_link', 'status' => 'accepted'])->get()
                ->filter(fn ($link) => ($link->data['series_id'] ?? null) === ($input['series_id'] ?? null));
            foreach ($links as $link) {
                $occurrence = TeamOperationRecord::where(['tenant_id' => $tenant, 'kind' => 'event'])->find($link->data['occurrence_id'] ?? '');
                $event = $occurrence ? app(\App\Services\Team\Studio\EventCommerceReport::class)->commerceId($occurrence) : null;
                if ($event) {
                    $ids = [...$ids, ...app(EventCustomerCohort::class)->read($tenant, $event)['profile_ids']];
                }
            }
            $orderIds = [];
            $squareIds = [];
            foreach ($input['legacy_ids'] ?? [] as $id) {
                [$type, $value] = explode(':', $id, 2);
                if ($type === 'event') {
                    $orderIds = [...$orderIds, ...Order::forTenantId($tenant)->where('event_id', $value)->pluck('id')->map('strval')->all()];
                } else {
                    $squareIds = [...$squareIds, ...MarketingOrderEventAttribution::forTenantId($tenant)->where('event_instance_id', $value)->pluck('source_id')->all()];
                }
            }
            $profileIds = \App\Models\MarketingProfileLink::forTenantId($tenant)->where(fn ($q) => $q->where(fn ($q) => $q->where('source_type', 'order')->whereIn('source_id', $orderIds))->orWhere(fn ($q) => $q->where('source_type', 'square_order')->whereIn('source_id', $squareIds)))->pluck('marketing_profile_id')->all();
            $profiles = \App\Models\MarketingProfile::forTenantId($tenant)->whereIn('id', $profileIds)->get();
            $canonical = app(CanonicalMarketingProfileResolver::class)->canonicalCollection($profiles, $tenant);
            abort_unless($canonical, 409, 'Some customer identities need review before this audience can be previewed.');
            $ids = [...$ids, ...($canonical?->pluck('id')->all() ?? [])];
            $zips = app(CustomerLocationDistance::class)->postalCodesWithin($input['postal_code'], (float) ($input['radius_miles'] ?? 50));

            return $query->where(fn ($q) => $q->whereIn('id', array_unique($ids))->orWhere(fn ($q) => $q->whereIn('country', ['US', 'USA', 'United States', 'United States of America'])->whereIn(DB::raw('SUBSTR(postal_code, 1, 5)'), $zips)))->orderBy('id')->pluck('id')->all();
        }
        // Product/scents use original linked purchase evidence; contacts without any purchase are not scanned.
        $sourceIds = \App\Models\MarketingProfileLink::forTenantId($tenant)->whereIn('source_type', ['order', 'square_order', 'square_payment', 'pos_sale', 'website_order', 'website_customer'])->distinct()->pluck('marketing_profile_id');
        $profiles = \App\Models\MarketingProfile::forTenantId($tenant)->whereIn('id', $sourceIds)->get();
        $canonical = app(CanonicalMarketingProfileResolver::class)->canonicalCollection($profiles, $tenant);
        abort_unless($canonical, 409, 'Some customer identities need review before this audience can be previewed.');

        return $canonical?->pluck('id')->unique()->sort()->values()->all() ?? [];
    }

    public function rules(int $tenant, array $input): array
    {
        $options = $this->optionsForInput($tenant, $input);
        $condition = fn ($field, $value) => ['field' => $field, 'operator' => 'contains_exact', 'value' => (string) $value];
        $owned = function ($key, $value) use ($options): void {
            if (! in_array((string) $value, array_map('strval', array_column($options[$key], 'id')), true)) {
                throw ValidationException::withMessages([$key => 'Choose a record belonging to this workspace.']);
            }
        };
        $conditions = [];
        $groups = [];
        if (in_array($input['kind'], ['event_buyers', 'captured_contacts'], true)) {
            $owned('events', $input['event_id'] ?? '');
            $conditions[] = $condition($input['kind'] === 'event_buyers' ? 'purchased_event_ids' : 'captured_event_ids', $input['event_id']);
        } elseif (in_array($input['kind'], ['product_buyers', 'scent_buyers'], true)) {
            $key = $input['kind'] === 'product_buyers' ? 'products' : 'scents';
            $owned($key, $input['item_key'] ?? '');
            $conditions[] = $condition($input['kind'] === 'product_buyers' ? 'purchased_product_keys' : 'purchased_scent_keys', $input['item_key']);
        } else {
            $previous = [];
            if (filled($input['series_id'] ?? null)) {
                $owned('series', $input['series_id']);
                $previous[] = $condition('purchased_series_ids', $input['series_id']);
            }
            foreach ($input['legacy_ids'] ?? [] as $id) {
                $owned('legacy', $id);
                $previous[] = $condition('purchased_legacy_event_ids', $id);
            }
            if (! $previous) {
                throw ValidationException::withMessages(['series_id' => 'Choose the recurring show or its historical occurrences.']);
            }
            $point = app(CustomerLocationDistance::class)->point($input['postal_code'] ?? null);
            if (! $point) {
                throw ValidationException::withMessages(['postal_code' => 'Enter a US event ZIP with a Census reference point.']);
            }
            $groups[] = ['logic' => 'or', 'conditions' => $previous, 'groups' => []];
            $groups[] = ['logic' => 'and', 'conditions' => [['field' => 'near_event_zip', 'operator' => 'within', 'value' => ['postal_code' => $input['postal_code'], 'radius_miles' => (float) ($input['radius_miles'] ?? 50)]]], 'groups' => []];
        }

        return ['logic' => $input['kind'] === 'previous_show_nearby' ? 'or' : 'and', 'conditions' => $conditions, 'groups' => $groups,
            '_event_audience' => ['version' => 1, 'filters' => $input, 'source' => 'confirmed_customer_purchase_projection']];
    }

    public function reasons(array $input, array $metrics, array $options, array $evaluated): array
    {
        $label = fn ($kind, $id) => collect($options[$kind])->first(fn ($row) => (string) $row['id'] === (string) $id)['name'] ?? 'the selected record';

        return match ($input['kind']) {
            'event_buyers' => ['Confirmed purchase at '.$label('events', $input['event_id'])],
            'captured_contacts' => ['Contact captured at '.$label('events', $input['event_id'])],
            'product_buyers' => ['Purchased '.$label('products', $input['item_key'])],
            'scent_buyers' => ['Purchased scent '.$label('scents', $input['item_key'])],
            default => array_values(array_filter([
                ...(! empty($input['series_id']) && in_array((string) $input['series_id'], $metrics['purchased_series_ids'] ?? [], true) ? ['Previous buyer at '.$label('series', $input['series_id'])] : []),
                ...array_map(fn ($id) => in_array($id, $metrics['purchased_legacy_event_ids'] ?? [], true) ? 'Previous buyer at '.$label('legacy', $id) : null, $input['legacy_ids'] ?? []),
                ...array_filter($evaluated, fn ($reason) => str_starts_with($reason, 'Approximately ')),
            ])),
        };
    }
}
