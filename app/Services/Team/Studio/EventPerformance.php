<?php

namespace App\Services\Team\Studio;

use App\Jobs\Team\RefreshEventPerformance;
use App\Models\MarketingProfile;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerEventValueService;
use App\Services\Marketing\EventCustomerCohort;
use App\Services\Team\Operations\OperationsAccess;
use App\Services\Tenancy\TenantModuleAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Rebuildable, issuer-scoped reports, never a purchase ledger or an authorization cache. */
class EventPerformance
{
    public const TABLE = 'team_event_performance_facts';

    private const REPORT_VERSION = 4;

    private const DIGEST_VERSION = 'indexed-content-v1';

    /** Frozen with the immutable generated-column migration; changed fields use the full digest. */
    private const INDEXED_DIGESTS = [
        'square_orders' => [
            'columns' => ['id', 'square_order_id', 'square_customer_id', 'location_id', 'state', 'total_money_amount', 'total_money_currency', 'closed_at', 'raw_payload'],
            'timestamp' => 'closed_at',
            'indexes' => ['epo_window_hash_idx', 'epo_order_hash_idx'],
        ],
        'square_payments' => [
            'columns' => ['id', 'square_payment_id', 'square_order_id', 'square_customer_id', 'location_id', 'amount_money', 'currency', 'status', 'raw_payload'],
            'timestamp' => null,
            'indexes' => ['epp_order_hash_idx'],
        ],
        'marketing_profiles' => [
            'columns' => ['id', 'merged_into_profile_id', 'archived_at', 'source_channels'],
            'timestamp' => 'archived_at',
            'indexes' => ['ep_profile_hash_idx'],
        ],
        'marketing_profile_links' => [
            'columns' => ['id', 'marketing_profile_id', 'source_type', 'source_id', 'source_meta', 'match_method', 'confidence'],
            'timestamp' => null,
            'indexes' => ['ep_link_hash_idx'],
        ],
    ];

    public function __construct(private OperationsAccess $access, private EventCustomerDependencies $customerDependencies) {}

    public static function rules(): array
    {
        return ['area' => 'required|uuid', 'group_by' => 'sometimes|in:event,team_member,customer,weekend',
            'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from',
            'q' => 'sometimes|nullable|string|max:100', 'page' => 'sometimes|integer|min:1|max:100000',
            'rank_basis' => 'sometimes|in:event,lifetime', 'sort' => 'sometimes|in:date,net_sales', 'event_id' => 'sometimes|uuid',
            'team_member_id' => 'sometimes|string|max:160', 'customer_id' => 'sometimes|integer|min:1',
            'weekend_start' => 'sometimes|integer|min:0|max:6', 'weekend_days' => 'sometimes|integer|min:1|max:7'];
    }

    public function read(Tenant $tenant, User $user, TeamArea $area, array $input = []): array
    {
        $this->authorize($tenant, $user, $area);
        $filters = array_replace(['group_by' => 'event', 'from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString(),
            'q' => '', 'rank_basis' => 'event', 'sort' => 'date', 'page' => 1, 'weekend_start' => 5, 'weekend_days' => 3], $input);
        $customers = $this->mayReadCustomers($tenant, $user, $area);
        abort_if(($filters['group_by'] === 'customer' || isset($filters['customer_id'])) && ! $customers, 403);
        $snapshot = $this->snapshot($tenant, $user, $area);
        $data = $snapshot?->data ?? [];
        $ready = Schema::hasTable(self::TABLE) && isset($data['generation']);
        $signature = $ready ? $this->signature($tenant, $area) : null;
        $stale = $ready && (($data['signature'] ?? '') !== $signature || ($data['customer_access'] ?? false) !== $customers || CarbonImmutable::parse($data['as_of'])->lt(now()->subMinute()));
        if (! $ready || $stale) {
            $this->requestRefresh($tenant, $user, $area, $snapshot);
        }
        $result = ['contract_version' => 1, 'status' => $ready ? ($stale ? 'stale' : 'ready') : 'preparing',
            'version' => $data['generation'] ?? null, 'as_of' => $data['as_of'] ?? null,
            'filters' => $filters, 'permissions' => ['can_compare_all' => true, 'can_view_customers' => $customers],
            'summary' => $this->emptySummary(), 'rows' => [], 'page' => (int) $filters['page'], 'per_page' => 50, 'has_more' => false, 'total_rows' => 0,
            'review_items' => $data['review_items'] ?? [],
            'basis' => 'Net sales exclude tax and tips. Customer spending includes tax after known refunds. Staff event-context totals are not individual sales credit.'];
        if (! $ready) {
            $result['review_items'][] = 'Preparing event history in the background. Source imports are never started by this page.';

            return $result;
        }
        // A changed canonical directory invalidates customer dimensions immediately, before refresh.
        // Event totals may still be shown explicitly as stale; private former aliases are withheld.
        if ($customers && (! ($data['customer_access'] ?? false) || ($data['customer_signature'] ?? '') !== $this->snapshotCustomerSignature($tenant, $area, $data))) {
            $customers = false;
            $this->requestRefresh($tenant, $user, $area, $snapshot);
            if ($filters['group_by'] === 'customer' || isset($filters['customer_id'])) {
                $this->assertSourceContext($tenant, $data);
                $result['status'] = 'preparing';
                $result['review_items'][] = 'Customer identities changed. Preparing the current customer comparison.';

                return $result;
            }
        }
        $generation = $data['generation'];
        $kind = match ($filters['group_by']) {
            'team_member' => 'team_member', 'customer' => $filters['rank_basis'] === 'lifetime' ? 'customer_lifetime' : 'customer', 'weekend' => 'day', default => 'event'
        };
        $customerKind = in_array($kind, ['customer', 'customer_lifetime'], true);
        $matching = $this->filtered($tenant, $area, $generation, $filters, $kind);
        $base = $this->filtered($tenant, $area, $generation, array_replace($filters, ['q' => '']), 'event')
            ->whereIn('event_id', (clone $matching)->select('event_id'));
        if ($kind === 'day') {
            $base = $this->weekendQuery(clone $matching, $filters);
        }
        $customerFacts = $customers ? $this->filtered($tenant, $area, $generation, $filters, 'customer') : null;
        if ($customerFacts !== null && $kind === 'day') {
            $customerFacts = $this->weekendQuery($customerFacts, $filters);
        }
        // Authorize the full contributing cohort before reading any totals or labels.
        // Pagination never narrows admission: later pages contribute to the summary.
        $matchingSources = $kind === 'day' ? $this->weekendQuery(clone $matching, $filters) : clone $matching;
        $operatorFacts = $kind === 'team_member' ? $this->filtered($tenant, $area, $generation, $filters, 'operator')
            ->whereIn('group_key', (clone $matching)->select('group_key')) : null;
        $sourceQueries = [$matchingSources, $base];
        if ($operatorFacts !== null) {
            $sourceQueries[] = $operatorFacts;
        }
        if ($customerFacts !== null) {
            $sourceQueries[] = $customerFacts;
        }
        $admission = $this->admitSources($tenant, $user, $area, $data, $this->contributingEventIds($sourceQueries));
        if ($customerFacts !== null) {
            $this->assertCustomerAdmission($tenant, $user, $area, $data);
        }
        $summary = (clone $base)->selectRaw('COUNT(DISTINCT event_id) as event_count, COALESCE(SUM(sale_count),0) as sale_count, SUM(net_sales_minor) as net_sales_minor, COALESCE(SUM(known_net_sales_minor),0) as known_net_sales_minor, COALESCE(SUM(selling_days),0) as selling_days, MIN(complete) as complete')->first();
        $result['summary'] = $this->summary($summary);
        if ($customerFacts !== null) {
            $customerTotals = (clone $customerFacts)->selectRaw('COUNT(DISTINCT customer_id) as customer_count, COALESCE(SUM(sale_count),0) as purchase_count, CASE WHEN COUNT(paid_total_minor) = COUNT(*) THEN SUM(paid_total_minor) ELSE NULL END as paid_total_minor')->first();
            $result['summary']['customer_count'] = (int) $customerTotals->customer_count;
            $result['summary']['customer_purchase_count'] = (int) $customerTotals->purchase_count;
            $result['summary']['paid_total_minor'] = $customerTotals->paid_total_minor === null ? null : (int) $customerTotals->paid_total_minor;
        }
        $query = $matching;
        // Day rows are mapped to a portable date key before SQL aggregation, without fetching source payloads.
        if ($kind === 'day') {
            $query = $this->weekendQuery($query, $filters);
        }
        $groupColumn = $kind === 'day' ? 'weekend_key' : 'group_key';
        $paidAggregate = $kind === 'customer_lifetime' ? 'MAX' : 'SUM';
        $countAggregate = $kind === 'customer_lifetime' ? 'MAX' : 'SUM';
        $groups = $query->selectRaw($groupColumn.' as dimension_key, MIN(id) as first_id, COUNT(DISTINCT event_id) as event_count, COALESCE('.$countAggregate.'(sale_count),0) as sale_count, SUM(net_sales_minor) as net_sales_minor, COALESCE(SUM(known_net_sales_minor),0) as known_net_sales_minor, CASE WHEN COUNT(paid_total_minor) = COUNT(*) THEN '.$paidAggregate.'(paid_total_minor) ELSE NULL END as paid_total_minor, COALESCE(SUM(selling_days),0) as selling_days, MIN(complete) as complete, MIN(local_date) as starts_on, MAX(ends_on) as ends_on')->groupBy($groupColumn);
        if ($kind === 'customer_lifetime') {
            $totals = DB::query()->fromSub(clone $groups, 'lifetime_customers')->selectRaw('COUNT(*) as customer_count, COALESCE(SUM(sale_count),0) as purchase_count, CASE WHEN COUNT(paid_total_minor) = COUNT(*) THEN SUM(paid_total_minor) ELSE NULL END as paid_total_minor')->first();
            $result['summary']['customer_count'] = (int) $totals->customer_count;
            $result['summary']['customer_purchase_count'] = (int) $totals->purchase_count;
            $result['summary']['paid_total_minor'] = $totals->paid_total_minor === null ? null : (int) $totals->paid_total_minor;
        }
        $result['total_rows'] = DB::query()->fromSub(clone $groups, 'grouped_events')->count();
        $order = $filters['sort'] === 'net_sales' ? ($customerKind ? 'paid_total_minor' : 'known_net_sales_minor') : 'starts_on';
        $rows = $groups->orderByDesc($order)->orderBy('dimension_key')->offset(($filters['page'] - 1) * 50)->limit(50)->get();
        $payloads = $this->facts($tenant, $area, $generation)->whereIn('id', $rows->pluck('first_id'))->pluck('payload', 'id');
        foreach ($rows as $row) {
            $payload = json_decode(Crypt::decryptString($payloads[$row->first_id]), true, 64, JSON_THROW_ON_ERROR);
            $id = $kind === 'day' ? $row->dimension_key : $payload['id'];
            $label = $kind === 'day' ? 'Weekend of '.CarbonImmutable::parse($row->dimension_key)->format('M j, Y') : $payload['label'];
            if ($customerKind) {
                $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $id);
                abort_unless($profile && ! $profile->archived_at && (int) $profile->id === (int) $id, 409, 'Customer identity changed. Refresh reporting.');
                $currentLabel = trim($profile->first_name.' '.$profile->last_name) ?: 'Customer';
                abort_if(isset($data['customer_scope']) && $currentLabel !== $label, 409, 'Customer labels changed while reporting was read.');
                $label = $currentLabel;
            }
            $eventIds = $this->filtered($tenant, $area, $generation, $filters, $kind);
            if ($kind === 'day') {
                $start = CarbonImmutable::parse($id);
                $eventIds->whereBetween('local_date', [$start->toDateString(), $start->addDays($filters['weekend_days'] - 1)->toDateString()]);
            } else {
                $eventIds->where('group_key', $row->dimension_key);
            }
            $metrics = $this->summary($row);
            $direct = ['direct_net_sales_minor' => null, 'direct_known_net_sales_minor' => 0, 'direct_sale_count' => 0, 'direct_complete' => false];
            if ($kind === 'team_member') {
                $operator = (clone $operatorFacts)->where('group_key', $row->dimension_key)
                    ->selectRaw('COUNT(*) as n, SUM(net_sales_minor) as net_sales_minor, SUM(known_net_sales_minor) as known_net_sales_minor, SUM(sale_count) as sale_count, MIN(complete) as complete')->first();
                if ($operator->n > 0) {
                    $direct = ['direct_net_sales_minor' => $operator->complete ? (int) $operator->net_sales_minor : null,
                        'direct_known_net_sales_minor' => (int) $operator->known_net_sales_minor, 'direct_sale_count' => (int) $operator->sale_count, 'direct_complete' => (bool) $operator->complete];
                }
            }
            $result['rows'][] = ['id' => (string) $id, 'label' => $label, ...$metrics,
                'rank_basis' => $filters['rank_basis'], 'paid_total_minor' => $row->paid_total_minor === null ? null : (int) $row->paid_total_minor,
                'starts_on' => $row->starts_on, 'ends_on' => $row->ends_on,
                'event_ids' => $eventIds->distinct()->limit(1000)->pluck('event_id')->all(),
                'href' => $customerKind ? route('customers.profile', ['profile' => $id, 'tenant' => $tenant->slug], false) : null,
                'attribution_basis' => $kind === 'team_member' ? 'Results of events worked; individual sales credit requires original operator evidence.' : ($customerKind ? 'Confirmed event purchases; spending includes tax after known refunds.' : 'Reviewed event sales'),
                ...$direct];
        }
        $result['has_more'] = $result['total_rows'] > $filters['page'] * 50;
        $this->finishAdmission($tenant, $user, $area, $data, $admission);
        if ($customerFacts !== null) {
            $this->assertCustomerAdmission($tenant, $user, $area, $data);
        }

        return $result;
    }

    /** All expensive source and lifetime reads occur here, outside HTTP rendering. */
    public function rebuild(Tenant $tenant, User $user, TeamArea $area): array
    {
        $this->authorize($tenant, $user, $area);
        abort_unless(Schema::hasTable(self::TABLE), 503);
        $key = $this->key($tenant, $user, $area);

        return Cache::lock('event-performance:'.$key, 660)->block(1, function () use ($tenant, $user, $area): array {
            $signature = $this->signature($tenant, $area, true);
            $customerAccess = $this->mayReadCustomers($tenant, $user, $area);
            $customerFrame = $customerAccess ? $this->captureCustomerFrame($tenant, $area) : null;
            $customerInvalidated = $customerAccess && $customerFrame === null;
            $customerAccess = $customerAccess && ! $customerInvalidated;
            $customerSignature = $customerFrame['signature'] ?? '';

            $current = $this->snapshot($tenant, $user, $area);
            $existing = $current?->data ?? [];
            if (! $customerInvalidated && isset($existing['customer_scope']) && isset($existing['generation']) && ($existing['signature'] ?? '') === $signature
                && ($existing['customer_signature'] ?? '') === $customerSignature && ($existing['customer_access'] ?? false) === $customerAccess
                && CarbonImmutable::parse($existing['built_at'] ?? $existing['as_of'])->gt(now()->subMinutes(5))) {
                $this->admitSources($tenant, $user, $area, $existing);
                if ($customerAccess) {
                    $this->assertCustomerAdmission($tenant, $user, $area, $existing);
                }
                $current->update(['data' => array_replace($existing, ['as_of' => now()->toIso8601String()])]);

                return ['version' => $existing['generation'], 'event_count' => count($existing['source_versions']), 'as_of' => now()->toIso8601String(), 'unchanged' => true];
            }
            $generation = (string) Str::uuid();
            try {
                $events = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event'])->where('status', '!=', 'withdrawn')->get()->keyBy('id');
                $links = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'event_link', 'status' => 'accepted'])->get();
                $linkByEvent = $links->keyBy(fn ($link) => $link->data['occurrence_id'] ?? '');
                $squareBindings = [];
                foreach ($links as $link) {
                    foreach ($link->data['square_scopes'] ?? [] as $scope) {
                        $binding = \App\Models\MarketingImportRun::forTenantId($tenant->id)->find($scope['orders_run_id'] ?? 0)?->summary['source_binding'] ?? null;
                        if (is_array($binding)) {
                            $squareBindings[hash('sha256', json_encode($binding, JSON_THROW_ON_ERROR))] = array_intersect_key($binding, array_flip(['connection_id', 'connection_generation', 'runtime_id', 'merchant_id', 'environment', 'location_ids', 'source_purpose']));
                        }
                    }
                }
                $extraIds = $linkByEvent->keys()->diff($events->keys());
                $events = $events->merge(TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('id', $extraIds)->whereIn('kind', ['financial', 'event'])->where('status', '!=', 'withdrawn')->get()->keyBy('id'));
                $versions = [];
                $issues = [];
                $facts = [];
                $histories = [];
                $this->access->readCollection($tenant, $user, $area, function () use ($tenant, $user, $area, $events, $linkByEvent, $generation, &$customerAccess, &$customerInvalidated, &$facts, &$versions, &$issues, &$histories, &$customerFrame, &$customerSignature): void {
                    if ($customerAccess) {
                        // One owned coherent witness view precedes private facts; provider calls stay outside it.
                        $customerFrame = $this->captureCustomerFrame($tenant, $area, null, true);
                        $customerInvalidated = $customerFrame === null;
                        $customerAccess = ! $customerInvalidated;
                        $customerSignature = $customerFrame['signature'] ?? '';
                    }
                    foreach ($events as $event) {
                        $this->access->record($tenant, $user, $event);
                        $versions[$event->id] = $event->version;
                        $summary = app(EventSalesHistory::class)->summarizeOccurrence($tenant, $user, $event, $linkByEvent->get($event->id));
                        if (collect($summary['staff'] ?? [])->contains(fn ($person) => $person['identity_review_required'] ?? false)) {
                            $issues[] = 'Some staff names have no reviewed shared person identity and are excluded from team comparisons.';
                        }
                        $facts = [...$facts, ...$this->eventFacts($tenant->id, $summary, $event->id)];
                        $customerFacts = [];
                        if ($customerAccess && ($mapping = $linkByEvent->get($event->id)) && ! empty($mapping->data['square_scopes'])) {
                            $scoped = app(EventSalesHistory::class)->scopePurchases($tenant, $event, $mapping);
                            $issues = [...$issues, ...$scoped['review_items'], ...$scoped['customer_review_items']];
                            foreach ($scoped['purchases'] as $purchase) {
                                if (empty($purchase['customer_id'])) {
                                    continue;
                                }
                                $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $purchase['customer_id']);
                                if (! $profile || $profile->archived_at || (int) $profile->id !== (int) $purchase['customer_id']) {
                                    $customerAccess = false;
                                    $customerInvalidated = true;

                                    break;
                                }
                                if (! $this->validateCustomerFact(fn () => $this->customerDependencies->assertPurchase($customerFrame, (string) $event->id, $profile, $purchase, $summary['timezone'] ?? 'UTC'))) {
                                    $customerAccess = false;
                                    $customerInvalidated = true;
                                    break;
                                }
                                $fact = $this->normalizedCustomerFact($tenant->id, $summary, $event->id, $profile, $purchase);
                                $customerFacts[$fact['source_key']] = $fact;
                            }
                        }
                        if ($customerAccess && $event->kind === 'event' && $commerce = app(EventCommerceReport::class)->commerceId($event)) {
                            $cohort = app(EventCustomerCohort::class)->read($tenant->id, $commerce, $event);
                            $issues = [...$issues, ...$cohort['review_items']];
                            foreach ($cohort['profile_ids'] as $id) {
                                $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $id);
                                if (! $profile || $profile->archived_at || (int) $profile->id !== (int) $id) {
                                    $customerAccess = false;
                                    $customerInvalidated = true;

                                    break;
                                }
                                $value = $histories[$id] ??= app(CustomerEventValueService::class)->forProfile($profile, $tenant->id, true);
                                $privateFacts = [];
                                if (! $this->validateCustomerFact(function () use ($tenant, $summary, $event, $commerce, $profile, $value, $cohort, $id, $customerFrame, &$privateFacts): void {
                                    $privateFacts = $this->customerFacts($tenant->id, $summary, $event->id, $commerce, $profile, $value, $cohort['relations'][$id] ?? [], $customerFrame);
                                })) {
                                    $customerAccess = false;
                                    $customerInvalidated = true;
                                    break;
                                }
                                foreach ($privateFacts as $fact) {
                                    if (isset($customerFacts[$fact['source_key']])) {
                                        if ($customerFacts[$fact['source_key']]['customer_id'] !== $fact['customer_id']) {
                                            $customerAccess = false;
                                            $customerInvalidated = true;

                                            break 2;
                                        }

                                        continue;
                                    }
                                    $customerFacts[$fact['source_key']] = $fact;
                                }
                            }
                        }
                        if ($customerAccess && ! $customerInvalidated) {
                            foreach (collect($customerFacts)->pluck('customer_id')->unique() as $customerId) {
                                $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $customerId);
                                if (! $profile || $profile->archived_at) {
                                    $customerInvalidated = true;
                                    break;
                                }
                                $value = $histories[$customerId] ??= app(CustomerEventValueService::class)->forProfile($profile, $tenant->id, true);
                                $journey = collect($value['purchase_journey'] ?? [])->where('confirmed_purchase', true)->unique('purchase_id');
                                $known = $journey->every(fn ($purchase) => ($purchase['currency'] ?? null) === 'USD'
                                    && is_int($purchase['amount_minor'] ?? null) && is_int($purchase['refunded_minor'] ?? null));
                                $paid = $known ? $journey->sum(fn ($purchase) => $purchase['amount_minor'] - $purchase['refunded_minor']) : null;
                                $facts[] = $this->fact($tenant->id, 'customer_lifetime', $event->id.':lifetime:'.$customerId, (string) $customerId,
                                    trim($profile->first_name.' '.$profile->last_name) ?: 'Customer', ['event_id' => $event->id, 'customer_id' => $customerId,
                                        'local_date' => $event->starts_on->toDateString(), 'ends_on' => $event->ends_on->toDateString(),
                                        'net_sales_minor' => null, 'known_net_sales_minor' => 0, 'paid_total_minor' => $paid,
                                        'sale_count' => $journey->count(), 'selling_days' => 0, 'complete' => $known && ! ($value['history_has_more'] ?? true)]);
                            }
                        }
                        $facts = [...$facts, ...($customerInvalidated ? [] : array_values($customerFacts))];
                        if (count($facts) >= 1000) {
                            $this->insertFacts($tenant, $area, $generation, $facts);
                            $facts = [];
                        }
                    }
                }, true);
                $this->insertFacts($tenant, $area, $generation, $facts);
                $this->authorize($tenant, $user, $area);
                abort_unless($signature === $this->signature($tenant, $area, true), 409, 'Source records changed while reporting was prepared.');
                if ($customerInvalidated || $customerAccess && ($customerSignature !== ($this->captureCustomerFrame($tenant, $area, $customerFrame['scope'])['signature'] ?? null) || ! $this->mayReadCustomers($tenant, $user, $area))) {
                    // A concurrent identity import must not block event totals.
                    // Withhold every customer fact; reads still compare the old
                    // signature freshly and return preparing for that dimension.
                    $this->facts($tenant, $area, $generation)->whereIn('kind', ['customer', 'customer_lifetime'])->delete();
                    Log::notice('Event reporting customer evidence withheld', ['tenant_id' => (int) $tenant->id, 'area_id' => (string) $area->id,
                        'phase' => $customerInvalidated ? 'witness_validation' : 'final_dependency_or_access']);
                    $customerAccess = false;
                    $issues[] = 'Customer identities changed. Preparing the current customer comparison.';
                }
                $snapshot = DB::transaction(function () use ($tenant, $user, $area, $generation, $signature, $customerSignature, $versions, $issues, $customerAccess, $squareBindings, $customerFrame): TeamOperationRecord {
                    TeamArea::where('tenant_id', $tenant->id)->whereKey($area->id)->lockForUpdate()->firstOrFail();
                    $this->authorize($tenant, $user, $area);
                    $snapshot = $this->snapshot($tenant, $user, $area) ?? new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
                        'kind' => 'performance_snapshot', 'source_key' => $this->key($tenant, $user, $area), 'assigned_user_id' => $user->id, 'visibility' => 'admin', 'title' => 'Event performance']);
                    $retired = $snapshot->data['retired_generations'] ?? [];
                    if ($old = ($snapshot->data['generation'] ?? null)) {
                        $retired[] = ['id' => $old, 'retired_at' => now()->toIso8601String()];
                    }
                    $snapshot->fill(['status' => 'ready', 'version' => ($snapshot->version ?? 0) + 1, 'data' => [
                        'generation' => $generation, 'signature' => $signature, 'customer_signature' => $customerSignature, 'customer_scope' => $customerFrame['scope'] ?? null,
                        'source_versions' => $versions, 'square_bindings' => array_values($squareBindings), 'customer_access' => $customerAccess, 'as_of' => now()->toIso8601String(), 'built_at' => now()->toIso8601String(),
                        'square_context_signature' => $this->squareContextSignature($tenant),
                        'runtime' => (string) config('pos_live.runtime_id', ''), 'review_items' => array_values(array_unique($issues)), 'retired_generations' => $retired,
                    ]])->save();

                    return $snapshot;
                });
                $retained = [];
                foreach ($snapshot->data['retired_generations'] ?? [] as $retired) {
                    if (CarbonImmutable::parse($retired['retired_at'])->lt(now()->subMinutes(15))) {
                        $this->facts($tenant, $area, $retired['id'])->delete();
                    } else {
                        $retained[] = $retired;
                    }
                }
                if (count($retained) !== count($snapshot->data['retired_generations'] ?? [])) {
                    $snapshot->update(['data' => array_replace($snapshot->data, ['retired_generations' => $retained])]);
                }

                return ['version' => $generation, 'event_count' => count($versions), 'as_of' => $snapshot->data['as_of']];
            } catch (\Throwable $error) {
                // Failed source walks may already have flushed batches. A failure
                // after publication must never remove the active generation.
                if (($this->snapshot($tenant, $user, $area)?->data['generation'] ?? null) !== $generation) {
                    $this->facts($tenant, $area, $generation)->delete();
                }
                throw $error;
            }
        });
    }

    public function requestRefresh(Tenant $tenant, User $user, TeamArea $area, ?TeamOperationRecord $snapshot = null): void
    {
        $snapshot ??= $this->snapshot($tenant, $user, $area);
        if (! $snapshot) {
            TeamOperationRecord::firstOrCreate(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'performance_snapshot', 'source_key' => $this->key($tenant, $user, $area)],
                ['id' => (string) Str::uuid(), 'assigned_user_id' => $user->id, 'visibility' => 'admin', 'title' => 'Event performance', 'status' => 'pending', 'version' => 1, 'data' => ['runtime' => (string) config('pos_live.runtime_id', '')]]);
        }
        $queue = (string) config('team_operations.performance_queue_connection', 'event_performance');
        if (in_array(config('queue.connections.'.$queue.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true)
            && Cache::add('event-performance-request:'.$this->key($tenant, $user, $area), true, 45)) {
            RefreshEventPerformance::dispatch($tenant->id, $area->id, $user->id, (string) config('pos_live.runtime_id', ''));
        }
    }

    public function authorize(Tenant $tenant, User $user, TeamArea $area): void
    {
        $this->access->financial($tenant, $user);
        $this->access->area($tenant, $user, $area->id);
        abort_unless((int) $area->tenant_id === (int) $tenant->id && $area->profile === 'events', 404);
    }

    private function mayReadCustomers(Tenant $tenant, User $user, TeamArea $area): bool
    {
        return $this->access->mayWrite($tenant, $user, $area->id) && app(TenantModuleAccessResolver::class)->canAccess($tenant->id, 'customers');
    }

    /** Union every source affecting rows, financial totals or customer totals. */
    private function contributingEventIds(array $queries): array
    {
        $sources = null;
        foreach ($queries as $query) {
            $ids = (clone $query)->reorder()->select('event_id')->distinct();
            $sources = $sources === null ? $ids : $sources->union($ids);
        }

        return $sources === null ? [] : DB::query()->fromSub($sources, 'contributing_events')->distinct()->orderBy('event_id')->pluck('event_id')->all();
    }

    private function assertSourceContext(Tenant $tenant, array $snapshot): void
    {
        abort_unless(hash_equals((string) ($snapshot['runtime'] ?? ''), (string) config('pos_live.runtime_id', '')), 409);
        abort_unless(hash_equals((string) ($snapshot['square_context_signature'] ?? ''), $this->squareContextSignature($tenant)), 409, 'Original Square access changed. Refresh the report after reviewing its source connection.');
        foreach ($snapshot['square_bindings'] ?? [] as $binding) {
            app(\App\Services\Marketing\SquareConnectionContext::class)->assertCurrent($tenant->id, $binding);
        }
    }

    private function connectionRevision(Tenant $tenant, TeamArea $area): string
    {
        $rows = \App\Models\TeamProviderConnection::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->orderBy('id')
            ->get(['id', 'user_id', 'provider', 'generation', 'status', 'settings'])->map(fn ($connection) => [
                'id' => $connection->id, 'user_id' => $connection->user_id, 'provider' => $connection->provider,
                'generation' => $connection->generation, 'status' => $connection->status, 'settings' => $connection->settings,
            ])->all();

        return hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    private function eventRevisions(iterable $events): array
    {
        $revisions = [];
        foreach ($events as $event) {
            $revisions[(string) $event->id] = [$event->kind, $event->version, $event->status, $event->assigned_user_id, $event->connection_id];
        }
        ksort($revisions);

        return $revisions;
    }

    /** A checkpoint exists only in this request; it is not a reusable access grant. */
    private function admitSources(Tenant $tenant, User $user, TeamArea $area, array $snapshot, ?array $eventIds = null): array
    {
        $this->assertSourceContext($tenant, $snapshot);
        $versions = $snapshot['source_versions'] ?? [];
        $ids = $eventIds === null ? array_keys($versions) : array_values(array_unique($eventIds));
        abort_unless(count(array_diff($ids, array_keys($versions))) === 0, 409, 'Report sources changed. Refresh the comparison.');
        $events = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('id', $ids)->where('status', '!=', 'withdrawn')->get();
        abort_unless($events->count() === count($ids), 409, 'Event sources changed. Refresh the comparison.');
        $checkpoint = ['events' => $this->eventRevisions($events), 'connections' => $this->connectionRevision($tenant, $area)];
        // A few exact task reads avoid loading an entire project for one event.
        // Broad cohorts still verify a fresh project inventory, including off-page sources.
        $available = $this->access->readCollection($tenant, $user, $area, function () use ($tenant, $user, $events): bool {
            foreach ($events as $event) {
                $this->access->record($tenant, $user, $event);
            }

            return $this->access->collectionSourcesAvailable();
        }, count($ids) > 8);
        $this->finishAdmission($tenant, $user, $area, $snapshot, $checkpoint);
        abort_unless($available, 503, 'Event sources could not be checked. Try again.');

        return $checkpoint;
    }

    private function assertCustomerAdmission(Tenant $tenant, User $user, TeamArea $area, array $snapshot): void
    {
        abort_unless($this->mayReadCustomers($tenant, $user, $area)
            && ($snapshot['customer_access'] ?? false)
            && hash_equals((string) ($snapshot['customer_signature'] ?? ''), (string) $this->snapshotCustomerSignature($tenant, $area, $snapshot)),
            409, 'Customer identities or access changed while reporting was read.');
    }

    private function captureCustomerFrame(Tenant $tenant, TeamArea $area, ?array $scope = null, bool $witnesses = false): ?array
    {
        try {
            return $this->customerDependencies->capture($tenant, $area, $scope, $witnesses);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }

            Log::notice('Event reporting customer evidence withheld', ['tenant_id' => (int) $tenant->id, 'area_id' => (string) $area->id,
                'phase' => 'dependency_frame', 'witnesses' => $witnesses, 'reason_fingerprint' => hash('sha256', $error->getMessage())]);

            return null;
        }
    }

    private function snapshotCustomerSignature(Tenant $tenant, TeamArea $area, array $snapshot): ?string
    {
        if (($snapshot['customer_scope']['version'] ?? null) === EventCustomerDependencies::VERSION) {
            return $this->captureCustomerFrame($tenant, $area, $snapshot['customer_scope'])['signature'] ?? null;
        }

        // Older encrypted generations retain their original conservative directory fence.
        return $this->customerSignature($tenant);
    }

    private function validateCustomerFact(callable $validate): bool
    {
        try {
            $validate();

            return true;
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
            if ($error->getStatusCode() !== 409) {
                throw $error;
            }

            Log::notice('Event reporting customer evidence withheld', ['phase' => 'purchase_validation', 'reason_fingerprint' => hash('sha256', $error->getMessage())]);

            return false;
        }
    }

    private function finishAdmission(Tenant $tenant, User $user, TeamArea $area, array $snapshot, array $checkpoint): void
    {
        $this->authorize($tenant, $user, $area);
        $this->assertSourceContext($tenant, $snapshot);
        abort_unless(hash_equals($checkpoint['connections'], $this->connectionRevision($tenant, $area)), 409, 'Source access changed while reporting was read.');
        $events = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('id', array_keys($checkpoint['events']))
            ->where('status', '!=', 'withdrawn')->get(['id', 'kind', 'version', 'status', 'assigned_user_id', 'connection_id']);
        abort_unless($checkpoint['events'] === $this->eventRevisions($events), 409, 'Event sources changed while reporting was read.');
    }

    private function snapshot(Tenant $tenant, User $user, TeamArea $area): ?TeamOperationRecord
    {
        return TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'kind' => 'performance_snapshot', 'source_key' => $this->key($tenant, $user, $area), 'assigned_user_id' => $user->id])->first();
    }

    private function key(Tenant $tenant, User $user, TeamArea $area): string
    {
        return hash('sha256', implode('|', [(string) config('pos_live.runtime_id', ''), $tenant->id, $area->id, $user->id]));
    }

    public function signature(Tenant $tenant, TeamArea $area, bool $fresh = false): string
    {
        $records = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('kind', ['event', 'event_link', 'financial', 'staff', 'series'])->orderBy('id')->get(['id', 'version', 'status', 'assigned_user_id', 'connection_id'])->toArray();
        $sources = \App\Models\TeamProviderConnection::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->orderBy('id')->get(['id', 'user_id', 'generation', 'status'])->toArray();
        $scope = $this->squareSourceScope($tenant, $area, $records);
        // A new mapping or receipt ancestor cannot reuse a previous scope's revision.
        $cacheKey = 'event-performance-source-revision:'.self::DIGEST_VERSION.':'.hash('sha256', json_encode([config('pos_live.runtime_id', ''), $tenant->id, $area->id, $records, $sources, $scope], JSON_THROW_ON_ERROR));
        $revisions = $fresh ? null : Cache::get($cacheKey);
        if (! is_array($revisions)) {
            $revisions = [];
            foreach (['square_orders', 'square_payments', 'pos_live_sales', 'pos_live_events', 'marketing_import_runs'] as $table) {
                if (Schema::hasTable($table)) {
                    $columns = match ($table) {
                        'square_orders' => ['id', 'square_order_id', 'square_customer_id', 'location_id', 'state', 'total_money_amount', 'total_money_currency', 'closed_at', 'raw_payload'],
                        'square_payments' => ['id', 'square_payment_id', 'square_order_id', 'square_customer_id', 'location_id', 'amount_money', 'currency', 'status', 'raw_payload'],
                        'pos_live_sales' => ['id', 'runtime_id', 'actor_id', 'state', 'snapshot_hash', 'provider_order_id', 'amount_minor', 'currency', 'created_at'],
                        'pos_live_events' => ['id', 'runtime_id', 'sale_id', 'code', 'details'],
                        default => ['id', 'type', 'status', 'summary', 'finished_at'],
                    };
                    $revisions[$table] = $this->tableDigest($table, $tenant->id, $columns, $scope);
                }
            }
            Cache::put($cacheKey, $revisions, 30);
        }

        return hash('sha256', json_encode([self::REPORT_VERSION, self::DIGEST_VERSION, $records, $sources, $revisions, $this->squareContextSignature($tenant)], JSON_THROW_ON_ERROR));
    }

    /** Only reviewed report inputs can invalidate this reporting generation. */
    private function squareSourceScope(Tenant $tenant, TeamArea $area, array $versions): array
    {
        $key = 'event-performance-square-scope:'.self::DIGEST_VERSION.':'.hash('sha256', json_encode([$tenant->id, $area->id, $versions], JSON_THROW_ON_ERROR));
        $scope = Cache::remember($key, 300, function () use ($tenant, $area): array {
            $scope = ['windows' => [], 'order_ids' => [], 'run_ids' => []];
            foreach (TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])
                ->whereIn('kind', ['event', 'event_link'])->where('status', '!=', 'withdrawn')->get(['kind', 'status', 'data']) as $record) {
                if ($record->kind === 'event') {
                    $scope['order_ids'] = [...$scope['order_ids'], ...($record->data['_teams_enrichment']['square_orders']['ids'] ?? [])];

                    continue;
                }
                if ($record->status !== 'accepted') {
                    continue;
                }
                foreach ($record->data['square_scopes'] ?? [] as $window) {
                    $timezone = $record->data['timezone'] ?? 'UTC';
                    $scope['windows'][] = ['location_id' => $window['location_id'],
                        'from' => CarbonImmutable::parse($window['starts_on'], $timezone)->startOfDay()->utc()->toDateTimeString(),
                        'through' => CarbonImmutable::parse($window['ends_on'], $timezone)->addDay()->startOfDay()->utc()->addHours(72)->toDateTimeString()];
                    $scope['run_ids'] = [...$scope['run_ids'], $window['orders_run_id'], $window['payments_run_id']];
                }
            }
            $scope['order_ids'] = array_values(array_unique($scope['order_ids']));
            $scope['run_ids'] = array_values(array_unique($scope['run_ids']));

            return $scope;
        });
        // Receipt ancestry is checked freshly; a deep pagination chain must not
        // turn every warm read into one network round trip per original page.
        $scope['run_ids'] = $this->receiptAncestors($tenant, $scope['run_ids']);

        return $scope;
    }

    private function receiptAncestors(Tenant $tenant, array $roots): array
    {
        $seen = array_fill_keys($roots, true);
        abort_if(count($seen) > 10000, 409, 'Original Square pagination needs review.');
        if (! $roots) {
            return [];
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            $parent = "CAST(JSON_UNQUOTE(JSON_EXTRACT(receipt.summary, '$.resumed_from_run_id')) AS UNSIGNED)";
            // DISTINCT terminates cycles. Keep original/missing IDs in the
            // revision too, so later insertion or changed ancestry invalidates it.
            $rows = DB::select('WITH RECURSIVE report_receipt_ancestry AS ('
                .'SELECT receipt.id, '.$parent.' AS parent_id FROM marketing_import_runs AS receipt '
                .'WHERE receipt.tenant_id = ? AND receipt.id IN ('.implode(',', array_fill(0, count($roots), '?')).') '
                .'UNION DISTINCT SELECT receipt.id, '.$parent.' AS parent_id FROM marketing_import_runs AS receipt '
                .'INNER JOIN report_receipt_ancestry AS child ON receipt.id = child.parent_id '
                .'WHERE receipt.tenant_id = ? LIMIT 10001) '
                .'SELECT /*+ SET_VAR(cte_max_recursion_depth=10001) */ id, parent_id FROM report_receipt_ancestry',
                [$tenant->id, ...$roots, $tenant->id]);
            foreach ($rows as $row) {
                $seen[(int) $row->id] = true;
                if ($row->parent_id) {
                    $seen[(int) $row->parent_id] = true;
                }
            }
            abort_if(count($seen) > 10000, 409, 'Original Square pagination needs review.');
        } else {
            $pending = $roots;
            $visited = [];
            while ($pending) {
                $ids = array_values(array_diff($pending, array_keys($visited)));
                if (! $ids) {
                    break;
                }
                $pending = [];
                foreach ($ids as $id) {
                    $visited[$id] = true;
                }
                foreach (\App\Models\MarketingImportRun::forTenantId($tenant->id)->whereIn('id', $ids)->get(['summary']) as $run) {
                    if ($parent = (int) ($run->summary['resumed_from_run_id'] ?? 0)) {
                        $pending[] = $parent;
                        $seen[$parent] = true;
                    }
                }
                abort_if(count($seen) > 10000, 409, 'Original Square pagination needs review.');
            }
        }
        $ids = array_keys($seen);
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    private function squareContextSignature(Tenant $tenant): string
    {
        $rows = app(\App\Services\Marketing\SquareConnectionContext::class)->available($tenant->id);
        $public = array_map(fn ($row) => array_intersect_key($row, array_flip(['id', 'kind', 'merchant_id', 'environment', 'location_id', 'location_ids', 'connection_generation', 'status'])), $rows);
        usort($public, fn ($a, $b) => strcmp((string) ($a['id'] ?? ''), (string) ($b['id'] ?? '')));

        return hash('sha256', json_encode($public, JSON_THROW_ON_ERROR));
    }

    public function customerSignature(Tenant $tenant): string
    {
        $parts = [];
        foreach (['marketing_profiles', 'marketing_profile_links'] as $table) {
            if (Schema::hasTable($table)) {
                $columns = $table === 'marketing_profiles'
                    ? ['id', 'merged_into_profile_id', 'archived_at', 'source_channels']
                    : ['id', 'marketing_profile_id', 'source_type', 'source_id', 'source_meta', 'match_method', 'confidence'];
                $parts[] = $this->tableDigest($table, $tenant->id, $columns);
            }
        }

        return hash('sha256', json_encode([self::DIGEST_VERSION, $parts], JSON_THROW_ON_ERROR));
    }

    /** Content revisions catch same-second edits and delete/reinsert pairs. Never emit private bytes. */
    private function tableDigest(string $table, int $tenant, array $columns, ?array $scope = null): string
    {
        $requestedColumns = $columns;
        $availableColumns = Schema::getColumnListing($table);
        $columns = array_values(array_intersect($columns, $availableColumns));
        $indexed = $this->indexedDigest($table, $requestedColumns, $availableColumns);
        $query = DB::table($table)->where('tenant_id', $tenant);
        if ($scope !== null && in_array($table, ['square_orders', 'square_payments'], true)) {
            $orders = DB::table('square_orders')->where('tenant_id', $tenant)->where(function (Builder $query) use ($scope): void {
                $query->whereIn('square_order_id', $scope['order_ids']);
                foreach ($scope['windows'] as $window) {
                    $query->orWhere(fn (Builder $q) => $q->where('location_id', $window['location_id'])
                        ->where('closed_at', '>=', $window['from'])->where('closed_at', '<', $window['through']));
                }
            });
            if ($indexed !== null) {
                $orderIndex = $scope['windows'] ? 'epo_window_hash_idx' : 'epo_order_hash_idx';
                if ($table === 'square_orders' || Schema::hasIndex('square_orders', $orderIndex)) {
                    $orders->forceIndex($orderIndex);
                }
            }
            $table === 'square_orders' ? $query = $orders : $query->whereIn('square_order_id', $orders->select('square_order_id'));
        } elseif ($scope !== null && $table === 'marketing_import_runs') {
            $query->whereIn('id', $scope['run_ids']);
        }
        if ($indexed !== null) {
            // Older tenant/order indexes can otherwise force virtual-hash
            // recomputation from the base JSON despite a covering index existing.
            $query->forceIndex($table === 'square_orders'
                ? (! empty($scope['windows']) ? 'epo_window_hash_idx' : 'epo_order_hash_idx')
                : $indexed['indexes'][0]);
            // InnoDB's covering index contains the generated 32-byte content hash
            // and primary id. No original document is read or hashed on this path.
            $fields = ['`id`'];
            if ($indexed['timestamp'] !== null) {
                $fields[] = 'UNIX_TIMESTAMP(`'.$indexed['timestamp'].'`)';
            }
            $fields[] = 'HEX(`event_content_hash`)';
            $rowHash = 'UNHEX(SHA2(JSON_ARRAY('.implode(',', $fields).'),256))';
            $revision = (array) $query->selectRaw('COUNT(*) as n, HEX(BIT_XOR('.$rowHash.')) as h')->first();

            return hash('sha256', json_encode([self::DIGEST_VERSION, $revision], JSON_THROW_ON_ERROR));
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            // Four independent 64-bit XOR lanes retain the full SHA-256 row digest.
            // Hashing stays inside MySQL; source payloads never cross into web memory.
            $hash = 'SHA2(JSON_ARRAY('.implode(',', array_map(fn ($column) => '`'.$column.'`', $columns)).'),256)';
            // LIMIT prevents the derived table being merged, so each large source
            // document is hashed once, rather than once for each XOR lane.
            $digests = $query->selectRaw($hash.' as content_digest')->limit(PHP_INT_MAX);
            $lanes = [];
            foreach ([1, 17, 33, 49] as $index => $offset) {
                $lanes[] = 'BIT_XOR(CAST(CONV(SUBSTRING(content_digest,'.$offset.',16),16,10) AS UNSIGNED)) AS h'.$index;
            }

            return hash('sha256', json_encode((array) DB::query()->fromSub($digests, 'source_digests')->selectRaw('COUNT(*) as n,'.implode(',', $lanes))->first(), JSON_THROW_ON_ERROR));
        }
        $context = hash_init('sha256');
        foreach ($query->orderBy('id')->select($columns)->cursor() as $row) {
            hash_update($context, hash('sha256', json_encode((array) $row, JSON_THROW_ON_ERROR)));
        }

        return hash_final($context);
    }

    /** Partial online upgrades and future field additions retain the complete fallback. */
    private function indexedDigest(string $table, array $requested, array $available): ?array
    {
        if ($table === 'marketing_profile_links' && array_intersect($requested, ['event_capture_present', 'event_attribution_present'])) {
            // These optional fields repeat exact source_meta path presence. Do
            // not omit unknown, ordinary or schema-drifted requested fields.
            $requested = array_values(array_diff($requested, app(EventCustomerAnchorSchema::class)->redundantColumns(DB::connection())));
        }
        $definition = self::INDEXED_DIGESTS[$table] ?? null;
        if (DB::connection()->getDriverName() !== 'mysql' || $definition === null
            || $requested !== $definition['columns']
            || array_diff($definition['columns'], $available)
            || ! in_array('event_content_hash', $available, true)) {
            return null;
        }
        $indexes = array_column(Schema::getIndexes($table), 'name');

        return array_diff($definition['indexes'], $indexes) ? null : $definition;
    }

    private function facts(Tenant $tenant, TeamArea $area, string $generation): Builder
    {
        return DB::table(self::TABLE)->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'generation' => $generation]);
    }

    private function filtered(Tenant $tenant, TeamArea $area, string $generation, array $filters, string $kind): Builder
    {
        $q = $this->facts($tenant, $area, $generation)->where('kind', $kind)->where('local_date', '<=', $filters['to']);
        in_array($kind, ['event', 'team_member', 'customer_lifetime'], true) ? $q->where('ends_on', '>=', $filters['from']) : $q->where('local_date', '>=', $filters['from']);
        if (! empty($filters['event_id'])) {
            $q->where('event_id', $filters['event_id']);
        }
        foreach (['team_member_id' => 'team_member', 'customer_id' => 'customer'] as $filter => $dimension) {
            if (! empty($filters[$filter])) {
                if ($dimension === $kind || $dimension === 'customer' && $kind === 'customer_lifetime') {
                    $q->where('group_key', hash('sha256', (string) $filters[$filter]));
                }
                $ids = $this->facts($tenant, $area, $generation)->where('kind', $dimension)->where('group_key', hash('sha256', (string) $filters[$filter]))->select('event_id');
                $q->whereIn('event_id', $ids);
            }
        }
        foreach ($this->words((string) ($filters['q'] ?? '')) as $word) {
            $q->whereJsonContains('search_tokens', $this->searchToken($tenant->id, $word));
        }

        return $q;
    }

    private function weekendQuery(Builder $query, array $filters): Builder
    {
        $driver = DB::connection()->getDriverName();
        $start = (int) $filters['weekend_start'];
        $days = (int) $filters['weekend_days'];
        $weekday = $driver === 'sqlite' ? "CAST(strftime('%w',local_date) AS INTEGER)" : 'DAYOFWEEK(local_date)-1';
        $offset = '(('.$weekday.'-'.$start.'+7)%7)';
        $key = $driver === 'sqlite' ? "date(local_date, '-' || ".$offset." || ' days')" : 'DATE_SUB(local_date, INTERVAL '.$offset.' DAY)';

        return DB::query()->fromSub($query->whereRaw($offset.' < ?', [$days])->selectRaw('*, '.$key.' as weekend_key'), 'weekend_days');
    }

    private function summary(?object $row): array
    {
        $known = (int) ($row->known_net_sales_minor ?? 0);
        $days = (int) ($row->selling_days ?? 0);
        $complete = (bool) ($row->complete ?? false);
        $net = $complete && $row?->net_sales_minor !== null ? (int) $row->net_sales_minor : null;

        return ['event_count' => (int) ($row->event_count ?? 0), 'sale_count' => (int) ($row->sale_count ?? 0),
            'net_sales_minor' => $net, 'known_net_sales_minor' => $known, 'customer_count' => null,
            'selling_days' => $days, 'average_day_net_sales_minor' => $net !== null && $days > 0 ? (int) round($net / $days) : null, 'complete' => $complete];
    }

    private function emptySummary(): array
    {
        return ['event_count' => 0, 'sale_count' => 0, 'net_sales_minor' => null, 'known_net_sales_minor' => 0, 'customer_count' => null, 'selling_days' => 0, 'average_day_net_sales_minor' => null, 'complete' => false];
    }

    private function eventFacts(int $tenant, array $summary, string $event): array
    {
        if (empty($summary['starts_on'])) {
            return [];
        }
        $base = ['event_id' => $event, 'local_date' => $summary['starts_on'], 'ends_on' => $summary['ends_on'] ?? $summary['starts_on'],
            'net_sales_minor' => $summary['net_sales_minor'] ?? null, 'known_net_sales_minor' => $summary['known_net_sales_minor'] ?? 0,
            'paid_total_minor' => null, 'sale_count' => $summary['sale_count'] ?? 0, 'selling_days' => $summary['selling_days'] ?? 0, 'complete' => $summary['complete'] ?? false, 'customer_id' => null];
        $facts = [$this->fact($tenant, 'event', $event, $event, $summary['title'], $base)];
        foreach ($summary['daily'] ?? [] as $day) {
            $complete = ($day['complete'] ?? false) && ($summary['source_coverage_complete'] ?? false) && ! ($day['partial'] ?? false);
            $facts[] = $this->fact($tenant, 'day', $event.':'.$day['date'], $event, $summary['title'], array_replace($base, [
                'local_date' => $day['date'], 'ends_on' => $day['date'], 'net_sales_minor' => $complete ? ($day['net_sales_minor'] ?? null) : null,
                'known_net_sales_minor' => $day['net_sales_minor'] ?? 0, 'sale_count' => $day['sale_count'] ?? 0,
                'selling_days' => 1, 'complete' => $complete]));
        }
        $staff = [];
        foreach ($summary['staff'] ?? [] as $person) {
            if (($person['identity_review_required'] ?? false) || str_starts_with((string) $person['id'], 'unlinked:')) {
                continue;
            }
            $staff[(string) $person['id']] = true;
            $facts[] = $this->fact($tenant, 'team_member', $event.':'.$person['id'], (string) $person['id'], $person['name'], $base);
        }
        foreach ($summary['operators'] ?? [] as $operator) {
            $id = 'user:'.$operator['user_id'];
            $name = $operator['name'] ?? 'Recorded operator';
            if (! isset($staff[$id])) {
                $facts[] = $this->fact($tenant, 'team_member', $event.':'.$id, $id, $name, array_replace($base,
                    ['net_sales_minor' => null, 'known_net_sales_minor' => 0, 'sale_count' => 0, 'selling_days' => 0, 'complete' => false]));
            }
            $facts[] = $this->fact($tenant, 'operator', $event.':'.$id, $id, $name, array_replace($base,
                ['net_sales_minor' => $operator['net_sales_minor'] ?? null, 'known_net_sales_minor' => $operator['known_net_sales_minor'] ?? 0,
                    'sale_count' => $operator['sale_count'] ?? 0, 'complete' => $operator['complete'] ?? false, 'selling_days' => 0]));
        }

        return $facts;
    }

    private function customerFacts(int $tenant, array $summary, string $event, int $commerce, MarketingProfile $profile, array $value, array $relations, array $frame): array
    {
        $facts = [];
        $label = trim($profile->first_name.' '.$profile->last_name) ?: 'Customer';
        $seen = [];
        foreach ($value['purchase_journey'] ?? [] as $purchase) {
            if (! ($purchase['confirmed_purchase'] ?? false) || (int) data_get($purchase, 'purchase_event.event.id', 0) !== $commerce
                || strtolower($purchase['currency'] ?? '') !== 'usd' || isset($seen[$purchase['purchase_id']])) {
                continue;
            }
            $this->customerDependencies->assertPurchase($frame, $event, $profile, $purchase, $summary['timezone'] ?? 'UTC');
            $seen[$purchase['purchase_id']] = true;
            $date = CarbonImmutable::parse($purchase['occurred_at'])->setTimezone($summary['timezone'] ?? 'UTC')->toDateString();
            $paid = isset($purchase['refunded_minor']) ? $purchase['amount_minor'] - $purchase['refunded_minor'] : null;
            $facts[] = $this->fact($tenant, 'customer', $purchase['purchase_id'], (string) $profile->id, $label, [
                'event_id' => $event, 'customer_id' => $profile->id, 'local_date' => $date, 'ends_on' => $date,
                'net_sales_minor' => null, 'known_net_sales_minor' => 0, 'paid_total_minor' => $paid, 'sale_count' => 1, 'selling_days' => 0, 'complete' => false]);
        }
        if (! $facts && isset($relations['captured'])) {
            $this->customerDependencies->assertCapture($frame, $event, $profile);
            $date = $summary['starts_on'] ?? null;
            if ($date) {
                $facts[] = $this->fact($tenant, 'customer', 'capture:'.$event.':'.$profile->id, (string) $profile->id, $label,
                    ['event_id' => $event, 'customer_id' => $profile->id, 'local_date' => $date, 'ends_on' => $date, 'net_sales_minor' => null,
                        'known_net_sales_minor' => 0, 'paid_total_minor' => 0, 'sale_count' => 0, 'selling_days' => 0, 'complete' => false]);
            }
        }

        return $facts;
    }

    private function normalizedCustomerFact(int $tenant, array $summary, string $event, MarketingProfile $profile, array $purchase): array
    {
        $date = CarbonImmutable::parse($purchase['occurred_at'])->setTimezone($summary['timezone'] ?? 'UTC')->toDateString();
        $paid = isset($purchase['refunded_minor']) ? $purchase['amount_minor'] - $purchase['refunded_minor'] : null;

        return $this->fact($tenant, 'customer', $purchase['source'].':'.$purchase['source_id'], (string) $profile->id, trim($profile->first_name.' '.$profile->last_name) ?: 'Customer', [
            'event_id' => $event, 'customer_id' => $profile->id, 'local_date' => $date, 'ends_on' => $date,
            'net_sales_minor' => $purchase['net_sales_minor'] ?? null, 'known_net_sales_minor' => $purchase['net_sales_minor'] ?? 0,
            'paid_total_minor' => $paid, 'sale_count' => 1, 'selling_days' => 0, 'complete' => $purchase['net_sales_minor'] !== null]);
    }

    private function fact(int $tenant, string $kind, string $source, string $id, string $label, array $metrics): array
    {
        $tokens = [];
        foreach ($this->words($label) as $word) {
            for ($length = 1; $length <= min(30, strlen($word)); $length++) {
                $tokens[] = $this->searchToken($tenant, substr($word, 0, $length));
            }
        }

        return ['kind' => $kind, 'source_key' => hash('sha256', $source), 'group_key' => hash('sha256', $id),
            'search_tokens' => json_encode(array_values(array_unique($tokens)), JSON_THROW_ON_ERROR),
            'payload' => Crypt::encryptString(json_encode(['id' => $id, 'label' => $label], JSON_THROW_ON_ERROR)), ...$metrics];
    }

    private function words(string $text): array
    {
        return array_values(array_filter(preg_split('/[^a-z0-9]+/', strtolower(Str::ascii(trim($text)))) ?: []));
    }

    private function searchToken(int $tenant, string $prefix): string
    {
        return hash_hmac('sha256', $tenant.'|'.$prefix, (string) config('app.key'));
    }

    private function insertFacts(Tenant $tenant, TeamArea $area, string $generation, array $facts): void
    {
        foreach (array_chunk($facts, 100) as $chunk) {
            DB::table(self::TABLE)->insert(array_map(fn ($fact) => ['tenant_id' => $tenant->id, 'area_id' => $area->id, 'generation' => $generation, ...$fact], $chunk));
        }
    }
}
