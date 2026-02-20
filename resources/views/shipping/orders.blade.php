<?php
// Legacy shipping view (not used by routes). Kept for reference during the Livewire migration.

namespace App\Livewire\Shipping;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Livewire\Component;
use Livewire\WithPagination;

class Orders extends Component
{
    use WithPagination;

    // Filters / view state
    public string $view = 'list';       // list | table | timeline
    public string $channel = 'all';     // all | wholesale | retail | event
    public string $status = 'all';      // all | new | reviewed | ...
    public string $search = '';

    /** @var array<int,bool> */
    public array $expanded = [];

    // Sorting (used in list/table)
    public string $sort = 'ship_by_at';
    public string $dir  = 'asc';

    // Timeline month (YYYY-MM)
    public string $timelineYm = '';

    protected $queryString = [
        'view'       => ['except' => 'list'],
        'channel'    => ['except' => 'all'],
        'status'     => ['except' => 'all'],
        'search'     => ['except' => ''],
        'sort'       => ['except' => 'ship_by_at'],
        'dir'        => ['except' => 'asc'],
        'timelineYm' => ['except' => ''],
        'page'       => ['except' => 1],
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
        'customer'     => 'customer_name',
        'container'    => 'container_name',
        'status'       => 'status',
    ];

    public function mount(): void
    {
        if ($this->timelineYm === '') {
            $this->timelineYm = now()->format('Y-m');
        }
    }

    // Reset paging on filter changes
    public function updatingView(): void     { $this->resetPage(); $this->expanded = []; }
    public function updatingChannel(): void  { $this->resetPage(); $this->expanded = []; }
    public function updatingStatus(): void   { $this->resetPage(); $this->expanded = []; }
    public function updatingSearch(): void   { $this->resetPage(); $this->expanded = []; }
    public function updatingSort(): void     { $this->resetPage(); $this->expanded = []; }
    public function updatingDir(): void      { $this->resetPage(); $this->expanded = []; }
    public function updatingTimelineYm(): void { $this->expanded = []; }

    public function setSort(string $sort): void
    {
        if ($this->sort === $sort) {
            $this->dir = $this->dir === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sort = $sort;
            $this->dir  = in_array($sort, ['newest'], true) ? 'desc' : 'asc';
        }
    }

    public function toggle(int $orderId): void
    {
        $this->expanded[$orderId] = !($this->expanded[$orderId] ?? false);
    }

    public function expandAll(): void
    {
        $orders = $this->baseQuery()->paginate(15);
        foreach ($orders->items() as $order) {
            $this->expanded[$order->id] = true;
        }
    }

    public function collapseAll(): void
    {
        $this->expanded = [];
    }

    // --- Timeline month nav (these fix your button errors) ---
    public function timelinePrevMonth(): void
    {
        $this->timelineYm = Carbon::createFromFormat('Y-m', $this->timelineYm)->subMonth()->format('Y-m');
        $this->expanded = [];
    }

    public function timelineNextMonth(): void
    {
        $this->timelineYm = Carbon::createFromFormat('Y-m', $this->timelineYm)->addMonth()->format('Y-m');
        $this->expanded = [];
    }

    public function timelineToday(): void
    {
        $this->timelineYm = now()->format('Y-m');
        $this->expanded = [];
    }

    // --- Query building ---
    private function applyChannelFilter(Builder $q): Builder
    {
        $channel = $this->channel ?: 'all';
        if ($channel === 'all') return $q;

        // crude-but-effective mapping for now:
        // - Wholesale: container_name starts with "Wholesale:"
        // - Event: "Market:" with keywords festival/market/show
        // - Retail: everything else
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

            // retail (default): not wholesale, and not "event market"
            $inner->where(function (Builder $k) {
                $k->whereNull('container_name')
                  ->orWhere('container_name', 'not like', 'Wholesale:%');
            })->where(function (Builder $k) {
                $k->whereNull('container_name')
                  ->orWhere('container_name', 'not like', 'Market:%')
                  ->orWhere(function (Builder $m) {
                      $m->where('container_name', 'like', 'Market:%')
                        ->where('container_name', 'not like', '%festival%')
                        ->where('container_name', 'not like', '%market%')
                        ->where('container_name', 'not like', '%show%');
                  });
            });
        });
    }

    private function baseQuery(): Builder
    {
        $status = $this->status ?: 'all';
        $search = trim($this->search);

        $sortKey = array_key_exists($this->sort, self::SORTS) ? $this->sort : 'ship_by_at';
        $dir     = strtolower($this->dir) === 'desc' ? 'desc' : 'asc';

        $q = Order::query()
            ->with([
                'lines' => function ($rel) {
                    $rel->orderBy('pour_status')
                        ->orderBy('scent_name');
                },
            ]);

        // status
        if ($status !== 'all') {
            $q->where('status', $status);
        }

        // channel
        $q = $this->applyChannelFilter($q);

        // search
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

        // sorting
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

            $col = self::SORTS[$sortKey] ?? 'ship_by_at';
            $qq->orderBy($col, $dir)->orderByDesc('id');
        });

        return $q;
    }

    private function timelineQueryForMonth(Carbon $month): Builder
    {
        $start = $month->copy()->startOfMonth()->startOfWeek(); // Sunday start
        $end   = $month->copy()->endOfMonth()->endOfWeek();     // Saturday end

        // Use same filters as baseQuery, but constrain ship_by_at to grid range
        $q = $this->baseQuery()
            ->whereNotNull('ship_by_at')
            ->whereBetween('ship_by_at', [$start->startOfDay(), $end->endOfDay()]);

        // Timeline should be stable and date-ordered
        $q->getQuery()->orders = null;
        $q->orderBy('ship_by_at', 'asc')->orderBy('id', 'desc');

        return $q;
    }

    public function render()
    {
        $orders = $this->baseQuery()->paginate(15);

        // Timeline payload (only used when timeline view is active)
        $timelineMonth = null;
        $timelineOrders = collect();

        if (($this->view ?? 'list') === 'timeline') {
            $timelineMonth = Carbon::createFromFormat('Y-m', $this->timelineYm)->startOfMonth();
            $timelineOrders = $this->timelineQueryForMonth($timelineMonth)->get();
        }

        return view('livewire.shipping.orders', [
            'orders'         => $orders,
            'statuses'       => array_merge(['all'], self::STATUSES),
            'sorts'          => array_keys(self::SORTS),

            // timeline inputs for the blade
            'timelineMonth'  => $timelineMonth,
            'timelineOrders' => $timelineOrders,
        ]);
    }
}
