<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\MarketPlan;
use App\Services\EventMatchingService;
use Illuminate\Support\Collection;
use Livewire\Component;

class PrefillPreviewPanel extends Component
{
    public ?int $candidateEventId = null;

    /** @var array<string,mixed> */
    public array $preview = [
        'candidate_title' => null,
        'candidate_date' => null,
        'rows' => [],
        'summary' => [
            'full_boxes' => 0,
            'half_boxes' => 0,
            'top_shelf_boxes' => 0,
            'rows_count' => 0,
        ],
        'has_plan_data' => false,
    ];

    public function mount(?int $candidateEventId = null): void
    {
        $this->candidateEventId = $candidateEventId;
        $this->loadPreview();
    }

    public function updatedCandidateEventId(mixed $value): void
    {
        $this->candidateEventId = $value ? (int) $value : null;
        $this->loadPreview();
    }

    protected function loadPreview(): void
    {
        $candidateId = (int) ($this->candidateEventId ?: 0);
        if ($candidateId <= 0) {
            $this->preview = [
                'candidate_title' => null,
                'candidate_date' => null,
                'rows' => [],
                'summary' => [
                    'full_boxes' => 0,
                    'half_boxes' => 0,
                    'top_shelf_boxes' => 0,
                    'rows_count' => 0,
                ],
                'has_plan_data' => false,
            ];
            return;
        }

        $candidate = Event::query()->select(['id', 'name', 'display_name', 'starts_at'])->find($candidateId);
        if (! $candidate || ! $candidate->starts_at) {
            return;
        }

        $normalizedTitle = app(EventMatchingService::class)->normalizeTitle((string) ($candidate->display_name ?: $candidate->name));
        if ($normalizedTitle === '') {
            return;
        }

        $rows = MarketPlan::query()
            ->where('status', 'published')
            ->whereDate('event_date', $candidate->starts_at->toDateString())
            ->where('normalized_title', $normalizedTitle)
            ->orderBy('id')
            ->get(['id', 'box_type', 'box_count', 'scent']);

        $this->preview = [
            'candidate_title' => (string) ($candidate->display_name ?: $candidate->name ?: 'Candidate Event'),
            'candidate_date' => $candidate->starts_at?->toDateString(),
            'rows' => $rows->map(function (MarketPlan $row): array {
                return [
                    'id' => (int) $row->id,
                    'box_type' => strtolower(trim((string) $row->box_type)),
                    'box_count' => max(0, (int) $row->box_count),
                    'scent' => (string) $row->scent,
                ];
            })->values()->all(),
            'summary' => $this->summary($rows),
            'has_plan_data' => $rows->isNotEmpty(),
        ];
    }

    /**
     * @param  Collection<int,MarketPlan>  $rows
     * @return array{full_boxes:int,half_boxes:int,top_shelf_boxes:int,rows_count:int}
     */
    protected function summary(Collection $rows): array
    {
        $full = 0;
        $half = 0;
        $topShelf = 0;

        foreach ($rows as $row) {
            $type = strtolower(trim((string) $row->box_type));
            $count = max(0, (int) $row->box_count);
            if ($type === 'full') {
                $full += $count;
            } elseif ($type === 'half') {
                $half += $count;
            } elseif ($type === 'top_shelf') {
                $topShelf += $count;
            }
        }

        return [
            'full_boxes' => $full,
            'half_boxes' => $half,
            'top_shelf_boxes' => $topShelf,
            'rows_count' => $rows->count(),
        ];
    }

    public function render()
    {
        return view('livewire.retail.markets.prefill-preview-panel');
    }
}
