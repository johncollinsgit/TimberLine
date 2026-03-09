<?php

namespace App\Livewire\Admin;

use App\Actions\ScentGovernance\CreateScentAliasAction;
use App\Models\MappingException;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Scent;
use App\Models\ShopifyImportRun;
use App\Models\Size;
use App\Services\ScentGovernance\ResolveScentMatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class MappingExceptions extends Component
{
    use WithPagination;

    protected $listeners = [
        'scentSelected' => 'handleScentSelected',
    ];

    public int $page = 1;

    public string $search = '';

    public string $filter = 'all'; // all | retail | wholesale

    public string $store = '';

    public string $account = '';

    public string $raw = '';

    public bool $onlyNeedsReview = true;

    public string $queueTab = 'needs'; // needs | excluded | normalized

    public string $groupBy = 'order'; // order | scent

    public string $sort = 'most'; // most | recent | alpha

    public array $expanded = [];

    public array $details = [];

    // Mapping modal
    public bool $showModal = false;

    public string $modalKey = '';

    public string $modalRawTitle = '';

    public array $modalExceptionIds = [];

    public ?int $modalSizeId = null;

    public ?string $modalWickType = null;

    public ?int $matchScentId = null;

    public string $matchScentSearch = '';

    public ?string $overrideScentName = null;

    public ?string $overrideSizeLabel = null;

    public bool $modalNeedsScent = false;

    public bool $modalNeedsSize = false;

    public bool $modalNeedsWick = false;

    public bool $modalCandleClub = false;

    public ?int $candleClubMonth = null;

    public ?int $candleClubYear = null;

    public string $candleClubScentName = '';

    public string $candleClubOil = '';

    public string $newScentName = '';

    public string $newScentDisplay = '';

    public string $newScentAbbr = '';

    public string $newScentOil = '';

    public bool $newScentIsBlend = false;

    public ?int $newScentBlendCount = null;

    // Order modal
    public bool $showOrderModal = false;

    public ?int $orderModalId = null;

    public array $orderModalExceptionIds = [];

    public array $orderModalLineExceptionIds = [];

    protected $queryString = [
        'search' => ['except' => ''],
        'filter' => ['except' => 'all'],
        'store' => ['except' => ''],
        'account' => ['except' => ''],
        'raw' => ['except' => ''],
        'onlyNeedsReview' => ['except' => true],
        'queueTab' => ['except' => 'needs'],
        'groupBy' => ['except' => 'order'],
        'sort' => ['except' => 'most'],
        'page' => ['except' => 1],
    ];

    public function mount(): void
    {
        $incomingChannel = strtolower(trim((string) request()->query('channel', '')));
        if ($this->filter === 'all' && in_array($incomingChannel, ['retail', 'wholesale'], true)) {
            $this->filter = $incomingChannel;
        }

        $this->store = trim((string) request()->query('store', $this->store));
        $this->account = trim((string) request()->query('account', $this->account));
        $this->raw = trim((string) request()->query('raw', $this->raw));

        if ($this->search === '') {
            $prefill = trim(implode(' ', array_filter([$this->raw, $this->account, $this->store])));
            if ($prefill !== '') {
                $this->search = $prefill;
            }
        }
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingFilter(): void
    {
        $this->resetPage();
    }

    public function updatingOnlyNeedsReview(): void
    {
        $this->resetPage();
    }

    public function updatingQueueTab(): void
    {
        $this->resetPage();
        $this->expanded = [];
        $this->details = [];
    }

    public function updatingGroupBy(): void
    {
        $this->resetPage();
        $this->expanded = [];
        $this->details = [];
    }

    public function updatingSort(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    public function toggleExpand(string $key, string $type, string $value): void
    {
        $this->expanded[$key] = ! ($this->expanded[$key] ?? false);
        if (! ($this->expanded[$key] ?? false)) {
            return;
        }

        if (! isset($this->details[$key])) {
            $this->details[$key] = $this->loadDetails($type, $value);
        }
    }

    public function openModalForGroup(string $key, string $rawTitle): void
    {
        $exceptions = $this->exceptionsQuery()
            ->where(function ($q) use ($rawTitle) {
                $q->where('raw_scent_name', $rawTitle)
                    ->orWhere('raw_title', $rawTitle);
            })
            ->pluck('id')
            ->all();

        $this->openModal($key, $rawTitle, $exceptions);
    }

    public function openModalForLine(string $key, int $exceptionId): void
    {
        $exception = MappingException::query()->find($exceptionId);
        if (! $exception) {
            return;
        }

        $rawTitle = (string) ($exception->raw_scent_name ?? $exception->raw_title ?? '');
        $exceptions = [$exception->id];

        $this->openModal($key, $rawTitle, $exceptions);
    }

    protected function openModal(string $key, string $rawTitle, array $exceptionIds): void
    {
        $this->modalKey = $key;
        $this->modalRawTitle = $rawTitle !== '' ? $rawTitle : 'Unlabeled';
        $this->modalExceptionIds = $exceptionIds;
        $this->modalSizeId = $this->suggestedSizeId($exceptionIds);
        $this->modalWickType = null;
        $this->overrideScentName = null;
        $this->overrideSizeLabel = null;

        $this->modalNeedsScent = false;
        $this->modalNeedsSize = false;
        $this->modalNeedsWick = false;
        $this->modalCandleClub = false;
        $this->candleClubMonth = (int) now()->month;
        $this->candleClubYear = (int) now()->year;
        $this->candleClubScentName = '';
        $this->candleClubOil = '';
        if (! empty($exceptionIds)) {
            $sample = MappingException::query()->whereIn('id', $exceptionIds)->first();
            if ($sample?->order_line_id) {
                $line = OrderLine::query()->find($sample->order_line_id);
                if ($line) {
                    $this->modalNeedsScent = empty($line->scent_id);
                    $this->modalNeedsSize = empty($line->size_id);
                    $this->modalNeedsWick = Schema::hasColumn('order_lines', 'wick_type') && empty($line->wick_type);
                    if ($this->modalNeedsSize && ! $this->modalSizeId) {
                        $this->modalSizeId = $this->inferSizeIdFromRaw($sample);
                    }
                    if ($this->modalNeedsWick && ! $this->modalWickType) {
                        $this->modalWickType = $this->inferWickFromRaw($sample);
                    }
                }
            }
            $inferredForm = $this->inferProductForm($sample, $this->modalSizeId);
            if (in_array($inferredForm, ['room_spray', 'wax_melt'], true)) {
                $this->modalNeedsWick = false;
                $this->modalWickType = null;
            }
            if (($sample?->reason ?? '') === 'candle_club') {
                $this->modalCandleClub = true;
                $this->modalNeedsScent = true;
            }
        }

        $clean = $this->cleanScentName($rawTitle);
        $this->newScentName = $clean;
        $this->newScentDisplay = $clean;
        $this->newScentAbbr = '';
        $this->newScentOil = '';
        $this->newScentIsBlend = false;
        $this->newScentBlendCount = null;
        $this->matchScentId = null;
        $this->matchScentSearch = '';

        $this->showModal = true;
    }

    public function closeModal(): void
    {
        $this->showModal = false;
    }

    public function getListeners(): array
    {
        return array_merge($this->listeners, [
            'intake-done' => 'closeModal',
        ]);
    }

    public function openOrderModal(int $orderId, array $exceptionIds = []): void
    {
        $this->orderModalId = $orderId;
        $this->orderModalExceptionIds = $exceptionIds;
        $this->showOrderModal = true;
    }

    public function openModalForOrder(int $orderId): void
    {
        $exception = $this->exceptionsQuery()
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->first();

        if (! $exception) {
            return;
        }

        $rawTitle = (string) ($exception->raw_scent_name ?? $exception->raw_title ?? '');
        $this->openModal('order-'.$orderId, $rawTitle, [$exception->id]);
    }

    public function closeOrderModal(): void
    {
        $this->showOrderModal = false;
        $this->orderModalId = null;
        $this->orderModalExceptionIds = [];
        $this->orderModalLineExceptionIds = [];
    }

    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        if ($key !== 'mapping-match') {
            return;
        }

        $this->matchScentId = $scentId;
        $this->matchScentSearch = $scentName ?? '';
    }

    public function saveGroup(): void
    {
        if (empty($this->modalExceptionIds)) {
            return;
        }

        $sizeId = $this->modalSizeId ? (int) $this->modalSizeId : null;
        $scentId = $this->matchScentId ? (int) $this->matchScentId : null;
        $wickType = $this->modalWickType ? strtolower($this->modalWickType) : null;
        $sample = ! empty($this->modalExceptionIds)
            ? MappingException::query()->whereIn('id', $this->modalExceptionIds)->first()
            : null;

        if (! empty($this->overrideSizeLabel)) {
            $needle = $this->normalizeSize($this->overrideSizeLabel);
            $sizeIndex = $this->buildSizeIndex();
            $sizeId = $sizeIndex[$needle] ?? $sizeId;
        }

        if (! empty($this->overrideScentName)) {
            $this->matchScentSearch = $this->overrideScentName;
            $scentId = null;
        }

        $productForm = $this->inferProductForm($sample, $sizeId);
        $isNonCandleForm = in_array($productForm, ['room_spray', 'wax_melt'], true);
        if ($isNonCandleForm) {
            $nonCandleSizeId = $this->sizeIdForProductForm($productForm);
            if ($nonCandleSizeId) {
                $sizeId = $nonCandleSizeId;
            }
            $wickType = null;
        }

        if (! $scentId && $this->matchScentSearch !== '') {
            $context = [
                'store_key' => (string) ($sample?->store_key ?? ''),
                'account_name' => (string) ($sample?->account_name ?? ''),
                'is_wholesale' => (string) ($sample?->store_key ?? '') === 'wholesale' || filled($sample?->account_name),
            ];

            $resolver = app(ResolveScentMatchService::class);
            $scentId = $resolver->resolveSingleCandidateId($this->matchScentSearch, $context, 90)
                ?? $resolver->findExistingScent($this->matchScentSearch, $context)?->id;
        }

        if ($this->modalCandleClub) {
            $name = trim($this->candleClubScentName);
            if ($name === '' || ! $this->candleClubMonth || ! $this->candleClubYear || trim($this->candleClubOil) === '') {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => 'Candle Club needs Month, Year, Scent name, and Oil.',
                ]);

                return;
            }
            if (! $scentId) {
                $this->dispatch('toast', [
                    'type' => 'warning',
                    'message' => 'Pick an existing scent or launch the New Scent Wizard first.',
                ]);
                $this->redirect($this->wizardUrlForModal($name, true), navigate: true);

                return;
            }

            \App\Models\CandleClubScent::query()->updateOrCreate(
                ['month' => (int) $this->candleClubMonth, 'year' => (int) $this->candleClubYear],
                ['scent_id' => $scentId]
            );
        }

        if (! $scentId) {
            $name = trim($this->newScentName) !== '' ? trim($this->newScentName) : trim($this->modalRawTitle);
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'No canonical scent selected. Launching the New Scent Wizard.',
            ]);
            $this->redirect($this->wizardUrlForModal($name, false), navigate: true);

            return;
        }

        $exceptions = MappingException::query()->whereIn('id', $this->modalExceptionIds)->get();
        DB::transaction(function () use ($exceptions, $scentId, $sizeId, $wickType, $isNonCandleForm) {
            foreach ($exceptions as $exception) {
                $line = $exception->order_line_id
                    ? OrderLine::query()->find($exception->order_line_id)
                    : null;

                if (! $line) {
                    continue;
                }

                $line->scent_id = $scentId;
                if ($sizeId) {
                    $line->size_id = $sizeId;
                }

                if ($isNonCandleForm && Schema::hasColumn('order_lines', 'wick_type')) {
                    $line->wick_type = null;
                } elseif ($wickType) {
                    $line->wick_type = $wickType;
                } elseif (Schema::hasColumn('order_lines', 'wick_type') && empty($line->wick_type)) {
                    $wick = $this->detectWickFromPayload($exception->payload_json ?? []);
                    if ($wick) {
                        $line->wick_type = $wick;
                    }
                }

                if (Schema::hasColumn('order_lines', 'scent_name')) {
                    $line->scent_name = Scent::query()->find($scentId)?->name;
                }

                if ($sizeId && Schema::hasColumn('order_lines', 'size_code')) {
                    $line->size_code = Size::query()->find($sizeId)?->code;
                }

                $line->save();

                if ($this->canResolveException($line)) {
                    $exception->canonical_scent_id = $scentId;
                    $exception->resolved_at = now();
                    $exception->resolved_by = auth()->id();
                    $exception->save();

                    if ($exception->order_id) {
                        $hasOpen = MappingException::query()
                            ->where('order_id', $exception->order_id)
                            ->whereNull('resolved_at')
                            ->exists();
                        if (! $hasOpen) {
                            \App\Models\Order::query()
                                ->whereKey($exception->order_id)
                                ->update(['requires_shipping_review' => false]);
                        }
                    }
                }
            }
        });

        $accountNames = $exceptions->pluck('account_name')->filter()->unique();
        if ($scentId && $accountNames->isNotEmpty()) {
            foreach ($accountNames as $accountName) {
                $accountName = trim((string) $accountName);
                $existing = \App\Models\WholesaleCustomScent::query()
                    ->whereRaw('lower(account_name) = ?', [mb_strtolower($accountName)])
                    ->get()
                    ->first(function (\App\Models\WholesaleCustomScent $row) use ($accountName) {
                        return \App\Models\WholesaleCustomScent::normalizeAccountName($row->account_name)
                            === \App\Models\WholesaleCustomScent::normalizeAccountName($accountName);
                    });

                $customName = $this->modalRawTitle !== '' ? trim($this->modalRawTitle) : null;
                if ($customName) {
                    \App\Models\WholesaleCustomScent::query()->updateOrCreate(
                        [
                            'account_name' => $accountName,
                            'custom_scent_name' => $customName,
                        ],
                        [
                            'canonical_scent_id' => $scentId,
                            'active' => true,
                        ]
                    );
                } elseif ($existing) {
                    $existing->canonical_scent_id = $scentId;
                    $existing->active = true;
                    $existing->save();
                }
            }
        }

        if ($scentId && Schema::hasTable('scent_aliases')) {
            $scent = Scent::query()->find($scentId, ['name', 'display_name']);
            $canonicalValues = collect([
                trim((string) ($scent?->name ?? '')),
                trim((string) ($scent?->display_name ?? '')),
            ])->filter()->all();

            $aliases = $exceptions
                ->flatMap(fn (MappingException $exception): array => [
                    trim((string) ($exception->raw_scent_name ?? '')),
                    trim((string) ($exception->raw_title ?? '')),
                ])
                ->filter()
                ->unique();

            app(CreateScentAliasAction::class)->syncAcrossScopes(
                $scentId,
                $aliases->all(),
                ['markets'],
                $canonicalValues
            );
        }

        $this->showModal = false;
        $this->dispatch('toast', [
            'type' => 'success',
            'message' => $this->modalCandleClub
                ? 'Candle Club scent saved. Admin + Wiki updated.'
                : 'Mapping applied and exceptions resolved.',
        ]);
    }

    protected function canResolveException(OrderLine $line): bool
    {
        return ! empty($line->scent_id) && ! empty($line->size_id);
    }

    protected function exceptionsQuery()
    {
        $query = MappingException::query();

        if ($this->queueTab === 'excluded') {
            $query->whereNotNull('excluded_at');
        } else {
            $query->whereNull('excluded_at');
        }

        if ($this->onlyNeedsReview && $this->queueTab !== 'excluded') {
            $query->whereNull('resolved_at');
        }

        if ($this->filter !== 'all') {
            $query->where('store_key', $this->filter);
        }

        if ($this->search !== '') {
            $s = '%'.$this->search.'%';
            $query->where(function ($q) use ($s) {
                $q->where('raw_title', 'like', $s)
                    ->orWhere('raw_scent_name', 'like', $s)
                    ->orWhere('raw_variant', 'like', $s)
                    ->orWhere('sku', 'like', $s)
                    ->orWhere('account_name', 'like', $s)
                    ->orWhereHas('order', function ($oq) use ($s) {
                        $oq->where('order_number', 'like', $s)
                            ->orWhere('order_label', 'like', $s)
                            ->orWhere('customer_name', 'like', $s);
                    });
            });
        }

        if ($this->store !== '') {
            $query->where('store_key', 'like', '%'.$this->store.'%');
        }

        if ($this->account !== '') {
            $query->where('account_name', 'like', '%'.$this->account.'%');
        }

        if ($this->raw !== '') {
            $raw = '%'.$this->raw.'%';
            $query->where(function ($q) use ($raw) {
                $q->where('raw_scent_name', 'like', $raw)
                    ->orWhere('raw_title', 'like', $raw);
            });
        }

        return $query;
    }

    protected function normalizationsQuery()
    {
        $query = \App\Models\ImportNormalization::query();

        if ($this->filter !== 'all') {
            $query->where('store_key', $this->filter);
        }

        if ($this->search !== '') {
            $s = '%'.$this->search.'%';
            $query->where(function ($q) use ($s) {
                $q->where('raw_value', 'like', $s)
                    ->orWhere('normalized_value', 'like', $s)
                    ->orWhere('field', 'like', $s);
            });
        }

        return $query;
    }

    public function excludeGroup(string $rawTitle, string $reason = 'excluded'): void
    {
        $ids = $this->exceptionsQuery()
            ->where(function ($q) use ($rawTitle) {
                $q->where('raw_scent_name', $rawTitle)
                    ->orWhere('raw_title', $rawTitle);
            })
            ->pluck('id')
            ->all();

        $this->excludeExceptions($ids, $reason);
    }

    public function excludeOrder(int $orderId, string $reason = 'excluded'): void
    {
        $ids = $this->exceptionsQuery()
            ->where('order_id', $orderId)
            ->pluck('id')
            ->all();

        $this->excludeExceptions($ids, $reason);
    }

    public function excludeLine(int $exceptionId, string $reason = 'excluded'): void
    {
        $this->excludeExceptions([$exceptionId], $reason);
    }

    public function restoreGroup(string $rawTitle): void
    {
        $ids = MappingException::query()
            ->whereNotNull('excluded_at')
            ->where(function ($q) use ($rawTitle) {
                $q->where('raw_scent_name', $rawTitle)
                    ->orWhere('raw_title', $rawTitle);
            })
            ->pluck('id')
            ->all();

        $this->restoreExceptions($ids);
    }

    public function restoreLine(int $exceptionId): void
    {
        $this->restoreExceptions([$exceptionId]);
    }

    public function restoreOrder(int $orderId): void
    {
        $ids = MappingException::query()
            ->whereNotNull('excluded_at')
            ->where('order_id', $orderId)
            ->pluck('id')
            ->all();

        $this->restoreExceptions($ids);
    }

    protected function excludeExceptions(array $ids, string $reason): void
    {
        if (empty($ids)) {
            return;
        }

        MappingException::query()
            ->whereIn('id', $ids)
            ->update([
                'excluded_at' => now(),
                'excluded_by' => auth()->user()?->email ?? 'system',
                'excluded_reason' => $reason,
            ]);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Exception excluded from review.',
        ]);
    }

    public function excludeFromModal(string $reason = 'excluded'): void
    {
        $this->excludeExceptions($this->modalExceptionIds, $reason);
        $this->showModal = false;
    }

    protected function restoreExceptions(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        MappingException::query()
            ->whereIn('id', $ids)
            ->update([
                'excluded_at' => null,
                'excluded_by' => null,
                'excluded_reason' => null,
            ]);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Exception restored to review queue.',
        ]);
    }

    protected function groupQuery()
    {
        $base = $this->exceptionsQuery();

        if ($this->groupBy === 'order') {
            $query = $base->whereNotNull('order_id')
                ->selectRaw('order_id, COUNT(*) as lines_count, MIN(created_at) as first_seen, MAX(created_at) as last_seen')
                ->groupBy('order_id');

            if ($this->sort === 'most') {
                $query->orderByDesc('lines_count');
            } elseif ($this->sort === 'alpha') {
                $query->orderBy('order_id');
            } else {
                $query->orderByDesc('last_seen');
            }

            return $query;
        }

        $query = $base->selectRaw('COALESCE(raw_scent_name, raw_title) as raw_title, COUNT(*) as lines_count, COUNT(DISTINCT order_id) as orders_count, MIN(created_at) as first_seen, MAX(created_at) as last_seen')
            ->groupBy('raw_title');

        if ($this->sort === 'most') {
            $query->orderByDesc('lines_count');
        } elseif ($this->sort === 'alpha') {
            $query->orderBy('raw_title');
        } else {
            $query->orderByDesc('last_seen');
        }

        return $query;
    }

    protected function summaryCounts(): array
    {
        $base = $this->exceptionsQuery();

        return [
            'total' => (clone $base)->count(),
            'scents' => (clone $base)->selectRaw('COUNT(DISTINCT COALESCE(raw_scent_name, raw_title)) as count')->value('count') ?? 0,
            'orders' => (clone $base)->selectRaw('COUNT(DISTINCT order_id) as count')->value('count') ?? 0,
        ];
    }

    protected function loadDetails(string $type, string $value): array
    {
        $query = $this->exceptionsQuery()->with([
            'order' => function ($q) {
                $q->select(['id', 'order_number', 'order_label', 'customer_name', 'created_at', 'ordered_at', 'due_at', 'ship_by_at', 'order_type']);
            },
            'orderLine' => function ($q) {
                $q->select(['id', 'order_id', 'ordered_qty', 'quantity', 'raw_title', 'raw_variant', 'sku', 'wick_type', 'size_id', 'scent_id'])
                    ->withCount(['scentSplits as split_count']);
            },
        ])->select(['id', 'store_key', 'order_id', 'order_line_id', 'raw_title', 'raw_variant', 'raw_scent_name', 'account_name', 'sku', 'reason', 'payload_json', 'created_at', 'excluded_reason']);

        if ($type === 'order') {
            $query->where('order_id', (int) $value);
        } else {
            $query->where(function ($q) use ($value) {
                $q->where('raw_scent_name', $value)
                    ->orWhere('raw_title', $value);
            });
        }

        $exceptions = $query->orderByDesc('id')->get();

        $sizes = Size::query()->get()->keyBy('id');
        $sizeIndex = $this->buildSizeIndex();

        $parser = new \App\Support\Shopify\InfiniteOptionsParser;

        return $exceptions->map(function (MappingException $exception) use ($sizes, $sizeIndex, $parser) {
            $line = $exception->orderLine;
            $order = $exception->order;
            $qty = $line?->ordered_qty ?? $line?->quantity ?? null;

            $status = [];
            if (! $line?->scent_id) {
                $status[] = 'unmapped scent';
            }
            if (! $line?->size_id) {
                $status[] = 'unmapped size';
            }
            if (Schema::hasColumn('order_lines', 'wick_type') && empty($line?->wick_type)) {
                $status[] = 'unmapped wick';
            }

            $sizeLabel = null;
            if ($line?->size_id) {
                $sizeLabel = $sizes[$line->size_id]->label ?? $sizes[$line->size_id]->code ?? null;
            } elseif (! empty($exception->raw_variant)) {
                $normalized = $this->normalizeSize((string) $exception->raw_variant);
                $guessId = $sizeIndex[$normalized] ?? null;
                if ($guessId && isset($sizes[$guessId])) {
                    $sizeLabel = $sizes[$guessId]->label ?? $sizes[$guessId]->code ?? null;
                }
            }

            $productForm = $this->inferProductForm($exception, $line?->size_id ? (int) $line->size_id : null);

            $bundleSelections = [];
            $payload = $exception->payload_json ?? [];
            if (! empty($payload['bundle_properties'])) {
                $bundleSelections = $parser->parseBundleSelections([
                    'properties' => $payload['bundle_properties'],
                ]);
            } elseif (! empty($payload['properties'])) {
                $bundleSelections = $parser->parseBundleSelections([
                    'properties' => $payload['properties'],
                ]);
            }

            [$noteLines, $labelText] = $this->extractPayloadNotesAndLabel($payload);
            $notesPreview = collect($noteLines)->take(2)->implode(' · ');

            return [
                'id' => $exception->id,
                'store_key' => $exception->store_key,
                'order_id' => $exception->order_id,
                'order_number' => $order?->order_number,
                'order_created_at' => $order?->created_at,
                'order_customer' => $order?->order_label ?? $order?->customer_name,
                'raw_title' => $exception->raw_title,
                'raw_variant' => $exception->raw_variant,
                'raw_scent_name' => $exception->raw_scent_name,
                'account_name' => $exception->account_name,
                'sku' => $exception->sku,
                'qty' => $qty,
                'wick' => $line?->wick_type,
                'size' => $sizeLabel,
                'product_form' => $productForm,
                'status' => $status ?: ['unmapped scent'],
                'payload' => $exception->payload_json ?? [],
                'bundle_selections' => $bundleSelections,
                'split_count' => max(0, (int) ($line?->split_count ?? 0)),
                'has_notes' => $noteLines !== [],
                'notes_preview' => $notesPreview,
                'label_text' => $labelText,
                'excluded_reason' => $exception->excluded_reason,
            ];
        })->all();
    }

    protected function suggestedSizeId(array $exceptionIds): ?int
    {
        if (empty($exceptionIds)) {
            return null;
        }

        $exceptions = MappingException::query()
            ->whereIn('id', $exceptionIds)
            ->get(['raw_variant']);

        $sizeIndex = $this->buildSizeIndex();
        foreach ($exceptions as $exception) {
            $normalizedVariant = $this->normalizeSize((string) ($exception->raw_variant ?? ''));
            $sizeId = $sizeIndex[$normalizedVariant] ?? null;
            if ($sizeId) {
                return $sizeId;
            }
        }

        return null;
    }

    protected function inferSizeIdFromRaw(MappingException $exception): ?int
    {
        $rawVariant = (string) ($exception->raw_variant ?? '');
        $rawTitle = (string) ($exception->raw_title ?? '');
        $needle = $this->normalizeSize($rawVariant !== '' ? $rawVariant : $rawTitle);
        if ($needle === '') {
            return null;
        }
        $sizeIndex = $this->buildSizeIndex();

        return $sizeIndex[$needle] ?? null;
    }

    protected function inferWickFromRaw(MappingException $exception): ?string
    {
        $rawVariant = (string) ($exception->raw_variant ?? '');
        $rawTitle = (string) ($exception->raw_title ?? '');
        $text = strtolower($rawVariant.' '.$rawTitle);
        if (str_contains($text, 'cedar') || str_contains($text, 'wood')) {
            return 'cedar';
        }
        if (str_contains($text, 'cotton')) {
            return 'cotton';
        }

        return null;
    }

    protected function buildSizeIndex(): array
    {
        $sizes = Size::query()->orderBy('label')->orderBy('code')->get();

        $sizeIndex = [];
        foreach ($sizes as $size) {
            $code = $size->code ?? '';
            $label = $size->label ?? '';
            $sizeIndex[$this->normalizeSize($code)] = $size->id;
            if ($label !== '') {
                $sizeIndex[$this->normalizeSize($label)] = $size->id;
            }
        }

        return $sizeIndex;
    }

    protected function normalizeSize(string $value): string
    {
        $lower = strtolower($value);
        $lower = str_replace([' ', '-', '_'], '', $lower);
        $lower = str_replace(['ounces', 'ounce'], 'oz', $lower);
        $lower = str_replace('o z', 'oz', $lower);
        if ($lower === 'waxmelt') {
            $lower = 'waxmelts';
        }
        if ($lower === 'roomspray') {
            $lower = 'roomsprays';
        }

        return preg_replace('/[^a-z0-9]+/i', '', $lower) ?? '';
    }

    protected function sizeIdForProductForm(string $productForm): ?int
    {
        if (! Schema::hasTable('sizes')) {
            return null;
        }

        $sizeIndex = $this->buildSizeIndex();

        if ($productForm === 'room_spray') {
            return $sizeIndex[$this->normalizeSize('room sprays')] ?? $sizeIndex[$this->normalizeSize('room spray')] ?? null;
        }

        if ($productForm === 'wax_melt') {
            return $sizeIndex[$this->normalizeSize('wax melts')] ?? $sizeIndex[$this->normalizeSize('wax melt')] ?? null;
        }

        return null;
    }

    protected function inferProductForm(?MappingException $exception, ?int $sizeId = null): string
    {
        if ($sizeId) {
            $size = Size::query()->find($sizeId, ['code', 'label']);
            if ($size) {
                $fromSize = $this->productFormFromText((string) $size->code.' '.(string) $size->label);
                if ($fromSize !== '') {
                    return $fromSize;
                }
            }
        }

        if (! $exception) {
            return '';
        }

        $payload = $exception->payload_json;
        if ($this->hasWickPropertyInPayload(is_array($payload) ? $payload : [])) {
            return 'candle';
        }

        $fromRawText = $this->productFormFromText(
            trim((string) ($exception->raw_variant ?? '').' '.(string) ($exception->raw_title ?? '').' '.(string) ($exception->raw_scent_name ?? ''))
        );
        if ($fromRawText !== '') {
            return $fromRawText;
        }

        $rawVariant = (string) ($exception->raw_variant ?? '');
        $rawName = trim((string) ($exception->raw_scent_name ?: $exception->raw_title ?: ''));

        return $this->wizardProductFormHint($rawVariant, $rawName);
    }

    protected function productFormFromText(string $text): string
    {
        $haystack = mb_strtolower(trim($text));
        if ($haystack === '') {
            return '';
        }

        if (str_contains($haystack, 'room spray') || str_contains($haystack, 'roomspray')) {
            return 'room_spray';
        }

        if (str_contains($haystack, 'wax melt') || str_contains($haystack, 'waxmelt') || str_contains($haystack, 'wm')) {
            return 'wax_melt';
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array{0:array<int,string>,1:string}
     */
    protected function extractPayloadNotesAndLabel(array $payload): array
    {
        $notes = [];
        $label = '';

        $pushLines = function (?string $value) use (&$notes): void {
            $value = trim((string) $value);
            if ($value === '') {
                return;
            }

            $lines = preg_split('/\r\n|\r|\n/u', $value) ?: [];
            foreach ($lines as $line) {
                $line = trim((string) $line);
                if ($line !== '') {
                    $notes[] = $line;
                }
            }
        };

        $pushLines((string) ($payload['note'] ?? ''));
        $lineItem = $payload['line_item'] ?? null;
        if (is_array($lineItem)) {
            $pushLines((string) ($lineItem['note'] ?? ''));
        }

        $properties = $payload['properties'] ?? (is_array($lineItem) ? ($lineItem['properties'] ?? null) : null);
        if (is_array($properties)) {
            foreach ($properties as $property) {
                $name = trim((string) ($property['name'] ?? ''));
                $value = trim((string) ($property['value'] ?? ''));
                if ($name === '' || $value === '') {
                    continue;
                }

                $nameLower = mb_strtolower($name);
                if ($label === '' && str_contains($nameLower, 'label')) {
                    $label = $value;
                }

                if (
                    str_contains($nameLower, 'note')
                    || str_contains($nameLower, 'instruction')
                    || str_contains($nameLower, 'message')
                    || str_contains($nameLower, 'personal')
                    || str_contains($nameLower, 'label')
                    || str_contains($nameLower, 'custom')
                    || str_contains($nameLower, 'text')
                    || str_contains($nameLower, 'split')
                ) {
                    $notes[] = $name.': '.$value;
                }
            }
        }

        $notes = collect($notes)
            ->map(fn (string $line): string => trim($line))
            ->filter(fn (string $line): bool => $line !== '')
            ->unique()
            ->values()
            ->all();

        return [$notes, $label];
    }

    protected function cleanScentName(string $value): string
    {
        $clean = trim($value);
        $clean = str_replace('*', '', $clean);
        $clean = preg_replace('/\b(wholesale|retail|market|event)\b\s*/i', '', $clean) ?? $clean;
        $clean = preg_replace('/\b(\d+\s?oz|ounce|ounces|wax\s?melt|wax\s?melts|melt|melts|room\s?spray|spray|top\s?off)\b/i', '', $clean) ?? $clean;
        $clean = trim(preg_replace('/\s+/', ' ', $clean) ?? $clean);

        return $clean;
    }

    protected function detectWickFromPayload(array $payload): ?string
    {
        $text = '';
        if (! empty($payload['properties_wick'])) {
            $text = (string) $payload['properties_wick'];
        } elseif (! empty($payload['properties']) && is_array($payload['properties'])) {
            foreach ($payload['properties'] as $prop) {
                $name = strtolower((string) ($prop['name'] ?? ''));
                if (str_contains($name, 'wick')) {
                    $text = (string) ($prop['value'] ?? '');
                    break;
                }
            }
        }

        $text = strtolower($text);
        if ($text === '') {
            return null;
        }

        if (str_contains($text, 'cedar') || str_contains($text, 'wood')) {
            return 'cedar';
        }

        if (str_contains($text, 'cotton')) {
            return 'cotton';
        }

        return null;
    }

    protected function wizardUrlForModal(string $rawName, bool $isCandleClub): string
    {
        $exception = ! empty($this->modalExceptionIds)
            ? MappingException::query()->whereIn('id', $this->modalExceptionIds)->first()
            : null;

        $channelHint = ($exception?->store_key === 'wholesale' || ! empty($exception?->account_name))
            ? 'wholesale'
            : ($isCandleClub ? 'candle_club' : 'retail');

        $query = [
            'raw' => $rawName !== '' ? $rawName : $this->modalRawTitle,
            'variant' => (string) ($exception?->raw_variant ?? ''),
            'account' => (string) ($exception?->account_name ?? ''),
            'store' => (string) ($exception?->store_key ?? ''),
            'source_context' => 'scent-intake',
            'channel_hint' => $channelHint,
            'product_form_hint' => $this->wizardProductFormHint(
                (string) ($exception?->raw_variant ?? ''),
                $rawName,
                is_array($exception?->payload_json) ? $exception->payload_json : []
            ),
            'return_to' => route('admin.index', ['tab' => 'scent-intake']),
        ];

        if ($isCandleClub) {
            $query['store'] = 'retail';
            $query['channel_hint'] = 'candle_club';
        }

        return route('admin.scent-wizard', array_filter($query, fn ($value) => $value !== ''));
    }

    protected function wizardProductFormHint(string $variant, string $rawName, array $payload = []): string
    {
        if ($this->hasWickPropertyInPayload($payload)) {
            return 'candle';
        }

        $haystack = mb_strtolower(trim($variant.' '.$rawName));
        if ($haystack === '') {
            return '';
        }

        if (str_contains($haystack, 'wax melt') || str_contains($haystack, 'wm')) {
            return 'wax_melt';
        }

        if (str_contains($haystack, 'room spray')) {
            return 'room_spray';
        }

        return '';
    }

    protected function hasWickPropertyInPayload(array $payload): bool
    {
        if (filled($payload['properties_wick'] ?? null)) {
            return true;
        }

        $properties = $payload['properties'] ?? null;
        if (! is_array($properties)) {
            return false;
        }

        foreach ($properties as $property) {
            $name = mb_strtolower(trim((string) ($property['name'] ?? '')));
            if ($name !== '' && str_contains($name, 'wick')) {
                return true;
            }
        }

        return false;
    }

    public function render()
    {
        $normalizations = null;
        if ($this->queueTab === 'normalized') {
            $normalizations = $this->normalizationsQuery()
                ->orderByDesc('id')
                ->paginate(20);
        }

        $groups = $this->queueTab === 'normalized' ? null : $this->groupQuery()->paginate(12);
        $sizes = Size::query()->orderBy('label')->orderBy('code')->get();
        $summary = $this->queueTab === 'normalized'
            ? [
                'total' => $this->normalizationsQuery()->count(),
                'scents' => 0,
                'orders' => 0,
            ]
            : $this->summaryCounts();
        $latestRun = ShopifyImportRun::query()->orderByDesc('id')->first();

        $orderIndex = [];
        if ($this->groupBy === 'order' && $groups) {
            $orderIds = collect($groups->items())->pluck('order_id')->filter()->unique()->all();
            if ($orderIds) {
                $orderIndex = Order::query()
                    ->whereIn('id', $orderIds)
                    ->get()
                    ->keyBy('id')
                    ->all();
            }
        }

        $orderModal = null;
        $orderModalLines = [];
        $orderModalPayloads = [];
        if ($this->showOrderModal && $this->orderModalId) {
            $orderModal = Order::query()->find($this->orderModalId);
            if ($orderModal) {
                $orderModalLines = OrderLine::query()
                    ->where('order_id', $orderModal->id)
                    ->orderBy('id')
                    ->withCount(['scentSplits as split_count'])
                    ->get();

                $orderModalPayloads = MappingException::query()
                    ->where('order_id', $orderModal->id)
                    ->whereNull('resolved_at')
                    ->pluck('payload_json')
                    ->all();

                $orderModalLineExceptionIds = MappingException::query()
                    ->where('order_id', $orderModal->id)
                    ->whereNull('resolved_at')
                    ->pluck('order_line_id')
                    ->filter()
                    ->all();
                $this->orderModalLineExceptionIds = $orderModalLineExceptionIds;
            }
        }

        return view('livewire.admin.mapping-exceptions', [
            'groups' => $groups,
            'normalizations' => $normalizations,
            'sizes' => $sizes,
            'summary' => $summary,
            'latestRun' => $latestRun,
            'orderIndex' => $orderIndex,
            'orderModal' => $orderModal,
            'orderModalLines' => $orderModalLines,
            'orderModalPayloads' => $orderModalPayloads,
            'orderModalLineExceptionIds' => $this->orderModalLineExceptionIds,
        ]);
    }
}
