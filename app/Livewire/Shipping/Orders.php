<?php

namespace App\Livewire\Shipping;

use App\Models\Order;
use App\Models\Scent;
use App\Models\Size;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Livewire\Component;
use Livewire\WithPagination;
use App\Services\Shipping\BusinessDayCalculator;
use Carbon\CarbonImmutable;

class Orders extends Component
{
    protected $listeners = [
        'scentSelected' => 'handleScentSelected',
    ];
    use WithPagination;

    /**
     * Livewire is hydrating ?page= into this property in your build.
     * Without it you get: Property ...::$page does not exist
     */
    public int $page = 1;

    // Filters / view state
    public string $view = 'table';       // list | table | timeline | gantt
    public string $channel = 'all';     // all | wholesale | retail | event
    public string $source = 'all';      // all | shopify_retail | shopify_wholesale | manual
    public string $status = 'all';      // all | new | reviewed | ...
    public string $search = '';

    /** @var array<int,bool> */
    public array $expanded = [];

    // Sorting (used in list/table)
    public string $sort = 'ship_by_at';
    public string $dir  = 'asc';

    // Timeline month (YYYY-MM)
    public string $timelineYm = '';
    // Gantt window start (YYYY-MM-DD)
    public string $ganttStart = '';
    public int $ganttDays = 120;

    // Order edit state
    public array $orderEditing = []; // [orderId => true]
    public array $orderEdit    = []; // [orderId => ['due_at' => 'Y-m-d|null', 'ship_by_at' => 'Y-m-d|null', 'status' => '...']]

    // Line qty editing state
    // lineEdit[lineId] = ['qty' => int]
    // lineDirty[lineId] = true (sparse map: key exists only when dirty)
    // lineOrder[lineId] = orderId
    public array $lineEdit  = [];
    public array $lineDirty = [];
    public array $lineOrder = [];

    // New line inputs per order
    // newLine[orderId] = ['scent_search' => '', 'scent_id' => null, 'size_search' => '', 'size_id' => null, 'wick_id' => null, 'wick' => 'cotton', 'qty' => 1]
    public array $newLine = [];

    // Per-order notices
    public array $orderNotice = [];

    protected $queryString = [
        'view'       => ['except' => 'table'],
        'channel'    => ['except' => 'all'],
        'source'     => ['except' => 'all'],
        'status'     => ['except' => 'all'],
        'search'     => ['except' => ''],
        'sort'       => ['except' => 'ship_by_at'],
        'dir'        => ['except' => 'asc'],
        'timelineYm' => ['except' => ''],
        'ganttStart' => ['except' => ''],
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
        'ship_by_at'   => 'ship_by_at',
        'due_at'       => 'due_at',
        'newest'       => 'id',
        'oldest'       => 'id',
        'order_number' => 'order_number',
        'order_type'   => 'order_type',
        'customer'     => 'customer_name',
        'source'       => 'source',
        'container'    => 'container_name',
        'status'       => 'status',
        'lines_count'  => '__virtual__',
        'qty_total'    => '__virtual__',
    ];

    // Wick options
    public const WICK_TYPES = ['cotton', 'wood'];

    public function mount(): void
    {
        $this->applyUserPreferences();

        if ($this->timelineYm === '') {
            $this->timelineYm = now()->format('Y-m');
        }
        if ($this->ganttStart === '') {
            $this->ganttStart = now()->startOfDay()->format('Y-m-d');
        }
    }

    // Reset paging on filter changes + clear edit state (prevents ghost edits across filters)
    public function updatingView(): void       { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingChannel(): void    { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingSource(): void     { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingStatus(): void     { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingSearch(): void     { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingSort(): void       { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingDir(): void        { $this->resetPage(); $this->expanded = []; $this->clearEdits(); }
    public function updatingTimelineYm(): void { $this->expanded = []; $this->clearEdits(); }
    public function updatingGanttStart(): void { $this->expanded = []; $this->clearEdits(); }

    public function updatedView(): void    { $this->persistUserPreferences(); }
    public function updatedChannel(): void { $this->persistUserPreferences(); }
    public function updatedSource(): void  { $this->persistUserPreferences(); }
    public function updatedStatus(): void  { $this->persistUserPreferences(); }
    public function updatedSort(): void    { $this->persistUserPreferences(); }
    public function updatedDir(): void     { $this->persistUserPreferences(); }

    public function clearFilters(): void
    {
        $this->channel = 'all';
        $this->source = 'all';
        $this->status = 'all';
        $this->search = '';
        $this->persistUserPreferences();
        $this->resetPage();
    }

    public function setViewMode(string $view): void
    {
        if (!in_array($view, ['table', 'list', 'timeline', 'gantt'], true)) {
            return;
        }

        $this->view = $view;
        $this->persistUserPreferences();
        $this->resetPage();
    }

    public function toggleSort(string $key): void
    {
        if (!array_key_exists($key, self::SORTS)) {
            return;
        }

        if ($this->sort !== $key) {
            $this->sort = $key;
            $this->dir = 'asc';
            return;
        }

        if ($this->dir === 'asc') {
            $this->dir = 'desc';
            return;
        }

        // Third click resets to the default operational sort.
        $this->sort = 'ship_by_at';
        $this->dir = 'asc';
    }

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

    private function applyUserPreferences(): void
    {
        $user = auth()->user();
        if (!$user || !is_array($user->ui_preferences ?? null)) {
            return;
        }

        $prefs = $user->ui_preferences;
        if (($prefs['shipping_channel'] ?? null) && request()->query('channel') === null) {
            $this->channel = $prefs['shipping_channel'];
        }
        if (($prefs['shipping_source'] ?? null) && request()->query('source') === null) {
            $this->source = $prefs['shipping_source'];
        }
        if (($prefs['shipping_status'] ?? null) && request()->query('status') === null) {
            $this->status = $prefs['shipping_status'];
        }
        if (($prefs['shipping_sort'] ?? null) && request()->query('sort') === null) {
            $this->sort = $prefs['shipping_sort'];
        }
        if (($prefs['shipping_dir'] ?? null) && request()->query('dir') === null) {
            $this->dir = $prefs['shipping_dir'];
        }
    }

    private function persistUserPreferences(): void
    {
        $user = auth()->user();
        if (!$user) {
            return;
        }

        $prefs = is_array($user->ui_preferences) ? $user->ui_preferences : [];
        $prefs['shipping_view'] = $this->view;
        $prefs['shipping_channel'] = $this->channel;
        $prefs['shipping_source'] = $this->source;
        $prefs['shipping_status'] = $this->status;
        $prefs['shipping_sort'] = $this->sort;
        $prefs['shipping_dir'] = $this->dir;

        $user->forceFill(['ui_preferences' => $prefs])->save();
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
                'scent_id'     => null,
                'size_search'  => '',
                'size_id'      => null,
                'wick_id'      => null,
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
                    'scent_id'     => null,
                    'size_search'  => '',
                    'size_id'      => null,
                    'wick_id'      => null,
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

    public function ganttPrev(): void
    {
        $this->ganttStart = Carbon::parse($this->ganttStart)->subDays(7)->format('Y-m-d');
        $this->expanded = [];
        $this->clearEdits();
    }

    public function ganttNext(): void
    {
        $this->ganttStart = Carbon::parse($this->ganttStart)->addDays(7)->format('Y-m-d');
        $this->expanded = [];
        $this->clearEdits();
    }

    public function ganttToday(): void
    {
        $this->ganttStart = now()->startOfDay()->format('Y-m-d');
        $this->expanded = [];
        $this->clearEdits();
    }

    // --- Order editing (due_at + ship_by_at + status) ---
    public function startEditing(int $orderId): void
    {
        $order = Order::query()
            ->select(['id', 'due_at', 'ship_by_at', 'status'])
            ->findOrFail($orderId);

        $this->orderEditing[$orderId] = true;
        $this->expanded[$orderId] = true;

        $this->orderEdit[$orderId] = [
            'due_at' => blank($order->due_at) ? null : Carbon::parse($order->due_at)->format('Y-m-d'),
            'ship_by_at' => blank($order->ship_by_at) ? null : Carbon::parse($order->ship_by_at)->format('Y-m-d'),
            'status'   => $order->status,
            'recalc_ship_by' => false,
        ];
    }

    public function cancelEditing(int $orderId): void
    {
        unset($this->orderEditing[$orderId], $this->orderEdit[$orderId]);

        $this->resetErrorBag([
            "orderEdit.$orderId.due_at",
            "orderEdit.$orderId.ship_by_at",
            "orderEdit.$orderId.status",
            "orderEdit.$orderId.recalc_ship_by",
        ]);
    }

    public function saveOrder(int $orderId): void
    {
        $data = $this->orderEdit[$orderId] ?? null;
        abort_unless(is_array($data), 404);

        $validated = validator($data, [
            'due_at' => ['nullable', 'date'],
            'ship_by_at' => ['nullable', 'date'],
            'status'   => ['required', 'in:' . implode(',', self::STATUSES)],
            'recalc_ship_by' => ['nullable', 'boolean'],
        ])->validate();

        $order = Order::query()->findOrFail($orderId);

        $hasOpenExceptions = $order->mappingExceptions()
            ->whereNull('resolved_at')
            ->exists();

        if ($hasOpenExceptions && in_array($validated['status'], ['verified', 'complete'], true)) {
            $this->dispatch('toast', [
                'type' => 'warning',
                'message' => 'Blocked: this order has unresolved scent mappings. Resolve before marking verified/complete.',
            ]);
            return;
        }

        $updates = [
            'status' => $validated['status'],
        ];

        $recalc = !empty($validated['recalc_ship_by']);
        if ($recalc) {
            $calculator = app(BusinessDayCalculator::class);
            $shipBy = $this->computeShipByDate($order, $calculator);
            $dueAt = $shipBy ? $calculator->subBusinessDays($shipBy, 2)->startOfDay() : null;
            $updates['ship_by_at'] = $shipBy;
            $updates['due_at'] = $dueAt;
        } else {
            $updates['due_at'] = empty($validated['due_at'])
                ? null
                : Carbon::parse($validated['due_at'])->startOfDay();
            $updates['ship_by_at'] = empty($validated['ship_by_at'])
                ? null
                : Carbon::parse($validated['ship_by_at'])->startOfDay();
        }

        Order::query()
            ->whereKey($orderId)
            ->update($updates);

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

        if (preg_match('/^orderEdit\.(\d+)\.ship_by_at$/', $name, $m)) {
            $orderId = (int) $m[1];
            if ($orderId <= 0) return;

            $date = trim((string) $value);
            if ($date === '') {
                $this->orderEdit[$orderId]['due_at'] = null;
                return;
            }

            $calculator = app(\App\Services\Shipping\BusinessDayCalculator::class);
            $shipBy = \Carbon\CarbonImmutable::parse($date)->startOfDay();
            $dueAt = $calculator->subBusinessDays($shipBy, 2);

            $this->orderEdit[$orderId]['due_at'] = $dueAt->toDateString();
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

    public function saveOrderWork(int $orderId): void
    {
        $isEditing = ($this->orderEditing[$orderId] ?? false) === true;
        $hasDirtyLines = false;

        foreach (($this->lineDirty ?? []) as $lineId => $dirty) {
            if (($this->lineOrder[$lineId] ?? null) === $orderId) {
                $hasDirtyLines = true;
                break;
            }
        }

        if ($isEditing) {
            $this->saveOrder($orderId);
        }

        if ($hasDirtyLines) {
            $this->saveOrderLines($orderId);
        }
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
            $this->newLine[$orderId]['scent_id'] = null;
            $this->addError("newLine.$orderId.scent_search", 'Type a scent name.');
            return;
        }

        $scent = Scent::query()
            ->where('is_active', true)
            ->where('name', $text)
            ->first(['id', 'name']);

        if (!$scent) {
            $this->newLine[$orderId]['scent_id'] = null;
            $this->addError("newLine.$orderId.scent_search", 'Pick a scent from the list.');
            return;
        }

        $this->newLine[$orderId]['scent_id'] = $scent->id;
        $this->newLine[$orderId]['scent_search'] = $scent->name;

        $this->resetErrorBag(["newLine.$orderId.scent_search"]);
    }

    public function handleScentSelected(string $key, ?int $scentId = null, ?string $scentName = null): void
    {
        if (!str_starts_with($key, 'order-')) {
            return;
        }
        $orderId = (int) str_replace('order-', '', $key);
        if ($orderId <= 0) {
            return;
        }

        $this->newLine[$orderId]['scent_id'] = $scentId;
        $this->newLine[$orderId]['scent_search'] = $scentName ?? '';
    }

    public function selectNewLineSize(int $orderId): void
    {
        $text = trim((string)($this->newLine[$orderId]['size_search'] ?? ''));

        if ($text === '') {
            $this->newLine[$orderId]['size_id'] = null;
            $this->addError("newLine.$orderId.size_search", 'Type a size code.');
            return;
        }

        $size = Size::query()
            ->where('is_active', true)
            ->where('code', $text)
            ->first(['id', 'code']);

        if (!$size) {
            $this->newLine[$orderId]['size_id'] = null;
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
            "newLine.$orderId.wick_id",
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

        $scent = null;
        if ($scentSearch !== '') {
            $scent = Scent::query()
                ->where('is_active', true)
                ->where('name', $scentSearch)
                ->first(['id', 'name']);
        }
        if (!$scent && !empty($data['scent_id'])) {
            $scent = Scent::query()->find((int) $data['scent_id'], ['id', 'name']);
        }

        $size = null;
        if ($sizeSearch !== '') {
            $size = Size::query()
                ->where('is_active', true)
                ->where('code', $sizeSearch)
                ->first(['id', 'code']);
        }
        if (!$size && !empty($data['size_id'])) {
            $size = Size::query()->find((int) $data['size_id'], ['id', 'code']);
        }

        $lineHasWickId = Schema::hasColumn('order_lines', 'wick_id');

        $this->newLine[$orderId]['scent_id'] = $scent?->id;
        $this->newLine[$orderId]['size_id']  = $size?->id;
        $this->newLine[$orderId]['qty']      = $qtyToAdd;
        $this->newLine[$orderId]['wick']     = $wick;
        if ($lineHasWickId) {
            $this->newLine[$orderId]['wick_id'] = isset($data['wick_id'])
                ? (int) $data['wick_id']
                : null;
        }

        $rules = [
            'scent_id' => ['required', 'integer', Rule::exists('scents', 'id')],
            'size_id'  => ['required', 'integer', Rule::exists('sizes', 'id')],
            'qty'      => ['required', 'integer', 'min:1', 'max:9999'],
        ];

        if ($lineHasWickId) {
            $rules['wick_id'] = ['required', 'integer'];
            if (Schema::hasTable('wicks')) {
                $rules['wick_id'][] = Rule::exists('wicks', 'id');
            }
        }

        validator($this->newLine[$orderId], $rules)
            ->after(function ($v) use ($scent, $size) {
                if (!$scent) $v->errors()->add('scent_id', 'Pick a scent from the list.');
                if (!$size)  $v->errors()->add('size_id', 'Pick a size from the list.');
            })
            ->validate();

        $qtyToAdd = (int) $this->newLine[$orderId]['qty'];
        $lineModelClass = $this->lineModelClass();

        $wickId = $lineHasWickId ? (int) ($this->newLine[$orderId]['wick_id'] ?? 0) : null;

        DB::transaction(function () use ($lineModelClass, $orderId, $scent, $size, $qtyToAdd, $lineHasWickId, $wickId) {
            $q = $lineModelClass::query()
                ->where('order_id', $orderId)
                ->where('scent_id', $scent->id)
                ->where('size_id', $size->id);

            if ($lineHasWickId) {
                $q->where('wick_id', $wickId);
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
                if ($lineHasWickId) {
                    $existing->setAttribute('wick_id', $wickId);
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
            if ($lineHasWickId) {
                $line->setAttribute('wick_id', $wickId);
            }

            $line->save();
        });

        $this->dispatch('toast', [
            'type' => 'success',
            'message' => 'Line item added.',
        ]);

        $this->dispatch('line-added', orderId: $orderId);

        $this->newLine[$orderId] = [
            'scent_search' => '',
            'scent_id'     => null,
            'size_search'  => '',
            'size_id'      => null,
            'wick_id'      => null,
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
                'scent_id'     => null,
                'size_search'  => '',
                'size_id'      => null,
                'wick_id'      => null,
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

        if (Schema::hasColumn('orders', 'order_type')) {
            return $q->where('order_type', $channel);
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
        $source = $this->source ?: 'all';
        $search = trim($this->search);

        $sortKey = array_key_exists($this->sort, self::SORTS) ? $this->sort : 'ship_by_at';
        $dir     = strtolower($this->dir) === 'desc' ? 'desc' : 'asc';

        $q = Order::query()
            ->select('orders.*')
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
            ])
            ->withCount([
                'lines as lines_count_sort',
                'mappingExceptions as open_mapping_exceptions_count' => function ($q) {
                    $q->whereNull('resolved_at');
                },
            ]);

        $q->selectSub(
            DB::table('order_lines')
                ->selectRaw('COALESCE(SUM(COALESCE(ordered_qty, quantity, 0)), 0)')
                ->whereColumn('order_lines.order_id', 'orders.id'),
            'qty_total_sort'
        );

        if ($status !== 'all') {
            $q->where('status', $status);
        }

        $q = $this->applyChannelFilter($q);

        if ($source !== 'all') {
            if ($source === 'manual') {
                $q->whereNull('source');
            } else {
                $q->where('source', $source);
            }
        }

        if ($search !== '') {
            $s = '%' . $search . '%';
            $q->where(function (Builder $inner) use ($s) {
                $inner->where('order_number', 'like', $s)
                      ->orWhere('order_label', 'like', $s)
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
            if ($sortKey === 'ship_by_at') {
                $qq->orderByRaw('CASE WHEN ship_by_at IS NULL THEN 1 ELSE 0 END')
                   ->orderBy('ship_by_at', $dir)
                   ->orderByDesc('id');
                return;
            }
            if ($sortKey === 'due_at') {
                $qq->orderByRaw('CASE WHEN due_at IS NULL THEN 1 ELSE 0 END')
                   ->orderBy('due_at', $dir)
                   ->orderByDesc('id');
                return;
            }

            if ($sortKey === 'newest') { $qq->orderByDesc('id'); return; }
            if ($sortKey === 'oldest') { $qq->orderBy('id', 'asc'); return; }
            if ($sortKey === 'lines_count') {
                $qq->orderBy('lines_count_sort', $dir)->orderByDesc('id');
                return;
            }
            if ($sortKey === 'qty_total') {
                $qq->orderBy('qty_total_sort', $dir)->orderByDesc('id');
                return;
            }

            $col = self::SORTS[$sortKey] ?? 'ship_by_at';
            $qq->orderBy($col, $dir)->orderByDesc('id');
        });

        return $q;
    }

    private function timelineQueryForMonth(Carbon $month): Builder
    {
        $start = $month->copy()->startOfMonth()->startOfWeek();
        $end   = $month->copy()->endOfMonth()->endOfWeek();

        $q = $this->baseQuery()
            ->whereNotNull('ship_by_at')
            ->whereBetween('ship_by_at', [$start->startOfDay(), $end->endOfDay()]);

        $q->reorder('ship_by_at', 'asc')->orderByDesc('id');

        return $q;
    }

    public function render()
    {
        $orders = $this->baseQuery()->paginate(15);

        $timelineMonth  = null;
        $timelineOrders = collect();
        $ganttOrders = collect();
        $ganttRows = collect();
        $ganttStart = null;
        $ganttEnd = null;

        if (($this->view ?? 'list') === 'timeline') {
            $timelineMonth  = Carbon::createFromFormat('Y-m', $this->timelineYm)->startOfMonth();
            $timelineOrders = $this->timelineQueryForMonth($timelineMonth)->get();
        }
        if (($this->view ?? 'list') === 'gantt') {
            $ganttStart = Carbon::parse($this->ganttStart)->startOfDay();
            $ganttEnd = $ganttStart->copy()->addDays(max(1, (int) $this->ganttDays) - 1)->endOfDay();
            $ganttOrders = $this->baseQuery()
                ->whereNotNull('created_at')
                ->get();

            $calculator = app(BusinessDayCalculator::class);
            $ganttRows = $ganttOrders->map(function (Order $order) use ($calculator) {
                $start = $this->computeGanttStart($order);
                $end = $this->computeShipByDate($order, $calculator);
                return [
                    'order' => $order,
                    'start' => $start,
                    'end' => $end,
                ];
            })->filter(fn ($row) => $row['start'] && $row['end']);
        }

        $sizes = Size::query()
            ->where('is_active', true)
            ->orderBy('code')
            ->get(['id', 'code']);

        return view('livewire.shipping.orders', [
            'orders'         => $orders,
            'statuses'       => array_merge(['all'], self::STATUSES),
            'sorts'          => array_keys(self::SORTS),
            'timelineMonth'  => $timelineMonth,
            'timelineOrders' => $timelineOrders,
            'ganttStart'     => $ganttStart,
            'ganttEnd'       => $ganttEnd,
            'ganttOrders'    => $ganttOrders,
            'ganttRows'      => $ganttRows,
            'ganttDays'      => $this->ganttDays,
            'sizes'          => $sizes,
        ]);
    }

    private function computeGanttStart(Order $order): ?CarbonImmutable
    {
        if (!$order->created_at) {
            return null;
        }
        return CarbonImmutable::parse($order->created_at)->startOfDay();
    }

    private function computeShipByDate(Order $order, BusinessDayCalculator $calculator): ?CarbonImmutable
    {
        if ($order->ship_by_at) {
            return CarbonImmutable::parse($order->ship_by_at)->startOfDay();
        }
        if (!$order->created_at) {
            return null;
        }
        $start = CarbonImmutable::parse($order->created_at)->startOfDay();
        $type = $order->order_type ?? $order->channel ?? 'retail';
        $days = $type === 'wholesale' ? 10 : 3;
        return $calculator->addBusinessDays($start, $days)->startOfDay();
    }
}
