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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/** Rebuildable, issuer-scoped reports, never a purchase ledger or an authorization cache. */
class EventPerformance
{
    public const TABLE = 'team_event_performance_facts';

    public function __construct(private OperationsAccess $access) {}

    public static function rules(): array
    {
        return ['area' => 'required|uuid', 'group_by' => 'sometimes|in:event,team_member,customer,weekend',
            'from' => 'sometimes|date_format:Y-m-d', 'to' => 'sometimes|date_format:Y-m-d|after_or_equal:from',
            'q' => 'sometimes|nullable|string|max:100', 'page' => 'sometimes|integer|min:1|max:100000',
            'sort' => 'sometimes|in:date,net_sales', 'event_id' => 'sometimes|uuid',
            'team_member_id' => 'sometimes|string|max:160', 'customer_id' => 'sometimes|integer|min:1',
            'weekend_start' => 'sometimes|integer|min:0|max:6', 'weekend_days' => 'sometimes|integer|min:1|max:7'];
    }

    public function read(Tenant $tenant, User $user, TeamArea $area, array $input = []): array
    {
        $this->authorize($tenant, $user, $area);
        $filters = array_replace(['group_by' => 'event', 'from' => now()->startOfYear()->toDateString(), 'to' => now()->toDateString(),
            'q' => '', 'sort' => 'date', 'page' => 1, 'weekend_start' => 5, 'weekend_days' => 3], $input);
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
        $this->admitSources($tenant, $user, $area, $data);
        // A changed canonical directory invalidates customer dimensions immediately, before refresh.
        // Event totals may still be shown explicitly as stale; private former aliases are withheld.
        if ($customers && (! ($data['customer_access'] ?? false) || ($data['customer_signature'] ?? '') !== $this->customerSignature($tenant))) {
            $customers = false;
            if ($filters['group_by'] === 'customer' || isset($filters['customer_id'])) {
                $result['status'] = 'preparing';
                $result['review_items'][] = 'Customer identities changed. Preparing the current customer comparison.';

                return $result;
            }
        }
        $generation = $data['generation'];
        $kind = match ($filters['group_by']) {
            'team_member' => 'team_member', 'customer' => 'customer', 'weekend' => 'day', default => 'event'
        };
        $matching = $this->filtered($tenant, $area, $generation, $filters, $kind);
        $base = $this->filtered($tenant, $area, $generation, array_replace($filters, ['q' => '']), 'event')
            ->whereIn('event_id', (clone $matching)->select('event_id'));
        if ($kind === 'day') {
            $base = $this->weekendQuery(clone $matching, $filters);
        }
        $summary = (clone $base)->selectRaw('COUNT(DISTINCT event_id) as event_count, COALESCE(SUM(sale_count),0) as sale_count, SUM(net_sales_minor) as net_sales_minor, COALESCE(SUM(known_net_sales_minor),0) as known_net_sales_minor, COALESCE(SUM(selling_days),0) as selling_days, MIN(complete) as complete')->first();
        $result['summary'] = $this->summary($summary);
        if ($customers) {
            $customerFacts = $this->filtered($tenant, $area, $generation, $filters, 'customer');
            if ($kind === 'day') {
                $customerFacts = $this->weekendQuery($customerFacts, $filters);
            }
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
        $groups = $query->selectRaw($groupColumn.' as dimension_key, MIN(id) as first_id, COUNT(DISTINCT event_id) as event_count, COALESCE(SUM(sale_count),0) as sale_count, SUM(net_sales_minor) as net_sales_minor, COALESCE(SUM(known_net_sales_minor),0) as known_net_sales_minor, CASE WHEN COUNT(paid_total_minor) = COUNT(*) THEN SUM(paid_total_minor) ELSE NULL END as paid_total_minor, COALESCE(SUM(selling_days),0) as selling_days, MIN(complete) as complete, MIN(local_date) as starts_on, MAX(ends_on) as ends_on')->groupBy($groupColumn);
        $result['total_rows'] = DB::query()->fromSub(clone $groups, 'grouped_events')->count();
        $order = $filters['sort'] === 'net_sales' ? ($kind === 'customer' ? 'paid_total_minor' : 'known_net_sales_minor') : 'starts_on';
        $rows = $groups->orderByDesc($order)->orderBy('dimension_key')->offset(($filters['page'] - 1) * 50)->limit(50)->get();
        $payloads = $this->facts($tenant, $area, $generation)->whereIn('id', $rows->pluck('first_id'))->pluck('payload', 'id');
        foreach ($rows as $row) {
            $payload = json_decode(Crypt::decryptString($payloads[$row->first_id]), true, 64, JSON_THROW_ON_ERROR);
            $id = $kind === 'day' ? $row->dimension_key : $payload['id'];
            $label = $kind === 'day' ? 'Weekend of '.CarbonImmutable::parse($row->dimension_key)->format('M j, Y') : $payload['label'];
            if ($kind === 'customer') {
                $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $id);
                abort_unless($profile && ! $profile->archived_at && (int) $profile->id === (int) $id, 409, 'Customer identity changed. Refresh reporting.');
                $label = trim($profile->first_name.' '.$profile->last_name) ?: 'Customer';
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
                $operator = $this->filtered($tenant, $area, $generation, $filters, 'operator')->where('group_key', $row->dimension_key)
                    ->selectRaw('COUNT(*) as n, SUM(net_sales_minor) as net_sales_minor, SUM(known_net_sales_minor) as known_net_sales_minor, SUM(sale_count) as sale_count, MIN(complete) as complete')->first();
                if ($operator->n > 0) {
                    $direct = ['direct_net_sales_minor' => $operator->complete ? (int) $operator->net_sales_minor : null,
                        'direct_known_net_sales_minor' => (int) $operator->known_net_sales_minor, 'direct_sale_count' => (int) $operator->sale_count, 'direct_complete' => (bool) $operator->complete];
                }
            }
            $result['rows'][] = ['id' => (string) $id, 'label' => $label, ...$metrics,
                'paid_total_minor' => $row->paid_total_minor === null ? null : (int) $row->paid_total_minor,
                'starts_on' => $row->starts_on, 'ends_on' => $row->ends_on,
                'event_ids' => $eventIds->distinct()->limit(1000)->pluck('event_id')->all(),
                'href' => $kind === 'customer' ? route('customers.profile', ['profile' => $id, 'tenant' => $tenant->slug], false) : null,
                'attribution_basis' => $kind === 'team_member' ? 'Results of events worked; individual sales credit requires original operator evidence.' : ($kind === 'customer' ? 'Confirmed event purchases; spending includes tax after known refunds.' : 'Reviewed event sales'),
                ...$direct];
        }
        $result['has_more'] = $result['total_rows'] > $filters['page'] * 50;

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
            $customerSignature = $this->customerSignature($tenant);
            $customerAccess = $this->mayReadCustomers($tenant, $user, $area);
            $customerInvalidated = false;
            $current = $this->snapshot($tenant, $user, $area);
            $existing = $current?->data ?? [];
            if (isset($existing['generation']) && ($existing['signature'] ?? '') === $signature
                && ($existing['customer_signature'] ?? '') === $customerSignature && ($existing['customer_access'] ?? false) === $customerAccess
                && CarbonImmutable::parse($existing['built_at'] ?? $existing['as_of'])->gt(now()->subMinutes(5))) {
                $this->admitSources($tenant, $user, $area, $existing);
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
                $this->access->readCollection($tenant, $user, $area, function () use ($tenant, $user, $area, $events, $linkByEvent, $generation, &$customerAccess, &$customerInvalidated, &$facts, &$versions, &$issues, &$histories): void {
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
                                foreach ($this->customerFacts($tenant->id, $summary, $event->id, $commerce, $profile, $value, $cohort['relations'][$id] ?? []) as $fact) {
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
                if ($customerInvalidated || $customerSignature !== $this->customerSignature($tenant)) {
                    // A concurrent identity import must not block event totals.
                    // Withhold every customer fact; reads still compare the old
                    // signature freshly and return preparing for that dimension.
                    $this->facts($tenant, $area, $generation)->where('kind', 'customer')->delete();
                    $customerAccess = false;
                    $issues[] = 'Customer identities changed. Preparing the current customer comparison.';
                }
                $snapshot = DB::transaction(function () use ($tenant, $user, $area, $generation, $signature, $customerSignature, $versions, $issues, $customerAccess, $squareBindings): TeamOperationRecord {
                    TeamArea::where('tenant_id', $tenant->id)->whereKey($area->id)->lockForUpdate()->firstOrFail();
                    $this->authorize($tenant, $user, $area);
                    $snapshot = $this->snapshot($tenant, $user, $area) ?? new TeamOperationRecord(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'area_id' => $area->id,
                        'kind' => 'performance_snapshot', 'source_key' => $this->key($tenant, $user, $area), 'assigned_user_id' => $user->id, 'visibility' => 'admin', 'title' => 'Event performance']);
                    $retired = $snapshot->data['retired_generations'] ?? [];
                    if ($old = ($snapshot->data['generation'] ?? null)) {
                        $retired[] = ['id' => $old, 'retired_at' => now()->toIso8601String()];
                    }
                    $snapshot->fill(['status' => 'ready', 'version' => ($snapshot->version ?? 0) + 1, 'data' => [
                        'generation' => $generation, 'signature' => $signature, 'customer_signature' => $customerSignature,
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

    private function admitSources(Tenant $tenant, User $user, TeamArea $area, array $snapshot): void
    {
        abort_unless(hash_equals((string) ($snapshot['runtime'] ?? ''), (string) config('pos_live.runtime_id', '')), 409);
        abort_unless(hash_equals((string) ($snapshot['square_context_signature'] ?? ''), $this->squareContextSignature($tenant)), 409, 'Original Square access changed. Refresh the report after reviewing its source connection.');
        foreach ($snapshot['square_bindings'] ?? [] as $binding) {
            app(\App\Services\Marketing\SquareConnectionContext::class)->assertCurrent($tenant->id, $binding);
        }
        $versions = $snapshot['source_versions'] ?? [];
        $events = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('id', array_keys($versions))->where('status', '!=', 'withdrawn')->get();
        abort_unless($events->count() === count($versions), 409, 'Event sources changed. Refresh the comparison.');
        $this->access->readCollection($tenant, $user, $area, function () use ($tenant, $user, $events): void {
            foreach ($events as $event) {
                $this->access->record($tenant, $user, $event);
            }
        }, true);
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
        $cacheKey = 'event-performance-source-revision:'.hash('sha256', config('pos_live.runtime_id', '').'|'.$tenant->id.'|'.$area->id);
        $revisions = $fresh ? null : Cache::get($cacheKey);
        if (! is_array($revisions)) {
            $scope = $this->squareSourceScope($tenant, $area, $records);
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

        return hash('sha256', json_encode([$records, $sources, $revisions, $this->squareContextSignature($tenant)], JSON_THROW_ON_ERROR));
    }

    /** Only reviewed report inputs can invalidate this reporting generation. */
    private function squareSourceScope(Tenant $tenant, TeamArea $area, array $versions): array
    {
        $key = 'event-performance-square-scope:'.hash('sha256', json_encode([$tenant->id, $area->id, $versions], JSON_THROW_ON_ERROR));
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
        // Original receipt ancestry is small and checked freshly. Unrelated
        // scheduled/backfill runs are not evidence used by this report.
        $pending = $scope['run_ids'];
        $seen = [];
        while ($pending) {
            $ids = array_values(array_diff($pending, array_keys($seen)));
            if (! $ids) {
                break;
            }
            abort_if(count($seen) + count($ids) > 10000, 409, 'Original Square pagination needs review.');
            $pending = [];
            foreach ($ids as $id) {
                $seen[$id] = true;
            }
            foreach (\App\Models\MarketingImportRun::forTenantId($tenant->id)->whereIn('id', $ids)->get(['summary']) as $run) {
                if ($parent = (int) ($run->summary['resumed_from_run_id'] ?? 0)) {
                    $pending[] = $parent;
                }
            }
        }
        $scope['run_ids'] = array_keys($seen);

        return $scope;
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

        return hash('sha256', json_encode($parts, JSON_THROW_ON_ERROR));
    }

    /** Content revisions catch same-second edits and delete/reinsert pairs. Never emit private bytes. */
    private function tableDigest(string $table, int $tenant, array $columns, ?array $scope = null): string
    {
        $columns = array_values(array_intersect($columns, Schema::getColumnListing($table)));
        $query = DB::table($table)->where('tenant_id', $tenant);
        if ($scope !== null && in_array($table, ['square_orders', 'square_payments'], true)) {
            $orders = DB::table('square_orders')->where('tenant_id', $tenant)->where(function (Builder $query) use ($scope): void {
                $query->whereIn('square_order_id', $scope['order_ids']);
                foreach ($scope['windows'] as $window) {
                    $query->orWhere(fn (Builder $q) => $q->where('location_id', $window['location_id'])
                        ->where('closed_at', '>=', $window['from'])->where('closed_at', '<', $window['through']));
                }
            });
            $table === 'square_orders' ? $query = $orders : $query->whereIn('square_order_id', $orders->select('square_order_id'));
        } elseif ($scope !== null && $table === 'marketing_import_runs') {
            $query->whereIn('id', $scope['run_ids']);
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

    private function facts(Tenant $tenant, TeamArea $area, string $generation): Builder
    {
        return DB::table(self::TABLE)->where(['tenant_id' => $tenant->id, 'area_id' => $area->id, 'generation' => $generation]);
    }

    private function filtered(Tenant $tenant, TeamArea $area, string $generation, array $filters, string $kind): Builder
    {
        $q = $this->facts($tenant, $area, $generation)->where('kind', $kind)->where('local_date', '<=', $filters['to']);
        $kind === 'event' || $kind === 'team_member' ? $q->where('ends_on', '>=', $filters['from']) : $q->where('local_date', '>=', $filters['from']);
        if (! empty($filters['event_id'])) {
            $q->where('event_id', $filters['event_id']);
        }
        foreach (['team_member_id' => 'team_member', 'customer_id' => 'customer'] as $filter => $dimension) {
            if (! empty($filters[$filter])) {
                if ($dimension === $kind) {
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

    private function customerFacts(int $tenant, array $summary, string $event, int $commerce, MarketingProfile $profile, array $value, array $relations): array
    {
        $facts = [];
        $label = trim($profile->first_name.' '.$profile->last_name) ?: 'Customer';
        $seen = [];
        foreach ($value['purchase_journey'] ?? [] as $purchase) {
            if (! ($purchase['confirmed_purchase'] ?? false) || (int) data_get($purchase, 'purchase_event.event.id', 0) !== $commerce
                || strtolower($purchase['currency'] ?? '') !== 'usd' || isset($seen[$purchase['purchase_id']])) {
                continue;
            }
            $seen[$purchase['purchase_id']] = true;
            $date = CarbonImmutable::parse($purchase['occurred_at'])->setTimezone($summary['timezone'] ?? 'UTC')->toDateString();
            $paid = isset($purchase['refunded_minor']) ? $purchase['amount_minor'] - $purchase['refunded_minor'] : null;
            $facts[] = $this->fact($tenant, 'customer', $purchase['purchase_id'], (string) $profile->id, $label, [
                'event_id' => $event, 'customer_id' => $profile->id, 'local_date' => $date, 'ends_on' => $date,
                'net_sales_minor' => null, 'known_net_sales_minor' => 0, 'paid_total_minor' => $paid, 'sale_count' => 1, 'selling_days' => 0, 'complete' => false]);
        }
        if (! $facts && isset($relations['captured'])) {
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
