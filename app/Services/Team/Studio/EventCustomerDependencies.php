<?php

namespace App\Services\Team\Studio;

use App\Models\MarketingProfile;
use App\Models\TeamArea;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Marketing\CanonicalMarketingProfileResolver;
use App\Services\Marketing\CustomerCommerceLinkService;
use App\Services\Marketing\CustomerCommerceSnapshotService;
use App\Services\Marketing\CustomerEventValueService;
use App\Services\Pos\PosService;
use App\Services\Pos\PosStore;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** A private dependency frame, never another customer or commerce authority. */
class EventCustomerDependencies
{
    public const VERSION = 2;

    private const MAX_ROWS = 50000;

    private const MAX_PROFILES = 10000;

    private array $selectorCache = [];

    private array $readIndexes = [];

    private ?array $paymentKeySchema = null;

    private ?array $orderKeySchema = null;

    private array $sourceHashVisibility = [];

    /** Capture original anchors before any result/customer cohort is consumed. */
    public function capture(Tenant $tenant, TeamArea $area, ?array $scope = null, bool $witnesses = false): array
    {
        $frame = app(EventCustomerReadView::class)->run($tenant->id, function () use ($tenant, $area, $scope, $witnesses): array {
            // Selectors and their selected originals belong to one local read view.
            $revision = $this->selectorRevisionInView($tenant, $area);
            if ($scope !== null) {
                abort_unless(($scope['version'] ?? null) === self::VERSION && hash_equals($scope['selector_revision'] ?? '', $revision), 409, 'Customer event selectors changed. Preparing reporting.');
            } else {
                $scope = ['version' => self::VERSION, 'selector_revision' => $revision, 'events' => $this->selectors($tenant, $area), 'seeds' => []];
            }

            return $this->frame($tenant, $scope, $witnesses);
        });
        abort_unless(hash_equals($frame['scope']['selector_revision'], $this->selectorRevision($tenant, $area)), 409, 'Customer event selectors changed while reporting was read.');

        return $frame;
    }

    /** Includes the tenant-wide competing allocation boundary used by shared readers. */
    public function selectorRevision(Tenant $tenant, TeamArea $area): string
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'mysql' || $db->transactionLevel() !== 0 || $db->getPdo()->inTransaction()) {
            // The caller owns its isolation; retain the conservative reload guards.
            return $this->selectorRevisionInView($tenant, $area);
        }
        // Metadata, ciphertext and competing allocations need the same short view.
        // SET TRANSACTION applies to this transaction only, preserving the session.
        $db->statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');

        return $db->transaction(fn () => $this->selectorRevisionInView($tenant, $area));
    }

    private function selectorRevisionInView(Tenant $tenant, TeamArea $area): string
    {
        abort_unless((int) $area->tenant_id === (int) $tenant->id, 404);
        $selectors = $this->selectors($tenant, $area);
        $orders = $commerce = [];
        foreach ($selectors as $selector) {
            $orders = [...$orders, ...$selector['orders']];
            if ($selector['commerce']) {
                $commerce[] = $selector['commerce'];
            }
        }
        $connections = \App\Models\TeamProviderConnection::where('tenant_id', $tenant->id)->where('area_id', $area->id)->orderBy('id')->get()
            ->map(fn ($connection) => ['id' => $connection->id, 'user_id' => $connection->user_id, 'generation' => $connection->generation,
                'status' => $connection->status, 'account_key' => $connection->account_key, 'settings' => $connection->settings])->all();

        return $this->hash([self::VERSION, $tenant->id, $area->id, config('pos_live.runtime_id'), $selectors, $connections,
            app(EventSquareAllocationFacts::class)->revisionFor($tenant->id, $orders, $commerce)]);
    }

    /** Decode only changed ciphertext; its effective selection, rather than notes, is the fence. */
    private function selectors(Tenant $tenant, TeamArea $area): array
    {
        $query = DB::table('team_operation_records')->where(['tenant_id' => $tenant->id, 'area_id' => $area->id])
            ->whereIn('kind', ['event', 'financial', 'event_link'])->orderBy('id');
        $columns = ['id', 'kind', 'version', 'status', 'connection_id', 'assigned_user_id', 'visibility', 'starts_on', 'ends_on'];
        $metadataQuery = (clone $query)->select($columns);
        $metadataRows = DB::connection()->getDriverName() === 'mysql'
            ? $metadataQuery->selectRaw('SHA2(`data`,256) AS content_revision')->get()
            : $metadataQuery->addSelect('data')->get();
        $metadata = $metadataRows->mapWithKeys(fn ($row) => [(string) $row->id => [$row->kind, (int) $row->version, $row->status, $row->connection_id, $row->assigned_user_id, $row->visibility, $row->starts_on, $row->ends_on,
            $row->content_revision ?? hash('sha256', (string) $row->data)]])->all();
        // Commerce membership is fresh even when the encrypted task itself has not changed.
        $stock = Schema::hasTable('commerce_stock_event_links') ? DB::table('commerce_stock_event_links')->where('tenant_id', $tenant->id)->whereNotNull('reviewed_by')
            ->orderBy('id')->get(['id', 'event_id', 'provider', 'external_id', 'reviewed_by'])->all() : [];
        $providers = \App\Models\TeamProviderConnection::where('tenant_id', $tenant->id)->where('area_id', $area->id)->orderBy('id')->get(['id', 'provider'])->toArray();
        $key = $tenant->id.':'.$area->id;
        $revision = $this->hash([$metadata, $stock, $providers]);
        if (($this->selectorCache[$key]['revision'] ?? null) === $revision) {
            return $this->selectorCache[$key]['selectors'];
        }
        $records = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('kind', ['event', 'financial', 'event_link'])->orderBy('id')->get();
        $loaded = $records->mapWithKeys(fn ($record) => [(string) $record->id => [$record->kind, (int) $record->version, $record->status, $record->connection_id, $record->assigned_user_id, $record->visibility, $record->getRawOriginal('starts_on'), $record->getRawOriginal('ends_on'),
            hash('sha256', (string) $record->getRawOriginal('data'))]])->all();
        abort_unless($loaded === $metadata, 409, 'Customer event selectors changed while reporting was read.');
        $links = $records->where('kind', 'event_link')->where('status', 'accepted')->keyBy(fn ($link) => (string) ($link->data['occurrence_id'] ?? ''));
        $commerceIds = app(EventCommerceReport::class)->commerceIds($records->where('kind', 'event'));
        $selected = [];
        foreach ($records as $event) {
            $mapping = $links->get((string) $event->id);
            if ($event->status === 'withdrawn' || ! ($event->kind === 'event' || $event->kind === 'financial' && $mapping)) {
                continue;
            }
            $data = $event->data;
            $allocation = $data['_teams_enrichment']['square_orders'] ?? [];
            $commerce = $commerceIds[(string) $event->id] ?? null;
            if (empty($allocation['ids']) && empty($mapping?->data['square_scopes']) && ! $commerce) {
                continue;
            }
            $selected[(string) $event->id] = ['orders' => array_values(array_filter($allocation['ids'] ?? [], 'is_string')),
                'windows' => $mapping?->data['square_scopes'] ?? [], 'timezone' => $mapping?->data['timezone'] ?? 'UTC',
                'commerce' => $commerce,
                'reviewers' => array_values(array_filter([$mapping?->data['reviewed_by'] ?? null, $allocation['reviewed_by'] ?? null])),
                'source' => [$event->kind, $event->status, $event->connection_id, $event->assigned_user_id, $event->visibility, $event->starts_on?->toDateString(), $event->ends_on?->toDateString(), array_intersect_key($data, array_flip(['source_id', 'source_type', 'source_workspace', 'source_project', 'source_projects', '_generation'])),
                    array_intersect_key($allocation, array_flip(['reviewed_by', 'reviewed_at', 'source_binding'])),
                    $mapping ? [$mapping->id, $mapping->status, array_intersect_key($mapping->data, array_flip(['occurrence_id', 'square_scopes', 'timezone', 'starts_on', 'ends_on', 'reviewed_by', 'reviewed_at', 'reviewed_source_versions', 'financial_id', 'financial_sources', 'staff_ids']))] : null]];
        }
        // Recheck the metadata in capture after the short read view; no decoded value is a grant.
        $this->selectorCache[$key] = ['revision' => $revision, 'selectors' => $selected];

        return $selected;
    }

    private function frame(Tenant $tenant, array $scope, bool $witnesses): array
    {
        $anchors = $this->anchors($tenant, $scope['events']);
        $links = $this->sourceLinks($tenant, $anchors['keys']);
        $anchorClaims = $links->filter(fn ($link) => isset($anchors['events'][$link->source_type.':'.$link->source_id]) || $link->source_type === 'pos_event_capture');
        $seeds = array_values(array_unique([...($scope['seeds'] ?? []), ...$anchors['profiles'], ...$anchorClaims->pluck('marketing_profile_id')->all()]));
        [$profiles, $canonical] = $this->profiles($tenant, $seeds, true);
        $aliasLinks = $profiles->isEmpty() ? collect() : $this->bounded($this->indexed(DB::table('marketing_profile_links'), 'marketing_profile_links', 'mpl_profile_source_type_idx', ['marketing_profile_id', 'source_type'])
            ->where('tenant_id', $tenant->id)->whereIn('marketing_profile_id', $profiles->keys()), self::MAX_ROWS);
        foreach ($aliasLinks->where('source_type', 'pos_sale')->groupBy(fn ($link) => $canonical[(int) $link->marketing_profile_id]) as $nativeLinks) {
            abort_if($nativeLinks->count() > 5000, 409, 'Native purchase witness coverage needs review.');
        }
        $keys = $anchors['keys'];
        foreach ($aliasLinks as $link) {
            $keys[$link->source_type][(string) $link->source_id] = true;
        }
        $financial = $this->financial($tenant, $keys, $anchors);
        $keys = $financial['keys'];
        $links = $this->sourceLinks($tenant, $keys);
        [$claimants, $claimantCanonical] = $this->profiles($tenant, [...$links->pluck('marketing_profile_id')->all(), ...array_merge([], ...array_values($financial['native_customers']))], false);
        $profiles = $profiles->union($claimants)->sortKeys();
        $canonical += $claimantCanonical;
        $labels = [];
        foreach ($canonical as $id => $root) {
            if ($root !== null && isset($profiles[$root]) && $profiles[$root]->archived_at === null) {
                $labels[$root] = trim($profiles[$root]->first_name.' '.$profiles[$root]->last_name) ?: 'Customer';
            }
        }
        $claims = [];
        foreach ($links->groupBy(fn ($link) => $link->source_type.':'.$link->source_id) as $key => $rows) {
            $roots = $rows->map(fn ($link) => $canonical[(int) $link->marketing_profile_id] ?? null)->unique()->values();
            $claims[$key] = $roots->count() === 1 && $roots[0] !== null && isset($labels[$roots[0]]) ? $roots[0] : null;
        }
        foreach ($financial['order_tenders'] as $order => $payments) {
            $key = 'square_order:'.$order;
            $root = $claims[$key] ?? null;
            foreach ($payments as $payment) {
                if (array_key_exists('square_payment:'.$payment, $claims) && $claims['square_payment:'.$payment] !== $root) {
                    $root = null;
                }
            }
            $claims[$key] = $root;
        }
        $events = $anchors['events'];
        $captures = [];
        $reviewerIds = [];
        foreach ($scope['events'] as $selector) {
            $reviewerIds = [...$reviewerIds, ...$selector['reviewers']];
        }
        foreach ($links as $link) {
            $meta = json_decode($link->source_meta ?? 'null', true, 512, JSON_THROW_ON_ERROR) ?? [];
            foreach (['reviewed_by', 'event_attribution.reviewed_by', 'acquisition_review.reviewed_by', 'membership_conversion_review.reviewed_by'] as $path) {
                if (is_int($reviewer = data_get($meta, $path))) {
                    $reviewerIds[] = $reviewer;
                }
            }
            $key = $link->source_type.':'.$link->source_id;
            if ($link->source_type === 'pos_event_capture' && ($root = $claims[$key] ?? null)) {
                foreach ($scope['events'] as $event => $selector) {
                    if ($selector['commerce'] && (int) ($meta['event_id'] ?? 0) === $selector['commerce'] && $this->dateValid($meta['captured_at'] ?? null)) {
                        $captures[$event][$root] = true;
                    }
                }
            }
            $review = $meta['event_attribution'] ?? [];
            if (is_array($review) && $this->reviewValid($tenant, $review)) {
                foreach ($scope['events'] as $event => $selector) {
                    if ($selector['commerce'] && (int) ($review['event_id'] ?? 0) === $selector['commerce']) {
                        $events[$key][$event] = true;
                    }
                }
            }
        }
        $reviewerIds = array_values(array_unique(array_map('intval', $reviewerIds)));
        $reviewers = $reviewerIds ? DB::table('users')->leftJoin('tenant_user', fn ($join) => $join->on('users.id', '=', 'tenant_user.user_id')->where('tenant_user.tenant_id', $tenant->id))
            ->whereIn('users.id', $reviewerIds)->orderBy('users.id')->get(['users.id', 'users.is_active', 'users.role', 'users.email_verified_at', 'tenant_user.role as membership_role', 'tenant_user.membership_active'])->toArray() : [];
        $captures = $this->admittedCaptures($tenant, $scope, $captures);
        $scope['seeds'] = $seeds;
        sort($scope['seeds'], SORT_NUMERIC);
        $state = ['anchors' => $anchors['revision'], 'profiles' => $profiles->values()->all(), 'links' => $links->sortBy('id')->values()->all(), 'financial' => $financial['revision'], 'reviewers' => $reviewers,
            'allocations' => app(EventSquareAllocationFacts::class)->revisionFor($tenant->id, array_keys($keys['square_order'] ?? []), array_values(array_filter(array_column($scope['events'], 'commerce'))))];
        $frame = ['scope' => $scope, 'signature' => $this->hash($state), 'canonical' => $canonical, 'labels' => $labels, 'claims' => $claims,
            'events' => $events, 'captures' => $captures, 'witnesses' => [], 'eligible_events' => [], 'scoped_witnesses' => []];
        if ($witnesses) {
            [$frame['witnesses'], $frame['eligible_events']] = $this->purchaseWitnesses($tenant, $frame, $keys, $financial);
            $frame['scoped_witnesses'] = $this->scopedPurchaseWitnesses($tenant, $scope);
        }

        return $frame;
    }

    private function hash(mixed $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }

    private function anchors(Tenant $tenant, array $selectors): array
    {
        $keys = $events = $profiles = $revision = [];
        $commerce = [];
        $hasOrders = Schema::hasTable('square_orders');
        $hasPayments = Schema::hasTable('square_payments');
        $hasNative = Schema::hasTable('commerce_stock_pools') && Schema::hasTable('pos_live_sales') && filled(config('pos_live.runtime_id'));
        foreach ($selectors as $event => $selector) {
            $ids = $selector['orders'];
            if ($hasOrders && ($selector['orders'] || $selector['windows'])) {
                $query = DB::table('square_orders')->where('tenant_id', $tenant->id)->where(function (Builder $query) use ($selector): void {
                    $query->whereIn('square_order_id', $selector['orders']);
                    foreach ($selector['windows'] as $window) {
                        $from = CarbonImmutable::parse($window['starts_on'], $selector['timezone'])->startOfDay()->utc();
                        $through = CarbonImmutable::parse($window['ends_on'], $selector['timezone'])->addDay()->startOfDay()->utc()->addHours(72);
                        $query->orWhere(fn (Builder $query) => $query->where('location_id', $window['location_id'])->where('closed_at', '>=', $from)->where('closed_at', '<', $through));
                    }
                });
                $orders = $this->bounded($query->select(['id', 'square_order_id', 'square_customer_id']), self::MAX_ROWS);
                $revision[$event]['orders'] = $orders->all();
                $ids = array_values(array_unique([...$ids, ...$orders->pluck('square_order_id')->all()]));
                foreach ($orders->pluck('square_customer_id')->filter() as $id) {
                    $keys['square_customer'][(string) $id] = true;
                }
            }
            foreach ($ids as $id) {
                $keys['square_order'][(string) $id] = true;
                $events['square_order:'.$id][$event] = true;
            }
            if ($ids && $hasPayments) {
                $payments = $this->bounded($this->paymentSources($tenant, $ids)
                    ->select(['id', 'square_payment_id', 'square_order_id', 'square_customer_id', 'status']), self::MAX_ROWS);
                $revision[$event]['payments'] = $payments->all();
                foreach ($payments as $payment) {
                    $keys['square_payment'][(string) $payment->square_payment_id] = true;
                    $events['square_payment:'.$payment->square_payment_id][$event] = true;
                    if ($payment->square_customer_id) {
                        $keys['square_customer'][(string) $payment->square_customer_id] = true;
                    }
                }
            }
            if ($selector['commerce']) {
                $commerce[(int) $selector['commerce']][] = $event;
                if ($hasNative) {
                    $pools = DB::table('commerce_stock_pools')->where('tenant_id', $tenant->id)->where('event_key', 'event:'.$selector['commerce'])->pluck('id')->all();
                    $index = app(\App\Services\Reporting\PayEventReceiptIndex::class)->forPools($tenant->id, $pools);
                    abort_if($index['review_items'] !== [], 409, 'Original customer receipt anchors need review.');
                    $store = app(PosStore::class);
                    $sales = $index['ids'] ? $this->bounded($store->rows('sales', $tenant->id)->whereIn('id', $index['ids']), self::MAX_ROWS) : collect();
                    $revision[$event]['native'] = $this->nativeReceiptRevisions($sales);
                    foreach ($sales as $sale) {
                        $snapshot = app(PosService::class)->snapshot($sale);
                        abort_unless(($snapshot['binding']['environment'] ?? null) === 'live' && in_array($snapshot['terms']['stock_pool_id'] ?? null, $pools, true), 409, 'Original customer receipt scope changed.');
                        $key = $store->runtime().':'.$sale->id;
                        $keys['pos_sale'][$key] = true;
                        $events['pos_sale:'.$key][$event] = true;
                        if ($id = (int) ($snapshot['customer']['id'] ?? 0)) {
                            $profiles[] = $id;
                        }
                    }
                }
            }
        }
        if ($commerce) {
            $schema = app(EventCustomerAnchorSchema::class);
            $captures = $schema->narrow(DB::table('marketing_profile_links')->where('tenant_id', $tenant->id)->where('source_type', 'pos_event_capture')
                ->whereIn('source_meta->event_id', array_keys($commerce)), '$.event_id')->selectRaw('id AS dependency_event_link_id');
            $reviewed = $schema->narrow(DB::table('marketing_profile_links')->where('tenant_id', $tenant->id)
                ->whereIn('source_type', ['order', 'square_order', 'square_payment', 'website_order'])
                ->whereIn('source_meta->event_attribution->event_id', array_keys($commerce)), '$.event_attribution.event_id')->selectRaw('id AS dependency_event_link_id');
            $selected = $captures->union($reviewed);
            $links = $this->bounded(DB::table('marketing_profile_links')->joinSub($selected, 'dependency_event_links', fn ($join) => $join->on('marketing_profile_links.id', '=', 'dependency_event_links.dependency_event_link_id'))
                ->where('marketing_profile_links.tenant_id', $tenant->id)->select('marketing_profile_links.*'), self::MAX_ROWS);
            foreach ($links as $link) {
                $keys[$link->source_type][(string) $link->source_id] = true;
                $profiles[] = (int) $link->marketing_profile_id;
            }
            $revision['event_links'] = $links->all();
        }

        return ['keys' => $keys, 'events' => $events, 'profiles' => $profiles, 'revision' => $revision];
    }

    private function sourceLinks(Tenant $tenant, array $keys): Collection
    {
        if (! array_filter($keys)) {
            return collect();
        }

        $selected = null;
        foreach ($keys as $type => $ids) {
            if ($ids) {
                $branch = $this->indexed(DB::table('marketing_profile_links'), 'marketing_profile_links', 'mpl_tenant_source_unique_idx', ['tenant_id', 'source_type', 'source_id'])
                    ->where('tenant_id', $tenant->id)->where('source_type', $type)->whereIn('source_id', array_keys($ids))->selectRaw('id AS dependency_source_id');
                // UNION deduplicates identities even under a case-insensitive
                // source-type collation; the outer bound applies to all branches.
                if ($selected === null) {
                    $selected = $branch;
                } else {
                    $selected->union($branch);
                }
            }
        }

        return $this->bounded(DB::table('marketing_profile_links')->joinSub($selected, 'dependency_source_keys', fn ($join) => $join->on('marketing_profile_links.id', '=', 'dependency_source_keys.dependency_source_id'))
            ->where('marketing_profile_links.tenant_id', $tenant->id)->select('marketing_profile_links.*'), self::MAX_ROWS);
    }

    /** Exact original membership; all callers retain their whole-population bound. */
    private function paymentSources(Tenant $tenant, array $orders, array $payments = []): Builder
    {
        // Keep the two original membership predicates independent so each
        // can select covered IDs without reading every tenant payment row.
        // Deduplication and the original bound apply to their whole union.
        $selected = null;
        $keyJoin = $this->sourceKeyInputAllowed();
        foreach (['square_order_id' => $orders, 'square_payment_id' => $payments] as $column => $ids) {
            if ($ids === []) {
                continue;
            }
            $index = $column === 'square_order_id' ? 'epp_order_hash_idx' : 'square_payments_tenant_payment_unique';
            $branch = $keyJoin ? $this->paymentInputKeys($tenant, $column, $ids, $index) : null;
            if ($branch !== null) {
                if ($selected === null) {
                    $selected = $branch;
                } else {
                    $selected->union($branch);
                }
                // Retain the ordinary index inventory used by the independently
                // guarded outer physical-ID lookup below.
                $this->readIndexes['square_payments'] ??= Schema::getIndexes('square_payments');

                continue;
            }
            $ranges = DB::connection()->getDriverName() === 'mysql' ? array_chunk($ids, 128) : [$ids];
            foreach ($ranges as $range) {
                // Native fallback leaves the optimizer free to ignore missing,
                // invisible or otherwise unverified optional source indexes.
                $branch = DB::table('square_payments')->where('tenant_id', $tenant->id)->whereIn($column, $range)->selectRaw('id AS dependency_payment_id');
                if ($selected === null) {
                    $selected = $branch;
                } else {
                    $selected->union($branch);
                }
            }
        }
        $query = DB::table('square_payments');
        if ($selected === null) {
            return $query->where('tenant_id', $tenant->id)->whereRaw('0 = 1');
        }
        $this->readIndexes['square_payments'] ??= Schema::getIndexes('square_payments');
        $primary = $query->getConnection()->getDriverName() === 'mysql' && collect($this->readIndexes['square_payments'] ?? [])
            ->contains(fn ($index) => strtolower($index['name']) === 'primary' && ($index['primary'] ?? false) && ($index['unique'] ?? false)
                && array_map('strtolower', $index['columns']) === ['id']);
        if ($primary) {
            // Drive physical row lookup from the deduplicated keys even
            // when many equality ranges make MySQL overestimate them.
            $query = DB::query()->fromRaw('('.$selected->toSql().') AS `dependency_payment_keys` STRAIGHT_JOIN `square_payments` FORCE INDEX (`PRIMARY`)'
                .' ON `square_payments`.`id` = `dependency_payment_keys`.`dependency_payment_id`', $selected->getBindings());
        } else {
            $query->joinSub($selected, 'dependency_payment_keys', fn ($join) => $join->on('square_payments.id', '=', 'dependency_payment_keys.dependency_payment_id'));
        }

        return $query->where('square_payments.tenant_id', $tenant->id);
    }

    /** The original order lane needs the same sparse point lookup as tenders. */
    private function orderSources(Tenant $tenant, array $orders): Builder
    {
        $query = DB::table('square_orders')->where('tenant_id', $tenant->id)->whereIn('square_order_id', $orders);
        $selected = $orders !== [] && $this->sourceKeyInputAllowed()
            ? $this->paymentInputKeys($tenant, 'square_order_id', $orders, 'epo_order_hash_idx', 'square_orders') : null;
        if ($selected === null) {
            return $query;
        }
        $this->readIndexes['square_orders'] ??= Schema::getIndexes('square_orders');
        $primary = collect($this->readIndexes['square_orders'])->contains(fn ($index) => strtolower($index['name']) === 'primary'
            && ($index['primary'] ?? false) && ($index['unique'] ?? false) && array_map('strtolower', $index['columns']) === ['id']);
        if ($primary) {
            $query = DB::query()->fromRaw('('.$selected->toSql().') AS `dependency_order_keys` STRAIGHT_JOIN `square_orders` FORCE INDEX (`PRIMARY`)'
                .' ON `square_orders`.`id` = `dependency_order_keys`.`dependency_order_id`', $selected->getBindings());
        } else {
            $query = DB::table('square_orders')->joinSub($selected, 'dependency_order_keys', fn ($join) => $join->on('square_orders.id', '=', 'dependency_order_keys.dependency_order_id'));
        }

        return $query->where('square_orders.tenant_id', $tenant->id);
    }

    private function sourceKeyInputAllowed(): bool
    {
        $db = DB::connection();
        if ($db->getDriverName() !== 'mysql') {
            return false;
        }
        $version = $db->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
        if (! preg_match('/^8\.4\.\d+(?:[-+].*)?$/', $version) || stripos($version, 'mariadb') !== false) {
            return false;
        }
        // Session encodings can change independently of configured charset.
        $session = $db->selectOne('SELECT @@character_set_client AS key_client, @@character_set_connection AS key_connection');

        return $session->key_client === 'utf8mb4' && $session->key_connection === 'utf8mb4';
    }

    /** A bound key relation only when its comparisons match the original IN. */
    private function paymentInputKeys(Tenant $tenant, string $column, array $ids, string $index, string $table = 'square_payments'): ?Builder
    {
        foreach ($ids as $id) {
            // Numeric values retain native IN coercion. Overlength, Unicode and
            // invalid UTF-8 use the original branch; JSON_TABLE may truncate with
            // only a warning, even under ERROR ON ERROR and strict SQL modes.
            if (! is_string($id) || strlen($id) > 255 || ! preg_match('/^[\x00-\x7f]*$/D', $id)) {
                return null;
            }
        }
        [$property, $columns, $indexes, $idAlias] = match ($table) {
            'square_payments' => ['paymentKeySchema', ['square_order_id', 'square_payment_id'], ['epp_order_hash_idx', 'square_payments_tenant_payment_unique'], 'dependency_payment_id'],
            'square_orders' => ['orderKeySchema', ['square_order_id'], ['epo_order_hash_idx'], 'dependency_order_id'],
        };
        $this->{$property} ??= [
            'columns' => DB::table('information_schema.columns')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', $table)
                ->whereIn('COLUMN_NAME', $columns)->get(['COLUMN_NAME as name', 'COLUMN_TYPE as type', 'CHARACTER_SET_NAME as charset',
                    'COLLATION_NAME as collation', 'IS_NULLABLE as nullable', 'GENERATION_EXPRESSION as expression'])->keyBy('name')->all(),
            'indexes' => DB::table('information_schema.statistics')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', $table)
                ->whereIn('INDEX_NAME', $indexes)->orderBy('INDEX_NAME')->orderBy('SEQ_IN_INDEX')
                ->get(['INDEX_NAME as name', 'COLUMN_NAME as column_name', 'SUB_PART as sub_part', 'NON_UNIQUE as non_unique', 'INDEX_TYPE as type',
                    'IS_VISIBLE as visible', 'COLLATION as collation', 'EXPRESSION as expression'])->groupBy('name')->map->all()->all(),
        ];
        $definition = $this->{$property}['columns'][$column] ?? null;
        $expected = $table === 'square_orders' ? ['tenant_id', 'square_order_id', 'location_id', 'closed_at', 'event_content_hash']
            : ($column === 'square_order_id' ? ['tenant_id', 'square_order_id', 'event_content_hash'] : ['tenant_id', 'square_payment_id']);
        $parts = $this->{$property}['indexes'][$index] ?? [];
        if (! $definition || strtolower($definition->type) !== 'varchar(255)' || $definition->charset !== 'utf8mb4' || $definition->collation !== 'utf8mb4_unicode_ci'
            || $definition->nullable !== ($table === 'square_payments' && $column === 'square_order_id' ? 'YES' : 'NO') || $definition->expression !== ''
            || array_column($parts, 'column_name') !== $expected
            || array_filter($parts, fn ($part) => $part->sub_part !== null || (int) $part->non_unique !== ($column === 'square_order_id' ? 1 : 0)
                || strtoupper($part->type) !== 'BTREE' || strtoupper($part->visible) !== 'YES' || $part->collation !== 'A' || $part->expression !== null)) {
            return null;
        }

        // DISTINCT is necessary even with one source lane: differently cased or
        // padded input strings can identify the same original physical row.
        return DB::query()->fromRaw("JSON_TABLE(?, '$[*]' COLUMNS (`source_id` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PATH '$' ERROR ON EMPTY ERROR ON ERROR)) AS dependency_source_ids"
            .' STRAIGHT_JOIN '.$table.' FORCE INDEX (`'.$index.'`) ON '.$table.'.tenant_id = ? AND '.$table.'.`'.$column.'` = dependency_source_ids.source_id',
            [json_encode(array_values($ids), JSON_THROW_ON_ERROR), $tenant->id])->distinct()->selectRaw($table.'.id AS '.$idAlias);
    }

    /** Original source membership and bytes, including representations suppressed by mirrors. */
    private function financial(Tenant $tenant, array $keys, array $anchors): array
    {
        $state = $orderTenders = $websiteParents = $nativeCustomers = $dates = [];
        $orders = $payments = $shopify = $website = collect();
        if ((! empty($keys['square_order']) || ! empty($keys['square_payment'])) && Schema::hasTable('square_payments')) {
            $payments = $this->sourceRows('square_payments', $this->paymentSources($tenant, array_keys($keys['square_order'] ?? []), array_keys($keys['square_payment'] ?? [])),
                ['id', 'square_payment_id', 'square_order_id', 'square_customer_id', 'status', 'created_at_source']);
            foreach ($payments as $payment) {
                $keys['square_payment'][(string) $payment->square_payment_id] = true;
                if ($payment->square_order_id) {
                    $keys['square_order'][(string) $payment->square_order_id] = true;
                    if ($payment->status === 'COMPLETED') {
                        $orderTenders[(string) $payment->square_order_id][] = (string) $payment->square_payment_id;
                    }
                }
                if ($payment->square_customer_id) {
                    $keys['square_customer'][(string) $payment->square_customer_id] = true;
                }
            }
            $state['payments'] = $payments->all();
        }
        if (! empty($keys['square_order']) && Schema::hasTable('square_orders')) {
            $orders = $this->sourceRows('square_orders', $this->orderSources($tenant, array_keys($keys['square_order'])),
                ['id', 'square_order_id', 'square_customer_id', 'closed_at']);
            $state['orders'] = $orders->all();
        }
        if (! empty($keys['order']) && Schema::hasTable('orders')) {
            $shopify = $this->sourceRows('orders', DB::table('orders')->where('tenant_id', $tenant->id)->whereIn('id', array_keys($keys['order'])), ['id', 'shopify_store_key', 'shopify_order_id']);
            $state['shopify'] = $shopify->all();
            if ($shopify->isNotEmpty() && Schema::hasTable('shopify_stores')) {
                $state['shopify_stores'] = DB::table('shopify_stores')->where('tenant_id', $tenant->id)->whereIn('store_key', $shopify->pluck('shopify_store_key')->filter())
                    ->orderBy('id')->get(['id', 'tenant_id', 'store_key'])->all();
            }
        }
        if ((! empty($keys['website_order']) || ! empty($keys['website_customer'])) && Schema::hasTable('website_orders')) {
            $website = $this->sourceRows('website_orders', \App\Models\WebsiteOrder::withoutPosSimulation()->forTenantId($tenant->id)
                ->where(fn ($q) => $q->whereIn('id', array_keys($keys['website_order'] ?? []))->orWhereIn('website_customer_id', array_keys($keys['website_customer'] ?? [])))->toBase(), ['id', 'website_customer_id']);
            foreach ($website as $order) {
                $keys['website_order'][(string) $order->id] = true;
                if ($order->website_customer_id) {
                    $keys['website_customer'][(string) $order->website_customer_id] = true;
                    $websiteParents[(string) $order->id] = (string) $order->website_customer_id;
                }
            }
            $state['website'] = $website->all();
        }
        if (filled(config('pos_live.runtime_id')) && Schema::hasTable('pos_live_sales')) {
            $store = app(PosStore::class);
            $nativeIds = [];
            foreach (array_keys($keys['pos_sale'] ?? []) as $id) {
                if (str_starts_with($id, $store->runtime().':')) {
                    $nativeIds[] = substr($id, strlen($store->runtime()) + 1);
                }
            }
            $operations = collect();
            if (Schema::hasTable('payment_core_operations') && ! empty($keys['square_payment'])) {
                $operations = $this->sourceRows('payment_core_operations', DB::table('payment_core_operations')->where('tenant_id', $tenant->id)->where('kind', 'payment')
                    ->whereIn('provider_id', array_keys($keys['square_payment'])), ['id', 'provider_id']);
            }
            $query = $store->rows('sales', $tenant->id)->where(function (Builder $query) use ($nativeIds, $keys, $operations, $shopify, $tenant): void {
                $query->whereIn('id', $nativeIds)->orWhereIn('provider_order_id', array_keys($keys['square_order'] ?? []))->orWhereIn('attempt_id', $operations->pluck('id'));
                foreach ($shopify as $order) {
                    if ($order->shopify_order_id && $order->shopify_store_key) {
                        $query->orWhere(fn (Builder $query) => $query->where('commerce_order_id', (string) $order->shopify_order_id)->whereIn('commerce_store_id',
                            DB::table('shopify_stores')->where('tenant_id', $tenant->id)->where('store_key', $order->shopify_store_key)->select('id')));
                    }
                }
            });
            $sales = $this->bounded($query, self::MAX_ROWS);
            $associations = Schema::hasTable('commerce_stock_receipt_associations') && $sales->isNotEmpty()
                ? $this->bounded(DB::table('commerce_stock_receipt_associations')->where('tenant_id', $tenant->id)->where('runtime_id', $store->runtime())->whereIn('sale_id', $sales->pluck('id')), self::MAX_ROWS) : collect();
            $associated = $associations->keyBy('sale_id');
            foreach ($sales as $sale) {
                $keys['pos_sale'][$store->runtime().':'.$sale->id] = true;
                $snapshot = app(PosService::class)->snapshot($sale);
                $nativeCustomers[$store->runtime().':'.$sale->id] = array_values(array_filter([(int) ($snapshot['customer']['id'] ?? 0), (int) ($associated->get($sale->id)?->customer_profile_id ?? 0)]));
            }
            if ($sales->isNotEmpty() && Schema::hasTable('payment_core_operations')) {
                $journal = $this->sourceRows('payment_core_operations', DB::table('payment_core_operations')->where('tenant_id', $tenant->id)
                    ->where(fn (Builder $query) => $query->whereIn('id', $sales->pluck('attempt_id'))->orWhereIn('original_id', $sales->pluck('attempt_id'))), ['id', 'binding_id']);
                $state['native_operations'] = $journal->all();
                if ($journal->isNotEmpty()) {
                    $state['native_payment_bindings'] = $this->sourceRows('payment_core_bindings', DB::table('payment_core_bindings')->where('tenant_id', $tenant->id)->whereIn('id', $journal->pluck('binding_id')), ['id'])->all();
                    $state['native_payment_events'] = $this->sourceRows('payment_core_events', DB::table('payment_core_events')->whereIn('operation_id', $journal->pluck('id')), ['id'])->all();
                }
            }
            if ($sales->isNotEmpty()) {
                $state['native_events'] = $this->sourceRows('pos_live_events', $store->rows('events', $tenant->id)->whereIn('sale_id', $sales->pluck('id')), ['id'])->all();
                if (Schema::hasTable('pos_candle_cash_reservations')) {
                    $state['native_reward_evidence'] = $this->sourceRows('pos_candle_cash_reservations', DB::table('pos_candle_cash_reservations')->where('tenant_id', $tenant->id)
                        ->where('runtime_id', $store->runtime())->whereIn('sale_id', $sales->pluck('id')), ['id'])->all();
                }
            }
            $state['native_sales'] = $this->nativeReceiptRevisions($sales);
            $state['native_associations'] = $associations->all();
            $state['provider_operations'] = $operations->all();
        }

        return ['keys' => $keys, 'order_tenders' => $orderTenders, 'website_parents' => $websiteParents, 'native_customers' => $nativeCustomers,
            'revision' => $state, 'dates' => $dates];
    }

    private function nativeReceiptRevisions(Collection $sales): array
    {
        // The reconciliation scheduler advances this polling cursor even when
        // the retained attempt has no new evidence. Preserve every other field,
        // including financial state, ownership, snapshot and stock references.
        return $sales->map(fn ($sale) => (object) array_diff_key((array) $sale, ['reconciled_at' => true]))->all();
    }

    private function sourceRows(string $table, Builder $query, array $identifiers): Collection
    {
        $available = Schema::getColumnListing($table);
        $columns = array_values(array_diff($available, ['updated_at', 'synced_at', 'event_content_hash']));
        if (DB::connection()->getDriverName() === 'mysql') {
            // Read the indexed generated value through a covering projection. Selecting
            // payload/extra row columns alongside a virtual value can recompute its JSON hash.
            $generated = match ($table) {
                'square_orders' => ['square_order_id', 'square_customer_id', 'location_id', 'state', 'total_money_amount', 'total_money_currency', 'raw_payload'],
                'square_payments' => ['square_payment_id', 'square_order_id', 'square_customer_id', 'location_id', 'amount_money', 'currency', 'status', 'raw_payload'],
                default => [],
            };
            $extras = match ($table) {
                'square_orders' => ['id', 'tenant_id', 'closed_at', 'source_name', 'raw_tax_names', 'created_at'],
                'square_payments' => ['id', 'tenant_id', 'source_type', 'created_at_source', 'created_at'],
                default => [],
            };
            $expected = [...$generated, ...$extras];
            $actual = $columns;
            sort($expected);
            sort($actual);
            $index = $table === 'square_orders' ? 'epo_order_hash_idx' : 'epp_order_hash_idx';
            if ($generated && $expected === $actual && in_array('event_content_hash', array_map('strtolower', $available), true) && Schema::hasIndex($table, $index)) {
                $rows = $this->bounded((clone $query)->select(array_values(array_unique([...$identifiers, ...$extras]))), self::MAX_ROWS);
                // The source population is already bounded to MAX_ROWS. Read
                // its covered hashes together rather than scan the same tenant
                // prefix again for every physical-ID batch.
                $hashQuery = DB::table($table);
                if ($this->sourceHashIndexVisible($table, $index)) {
                    $hashQuery->forceIndex($index);
                }
                $hashes = $rows->isEmpty() ? collect() : $hashQuery->where('tenant_id', $rows->first()->tenant_id)->whereIn('id', $rows->pluck('id'))
                    ->select('id')->selectRaw('HEX(`event_content_hash`) AS content_revision')->get()->keyBy('id');
                abort_unless($rows->count() === $hashes->count(), 409, 'Original purchase dependencies changed.');

                return $rows->map(fn ($row) => (object) [...array_intersect_key((array) $row, array_flip($identifiers)),
                    'source_revision' => $this->hash([(array) $row, $hashes[$row->id]->content_revision])]);
            }
            $expression = 'SHA2(JSON_ARRAY('.implode(',', array_map(fn ($column) => '`'.$column.'`', $columns)).'),256)';

            return $this->bounded($query->select($identifiers)->selectRaw($expression.' as source_revision'), self::MAX_ROWS);
        }
        $rows = $this->bounded($query->select($columns), self::MAX_ROWS);

        return $rows->map(fn ($row) => (object) [...array_intersect_key((array) $row, array_flip($identifiers)), 'source_revision' => $this->hash((array) $row)]);
    }

    private function sourceHashIndexVisible(string $table, string $index): bool
    {
        $key = $table.':'.$index;
        if (! array_key_exists($key, $this->sourceHashVisibility)) {
            $version = DB::connection()->getPdo()->getAttribute(\PDO::ATTR_SERVER_VERSION);
            $this->sourceHashVisibility[$key] = preg_match('/^8\.4\.\d+(?:[-+].*)?$/', $version) && stripos($version, 'mariadb') === false
                && DB::table('information_schema.statistics')->whereRaw('TABLE_SCHEMA = DATABASE()')->where('TABLE_NAME', $table)->where('INDEX_NAME', $index)->value('IS_VISIBLE') === 'YES';
        }

        return $this->sourceHashVisibility[$key];
    }

    private function purchaseWitnesses(Tenant $tenant, array $frame, array $keys, array $financial): array
    {
        $result = $eligible = [];
        $eventValues = app()->build(CustomerEventValueService::class);
        foreach ($frame['labels'] as $root => $label) {
            $sources = $candidateEvents = [];
            foreach ($frame['events'] as $key => $eventIds) {
                [$type, $id] = explode(':', $key, 2);
                $owned = ($frame['claims'][$key] ?? null) === $root;
                if ($type === 'website_order' && isset($financial['website_parents'][$id])) {
                    $owned = $owned || ($frame['claims']['website_customer:'.$financial['website_parents'][$id]] ?? null) === $root;
                }
                if ($owned && in_array($type, ['pos_sale', 'order', 'square_order', 'square_payment', 'website_order'], true)) {
                    $sources[] = ['type' => $type, 'id' => $type === 'pos_sale' ? $this->bareReceipt($id) : $id];
                    $candidateEvents += $eventIds;
                }
            }
            if ($sources === []) {
                continue;
            }
            if (collect($sources)->contains('type', 'website_order')) {
                foreach (array_keys($keys['website_customer'] ?? []) as $customer) {
                    if (($frame['claims']['website_customer:'.$customer] ?? null) === $root) {
                        $sources[] = ['type' => 'website_customer', 'id' => (string) $customer];
                    }
                }
            }
            $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $root);
            abort_unless($profile && (int) $profile->id === (int) $root && ! $profile->archived_at, 409, 'Customer witness identity changed.');
            $snapshot = app(CustomerCommerceSnapshotService::class)->forDependencySources($profile, $tenant->id, $sources);
            abort_if($snapshot['has_more'], 409, 'Customer witness coverage needs review.');
            foreach ($candidateEvents as $event => $present) {
                $selector = $frame['scope']['events'][$event];
                if (! $selector['commerce']) {
                    continue;
                }
                try {
                    $relations = $eventValues->eventSourceRelations($profile, $tenant->id, $selector['commerce'], array_values(array_filter($sources, fn ($source) => $source['type'] !== 'website_customer')), true);
                    abort_if($relations['has_more'], 409, 'Customer event witness coverage needs review.');
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    if ($error->getStatusCode() !== 404) {
                        throw $error;
                    }

                    continue;
                }
                $orders = [];
                foreach ($relations['confirmed_source_keys'] as $source) {
                    if (str_starts_with($source, 'square_order:')) {
                        $orders[] = substr($source, strlen('square_order:'));
                    }
                }
                $conflicts = app(EventSquareAllocationFacts::class)->conflictsFor($tenant->id, $orders, $event);
                foreach ($relations['confirmed_source_keys'] as $source) {
                    [$type, $id] = explode(':', $source, 2);
                    if ($type !== 'square_order' || ! in_array($id, $conflicts, true)) {
                        $eligible[$this->purchaseKey($type, $id)][$event][(int) $root] = true;
                    }
                }
            }
            foreach ($snapshot['purchases'] as $purchase) {
                if ($purchase['confirmed']) {
                    $key = $this->purchaseKey($purchase['source'], $purchase['source_id']);
                    $result[$key][(int) $root] = $purchase;
                }
            }
        }

        return [$result, $eligible];
    }

    private function admittedCaptures(Tenant $tenant, array $scope, array $captures): array
    {
        $result = [];
        $eventValues = app()->build(CustomerEventValueService::class);
        foreach ($captures as $event => $customers) {
            foreach (array_keys($customers) as $root) {
                $profile = app(CanonicalMarketingProfileResolver::class)->byId($tenant->id, (int) $root);
                if (! $profile || $profile->archived_at || (int) $profile->id !== (int) $root) {
                    continue;
                }
                try {
                    $relations = $eventValues->eventSourceRelations($profile, $tenant->id, $scope['events'][$event]['commerce'], []);
                    if ($relations['capture_link_ids']) {
                        $result[$event][(int) $root] = true;
                    }
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                    if ($error->getStatusCode() !== 404) {
                        throw $error;
                    }
                }
            }
        }

        return $result;
    }

    /** Reuse the exact historical/offline/tax normalizer for mapped scope facts. */
    private function scopedPurchaseWitnesses(Tenant $tenant, array $scope): array
    {
        $result = [];
        $ids = array_keys(array_filter($scope['events'], fn ($selector) => $selector['windows'] !== []));
        if ($ids === []) {
            return [];
        }
        $events = TeamOperationRecord::where('tenant_id', $tenant->id)->whereIn('id', $ids)->get()->keyBy('id');
        $links = TeamOperationRecord::where('tenant_id', $tenant->id)->where('kind', 'event_link')->where('status', 'accepted')
            ->whereIn('area_id', $events->pluck('area_id')->unique())->get()->keyBy(fn ($link) => (string) ($link->data['occurrence_id'] ?? ''));
        foreach ($ids as $id) {
            abort_unless($event = $events->get($id), 409, 'Customer occurrence is unavailable.');
            $selector = $scope['events'][$id];
            $selected = app(\App\Services\Reporting\ReviewedSquareEventPurchases::class)->forScopes($tenant->id, $event, $links->get($id), $selector['timezone'], $selector['commerce']);
            foreach ($selected['chunks'] as $chunk) {
                foreach ($chunk['purchases'] as $purchase) {
                    if ($purchase['customer_id']) {
                        $result[$id][$this->purchaseKey($purchase['source'], $purchase['source_id'])][(int) $purchase['customer_id']] = $purchase;
                    }
                }
            }
        }

        return $result;
    }

    public function assertPurchase(array $frame, string $event, MarketingProfile $profile, array $purchase, string $timezone = 'UTC'): void
    {
        $source = $purchase['source'] ?? null;
        $id = $purchase['source_id'] ?? null;
        if (! $source || ! $id) {
            [$source, $id] = array_pad(explode(':', (string) ($purchase['purchase_id'] ?? ''), 2), 2, null);
        }
        $key = $this->purchaseKey((string) $source, (string) $id);
        $root = (int) $profile->id;
        $scoped = array_key_exists('net_sales_minor', $purchase);
        $witness = $scoped ? ($frame['scoped_witnesses'][$event][$key][$root] ?? null) : ($frame['witnesses'][$key][$root] ?? null);
        abort_unless($witness && isset($frame['events'][$key][$event])
            && ($scoped || isset($frame['eligible_events'][$key][$event][$root]))
            && ($frame['labels'][$root] ?? null) === (trim($profile->first_name.' '.$profile->last_name) ?: 'Customer')
            && ($purchase['amount_minor'] ?? null) === $witness['amount_minor']
            && ($purchase['refunded_minor'] ?? null) === $witness['refunded_minor']
            && strtoupper($purchase['currency'] ?? 'USD') === ($witness['currency'] ?? 'USD')
            && (! $scoped || $purchase['net_sales_minor'] === $witness['net_sales_minor'])
            && CarbonImmutable::parse($purchase['occurred_at'])->setTimezone($timezone)->toDateString() === CarbonImmutable::parse($witness['occurred_at'])->setTimezone($timezone)->toDateString(),
            409, 'Customer source evidence changed while reporting was prepared.');
    }

    public function assertCapture(array $frame, string $event, MarketingProfile $profile): void
    {
        abort_unless(isset($frame['captures'][$event][(int) $profile->id])
            && ($frame['labels'][(int) $profile->id] ?? null) === (trim($profile->first_name.' '.$profile->last_name) ?: 'Customer'),
            409, 'Customer capture evidence changed while reporting was prepared.');
    }

    private function purchaseKey(string $type, string $id): string
    {
        if ($type === 'pos_sale' && ! str_starts_with($id, (string) config('pos_live.runtime_id').':')) {
            $id = config('pos_live.runtime_id').':'.$id;
        }

        return $type.':'.$id;
    }

    private function bareReceipt(string $id): string
    {
        $prefix = config('pos_live.runtime_id').':';

        return str_starts_with($id, $prefix) ? substr($id, strlen($prefix)) : $id;
    }

    /** Shared canonical resolution with a fresh, bounded incoming-alias inventory. */
    private function profiles(Tenant $tenant, array $seeds, bool $incoming): array
    {
        $rows = collect();
        $pending = array_values(array_unique(array_filter(array_map('intval', $seeds), fn ($id) => $id > 0)));
        $columns = ['id', 'tenant_id', 'first_name', 'last_name', 'merged_into_profile_id', 'archived_at', 'source_channels'];
        for ($depth = 0; $pending && $depth < 32; $depth++) {
            abort_if($rows->count() + count($pending) > self::MAX_PROFILES, 409, 'Customer alias coverage needs review.');
            $found = DB::table('marketing_profiles')->where('tenant_id', $tenant->id)->whereIn('id', $pending)->get($columns)->keyBy('id');
            abort_unless($found->count() === count($pending), 409, 'Customer aliases are unavailable or belong to another workspace.');
            $rows = $rows->union($found);
            $pending = $found->pluck('merged_into_profile_id')->filter()->unique()->diff($rows->keys())->values()->all();
        }
        abort_if($pending !== [], 409, 'Customer alias depth needs review.');
        if ($incoming) {
            $frontier = $rows->keys()->all();
            for ($depth = 0; $frontier && $depth < 32; $depth++) {
                $found = $this->indexed(DB::table('marketing_profiles'), 'marketing_profiles', 'marketing_profiles_merged_into_profile_id_foreign', ['merged_into_profile_id'])
                    ->where('tenant_id', $tenant->id)->whereIn('merged_into_profile_id', $frontier)->whereNotIn('id', $rows->keys())
                    ->orderBy('id')->limit(self::MAX_PROFILES + 1)->get($columns)->keyBy('id');
                $rows = $rows->union($found);
                abort_if($rows->count() > self::MAX_PROFILES, 409, 'Customer alias coverage needs review.');
                $frontier = $found->keys()->all();
            }
            abort_if($frontier !== [], 409, 'Customer alias depth needs review.');
        }
        $canonical = [];
        foreach ($rows as $id => $row) {
            $model = (new MarketingProfile)->setRawAttributes((array) $row, true);
            $root = app(CanonicalMarketingProfileResolver::class)->canonical($model, $tenant->id);
            abort_unless($root !== null, 409, 'Customer canonical identity needs review.');
            $canonical[(int) $id] = (int) $root->id;
        }
        foreach (array_count_values($canonical) as $count) {
            abort_if($count > 1000, 409, 'Customer alias coverage needs review.');
        }

        return [$rows, $canonical];
    }

    private function bounded(Builder $query, int $limit): Collection
    {
        $rows = $query->orderBy('id')->limit($limit + 1)->get();
        abort_if($rows->count() > $limit, 409, 'Customer dependency coverage needs review.');

        return $rows;
    }

    /** Structural hints only; every row and authority predicate is still freshly read. */
    private function indexed(Builder $query, string $table, string $name, array $prefix): Builder
    {
        if (! in_array($query->getConnection()->getDriverName(), ['mysql', 'sqlite'], true)) {
            return $query;
        }
        $this->readIndexes[$table] ??= Schema::getIndexes($table);
        foreach ($this->readIndexes[$table] as $index) {
            if (strtolower($index['name']) === $name && array_slice(array_map('strtolower', $index['columns']), 0, count($prefix)) === $prefix) {
                return $query->forceIndex($name);
            }
        }

        return $query;
    }

    private function dateValid(mixed $date): bool
    {
        return is_string($date) && strtotime($date) !== false && strtotime($date) <= now()->timestamp;
    }

    private function reviewValid(Tenant $tenant, array $review): bool
    {
        return is_int($review['reviewed_by'] ?? null) && $this->dateValid($review['reviewed_at'] ?? null)
            && is_string($review['evidence_reference'] ?? null) && trim($review['evidence_reference']) !== ''
            && app(CustomerCommerceLinkService::class)->canLink(User::find($review['reviewed_by']), $tenant->id);
    }
}
