<?php

namespace App\Services\Shopify;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\Paginator as PaginatorContract;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ShopifyEmbeddedCustomersGridService
{
    /**
     * @return array{
     *   paginator:PaginatorContract,
     *   filters:array<string,string|int>,
     *   sort_options:array<int,array{value:string,label:string}>,
     *   active_filter_count:int
     * }
     */
    public function resolve(Request $request, ?int $tenantId = null): array
    {
        $filters = $this->normalizeFilters($request);
        $searchContext = $this->resolveSearchContext((string) $filters['search'], $tenantId);
        $query = $this->baseQuery($tenantId, $searchContext['scoped_profile_ids'] ?? null);

        $this->applySearch($query, $searchContext);
        $this->applyFilters($query, $filters);
        $this->applySort($query, (string) $filters['sort'], (string) $filters['direction']);

        $paginator = $query
            ->simplePaginate((int) $filters['per_page'])
            ->withQueryString();

        $mapped = $paginator->getCollection()
            ->map(fn (object $row): array => $this->mapRow($row));

        $paginator->setCollection($mapped);

        return [
            'paginator' => $paginator,
            'filters' => $filters,
            'sort_options' => $this->sortOptions(),
            'active_filter_count' => $this->activeFilterCount($filters),
        ];
    }

    /**
     * @return array{
     *   paginator:PaginatorContract,
     *   filters:array<string,string|int>,
     *   sort_options:array<int,array{value:string,label:string}>,
     *   active_filter_count:int
     * }
     */
    public function emptyResult(Request $request): array
    {
        $filters = $this->normalizeFilters($request);
        $currentPage = max(1, (int) $request->query('page', 1));
        $perPage = (int) ($filters['per_page'] ?? 25);

        $paginator = new LengthAwarePaginator(
            collect(),
            0,
            $perPage,
            $currentPage,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return [
            'paginator' => $paginator,
            'filters' => $filters,
            'sort_options' => $this->sortOptions(),
            'active_filter_count' => $this->activeFilterCount($filters),
        ];
    }

    /**
     * @return array<string,string|int>
     */
    protected function normalizeFilters(Request $request): array
    {
        $sort = (string) $request->query('sort', 'last_activity');
        $direction = strtolower((string) $request->query('direction', 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = (int) $request->query('per_page', 25);
        $perPage = in_array($perPage, [25, 50, 100], true) ? $perPage : 25;

        $filters = [
            'search' => trim((string) $request->query('search', '')),
            'segment' => $this->normalizeSegment((string) $request->query('segment', 'all')),
            'sort' => in_array($sort, ['last_activity', 'name', 'email', 'orders', 'candle_cash', 'rewards_actions'], true)
                ? $sort
                : 'last_activity',
            'direction' => $direction,
            'per_page' => $perPage,
            'candle_club' => $this->normalizeTriState((string) $request->query('candle_club', 'all')),
            'candle_cash' => $this->normalizeTriState((string) $request->query('candle_cash', 'all')),
            'referral' => $this->normalizeTriState((string) $request->query('referral', 'all')),
            'review' => $this->normalizeTriState((string) $request->query('review', 'all')),
            'birthday' => $this->normalizeTriState((string) $request->query('birthday', 'all')),
            'wholesale' => $this->normalizeTriState((string) $request->query('wholesale', 'all')),
        ];

        return $filters;
    }

    protected function normalizeSegment(string $value): string
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['all', 'with_points', 'reachable', 'needs_contact'], true)
            ? $normalized
            : 'all';
    }

    protected function normalizeTriState(string $value): string
    {
        $value = strtolower(trim($value));

        return in_array($value, ['all', 'yes', 'no'], true) ? $value : 'all';
    }

    /**
     * @return Builder
     */
    protected function baseQuery(?int $tenantId = null, ?array $scopedProfileIds = null): Builder
    {
        $lastActivityExpression = $this->lastActivityExpression();

        $query = DB::table('marketing_profiles as mp');
        $query = $this->applyTenantScope($query, 'marketing_profiles', 'mp', $tenantId);

        if ($scopedProfileIds !== null) {
            $query->whereIn('mp.id', $scopedProfileIds ?: [0]);
        }

        return $query
            ->leftJoinSub($this->balanceSubquery($tenantId, $scopedProfileIds), 'balance_stats', function ($join): void {
                $join->on('balance_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoinSub($this->taskStatsSubquery($tenantId, $scopedProfileIds), 'task_stats', function ($join): void {
                $join->on('task_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoinSub($this->referralStatsSubquery($tenantId, $scopedProfileIds), 'referral_stats', function ($join): void {
                $join->on('referral_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoinSub($this->reviewStatsSubquery($tenantId, $scopedProfileIds), 'review_stats', function ($join): void {
                $join->on('review_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoinSub($this->externalProfileStatsSubquery($tenantId, $scopedProfileIds), 'external_stats', function ($join): void {
                $join->on('external_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoinSub($this->ordersStatsSubquery($tenantId), 'order_stats', function ($join): void {
                $join->on('order_stats.normalized_email', '=', 'mp.normalized_email');
            })
            ->leftJoinSub($this->wholesaleLinkStatsSubquery($tenantId, $scopedProfileIds), 'wholesale_link_stats', function ($join): void {
                $join->on('wholesale_link_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoinSub($this->groupStatsSubquery($tenantId, $scopedProfileIds), 'group_stats', function ($join): void {
                $join->on('group_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoinSub($this->birthdayIssuanceStatsSubquery($tenantId, $scopedProfileIds), 'birthday_issuance_stats', function ($join): void {
                $join->on('birthday_issuance_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoinSub($this->transactionStatsSubquery($tenantId, $scopedProfileIds), 'transaction_stats', function ($join): void {
                $join->on('transaction_stats.marketing_profile_id', '=', 'mp.id');
            })
            ->leftJoin('customer_birthday_profiles as birthday_profiles', function ($join) use ($tenantId): void {
                $join->on('birthday_profiles.marketing_profile_id', '=', 'mp.id');

                if (! Schema::hasTable('customer_birthday_profiles') || ! Schema::hasColumn('customer_birthday_profiles', 'tenant_id')) {
                    return;
                }

                if ($tenantId === null) {
                    $join->whereNull('birthday_profiles.tenant_id');

                    return;
                }

                $join->where('birthday_profiles.tenant_id', '=', $tenantId);
            })
            ->select([
                'mp.id',
                'mp.first_name',
                'mp.last_name',
                'mp.email',
                'mp.phone',
                'mp.normalized_phone',
            ])
            ->selectRaw('coalesce(order_stats.orders_count, 0) as orders_count')
            ->selectRaw("coalesce(nullif(trim(external_stats.vip_tier), ''), case when ".$this->candleClubExpression()." = 1 then 'Candle Club' else 'Standard' end) as vip_tier")
            ->selectRaw('coalesce(balance_stats.candle_cash_balance, 0) as candle_cash_balance')
            ->selectRaw('coalesce(task_stats.rewards_actions_count, 0) as rewards_actions_count')
            ->selectRaw($this->candleClubExpression() . ' as candle_club_active')
            ->selectRaw($this->referralExpression() . ' as referral_completed')
            ->selectRaw($this->reviewExpression() . ' as review_completed')
            ->selectRaw($this->birthdayExpression() . ' as birthday_completed')
            ->selectRaw($this->wholesaleExpression() . ' as wholesale_eligible')
            ->selectRaw($lastActivityExpression . ' as last_activity_at');
    }

    /**
     * @param array{
     *   raw:string,
     *   mode:string,
     *   search_like:?string,
     *   prefix_like:?string,
     *   normalized_phone:?string,
     *   phone_prefix_like:?string,
     *   normalized_phone_with_country_code:?string,
     *   numeric_id:?int,
     *   terms:\Illuminate\Support\Collection<int,string>,
     *   scoped_profile_ids:?array<int,int>
     * } $searchContext
     */
    protected function applySearch(Builder $query, array $searchContext): void
    {
        $search = (string) ($searchContext['raw'] ?? '');
        if ($search === '') {
            return;
        }

        $mode = (string) ($searchContext['mode'] ?? 'text');
        $searchLike = (string) ($searchContext['search_like'] ?? ('%' . $search . '%'));
        $prefixLike = (string) ($searchContext['prefix_like'] ?? ($search . '%'));
        $phonePrefixLike = (string) ($searchContext['phone_prefix_like'] ?? '');
        $normalizedPhoneWithCountryCode = $searchContext['normalized_phone_with_country_code'] ?? null;
        $numericId = $searchContext['numeric_id'] ?? null;
        /** @var \Illuminate\Support\Collection<int,string> $terms */
        $terms = $searchContext['terms'] ?? collect();

        if ($mode === 'exact_id' && $numericId !== null) {
            $query->where('mp.id', '=', $numericId);

            return;
        }

        if ($mode === 'email') {
            $query->where(function ($nested) use ($prefixLike, $searchLike): void {
                $nested
                    ->where('mp.normalized_email', 'like', strtolower($prefixLike))
                    ->orWhere('mp.email', 'like', $searchLike);
            });

            return;
        }

        if ($mode === 'phone' && $phonePrefixLike !== '') {
            $query->where(function ($phoneQuery) use ($phonePrefixLike, $normalizedPhoneWithCountryCode, $searchLike): void {
                $phoneQuery->where('mp.normalized_phone', 'like', $phonePrefixLike);

                if ($normalizedPhoneWithCountryCode !== null) {
                    $phoneQuery->orWhere('mp.normalized_phone', 'like', $normalizedPhoneWithCountryCode . '%');
                }

                $phoneQuery->orWhere('mp.phone', 'like', $searchLike);
            });

            return;
        }

        $query->where(function ($nested) use ($searchLike, $prefixLike, $terms): void {
            $nested
                ->where('mp.normalized_email', 'like', strtolower($prefixLike))
                ->orWhere('mp.email', 'like', $searchLike)
                ->orWhere('mp.first_name', 'like', $searchLike)
                ->orWhere('mp.last_name', 'like', $searchLike);

            if ($terms->count() >= 2) {
                $first = (string) $terms->first();
                $last = (string) $terms->last();

                $nested->orWhere(function ($nameQuery) use ($first, $last): void {
                    $nameQuery
                        ->where('mp.first_name', 'like', $first . '%')
                        ->where('mp.last_name', 'like', $last . '%');
                });

                $nested->orWhere(function ($nameQuery) use ($first, $last): void {
                    $nameQuery
                        ->where('mp.first_name', 'like', $last . '%')
                        ->where('mp.last_name', 'like', $first . '%');
                });
            }
        });
    }

    /**
     * @param array<string,string|int> $filters
     */
    protected function applyFilters(Builder $query, array $filters): void
    {
        $segment = (string) ($filters['segment'] ?? 'all');
        if ($segment === 'with_points') {
            $query->whereRaw('coalesce(balance_stats.candle_cash_balance, 0) > 0');
        }
        if ($segment === 'reachable') {
            $query->where(function ($reachable): void {
                $reachable
                    ->whereNotNull('mp.normalized_email')
                    ->where('mp.normalized_email', '!=', '')
                    ->orWhere(function ($phoneQuery): void {
                        $phoneQuery
                            ->whereNotNull('mp.normalized_phone')
                            ->where('mp.normalized_phone', '!=', '');
                    });
            });
        }
        if ($segment === 'needs_contact') {
            $query->where(function ($contact): void {
                $contact
                    ->whereNull('mp.normalized_email')
                    ->orWhere('mp.normalized_email', '=', '');
            })->where(function ($contact): void {
                $contact
                    ->whereNull('mp.normalized_phone')
                    ->orWhere('mp.normalized_phone', '=', '');
            });
        }

        $this->applyTriStateFilter(
            $query,
            (string) $filters['candle_club'],
            $this->candleClubExpression()
        );

        $this->applyTriStateFilter(
            $query,
            (string) $filters['referral'],
            $this->referralExpression()
        );

        $this->applyTriStateFilter(
            $query,
            (string) $filters['review'],
            $this->reviewExpression()
        );

        $this->applyTriStateFilter(
            $query,
            (string) $filters['birthday'],
            $this->birthdayExpression()
        );

        $this->applyTriStateFilter(
            $query,
            (string) $filters['wholesale'],
            $this->wholesaleExpression()
        );

        $candleCash = (string) ($filters['candle_cash'] ?? 'all');
        if ($candleCash === 'yes') {
            $query->whereRaw('coalesce(balance_stats.candle_cash_balance, 0) > 0');
        }
        if ($candleCash === 'no') {
            $query->whereRaw('coalesce(balance_stats.candle_cash_balance, 0) = 0');
        }
    }

    protected function applyTriStateFilter(Builder $query, string $value, string $expression): void
    {
        if ($value === 'yes') {
            $query->whereRaw($expression . ' = 1');
        }

        if ($value === 'no') {
            $query->whereRaw($expression . ' = 0');
        }
    }

    protected function applySort(Builder $query, string $sort, string $direction): void
    {
        if ($sort === 'name') {
            $query->orderByRaw("coalesce(mp.last_name, '') " . $direction)
                ->orderByRaw("coalesce(mp.first_name, '') " . $direction)
                ->orderBy('mp.id', 'asc');

            return;
        }

        if ($sort === 'email') {
            $query->orderByRaw("coalesce(mp.normalized_email, mp.email, '') " . $direction)
                ->orderBy('mp.id', 'asc');

            return;
        }

        if ($sort === 'candle_cash') {
            $query->orderByRaw('coalesce(balance_stats.candle_cash_balance, 0) ' . $direction)
                ->orderBy('mp.id', 'asc');

            return;
        }

        if ($sort === 'rewards_actions') {
            $query->orderByRaw('coalesce(task_stats.rewards_actions_count, 0) ' . $direction)
                ->orderBy('mp.id', 'asc');

            return;
        }

        if ($sort === 'orders') {
            $query->orderByRaw('coalesce(order_stats.orders_count, 0) ' . $direction)
                ->orderBy('mp.id', 'asc');

            return;
        }

        $query->orderByRaw('last_activity_at is null asc')
            ->orderBy('last_activity_at', $direction)
            ->orderBy('mp.id', 'asc');
    }

    protected function mapRow(object $row): array
    {
        $displayName = trim((string) (($row->first_name ?? '') . ' ' . ($row->last_name ?? '')));
        if ($displayName === '') {
            $displayName = (string) ($row->email ?: ('Customer #' . $row->id));
        }

        return [
            'id' => (int) $row->id,
            'name' => $displayName,
            'email' => (string) ($row->email ?: '—'),
            'phone' => (string) ($row->phone ?: '—'),
            'orders_count' => (int) ($row->orders_count ?? 0),
            'candle_cash_balance' => (int) ($row->candle_cash_balance ?? 0),
            'candle_club_active' => ((int) ($row->candle_club_active ?? 0)) === 1,
            'vip_tier' => trim((string) ($row->vip_tier ?? '')) !== ''
                ? trim((string) $row->vip_tier)
                : (((int) ($row->candle_club_active ?? 0)) === 1 ? 'Candle Club' : 'Standard'),
            'referral_completed' => ((int) ($row->referral_completed ?? 0)) === 1,
            'review_completed' => ((int) ($row->review_completed ?? 0)) === 1,
            'birthday_completed' => ((int) ($row->birthday_completed ?? 0)) === 1,
            'wholesale_eligible' => ((int) ($row->wholesale_eligible ?? 0)) === 1,
            'rewards_actions_count' => (int) ($row->rewards_actions_count ?? 0),
            'last_activity_display' => $this->formatTimestamp($row->last_activity_at),
            'status' => $this->customerStatus(
                email: (string) ($row->email ?? ''),
                normalizedPhone: (string) ($row->normalized_phone ?? ''),
                pointsBalance: (int) ($row->candle_cash_balance ?? 0)
            ),
        ];
    }

    protected function customerStatus(string $email, string $normalizedPhone, int $pointsBalance): array
    {
        $hasEmail = trim($email) !== '';
        $hasPhone = trim($normalizedPhone) !== '';

        if (! $hasEmail && ! $hasPhone) {
            return [
                'key' => 'needs_contact',
                'label' => 'Needs contact',
            ];
        }

        if ($pointsBalance > 0) {
            return [
                'key' => 'active',
                'label' => 'Active',
            ];
        }

        return [
            'key' => 'standard',
            'label' => 'Standard',
        ];
    }

    /**
     * @return array{
     *   raw:string,
     *   mode:string,
     *   search_like:?string,
     *   prefix_like:?string,
     *   normalized_phone:?string,
     *   phone_prefix_like:?string,
     *   normalized_phone_with_country_code:?string,
     *   numeric_id:?int,
     *   terms:\Illuminate\Support\Collection<int,string>,
     *   scoped_profile_ids:?array<int,int>
     * }
     */
    protected function resolveSearchContext(string $search, ?int $tenantId = null): array
    {
        $search = trim($search);
        $searchLike = $search !== '' ? '%' . $search . '%' : null;
        $prefixLike = $search !== '' ? $search . '%' : null;
        $normalizedPhone = $search !== '' ? (preg_replace('/\D+/', '', $search) ?? '') : '';
        $phonePrefixLike = $normalizedPhone !== '' ? $normalizedPhone . '%' : null;
        $normalizedPhoneWithCountryCode = strlen($normalizedPhone) === 10 ? '1' . $normalizedPhone : null;
        $terms = collect(preg_split('/\s+/', $search, -1, PREG_SPLIT_NO_EMPTY))
            ->map(fn (string $term): string => trim($term))
            ->filter()
            ->values();

        $mode = 'text';
        $numericId = null;

        if ($search === '') {
            $mode = 'none';
        } elseif (ctype_digit($search) && strlen($search) <= 8) {
            $mode = 'exact_id';
            $numericId = (int) $search;
        } elseif (str_contains($search, '@')) {
            $mode = 'email';
        } elseif ($normalizedPhone !== '' && (strlen($normalizedPhone) >= 7) && ($normalizedPhone !== $search || strlen($normalizedPhone) >= 9)) {
            $mode = 'phone';
        }

        return [
            'raw' => $search,
            'mode' => $mode,
            'search_like' => $searchLike,
            'prefix_like' => $prefixLike,
            'normalized_phone' => $normalizedPhone !== '' ? $normalizedPhone : null,
            'phone_prefix_like' => $phonePrefixLike,
            'normalized_phone_with_country_code' => $normalizedPhoneWithCountryCode,
            'numeric_id' => $numericId,
            'terms' => $terms,
            'scoped_profile_ids' => $this->searchScopedProfileIds(
                mode: $mode,
                search: $search,
                prefixLike: $prefixLike,
                searchLike: $searchLike,
                phonePrefixLike: $phonePrefixLike,
                normalizedPhoneWithCountryCode: $normalizedPhoneWithCountryCode,
                numericId: $numericId,
                tenantId: $tenantId,
            ),
        ];
    }

    /**
     * @return array<int,int>|null
     */
    protected function searchScopedProfileIds(
        string $mode,
        string $search,
        ?string $prefixLike,
        ?string $searchLike,
        ?string $phonePrefixLike,
        ?string $normalizedPhoneWithCountryCode,
        ?int $numericId,
        ?int $tenantId = null,
    ): ?array {
        if ($mode === 'none') {
            return null;
        }

        if ($mode === 'exact_id') {
            return $numericId !== null ? [$numericId] : [0];
        }

        if (! in_array($mode, ['email', 'phone'], true)) {
            return null;
        }

        $candidateQuery = DB::table('marketing_profiles as mp')
            ->select('mp.id')
            ->limit(251);
        $candidateQuery = $this->applyTenantScope($candidateQuery, 'marketing_profiles', 'mp', $tenantId);

        if ($mode === 'email' && $prefixLike !== null && $searchLike !== null) {
            $candidateQuery->where(function ($nested) use ($prefixLike, $searchLike): void {
                $nested
                    ->where('mp.normalized_email', 'like', strtolower($prefixLike))
                    ->orWhere('mp.email', 'like', $searchLike);
            });
        }

        if ($mode === 'phone' && $phonePrefixLike !== null && $searchLike !== null) {
            $candidateQuery->where(function ($nested) use ($phonePrefixLike, $normalizedPhoneWithCountryCode, $searchLike): void {
                $nested->where('mp.normalized_phone', 'like', $phonePrefixLike);

                if ($normalizedPhoneWithCountryCode !== null) {
                    $nested->orWhere('mp.normalized_phone', 'like', $normalizedPhoneWithCountryCode . '%');
                }

                $nested->orWhere('mp.phone', 'like', $searchLike);
            });
        }

        $ids = $candidateQuery->pluck('mp.id')
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($ids === []) {
            return [0];
        }

        if (count($ids) > 250) {
            return null;
        }

        return $ids;
    }

    /**
     * @return array<int,array{value:string,label:string}>
     */
    protected function sortOptions(): array
    {
        return [
            ['value' => 'last_activity', 'label' => 'Last Activity'],
            ['value' => 'name', 'label' => 'Name'],
            ['value' => 'email', 'label' => 'Email'],
            ['value' => 'orders', 'label' => 'Orders'],
            ['value' => 'candle_cash', 'label' => 'Rewards Balance'],
            ['value' => 'rewards_actions', 'label' => 'Rewards Actions'],
        ];
    }

    /**
     * @param array<string,string|int> $filters
     */
    protected function activeFilterCount(array $filters): int
    {
        return collect(['segment', 'candle_club', 'candle_cash', 'referral', 'review', 'birthday', 'wholesale'])
            ->filter(fn (string $key): bool => (($filters[$key] ?? 'all') !== 'all'))
            ->count();
    }

    protected function formatTimestamp(mixed $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '—';
        }

        try {
            return CarbonImmutable::parse((string) $value)->format('M j, Y g:i A');
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    protected function candleClubExpression(): string
    {
        return "case
            when coalesce(task_stats.candle_club_completed, 0) = 1
              or coalesce(group_stats.candle_club_member, 0) = 1
            then 1 else 0 end";
    }

    protected function referralExpression(): string
    {
        return "case
            when coalesce(task_stats.referral_completed, 0) = 1
              or coalesce(referral_stats.referral_completed, 0) = 1
            then 1 else 0 end";
    }

    protected function reviewExpression(): string
    {
        return "case
            when coalesce(task_stats.review_completed, 0) = 1
              or coalesce(review_stats.review_completed, 0) = 1
            then 1 else 0 end";
    }

    protected function birthdayExpression(): string
    {
        return "case
            when coalesce(task_stats.birthday_completed, 0) = 1
              or coalesce(birthday_issuance_stats.birthday_completed, 0) = 1
              or (
                    birthday_profiles.birth_month is not null
                and birthday_profiles.birth_day is not null
                and birthday_profiles.reward_last_issued_at is not null
              )
            then 1 else 0 end";
    }

    protected function wholesaleExpression(): string
    {
        return "case
            when coalesce(external_stats.wholesale_eligible, 0) = 1
              or coalesce(wholesale_link_stats.wholesale_eligible, 0) = 1
            then 1 else 0 end";
    }

    protected function lastActivityExpression(): string
    {
        $columns = [
            'coalesce(mp.updated_at, mp.created_at)',
            'coalesce(transaction_stats.last_transaction_at, mp.updated_at, mp.created_at)',
            'coalesce(task_stats.last_task_activity_at, mp.updated_at, mp.created_at)',
            'coalesce(referral_stats.last_referral_activity_at, mp.updated_at, mp.created_at)',
            'coalesce(review_stats.last_review_activity_at, mp.updated_at, mp.created_at)',
            'coalesce(external_stats.last_external_activity_at, mp.updated_at, mp.created_at)',
            'coalesce(birthday_profiles.reward_last_issued_at, mp.updated_at, mp.created_at)',
            'coalesce(birthday_issuance_stats.last_birthday_activity_at, mp.updated_at, mp.created_at)',
        ];

        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            return 'greatest(' . implode(', ', $columns) . ')';
        }

        return 'max(' . implode(', ', $columns) . ')';
    }

    protected function balanceSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('candle_cash_balances')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                '0 as candle_cash_balance',
            ]);
        }

        $query = DB::table('candle_cash_balances')
            ->selectRaw('marketing_profile_id')
            ->selectRaw('max(balance) as candle_cash_balance')
            ->groupBy('marketing_profile_id');
        $query = $this->applyTenantScope($query, 'candle_cash_balances', 'candle_cash_balances', $tenantId);

        return $this->applyProfileScope(
            $query,
            'marketing_profile_id',
            $profileIds
        );
    }

    protected function taskStatsSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('candle_cash_task_completions') || ! Schema::hasTable('candle_cash_tasks')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                '0 as rewards_actions_count',
                '0 as candle_club_completed',
                '0 as referral_completed',
                '0 as review_completed',
                '0 as birthday_completed',
                'null as last_task_activity_at',
            ]);
        }

        $query = DB::table('candle_cash_task_completions as completions')
            ->join('candle_cash_tasks as tasks', 'tasks.id', '=', 'completions.candle_cash_task_id')
            ->selectRaw('completions.marketing_profile_id')
            ->selectRaw('count(*) as rewards_actions_count')
            ->selectRaw("max(case when tasks.handle = 'candle-club-join' and completions.status in ('awarded', 'approved', 'completed') then 1 else 0 end) as candle_club_completed")
            ->selectRaw("max(case when tasks.handle in ('refer-a-friend', 'referred-friend-bonus') and completions.status in ('awarded', 'approved', 'completed') then 1 else 0 end) as referral_completed")
            ->selectRaw("max(case when tasks.handle in ('google-review', 'product-review', 'photo-review') and completions.status in ('awarded', 'approved', 'completed') then 1 else 0 end) as review_completed")
            ->selectRaw("max(case when tasks.handle = 'birthday-signup' and completions.status in ('awarded', 'approved', 'completed') then 1 else 0 end) as birthday_completed")
            ->selectRaw('max(completions.created_at) as last_task_activity_at')
            ->groupBy('completions.marketing_profile_id');
        $query = $this->applyTenantScope($query, 'candle_cash_task_completions', 'completions', $tenantId);

        return $this->applyProfileScope(
            $query,
            'completions.marketing_profile_id',
            $profileIds
        );
    }

    protected function referralStatsSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('candle_cash_referrals')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                '0 as referral_completed',
                'null as last_referral_activity_at',
            ]);
        }

        $query = DB::table('candle_cash_referrals')
            ->selectRaw('referrer_marketing_profile_id as marketing_profile_id')
            ->selectRaw("max(case when status in ('qualified', 'rewarded', 'completed') or rewarded_at is not null then 1 else 0 end) as referral_completed")
            ->selectRaw('max(coalesce(rewarded_at, qualified_at, updated_at, created_at)) as last_referral_activity_at')
            ->groupBy('referrer_marketing_profile_id');
        $query = $this->applyTenantScope($query, 'candle_cash_referrals', 'candle_cash_referrals', $tenantId);

        return $this->applyProfileScope(
            $query,
            'referrer_marketing_profile_id',
            $profileIds
        );
    }

    protected function reviewStatsSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('marketing_review_summaries')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                '0 as review_completed',
                'null as last_review_activity_at',
            ]);
        }

        $query = DB::table('marketing_review_summaries')
            ->selectRaw('marketing_profile_id')
            ->selectRaw("max(case when review_count > 0 then 1 else 0 end) as review_completed")
            ->selectRaw('max(coalesce(last_reviewed_at, source_synced_at, updated_at, created_at)) as last_review_activity_at')
            ->whereNotNull('marketing_profile_id')
            ->groupBy('marketing_profile_id');
        $query = $this->applyTenantScope($query, 'marketing_review_summaries', 'marketing_review_summaries', $tenantId);

        return $this->applyProfileScope(
            $query,
            'marketing_profile_id',
            $profileIds
        );
    }

    protected function externalProfileStatsSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('customer_external_profiles')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                '0 as wholesale_eligible',
                'null as vip_tier',
                'null as last_external_activity_at',
            ]);
        }

        $query = DB::table('customer_external_profiles')
            ->selectRaw('marketing_profile_id')
            ->selectRaw("max(case when lower(coalesce(store_key, '')) = 'wholesale' or lower(coalesce(integration, '')) = 'wholesale' or lower(coalesce(provider, '')) = 'wholesale' then 1 else 0 end) as wholesale_eligible")
            ->selectRaw("max(nullif(trim(vip_tier), '')) as vip_tier")
            ->selectRaw('max(coalesce(last_activity_at, synced_at, updated_at, created_at)) as last_external_activity_at')
            ->whereNotNull('marketing_profile_id')
            ->groupBy('marketing_profile_id');
        $query = $this->applyTenantScope($query, 'customer_external_profiles', 'customer_external_profiles', $tenantId);

        return $this->applyProfileScope(
            $query,
            'marketing_profile_id',
            $profileIds
        );
    }

    protected function wholesaleLinkStatsSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('marketing_profile_links')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                '0 as wholesale_eligible',
            ]);
        }

        $query = DB::table('marketing_profile_links')
            ->selectRaw('marketing_profile_id')
            ->selectRaw("max(case when lower(coalesce(source_type, '')) like 'wholesale%' then 1 else 0 end) as wholesale_eligible")
            ->groupBy('marketing_profile_id');
        $query = $this->applyTenantScope($query, 'marketing_profile_links', 'marketing_profile_links', $tenantId);

        return $this->applyProfileScope(
            $query,
            'marketing_profile_id',
            $profileIds
        );
    }

    protected function groupStatsSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('marketing_group_members') || ! Schema::hasTable('marketing_groups')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                '0 as candle_club_member',
            ]);
        }

        $query = DB::table('marketing_group_members as members')
            ->join('marketing_groups as groups', 'groups.id', '=', 'members.marketing_group_id')
            ->selectRaw('members.marketing_profile_id')
            ->selectRaw("max(case when lower(coalesce(groups.name, '')) like '%candle club%' then 1 else 0 end) as candle_club_member")
            ->groupBy('members.marketing_profile_id');
        $query = $this->applyTenantScope($query, 'marketing_group_members', 'members', $tenantId);

        return $this->applyProfileScope(
            $query,
            'members.marketing_profile_id',
            $profileIds
        );
    }

    protected function birthdayIssuanceStatsSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('birthday_reward_issuances')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                '0 as birthday_completed',
                'null as last_birthday_activity_at',
            ]);
        }

        $query = DB::table('birthday_reward_issuances')
            ->selectRaw('marketing_profile_id')
            ->selectRaw("max(case when status in ('issued', 'claimed', 'redeemed') or claimed_at is not null then 1 else 0 end) as birthday_completed")
            ->selectRaw('max(coalesce(claimed_at, issued_at, updated_at, created_at)) as last_birthday_activity_at')
            ->groupBy('marketing_profile_id');
        $query = $this->applyTenantScope($query, 'birthday_reward_issuances', 'birthday_reward_issuances', $tenantId);

        return $this->applyProfileScope(
            $query,
            'marketing_profile_id',
            $profileIds
        );
    }

    protected function transactionStatsSubquery(?int $tenantId = null, ?array $profileIds = null): Builder
    {
        if (! Schema::hasTable('candle_cash_transactions')) {
            return $this->emptySubquery([
                '0 as marketing_profile_id',
                'null as last_transaction_at',
            ]);
        }

        $query = DB::table('candle_cash_transactions')
            ->selectRaw('marketing_profile_id')
            ->selectRaw('max(created_at) as last_transaction_at')
            ->groupBy('marketing_profile_id');
        $query = $this->applyTenantScope($query, 'candle_cash_transactions', 'candle_cash_transactions', $tenantId);

        return $this->applyProfileScope(
            $query,
            'marketing_profile_id',
            $profileIds
        );
    }

    protected function ordersStatsSubquery(?int $tenantId = null): Builder
    {
        if (! Schema::hasTable('orders')) {
            return $this->emptySubquery([
                "'' as normalized_email",
                '0 as orders_count',
            ]);
        }

        $emailColumns = array_values(array_filter([
            Schema::hasColumn('orders', 'customer_email') ? 'orders.customer_email' : null,
            Schema::hasColumn('orders', 'email') ? 'orders.email' : null,
            Schema::hasColumn('orders', 'billing_email') ? 'orders.billing_email' : null,
            Schema::hasColumn('orders', 'shipping_email') ? 'orders.shipping_email' : null,
        ]));

        if ($emailColumns === []) {
            return $this->emptySubquery([
                "'' as normalized_email",
                '0 as orders_count',
            ]);
        }

        $emailExpression = 'coalesce(' . implode(', ', array_map(
            static fn (string $column): string => "nullif(trim($column), '')",
            $emailColumns
        )) . ')';
        $normalizedEmailExpression = 'lower(' . $emailExpression . ')';

        $query = DB::table('orders as orders')
            ->selectRaw($normalizedEmailExpression . ' as normalized_email')
            ->selectRaw('count(*) as orders_count')
            ->whereRaw($emailExpression . ' is not null')
            ->groupByRaw($normalizedEmailExpression);
        $query = $this->applyTenantScope($query, 'orders', 'orders', $tenantId);

        return $query;
    }

    /**
     * @param array<int,string> $columns
     */
    protected function emptySubquery(array $columns): Builder
    {
        $query = DB::table('marketing_profiles as profiles')->whereRaw('1 = 0');

        foreach ($columns as $column) {
            $query->selectRaw($column);
        }

        return $query;
    }

    protected function applyProfileScope(Builder $query, string $column, ?array $profileIds): Builder
    {
        if ($profileIds === null) {
            return $query;
        }

        return $query->whereIn($column, $profileIds ?: [0]);
    }

    protected function applyTenantScope(Builder $query, string $table, string $alias, ?int $tenantId): Builder
    {
        if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
            return $query;
        }

        $column = $alias !== '' ? $alias . '.tenant_id' : 'tenant_id';

        if ($tenantId === null) {
            return $query->whereNull($column);
        }

        return $query->where($column, '=', $tenantId);
    }
}
