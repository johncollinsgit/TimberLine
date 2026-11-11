<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Models\TeamOperationRecord;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\PosEventSales;
use App\Services\Pos\PosStore;

/** Event context is an explicit source relationship, never a name/card/date-window guess. */
class EventCustomerCohort
{
    public function options(int $tenant): array
    {
        return app(StockLedger::class)->rows('event_links', $tenant)->whereNotNull('reviewed_by')->orderByDesc('id')->get()->unique('event_id')->take(300)->map(function ($row) {
            $meta = json_decode($row->metadata, true, 64, JSON_THROW_ON_ERROR);

            return ['id' => (int) $row->event_id, 'name' => $meta['name'] ?? 'Reviewed event', 'starts_on' => $meta['start_on'] ?? null, 'ends_on' => $meta['end_on'] ?? null];
        })->values()->all();
    }

    public function read(int $tenant, int $eventId, ?TeamOperationRecord $teamsEvent = null): array
    {
        $link = app(StockLedger::class)->rows('event_links', $tenant)->where('event_id', $eventId)->whereNotNull('reviewed_by')->first();
        abort_unless($link, 404);
        $ids = [];
        $relations = [];
        $issues = [];
        $captures = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', 'pos_event_capture')->where('source_meta->event_id', $eventId)->orderBy('id')->limit(5001)->get();
        if ($captures->count() > 5000) {
            $issues[] = 'Event capture coverage is incomplete.';
        }
        $groups = [];
        foreach ($captures->take(5000) as $capture) {
            if ((int) ($capture->source_meta['event_id'] ?? 0) === $eventId) {
                $groups[] = ['links' => collect([$capture]), 'relation' => 'captured'];
            }
        }
        try {
            $metadata = json_decode($link->metadata, true, 64, JSON_THROW_ON_ERROR);
            $sales = app(PosEventSales::class)->read($tenant, $eventId, $metadata['timezone'] ?? 'UTC', true);
            $issues = [...$issues, ...$sales['review_items']];
            $externalLinks = MarketingProfileLink::forTenantId($tenant)->where('source_type', 'square_order')->whereIn('source_id', $sales['square_order_ids'] ?? [])->get();
            foreach ($externalLinks->groupBy('source_id') as $links) {
                $groups[] = ['links' => $links, 'relation' => 'purchased', 'purchase_type' => 'square_order', 'purchase_id' => $links->first()->source_id];
            }
            $prefix = app(PosStore::class)->runtime().':';
            $sourceIds = array_map(fn ($id) => $prefix.$id, $sales['receipt_ids']);
            $linked = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', 'pos_sale')->whereIn('source_id', $sourceIds)->get();
            foreach ($linked->groupBy('source_id') as $links) {
                $groups[] = ['links' => $links, 'relation' => 'purchased', 'purchase_type' => 'pos_sale', 'purchase_id' => substr($links->first()->source_id, strlen($prefix))];
            }
        } catch (\Throwable) {
            $issues[] = 'Pay purchase coverage is unavailable.';
        }
        if ($teamsEvent) {
            abort_unless((int) $teamsEvent->tenant_id === $tenant, 404);
            $squareIds = $teamsEvent->data['_teams_enrichment']['square_orders']['ids'] ?? [];
            $selected = MarketingProfileLink::query()->forTenantId($tenant)->where('source_type', 'square_order')->whereIn('source_id', $squareIds)->get();
            foreach ($selected->groupBy('source_id') as $links) {
                $groups[] = ['links' => $links, 'relation' => 'purchased', 'purchase_type' => 'square_order', 'purchase_id' => $links->first()->source_id];
            }
        }
        // Owner-reviewed attribution annotates the existing original purchase link.
        // It cannot turn an unpaid/unverified source into an event purchase.
        $reviewed = MarketingProfileLink::query()->forTenantId($tenant)
            ->whereIn('source_type', ['order', 'square_order', 'square_payment', 'website_order'])->where('source_meta->event_attribution->event_id', $eventId)->orderBy('id')->limit(5001)->get();
        if ($reviewed->count() > 5000) {
            $issues[] = 'Reviewed purchase attribution coverage is incomplete.';
        }
        foreach ($reviewed->take(5000)->groupBy(fn ($source) => $source->source_type.':'.$source->source_id) as $links) {
            $matches = $links->contains(function ($source) use ($tenant, $eventId) {
                $review = $source->source_meta['event_attribution'] ?? [];
                $date = is_array($review) ? ($review['reviewed_at'] ?? null) : null;

                return is_array($review) && (int) ($review['event_id'] ?? 0) === $eventId
                    && is_string($date) && strtotime($date) !== false && strtotime($date) <= now()->timestamp
                    && is_string($review['evidence_reference'] ?? null) && trim($review['evidence_reference']) !== ''
                    && is_int($review['reviewed_by'] ?? null)
                    && app(CustomerCommerceLinkService::class)->canLink(\App\Models\User::find($review['reviewed_by']), $tenant);
            });
            if ($matches) {
                $groups[] = ['links' => $links, 'relation' => 'purchased', 'purchase_type' => $links->first()->source_type, 'purchase_id' => $links->first()->source_id];
            }
        }
        $resolver = app(CanonicalMarketingProfileResolver::class);
        foreach ($groups as $group) {
            $profiles = MarketingProfile::query()->forTenantId($tenant)->whereIn('id', $group['links']->pluck('marketing_profile_id'))->get();
            if ($profiles->count() !== $group['links']->pluck('marketing_profile_id')->unique()->count()) {
                $issues[] = 'Some event/customer identities need review.';

                continue;
            }
            $profile = $resolver->oneCanonical($profiles, $tenant);
            if (! $profile || $profile->archived_at !== null) {
                $issues[] = 'Some event/customer identities need review.';

                continue;
            }
            if (isset($group['purchase_id'])) {
                $history = app(CustomerCommerceSnapshotService::class)->forReportingProfile($profile, $tenant);
                if (! collect($history['purchases'])->contains(fn ($purchase) => $purchase['confirmed'] && $purchase['source'] === $group['purchase_type'] && $purchase['source_id'] === $group['purchase_id'])) {
                    if ($history['has_more']) {
                        $issues[] = 'Some linked purchase coverage is incomplete.';
                    }

                    continue;
                }
            }
            $ids[$profile->id] = $profile->id;
            $relations[$profile->id][$group['relation']] = true;
        }

        return ['profile_ids' => array_values($ids), 'relations' => $relations, 'review_items' => array_values(array_unique($issues)),
            'basis' => 'Recorded event capture or an explicitly linked confirmed purchase. Later online purchases remain in the selected customer history.'];
    }
}
