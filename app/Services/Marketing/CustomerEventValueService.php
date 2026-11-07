<?php

namespace App\Services\Marketing;

use App\Models\MarketingProfile;
use App\Models\MarketingProfileLink;
use App\Services\Inventory\StockLedger;
use App\Services\Pos\PosMembershipEvidence;
use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

/** Read-only attribution over existing identity links and original commerce; never a second ledger. */
class CustomerEventValueService
{
    public const WINDOWS = [30, 90, 365];

    public function __construct(private CanonicalMarketingProfileResolver $identities, private StockLedger $stock) {}

    /** The caller must already authorize customer/financial access to this tenant. */
    public function forProfile(MarketingProfile $profile, int $tenantId): array
    {
        $profile = (int) $profile->tenant_id === $tenantId ? $this->identities->byId($tenantId, (int) $profile->id) : null;
        if (! $profile || $tenantId < 1 || $profile->archived_at !== null) {
            throw (new ModelNotFoundException)->setModel(MarketingProfile::class);
        }
        $now = CarbonImmutable::now('UTC');
        $issues = [];
        $links = $this->profileLinks($profile, $tenantId, $issues);
        $captures = $this->captures($links, $profile, $tenantId, $now, $issues);
        $first = $captures[0] ?? null;
        if (array_intersect($issues, ['capture_coverage_incomplete', 'capture_evidence_requires_review'])) {
            $first = null;
        }
        if ($first && isset($captures[1]) && $captures[1]['captured_at'] === $first['captured_at'] && $captures[1]['event']['id'] !== $first['event']['id']) {
            $issues[] = 'first_capture_ambiguous';
            $first = null;
        }
        try {
            $history = app(CustomerCommerceSnapshotService::class)->forProfile($profile, $tenantId, 100);
        } catch (\Throwable) {
            $history = ['customer_id' => (int) $profile->id, 'purchases' => [], 'has_more' => true, 'issues' => ['customer_history_unavailable']];
        }
        abort_unless((int) ($history['customer_id'] ?? 0) === (int) $profile->id, 409, 'Customer identity changed. Refresh before displaying event value.');
        $this->assertCurrentProfile($tenantId, (int) $profile->id);
        $issues = [...$issues, ...($history['issues'] ?? [])];
        if ($history['has_more'] ?? true) {
            $issues[] = 'customer_history_window_incomplete';
        }
        // This read model contains explicit links, not proof of all prior or unconnected purchases.
        $issues[] = 'unconnected_and_unlinked_history_unknown';
        $purchases = collect($history['purchases'] ?? [])->unique('id')->sortBy([['occurred_at', 'asc'], ['id', 'asc']])->values();
        $firstPaidAt = null;
        $journey = [];
        foreach ($purchases as $purchase) {
            $date = $this->date($purchase['occurred_at'] ?? null);
            $valid = ($purchase['confirmed'] ?? false) === true && $date && $date <= $now
                && is_int($purchase['amount_minor'] ?? null) && $purchase['amount_minor'] >= 0
                && is_string($purchase['currency'] ?? null) && $purchase['currency'] !== '';
            if (($purchase['confirmed'] ?? false) && ! $valid) {
                $issues[] = 'purchase_timing_or_amount_unknown';
            }
            $paid = $valid && $purchase['amount_minor'] > 0;
            $repeat = $paid && $firstPaidAt !== null && $date > $firstPaidAt;
            if ($paid && $firstPaidAt === null) {
                $firstPaidAt = $date;
            }
            $event = $valid ? $this->purchaseEvent($purchase, $links, $tenantId, $profile, $now, $issues) : null;
            $journey[] = [
                'purchase_id' => $purchase['id'], 'source' => $purchase['source'] ?? null, 'source_id' => $purchase['source_id'] ?? null,
                'occurred_at' => $date?->toIso8601String(), 'channel' => $purchase['channel'] ?? 'unknown',
                'confirmed_purchase' => (bool) $valid, 'confirmed_paid_purchase' => (bool) $paid,
                'amount_minor' => $purchase['amount_minor'] ?? null, 'refunded_minor' => $purchase['refunded_minor'] ?? null,
                'currency' => $purchase['currency'] ?? null, 'currency_scale' => $purchase['currency_scale'] ?? null,
                'observed_repeat_purchase' => (bool) $repeat, 'purchase_event' => $event,
                'after_first_capture' => $valid && $first ? $date > $this->date($first['captured_at']) : null,
                'acquisition_event_id' => $first['event']['id'] ?? null, 'causal_credit' => null,
            ];
        }
        $priorPaid = $first && $firstPaidAt && $firstPaidAt < $this->date($first['captured_at']);
        $review = $first ? $this->acquisitionReview($first, $links, $tenantId, $now) : null;
        $classification = $priorPaid ? 'returning' : (($review['classification'] ?? null) === 'new' ? 'new' : (($review['classification'] ?? null) === 'returning' ? 'returning' : 'unknown'));
        if ($priorPaid && ($review['classification'] ?? null) === 'new') {
            $issues[] = 'acquisition_review_conflicts_with_prior_purchase';
        }
        $windows = [];
        foreach (self::WINDOWS as $days) {
            $windows[] = $this->window($journey, $first, $days, $now);
        }
        $membership = $this->membership($profile, $first, $links, $tenantId, $now, $issues);
        $reviewOptions = $this->reviewOptions($links, $journey, $first, $membership, $tenantId);
        $this->assertCurrentProfile($tenantId, (int) $profile->id);

        return [
            'contract_version' => 1, 'tenant_id' => $tenantId, 'customer_id' => (int) $profile->id, 'as_of' => $now->toIso8601String(),
            'first_recorded_capture' => $first, 'captures' => $captures,
            'acquisition' => ['event' => $first['event'] ?? null, 'basis' => $review ? 'reviewed_first_customer_acquisition' : 'first_recorded_event_capture_only',
                'first_customer_acquisition_confirmed' => $classification === 'new' && $review !== null,
                'review' => $review, 'causal_credit' => null],
            'customer_classification' => $classification,
            'contact_classification' => $first ? ($first['record_origin'] === 'created' ? 'new_customer_record' : 'captured_contact') : 'no_recorded_capture',
            'confirmed_buyer' => $firstPaidAt !== null, 'first_observed_paid_purchase_at' => $firstPaidAt?->toIso8601String(),
            'purchase_journey' => $journey, 'membership_conversion' => $membership, 'windows' => $windows,
            'review_options' => $reviewOptions,
            'coverage' => ['source' => 'canonical_customer_commerce', 'scope' => 'explicitly_linked_records_only', 'complete' => false,
                'purchase_limit' => 100, 'has_more_purchases' => (bool) ($history['has_more'] ?? true), 'capture_limit' => 5000],
            'contribution_margin_minor' => null, 'margin_missing_evidence' => ['original_item_cogs', 'shipping_cost', 'processing_cost', 'reward_cost'],
            'issues' => array_values(array_unique($issues)),
            'basis' => 'First recorded contact, each purchase event and membership conversion are separate relationships. Later linked activity is correlation; causal credit is unknown. Values are observed paid totals after recorded refunds, including tax, not accounting revenue or contribution margin.',
        ];
    }

    /** Event cohort uses the existing capture/confirmed-receipt source definitions. */
    public function forEvent(int $tenantId, int $eventId, int $page = 1): array
    {
        $event = $this->reviewedEvent($tenantId, $eventId);
        abort_unless($event, 404);
        $cohort = app(EventCustomerCohort::class)->read($tenantId, $eventId);
        $ids = $cohort['profile_ids'];
        sort($ids, SORT_NUMERIC);
        $page = max(1, $page);
        $rows = [];
        $issues = $cohort['review_items'];
        foreach (array_slice($ids, ($page - 1) * 100, 100) as $id) {
            $profile = $this->identities->byId($tenantId, $id);
            if (! $profile || $profile->archived_at !== null) {
                $issues[] = 'customer_identity_review_required';

                continue;
            }
            abort_unless((int) $profile->id === (int) $id, 409, 'Customer cohort identities changed. Refresh the event page.');
            $value = $this->forProfile($profile, $tenantId);
            abort_unless((int) $value['customer_id'] === (int) $profile->id, 409, 'Customer cohort identities changed. Refresh the event page.');
            $rows[] = ['customer_id' => (int) $profile->id, 'name' => trim($profile->first_name.' '.$profile->last_name),
                'relations' => array_keys($cohort['relations'][$id] ?? []), 'customer_classification' => $value['customer_classification'],
                'contact_classification' => $value['contact_classification'], 'confirmed_buyer' => $value['confirmed_buyer'],
                'acquisition_cohort' => ($value['acquisition']['event']['id'] ?? null) === $eventId,
                'acquisition' => $value['acquisition'], 'membership_conversion' => $value['membership_conversion'], 'windows' => $value['windows'], 'issues' => $value['issues']];
        }
        $acquired = array_values(array_filter($rows, fn ($row) => $row['acquisition_cohort']));
        $windows = [];
        foreach (self::WINDOWS as $index => $days) {
            $currencies = [];
            foreach ($acquired as $row) {
                foreach ($row['windows'][$index]['currencies'] as $currency) {
                    $key = $currency['currency'];
                    $currencies[$key] ??= $this->emptyCurrency($key) + ['customers_with_purchase' => 0, 'customers_with_observed_repeat' => 0];
                    $this->addCurrency($currencies[$key], $currency);
                    $currencies[$key]['customers_with_purchase']++;
                    $currencies[$key]['customers_with_observed_repeat'] += $currency['observed_repeat_purchase_count'] > 0 ? 1 : 0;
                }
            }
            $matured = count(array_filter($acquired, fn ($row) => $row['windows'][$index]['time_matured']));
            $windows[] = ['days' => $days, 'currencies' => array_values($currencies), 'denominators' => [
                'recorded_acquisition_customers_on_page' => count($acquired), 'time_matured_customers_on_page' => $matured,
                'source_coverage_unknown_customers_on_page' => count($acquired),
                'new_customers_on_page' => count(array_filter($acquired, fn ($row) => $row['customer_classification'] === 'new')),
                'returning_customers_on_page' => count(array_filter($acquired, fn ($row) => $row['customer_classification'] === 'returning')),
                'classification_unknown_customers_on_page' => count(array_filter($acquired, fn ($row) => $row['customer_classification'] === 'unknown')),
            ], 'coverage_complete' => false, 'summary_scope' => 'this_page_only'];
        }
        // A later row may merge a customer already projected earlier on this page.
        foreach ($rows as $row) {
            $this->assertCurrentProfile($tenantId, $row['customer_id']);
        }

        return ['contract_version' => 1, 'tenant_id' => $tenantId, 'event' => $event, 'customers' => $rows, 'windows' => $windows,
            'denominators' => ['recorded_event_customers' => count($ids), 'customers_on_page' => count($rows),
                'captured_contacts_on_page' => count(array_filter($rows, fn ($row) => in_array('captured', $row['relations'], true))),
                'confirmed_event_buyers_on_page' => count(array_filter($rows, fn ($row) => in_array('purchased', $row['relations'], true)))],
            'page' => $page, 'per_page' => 100, 'has_more' => count($ids) > $page * 100,
            'issues' => array_values(array_unique([...$issues, 'unconnected_and_unlinked_history_unknown'])),
            'as_of' => CarbonImmutable::now('UTC')->toIso8601String(), 'causal_credit' => null,
            'basis' => 'Acquisition windows group the first recorded capture, not every event attended. Buyers and captured contacts use separate denominators. Monetary summaries cover this page only and retain currencies separately.'];
    }

    private function assertCurrentProfile(int $tenant, int $profileId): void
    {
        $current = $this->identities->byId($tenant, $profileId);
        abort_unless($current && (int) $current->id === $profileId && $current->archived_at === null,
            409, 'Customer identity changed. Refresh before displaying event value.');
    }

    private function profileLinks(MarketingProfile $profile, int $tenant, array &$issues): Collection
    {
        $aliasIds = [(int) $profile->id];
        $frontier = $aliasIds;
        for ($depth = 0; $frontier && $depth < 32; $depth++) {
            $frontier = MarketingProfile::forTenantId($tenant)->whereIn('merged_into_profile_id', $frontier)->whereNotIn('id', $aliasIds)->limit(1001)->pluck('id')->map(fn ($id) => (int) $id)->all();
            $aliasIds = [...$aliasIds, ...$frontier];
            if (count($aliasIds) > 1000) {
                throw (new ModelNotFoundException)->setModel(MarketingProfile::class);
            }
        }
        if ($frontier) {
            throw (new ModelNotFoundException)->setModel(MarketingProfile::class);
        }
        $captures = MarketingProfileLink::forTenantId($tenant)->whereIn('marketing_profile_id', $aliasIds)->where('source_type', 'pos_event_capture')->orderBy('id')->limit(5001)->get();
        if ($captures->count() > 5000) {
            $issues[] = 'capture_coverage_incomplete';
        }

        return $captures->take(5000)->whereIn('marketing_profile_id', $aliasIds)->concat(
            MarketingProfileLink::forTenantId($tenant)->whereIn('marketing_profile_id', $aliasIds)->where('source_type', '!=', 'pos_event_capture')->get()
        );
    }

    private function captures(Collection $links, MarketingProfile $profile, int $tenant, CarbonImmutable $now, array &$issues): array
    {
        $result = [];
        foreach ($links->where('source_type', 'pos_event_capture') as $link) {
            $meta = $link->source_meta ?? [];
            $date = $this->date($meta['captured_at'] ?? null);
            $event = $this->reviewedEvent($tenant, (int) ($meta['event_id'] ?? 0));
            if (! $date || $date > $now || ! $event || $this->sourceConflicts($link, $tenant, $profile)) {
                $issues[] = 'capture_evidence_requires_review';

                continue;
            }
            $result[] = ['source_link_id' => (int) $link->id, 'source_type' => 'pos_event_capture', 'source_id' => $link->source_id,
                'captured_at' => $date->toIso8601String(), 'event' => $event, 'match_method' => $link->match_method,
                'record_origin' => ($meta['capture_evidence_version'] ?? null) === 1 && in_array($meta['record_origin'] ?? null, ['created', 'selected'], true) ? $meta['record_origin'] : 'unknown',
                'source_link_revision' => (int) ($meta['event_attribution_revision'] ?? 0),
                'attribution_revision' => (int) ($meta['event_attribution_revision'] ?? 0)];
        }
        usort($result, fn ($a, $b) => strcmp($a['captured_at'], $b['captured_at']) ?: ($a['source_link_id'] <=> $b['source_link_id']));

        return $result;
    }

    private function acquisitionReview(array $capture, Collection $links, int $tenant, CarbonImmutable $now): ?array
    {
        $meta = $links->firstWhere('id', $capture['source_link_id'])?->source_meta ?? [];
        $review = $meta['acquisition_review'] ?? [];
        if (! is_array($review)) {
            return null;
        }
        $through = $this->date($review['history_complete_through'] ?? null);
        if (! $this->reviewValid($review, $tenant, $now) || ! in_array($review['classification'] ?? null, ['new', 'returning'], true)
            || ! $through || $through < $this->date($capture['captured_at']) || $through > $now
            || $this->date($review['reviewed_at']) < $this->date($capture['captured_at'])
            || ($review['captured_at'] ?? null) !== $capture['captured_at'] || ($review['event_id'] ?? null) !== $capture['event']['id']) {
            return null;
        }

        return array_intersect_key($review, array_flip(['classification', 'reviewed_by', 'reviewed_at', 'evidence_reference', 'history_complete_through']));
    }

    private function purchaseEvent(array $purchase, Collection $links, int $tenant, MarketingProfile $profile, CarbonImmutable $now, array &$issues): ?array
    {
        // A live Pay purchase is scoped by the immutable receipt's pool, not today's register.
        if (($purchase['source'] ?? null) === 'pos_sale') {
            try {
                $store = app(PosStore::class);
                $sale = $store->rows('sales', $tenant)->where('id', $purchase['source_id'])->first();
                if (! $sale) {
                    return null;
                }
                $snapshot = app(PosService::class)->snapshot($sale);
                $pool = $this->stock->rows('pools', $tenant)->where('id', $snapshot['terms']['stock_pool_id'] ?? '')->first();
                if (($snapshot['binding']['environment'] ?? null) !== 'live' || ! $pool || ! preg_match('/^event:([1-9][0-9]*)$/D', (string) $pool->event_key, $m)) {
                    return null;
                }
                $event = $this->reviewedEvent($tenant, (int) $m[1]);

                return $event ? ['event' => $event, 'basis' => 'original_pay_receipt_stock_pool', 'causal_credit' => null] : null;
            } catch (\Throwable) {
                $issues[] = 'purchase_event_evidence_unavailable';

                return null;
            }
        }
        $events = [];
        foreach ($links->where('source_type', $purchase['source'] ?? '')->where('source_id', (string) ($purchase['source_id'] ?? '')) as $link) {
            $review = $link->source_meta['event_attribution'] ?? [];
            if (! is_array($review) || ! $this->reviewValid($review, $tenant, $now) || $this->sourceConflicts($link, $tenant, $profile)) {
                continue;
            }
            $event = $this->reviewedEvent($tenant, (int) ($review['event_id'] ?? 0));
            if ($event) {
                $events[$event['id']] = $event;
            }
        }
        if (count($events) > 1) {
            $issues[] = 'purchase_event_attribution_ambiguous';
        }

        return count($events) === 1 ? ['event' => reset($events), 'basis' => 'reviewed_original_purchase_link', 'causal_credit' => null] : null;
    }

    private function membership(MarketingProfile $profile, ?array $capture, Collection $links, int $tenant, CarbonImmutable $now, array &$issues): array
    {
        try {
            $history = app(PosMembershipEvidence::class)->history($profile);
        } catch (\Throwable) {
            $history = ['joined_at' => [], 'coverage_complete' => false];
        }
        $sourceDates = collect($history['joined_at'] ?? []);
        $dates = $sourceDates->map(fn ($value) => $this->date($value))->filter(fn ($date) => $date && $date <= $now)->sort()->values();
        $anchor = $capture ? $this->date($capture['captured_at']) : null;
        $prior = $anchor && $dates->contains(fn ($date) => $date <= $anchor);
        $later = $anchor ? $dates->first(fn ($date) => $date > $anchor) : null;
        $complete = ($history['coverage_complete'] ?? false) === true && $sourceDates->count() === $dates->count();
        if (! $complete) {
            $issues[] = 'membership_original_join_history_unknown';
        }
        $attributed = null;
        if ($later && $complete && ! $prior && $capture) {
            $review = $links->firstWhere('id', $capture['source_link_id'])?->source_meta['membership_conversion_review'] ?? [];
            $reviewDate = $this->date($review['joined_at'] ?? null);
            if (is_array($review) && $this->reviewValid($review, $tenant, $now) && $reviewDate?->equalTo($later)
                && (int) ($review['event_id'] ?? 0) === $capture['event']['id']) {
                $attributed = $capture['event']['id'];
            }
        }

        return ['source' => 'pos_membership_evidence_original_subscription_created_at', 'original_join_dates' => $dates->map(fn ($date) => $date->toIso8601String())->all(),
            'coverage_complete' => $complete, 'existing_before_capture' => $anchor && ($complete || $prior) ? (bool) $prior : null,
            'observed_join_after_capture_at' => $later?->toIso8601String(),
            'first_join_after_capture' => $anchor && $complete ? (bool) ($later && ! $prior) : null,
            'correlated_event_id' => $later ? ($capture['event']['id'] ?? null) : null, 'attributed_event_id' => $attributed,
            'attribution_basis' => $attributed ? 'reviewed_conversion_with_original_join_evidence' : 'timing_correlation_only', 'causal_credit' => null];
    }

    private function window(array $journey, ?array $capture, int $days, CarbonImmutable $now): array
    {
        $start = $capture ? $this->date($capture['captured_at']) : null;
        $end = $start?->addDays($days);
        $currencies = [];
        $rewardOnly = 0;
        foreach ($journey as $purchase) {
            $date = $this->date($purchase['occurred_at']);
            if (! $start || ! $purchase['confirmed_purchase'] || ! $date || $date < $start || $date >= $end || $date > $now) {
                continue;
            }
            if (! $purchase['confirmed_paid_purchase']) {
                $rewardOnly++;

                continue;
            }
            $key = $purchase['currency'];
            $currencies[$key] ??= $this->emptyCurrency($key);
            $row = &$currencies[$key];
            $row['confirmed_paid_purchase_count']++;
            $row['gross_paid_minor'] += $purchase['amount_minor'];
            $refundKnown = is_int($purchase['refunded_minor']) && $purchase['refunded_minor'] >= 0 && $purchase['refunded_minor'] <= $purchase['amount_minor'];
            $net = $refundKnown ? $purchase['amount_minor'] - $purchase['refunded_minor'] : null;
            $row['recorded_refunds_minor'] += $refundKnown ? $purchase['refunded_minor'] : 0;
            $row['unknown_refund_purchase_count'] += $refundKnown ? 0 : 1;
            $row['known_net_paid_minor'] += $net ?? 0;
            $row['net_paid_minor'] = $row['unknown_refund_purchase_count'] ? null : $row['known_net_paid_minor'];
            if ($purchase['observed_repeat_purchase']) {
                $row['observed_repeat_purchase_count']++;
                $row['known_repeat_net_paid_minor'] += $net ?? 0;
                $row['unknown_repeat_refund_count'] += $refundKnown ? 0 : 1;
                $row['repeat_net_paid_minor'] = $row['unknown_repeat_refund_count'] ? null : $row['known_repeat_net_paid_minor'];
            }
            unset($row);
        }

        return ['days' => $days, 'starts_at' => $start?->toIso8601String(), 'ends_before' => $end?->toIso8601String(),
            'time_matured' => $end ? $now >= $end : false, 'observed_through' => $end ? $now->min($end)->toIso8601String() : null,
            'coverage_complete' => false, 'currencies' => array_values($currencies), 'zero_paid_confirmed_purchase_count' => $rewardOnly,
            'basis' => 'UTC elapsed-day window [capture, capture + days). Repeat means a later timestamp than the earliest observed confirmed positive-paid purchase; unknown prior history is not assumed absent.'];
    }

    private function emptyCurrency(string $currency): array
    {
        return ['currency' => $currency, 'confirmed_paid_purchase_count' => 0, 'gross_paid_minor' => 0, 'recorded_refunds_minor' => 0,
            'unknown_refund_purchase_count' => 0, 'known_net_paid_minor' => 0, 'net_paid_minor' => 0,
            'observed_repeat_purchase_count' => 0, 'known_repeat_net_paid_minor' => 0, 'unknown_repeat_refund_count' => 0, 'repeat_net_paid_minor' => 0];
    }

    private function reviewOptions(Collection $links, array $journey, ?array $capture, array $membership, int $tenant): array
    {
        $options = [];
        foreach ($links as $link) {
            $kinds = [];
            $meta = $link->source_meta ?? [];
            $clearKinds = [];
            foreach (['acquisition' => 'acquisition_review', 'purchase_event' => 'event_attribution', 'membership_conversion' => 'membership_conversion_review'] as $kind => $key) {
                if (isset($meta[$key])) {
                    $clearKinds[] = $kind;
                }
            }
            $purchase = collect($journey)->first(fn ($row) => $row['source'] === $link->source_type && $row['source_id'] === $link->source_id && $row['confirmed_purchase']);
            if ($purchase && $link->source_type !== 'pos_sale') {
                $kinds[] = 'purchase_event';
            }
            if ($capture && $capture['source_link_id'] === (int) $link->id) {
                $kinds[] = 'acquisition';
                if ($membership['first_join_after_capture'] === true) {
                    $kinds[] = 'membership_conversion';
                }
            }
            if ($kinds || $clearKinds) {
                $options[] = ['source_link_id' => (int) $link->id, 'source_link_revision' => (int) ($meta['event_attribution_revision'] ?? 0),
                    'source_type' => $link->source_type, 'source_id' => $link->source_id, 'allowed_kinds' => $kinds, 'allowed_clear_kinds' => $clearKinds,
                    'capture_event_id' => $capture && $capture['source_link_id'] === (int) $link->id ? $capture['event']['id'] : null,
                    'original_join_at' => $membership['observed_join_after_capture_at'],
                    'current_reviews' => array_intersect_key($meta, array_flip(['acquisition_review', 'event_attribution', 'membership_conversion_review']))];
            }
        }

        return ['links' => $options, 'events' => app(EventCustomerCohort::class)->options($tenant), 'acquisition_classifications' => ['new', 'returning', 'unknown'],
            'requires_owner_admin' => true, 'prehistory_attestation_required_for_acquisition' => true];
    }

    private function addCurrency(array &$total, array $currency): void
    {
        foreach (['confirmed_paid_purchase_count', 'gross_paid_minor', 'recorded_refunds_minor', 'unknown_refund_purchase_count', 'known_net_paid_minor', 'observed_repeat_purchase_count', 'known_repeat_net_paid_minor', 'unknown_repeat_refund_count'] as $key) {
            $total[$key] += $currency[$key];
        }
        $total['net_paid_minor'] = $total['unknown_refund_purchase_count'] ? null : $total['known_net_paid_minor'];
        $total['repeat_net_paid_minor'] = $total['unknown_repeat_refund_count'] ? null : $total['known_repeat_net_paid_minor'];
    }

    public function reviewedEvent(int $tenant, int $event): ?array
    {
        if ($tenant < 1 || $event < 1) {
            return null;
        }
        $rows = $this->stock->rows('event_links', $tenant)->where('event_id', $event)->whereNotNull('reviewed_by')->orderBy('id')->get();
        if ($rows->isEmpty()) {
            return null;
        }
        try {
            $metadata = $rows->map(fn ($row) => json_decode($row->metadata, true, 64, JSON_THROW_ON_ERROR));
            $identities = $metadata->map(fn ($meta) => [$meta['start_on'] ?? null, $meta['end_on'] ?? null, $meta['timezone'] ?? null])->unique(fn ($value) => json_encode($value));
            if ($identities->count() !== 1) {
                return null;
            }
            $meta = $metadata->first();

            return ['id' => $event, 'name' => $meta['name'] ?? 'Reviewed event', 'starts_on' => $meta['start_on'] ?? null,
                'ends_on' => $meta['end_on'] ?? null, 'timezone' => $meta['timezone'] ?? null,
                'sources' => $rows->map(fn ($row) => ['provider' => $row->provider, 'source_id' => $row->external_id, 'reviewed_by' => (int) $row->reviewed_by])->all()];
        } catch (\Throwable) {
            return null;
        }
    }

    private function sourceConflicts(MarketingProfileLink $link, int $tenant, MarketingProfile $profile): bool
    {
        return MarketingProfileLink::forTenantId($tenant)->where('source_type', $link->source_type)->where('source_id', $link->source_id)->get()
            ->contains(fn ($other) => $this->identities->byId($tenant, (int) $other->marketing_profile_id)?->id !== $profile->id);
    }

    private function reviewValid(array $review, int $tenant, CarbonImmutable $now): bool
    {
        $date = $this->date($review['reviewed_at'] ?? null);

        return is_int($review['reviewed_by'] ?? null) && $date && $date <= $now
            && is_string($review['evidence_reference'] ?? null) && trim($review['evidence_reference']) !== ''
            && app(CustomerCommerceLinkService::class)->canLink(\App\Models\User::find($review['reviewed_by']), $tenant);
    }

    private function date(mixed $date): ?CarbonImmutable
    {
        // An explicit timestamp with timezone is required; a date alone cannot choose first touch.
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D', $date)) {
            return null;
        }
        try {
            $parsed = new \DateTimeImmutable($date);
            $errors = \DateTimeImmutable::getLastErrors();

            return $errors !== false && ($errors['warning_count'] || $errors['error_count']) ? null : CarbonImmutable::instance($parsed)->utc();
        } catch (\Throwable) {
            return null;
        }
    }
}
