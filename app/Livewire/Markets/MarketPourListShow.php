<?php

namespace App\Livewire\Markets;

use App\Models\Event;
use App\Models\MarketPourList;
use App\Models\MarketPourListEventLine;
use App\Models\MarketPourListLine;
use App\Models\PourRequest;
use App\Models\PourRequestLine;
use App\Services\MarketRecommender;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class MarketPourListShow extends Component
{
    public MarketPourList $list;
    public array $selectedEvents = [];
    public float $growthFactor = 1.10;
    public float $safetyStock = 0.05;

    public array $edited = [];

    public function mount(MarketPourList $list): void
    {
        $this->list = $list;
        $this->selectedEvents = $list->events()->pluck('events.id')->all();
    }

    public function generate(): void
    {
        abort_unless((string) auth()->user()?->role === 'admin', 403);

        if (empty($this->selectedEvents)) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Select at least one event.']);
            return;
        }

        DB::transaction(function () {
            $this->list->events()->sync($this->selectedEvents);
            $this->list->lines()->delete();
            MarketPourListEventLine::query()->where('market_pour_list_id', $this->list->id)->delete();

            $recommender = app(MarketRecommender::class);
            $aggregate = [];
            $aggregateReasons = [];

            foreach ($this->selectedEvents as $eventId) {
                $event = Event::query()->find($eventId);
                if (!$event) {
                    continue;
                }
                $recommendations = $recommender->recommendForEvent($event, $this->growthFactor, $this->safetyStock);
                foreach ($recommendations as $rec) {
                    $key = ($rec['scent_id'] ?? 'null') . ':' . ($rec['size_id'] ?? 'null');
                    $aggregate[$key] = ($aggregate[$key] ?? 0) + $rec['recommended_qty'];
                    $aggregateReasons[$key][] = $rec['reason'] ?? null;

                    MarketPourListEventLine::query()->create([
                        'market_pour_list_id' => $this->list->id,
                        'event_id' => $event->id,
                        'scent_id' => $rec['scent_id'],
                        'size_id' => $rec['size_id'],
                        'recommended_qty' => $rec['recommended_qty'],
                    ]);
                }
            }

            foreach ($aggregate as $key => $qty) {
                [$scentId, $sizeId] = explode(':', $key);
                $reason = [
                    'sources' => array_values(array_filter($aggregateReasons[$key] ?? [])),
                ];
                MarketPourListLine::query()->create([
                    'market_pour_list_id' => $this->list->id,
                    'scent_id' => $scentId !== 'null' ? (int) $scentId : null,
                    'size_id' => $sizeId !== 'null' ? (int) $sizeId : null,
                    'recommended_qty' => (int) $qty,
                    'reason_json' => $reason,
                ]);
            }

            $this->list->generated_at = now();
            $this->list->save();
        });

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Recommendations generated.']);
    }

    public function updateLine(int $lineId, int $qty): void
    {
        abort_unless((string) auth()->user()?->role === 'admin', 403);
        MarketPourListLine::query()->where('id', $lineId)->update(['edited_qty' => max(0, $qty)]);
    }

    public function publish(): void
    {
        abort_unless((string) auth()->user()?->role === 'admin', 403);

        if ($this->list->lines()->count() === 0) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'Generate recommendations first.']);
            return;
        }

        $missingDates = $this->list->events()->where(function ($q) {
            $q->whereNull('due_date')->orWhereNull('ship_date');
        })->count();
        if ($missingDates > 0) {
            $this->dispatch('toast', ['type' => 'warning', 'message' => 'All events need due date + ship date before publishing.']);
            return;
        }

        DB::transaction(function () {
            $request = PourRequest::query()->updateOrCreate(
                ['source_type' => 'market_pour_list', 'source_id' => $this->list->id],
                ['status' => 'open', 'due_date' => $this->list->events()->min('due_date')]
            );
            $request->lines()->delete();

            foreach ($this->list->lines as $line) {
                $finalQty = $line->edited_qty ?? $line->recommended_qty;
                if ($finalQty <= 0) continue;
                PourRequestLine::query()->create([
                    'pour_request_id' => $request->id,
                    'scent_id' => $line->scent_id,
                    'size_id' => $line->size_id,
                    'wick_type' => $line->wick_type,
                    'qty' => $finalQty,
                ]);
            }

            $this->list->status = 'published';
            $this->list->published_at = now();
            $this->list->published_by_user_id = auth()->id();
            $this->list->save();
        });

        $this->dispatch('toast', ['type' => 'success', 'message' => 'Market Pour List published to Pouring Room.']);
    }

    public function render()
    {
        $lines = $this->list->lines()->with(['scent', 'size'])->get();
        $totalQty = $lines->sum(fn ($line) => $line->edited_qty ?? $line->recommended_qty);

        return view('livewire.markets.show', [
            'events' => Event::query()->orderByDesc('starts_at')->get(),
            'lines' => $lines,
            'totalQty' => $totalQty,
            'eventLines' => MarketPourListEventLine::query()
                ->where('market_pour_list_id', $this->list->id)
                ->with(['event', 'scent', 'size'])
                ->get(),
        ])->layout('layouts.app');
    }
}
