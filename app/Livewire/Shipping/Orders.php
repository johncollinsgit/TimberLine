<?php

namespace App\Livewire\Shipping;

use App\Models\Order;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Livewire\Component;
use Livewire\WithPagination;

class Orders extends Component
{
    use WithPagination;

    /**
     * Livewire is hydrating ?page= into this property in your build.
     * Without it you get: Property ...::$page does not exist
     */
    public int $page = 1;

    // Filters / view state
    public string $view = 'list';       // list | table | timeline
    public string $channel = 'all';     // all | wholesale | retail | event
    public string $status = 'all';      // all | new | reviewed | ...
    public string $search = '';

    /** @var array<int,bool> */
    public array $expanded = [];

    // Sorting (used in list/table)
    public string $sort = 'due_date';
    public string $dir  = 'asc';

    // Timeline month (YYYY-MM)
    public string $timelineYm = '';

    // Order edit state
    public array $orderEditing = []; // [orderId => true]
    public array $orderEdit    = []; // [orderId => ['due_date' => 'Y-m-d|null', 'status' => '...']]

    // Line qty editing state
    // lineEdit[lineId] = ['qty' => int]
    // lineDirty[lineId] = true (sparse map: key exists only when dirty)
    // lineOrder[lineId] = orderId
    public array $lineEdit  = [];
    public array $lineDirty = [];
    public array $lineOrder = [];

    // New line inputs per order
    // newLine[orderId] = ['scent_search' => '', 'size_search' => '', 'wick' => 'cotton', 'qty' => 1]
    public array $newLine = [];

    // Per-order notices
    public array $orderNotice = [];

    protected $queryString = [
        'view'       => ['except' => 'list'],
        'channel'    => ['except' => 'all'],
        'status'     => ['except' => 'all'],
        'search'     => ['except' => ''],
        'sort'       => ['except' => 'due_date'],
        'dir'        => ['except' => 'asc'],
        'timelineYm' => ['except' => ''],
        // NOTE: do NOT put 'page' here. Livewire handles pagination param separately.
    ];

    public const STATUSES = [
        'new',
        'reviewed',
        'submitted_to_pouring',
        'pouring',
        'brought_down',
        'verified',
        'complete',
    ];

    private const SORTS = [
        'due_date'     => 'due_date',
        'newest'       => 'id',
        'oldest'       => 'id',
        'order_number' => 'order_number',
        'customer'     => 'customer_name',
        'container'    => 'container_name',
        'status'       => 'status',
    ];

    // Wick options
    public const WICK_TYPES = ['cotton', 'wood'];

    public function mount(): void
    {
        if ($this->timelineYm === '') {
            $this->timelineYm = now()->format('Y-m');
        }
    }

    // Reset paging on filter changes + clear edit state (prevents ghost edits across filters)
    public function updatingView(): void       { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingChannel(): void    { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingStatus(): void     { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingSearch(): void     { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingSort(): void       { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingDir(): void        { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingTimelineYm(): void { $this->expanded = []; $this->clearEdits(); }

    private function clearEdits(): void
    {
        $this->orderEditing = [];
        $this->orderEdit    = [];

        $this->lineEdit  = [];
        $this->lineDirty = [];
        $this->lineOrder = [];

        $this->newLine      = [];
        $this->orderNotice  = [];

        $this->resetValidation();
        $this->resetErrorBag();
    }

    // --- Expand/collapse ---
    public function toggle(int $orderId): void
    {
        $opening = !($this->expanded[$orderId] ?? false);
        $this->expanded[$orderId] = $opening;

        if (!$opening) {
            return;
        }

        $this->hydrateLineDraftsForOrder($orderId);

        if (!isset($this->newLine[$orderId])) {
            $this->newLine[$orderId] = [
                'scent_search' => '',
                'size_search'  => '',
                'wick'         => 'cotton',
                'qty'          => 1,
            ];
        }
    }

    public function expandAll(): void
    {
        $orders = $this->baseQuery()->paginate(15);

        foreach ($orders->items() as $order) {
            $this->expanded[$order->id] = true;
            $this->hydrateLineDraftsForOrder($order->id);

            if (!isset($this->newLine[$order->id])) {
                $this->newLine[$order->id] = [
                    'scent_search' => '',
                    'size_search'  => '',
                    'wick'         => 'cotton',
                    'qty'          => 1,
                ];
            }
        }
    }

    public function collapseAll(): void
    {
        $this->expanded = [];
        $this->clearEdits();
    }

    // --- Timeline month nav (Blade calls these) ---
    public function timelinePrevMonth(): void
    {
        $this->timelineYm = Carbon::createFromFormat('Y-m', $this->timelineYm)->subMonth()->format('Y-m');
        $this->expanded = [];
        $this->clearEdits();
    }

    public function timelineNextMonth(): void
    {
        $this->timelineYm = Carbon::createFromFormat('Y-m', $this->timelineYm)->addMonth()->format('Y-m');
        $this->expanded = [];
        $this->clearEdits();
    }

    public function timelineToday(): void
    {
        $this->timelineYm = now()->format('Y-m');
        $this->expanded = [];
        $this->clearEdits();
    }

    // --- Order editing (due_date + status) ---
    public function startEditing(int $orderId): void
    {
        $order = Order::query()
            ->select(['id', 'due_date', 'status'])
            ->findOrFail($orderId);

        $this->orderEditing[$orderId] = true;

        $this->orderEdit[$orderId] = [
            'due_date' => blank($order->due_date) ? null : Carbon::parse($order->due_date)->format('Y-m-d'),
            'status'   => $order->status,
        ];
    }

    public function cancelEditing(int $orderId): void
    {
        unset($this->orderEditing[$orderId], $this->orderEdit[$orderId]);

        $this->resetErrorBag([
            "orderEdit.$orderId.due_date",
            "orderEdit.$orderId.status",
        ]);
    }

    public function saveOrder(int $orderId): void
    {
        $data = $this->orderEdit[$orderId] ?? null;
        abort_unless(is_array($data), 404);

        $validated = validator($data, [
            'due_date' => ['nullable', 'date'],
            'status'   => ['required', 'in:' . implode(',', self::STATUSES)],
        ])->validate();

        Order::query()
            ->whereKey($orderId)
            ->update([
                'due_date' => $validated['due_date'],
                'status'   => $validated['status'],
            ]);

        $this->cancelEditing($orderId);
    }

    // --- Line qty editing ---
    private function markLineDirty(int $lineId): void
    {
        if ($lineId <= 0) return;

        $this->lineDirty[$lineId] = true;

        if (!isset($this->lineOrder[$lineId])) {
            $this->ensureLineOrderForLine($lineId);
        }
    }

    private function ensureLineOrderForLine(int $lineId): ?int
    {
        if ($lineId <= 0) return null;

        if (isset($this->lineOrder[$lineId])) {
            return (int) $this->lineOrder[$lineId];
        }

        $lineModelClass = $this->lineModelClass();
        $orderId = $lineModelClass::query()->whereKey($lineId)->value('order_id');

        if ($orderId) {
            $this->lineOrder[$lineId] = (int) $orderId;
            return (int) $orderId;
        }

        return null;
    }

    public function incrementLineQty(int $lineId): void
    {
        $current = (int) ($this->lineEdit[$lineId]['qty'] ?? 0);
        $this->lineEdit[$lineId]['qty'] = $current + 1;
        $this->markLineDirty($lineId);
    }

    public function decrementLineQty(int $lineId): void
    {
        $current = (int) ($this->lineEdit[$lineId]['qty'] ?? 0);
        $this->lineEdit[$lineId]['qty'] = max(0, $current - 1);
        $this->markLineDirty($lineId);
    }

    /**
     * Fires for nested updates like lineEdit.{id}.qty when Livewire syncs.
     * Normalize to int >= 0 and mark dirty.
     */
    public function updated($name, $value): void
    {
        if (!is_string($name)) return;

        if (preg_match('/^lineEdit\.(\d+)\.qty$/', $name, $m)) {
            $lineId = (int) $m[1];
            if ($lineId <= 0) return;

            $qty = max(0, (int) $value);
            $this->lineEdit[$lineId]['qty'] = $qty;

            $this->markLineDirty($lineId);
        }
    }

    public function saveLine(int $lineId): void
    {
        $data = $this->lineEdit[$lineId] ?? null;
        abort_unless(is_array($data), 404);

        $validated = validator($data, [
            'qty' => ['required', 'integer', 'min:0', 'max:9999'],
        ])->validate();

        $qty = (int) $validated['qty'];

        $updates = [];

        if (Schema::hasColumn('order_lines', 'ordered_qty')) {
            $updates['ordered_qty'] = $qty;
        }
        if (Schema::hasColumn('order_lines', 'quantity')) {
            $updates['quantity'] = $qty; // legacy sync for now
        }

        abort_if(empty($updates), 500, 'No qty column found to update on order_lines.');

        $lineModelClass = $this->lineModelClass();
        $lineModelClass::query()->whereKey($lineId)->update($updates);

        unset($this->lineDirty[$lineId]); // keep sparse map behavior
    }

    public function saveOrderLines(int $orderId): void
    {
        if (empty($this->lineDirty)) return;

        $dirtyIds = array_keys($this->lineDirty);
        if (!$dirtyIds) return;

        foreach ($dirtyIds as $lineId) {
            $lineId = (int) $lineId;

            if (($this->lineOrder[$lineId] ?? null) !== $orderId) {
                continue;
            }

            $this->saveLine($lineId); // unsets lineDirty[$lineId]
        }

        $this->hydrateLineDraftsForOrder($orderId);

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Saved line item changes.',
        ]);
    }

    // --- New line helpers ---
    public function incrementNewLineQty(int $orderId): void
    {
        $current = (int) ($this->newLine[$orderId]['qty'] ?? 1);
        $this->newLine[$orderId]['qty'] = $current + 1;
    }

    public function decrementNewLineQty(int $orderId): void
    {
        $current = (int) ($this->newLine[$orderId]['qty'] ?? 1);
        $this->newLine[$orderId]['qty'] = max(0, $current - 1);
    }

    public function selectNewLineScent(int $orderId): void
    {
        $text = trim((string)($this->newLine[$orderId]['scent_search'] ?? ''));

        if ($text === '') {
            $this->addError("newLine.$orderId.scent_search", 'Type a scent name.');
            return;
        }

        $scent = Scent::query()
            ->where('is_active', true)
            ->where('name', $text)
            ->first(['id', 'name']);

        if (!$scent) {
            $this->addError("newLine.$orderId.scent_search", 'Pick a scent from the list.');
            return;
        }

        $this->newLine[$orderId]['scent_id'] = $scent->id;
        $this->newLine[$orderId]['scent_search'] = $scent->name;

        $this->resetErrorBag(["newLine.$orderId.scent_search"]);
    }

    public function selectNewLineSize(int $orderId): void
    {
        $text = trim((string)($this->newLine[$orderId]['size_search'] ?? ''));

        if ($text === '') {
            $this->addError("newLine.$orderId.size_search", 'Type a size code.');
            return;
        }

        $size = Size::query()
            ->where('is_active', true)
            ->where('code', $text)
            ->first(['id', 'code']);

        if (!$size) {
            $this->addError("newLine.$orderId.size_search", 'Pick a size from the list.');
            return;
        }

        $this->newLine[$orderId]['size_id'] = $size->id;
        $this->newLine[$orderId]['size_search'] = $size->code;

        $this->resetErrorBag(["newLine.$orderId.size_search"]);
    }

    public function deleteLine(int $lineId): void
    {
        $lineId = (int) $lineId;
        if ($lineId <= 0) return;

        $orderId = $this->ensureLineOrderForLine($lineId);

        $lineModelClass = $this->lineModelClass();

        DB::transaction(function () use ($lineModelClass, $lineId, $orderId) {
            $line = $lineModelClass::query()->lockForUpdate()->findOrFail($lineId);

            if ($orderId !== null && (int) $line->order_id !== (int) $orderId) {
                abort(409, 'Line/order mismatch.');
            }

            $line->delete();
        });

        unset($this->lineDirty[$lineId], $this->lineEdit[$lineId], $this->lineOrder[$lineId]);

        if ($orderId) {
            $this->orderNotice[$orderId] = 'Line item deleted.';
            $this->hydrateLineDraftsForOrder((int) $orderId);
        }
    }

    public function addLineItem(int $orderId): void
    {
        $this->resetErrorBag([
            "newLine.$orderId.scent_id",
            "newLine.$orderId.size_id",
            "newLine.$orderId.qty",
            "newLine.$orderId.wick",
            "newLine.$orderId.scent_search",
            "newLine.$orderId.size_search",
        ]);

        $data = $this->newLine[$orderId] ?? [];

        $scentSearch = trim((string) ($data['scent_search'] ?? ''));
        $sizeSearch  = trim((string) ($data['size_search'] ?? ''));
        $qtyToAdd    = (int) ($data['qty'] ?? 0);
        $wick        = strtolower(trim((string)($data['wick'] ?? 'cotton')));

        if (!in_array($wick, self::WICK_TYPES, true)) {
            $wick = 'cotton';
        }

        $scent = Scent::query()
            ->where('is_active', true)
            ->where('name', $scentSearch)
            ->first(['id', 'name']);

        $size = Size::query()
            ->where('is_active', true)
            ->where('code', $sizeSearch)
            ->first(['id', 'code']);

        $this->newLine[$orderId]['scent_id'] = $scent?->id;
        $this->newLine[$orderId]['size_id']  = $size?->id;
        $this->newLine[$orderId]['qty']      = $qtyToAdd;
        $this->newLine[$orderId]['wick']     = $wick;

        validator($this->newLine[$orderId], [
            'scent_id' => ['required', 'integer'],
            'size_id'  => ['required', 'integer'],
            'qty'      => ['required', 'integer', 'min:1', 'max:9999'],
            'wick'     => ['required', 'in:' . implode(',', self::WICK_TYPES)],
        ])->after(function ($v) use ($scent, $size) {
            if (!$scent) $v->errors()->add('scent_id', 'Pick a scent from the list.');
            if (!$size)  $v->errors()->add('size_id', 'Pick a size from the list.');
        })->validate();

        $qtyToAdd = (int) $this->newLine[$orderId]['qty'];
        $lineModelClass = $this->lineModelClass();

        $wickCol = null;
        if (Schema::hasColumn('order_lines', 'wick_type')) $wickCol = 'wick_type';
        elseif (Schema::hasColumn('order_lines', 'wick')) $wickCol = 'wick';

        DB::transaction(function () use ($lineModelClass, $orderId, $scent, $size, $qtyToAdd, $wick, $wickCol) {
            $q = $lineModelClass::query()
                ->where('order_id', $orderId)
                ->where('scent_id', $scent->id)
                ->where('size_id', $size->id);

            if ($wickCol) {
                $q->where($wickCol, $wick);
            }

            $existing = $q->lockForUpdate()->first();

            if ($existing) {
                $base = (int) (($existing->ordered_qty ?? $existing->quantity) ?? 0);
                $newQty = $base + $qtyToAdd;

                if (Schema::hasColumn('order_lines', 'ordered_qty')) {
                    $existing->setAttribute('ordered_qty', $newQty);
                }
                if (Schema::hasColumn('order_lines', 'quantity')) {
                    $existing->setAttribute('quantity', $newQty);
                }
                if (Schema::hasColumn('order_lines', 'scent_name')) {
                    $existing->setAttribute('scent_name', $scent->name);
                }
                if (Schema::hasColumn('order_lines', 'size_code')) {
                    $existing->setAttribute('size_code', $size->code);
                }
                if ($wickCol) {
                    $existing->setAttribute($wickCol, $wick);
                }

                $existing->save();
                return;
            }

            $line = new $lineModelClass();
            $line->setAttribute('order_id', $orderId);
            $line->setAttribute('scent_id', $scent->id);
            $line->setAttribute('size_id', $size->id);

            if (Schema::hasColumn('order_lines', 'ordered_qty')) {
                $line->setAttribute('ordered_qty', $qtyToAdd);
            }
            if (Schema::hasColumn('order_lines', 'quantity')) {
                $line->setAttribute('quantity', $qtyToAdd);
            }
            if (Schema::hasColumn('order_lines', 'scent_name')) {
                $line->setAttribute('scent_name', $scent->name);
            }
            if (Schema::hasColumn('order_lines', 'size_code')) {
                $line->setAttribute('size_code', $size->code);
            }
            if ($wickCol) {
                $line->setAttribute($wickCol, $wick);
            }

            $line->save();
        });

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Line item added.',
        ]);

        $this->newLine[$orderId] = [
            'scent_search' => '',
            'size_search'  => '',
            'wick'         => 'cotton',
            'qty'          => 1,
        ];

        $this->hydrateLineDraftsForOrder($orderId);
    }

    private function hydrateLineDraftsForOrder(int $orderId): void
    {
        $order = Order::query()
            ->whereKey($orderId)
            ->with([
                'lines' => function ($rel) {
                    $rel->with(['scent:id,name', 'size:id,code,label'])
                        ->orderBy('pour_status')
                        ->orderBy('scent_id')
                        ->orderBy('size_id');
                },
            ])
            ->firstOrFail();

        $lineIds = [];

        foreach (($order->lines ?? collect()) as $line) {
            $id = (int) $line->id;
            $lineIds[] = $id;

            $this->lineOrder[$id] = $orderId;

            $existingDraft = $this->lineEdit[$id]['qty'] ?? null;
            if ($existingDraft === null || !isset($this->lineDirty[$id])) {
                $this->lineEdit[$id] = [
                    'qty' => (int) (($line->ordered_qty ?? $line->quantity) ?? 0),
                ];
            }
        }

        if (!isset($this->newLine[$orderId])) {
            $this->newLine[$orderId] = [
                'scent_search' => '',
                'size_search'  => '',
                'wick'         => 'cotton',
                'qty'          => 1,
            ];
        }

        foreach ($lineIds as $id) {
            unset($this->lineDirty[$id]);
        }
    }

    private function lineModelClass(): string
    {
        $order = new Order();
        $relation = $order->lines();
        $model = $relation->getModel();

        return get_class($model);
    }

    // --- Query building ---
    private function applyChannelFilter(Builder $q): Builder
    {
        $channel = $this->channel ?: 'all';
        if ($channel === 'all') return $q;

        if (Schema::hasColumn('orders', 'channel')) {
            return $q->where('channel', $channel);
        }

        return $q->where(function (Builder $inner) use ($channel) {
            if ($channel === 'wholesale') {
                $inner->where('container_name', 'like', 'Wholesale:%');
                return;
            }

            if ($channel === 'event') {
                $inner->where('container_name', 'like', 'Market:%')
                    ->where(function (Builder $k) {
                        $k->where('container_name', 'like', '%festival%')
                          ->orWhere('container_name', 'like', '%market%')
                          ->orWhere('container_name', 'like', '%show%');
                    });
                return;
            }

            $inner->where(function (Builder $k) {
                $k->whereNull('container_name')
                  ->orWhere('container_name', 'not like', 'Wholesale:%');
            });
        });
    }

    private function baseQuery(): Builder
    {
        $status = $this->status ?: 'all';
        $search = trim($this->search);

        $sortKey = array_key_exists($this->sort, self::SORTS) ? $this->sort : 'due_date';
        $dir     = strtolower($this->dir) === 'desc' ? 'desc' : 'asc';

        $q = Order::query()
            ->with([
                'lines' => function ($rel) {
                    $rel->with(['scent:id,name', 'size:id,code,label'])
                        ->where(function ($q) {
                            $q->whereNotNull('scent_id')
                              ->orWhereNotNull('size_id')
                              ->orWhereNotNull('scent_name')
                              ->orWhereNotNull('size_code');
                        })
                        ->orderBy('pour_status')
                        ->orderBy('scent_name');
                },
            ]);

        if ($status !== 'all') {
            $q->where('status', $status);
        }

        $q = $this->applyChannelFilter($q);

        if ($search !== '') {
            $s = '%' . $search . '%';
            $q->where(function (Builder $inner) use ($s) {
                $inner->where('order_number', 'like', $s)
                      ->orWhere('container_name', 'like', $s)
                      ->orWhere('customer_name', 'like', $s)
                      ->orWhereHas('lines', function (Builder $l) use ($s) {
                          $l->where(function (Builder $ll) use ($s) {
                              $ll->where('scent_name', 'like', $s)
                                 ->orWhere('size_code', 'like', $s);
                          });
                      });
            });
        }

        $q->tap(function (Builder $qq) use ($sortKey, $dir) {
            if ($sortKey === 'due_date') {
                $qq->orderByRaw('CASE WHEN due_date IS NULL THEN 1 ELSE 0 END')
                   ->orderBy('due_date', $dir)
                   ->orderByDesc('id');
                return;
            }

            if ($sortKey === 'newest') { $qq->orderByDesc('id'); return; }
            if ($sortKey === 'oldest') { $qq->orderBy('id', 'asc'); return; }

            $col = self::SORTS[$sortKey] ?? 'due_date';
            $qq->orderBy($col, $dir)->orderByDesc('id');
        });

        return $q;
    }

    private function timelineQueryForMonth(Carbon $month): Builder
    {
        $start = $month->copy()->startOfMonth()->startOfWeek();
        $end   = $month->copy()->endOfMonth()->endOfWeek();

        $q = $this->baseQuery()
            ->whereNotNull('due_date')
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()]);

        $q->reorder('due_date', 'asc')->orderByDesc('id');

        return $q;
    }

    public function render()
    {
        $orders = $this->baseQuery()->paginate(15);

        $timelineMonth  = null;
        $timelineOrders = collect();

        if (($this->view ?? 'list') === 'timeline') {
            $timelineMonth  = Carbon::createFromFormat('Y-m', $this->timelineYm)->startOfMonth();
            $timelineOrders = $this->timelineQueryForMonth($timelineMonth)->get();
        }

        $scents = Scent::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['id', 'name']);

        $sizes = Size::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'code']);

        return view('livewire.shipping.orders', [
            'orders'         => $orders,
            'statuses'       => array_merge(['all'], self::STATUSES),
            'sorts'          => array_keys(self::SORTS),
            'timelineMonth'  => $timelineMonth,
            'timelineOrders' => $timelineOrders,
            'scents'         => $scents,
            'sizes'          => $sizes,
        ]);
    }
}
