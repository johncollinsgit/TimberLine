<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Services\EventMatchingService;
use App\Services\MarketEventSyncCoordinator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\On;
use Livewire\Component;

class CandidateMatchList extends Component
{
    public ?int $upcomingEventId = null;
    public ?int $selectedCandidateEventId = null;
    public int $matchWindowDays = 45;
    public bool $hasMatchRun = false;

    /** @var array<int,array<string,mixed>> */
    public array $candidates = [];
    public ?string $error = null;

    public function mount(?int $upcomingEventId = null, ?int $selectedCandidateEventId = null, int $matchWindowDays = 45): void
    {
        $this->upcomingEventId = $upcomingEventId;
        $this->selectedCandidateEventId = $selectedCandidateEventId;
        $this->matchWindowDays = max(14, min(60, $matchWindowDays));
    }

    public function updatedUpcomingEventId(mixed $value): void
    {
        $this->upcomingEventId = $value ? (int) $value : null;
        $this->selectedCandidateEventId = null;
        $this->hasMatchRun = false;
        $this->error = null;
        $this->candidates = [];
    }

    public function updatedMatchWindowDays(mixed $value): void
    {
        $this->matchWindowDays = max(14, min(60, (int) $value));
        $this->hasMatchRun = false;
        $this->error = null;
        $this->candidates = [];
    }

    public function selectCandidate(int $candidateEventId): void
    {
        $this->selectedCandidateEventId = $candidateEventId;
        $this->dispatch('marketsCandidateSelected', candidateEventId: $candidateEventId);
    }

    #[On('marketsRunCandidateMatch')]
    public function handleRunCandidateMatch(int $upcomingEventId, ?int $matchWindowDays = null): void
    {
        $this->upcomingEventId = $upcomingEventId > 0 ? $upcomingEventId : null;
        if ($matchWindowDays !== null) {
            $this->matchWindowDays = max(14, min(60, (int) $matchWindowDays));
        }

        $this->selectedCandidateEventId = null;
        $this->hasMatchRun = true;
        $this->loadCandidates();
    }

    protected function loadCandidates(): void
    {
        $this->error = null;
        $this->candidates = [];

        $upcomingId = (int) ($this->upcomingEventId ?: 0);
        if ($upcomingId <= 0) {
            return;
        }

        $upcoming = Event::query()->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'source_ref'])->find($upcomingId);
        if (! $upcoming || ! $upcoming->starts_at) {
            return;
        }

        $window = max(14, min(60, (int) $this->matchWindowDays));
        $version = app(MarketEventSyncCoordinator::class)->matchingCacheVersion();
        $cacheKey = "markets:candidates:v3:{$version}:{$upcoming->id}:{$window}";

        try {
            $this->candidates = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($upcoming, $window): array {
                $startedAt = microtime(true);
                $rows = app(EventMatchingService::class)->candidatesForUpcoming($upcoming, $window, 5)
                    ->take(25)
                    ->map(function (array $row): array {
                        /** @var Event|null $candidate */
                        $candidate = $row['event'] ?? null;

                        return [
                            'event_id' => (int) ($row['candidate_event_id'] ?? $candidate?->id ?? 0),
                            'title' => (string) ($candidate?->display_name ?: $candidate?->name ?: 'Untitled Event'),
                            'starts_at' => $candidate?->starts_at?->toDateString(),
                            'city' => (string) ($candidate?->city ?? ''),
                            'state' => (string) ($candidate?->state ?? ''),
                            'match_score' => (float) ($row['match_score'] ?? 0),
                            'match_score_percent' => (int) round(((float) ($row['match_score'] ?? 0)) * 100),
                            'title_score_percent' => (int) round(((float) ($row['title_score'] ?? 0)) * 100),
                            'date_score_percent' => (int) round(((float) ($row['date_score'] ?? 0)) * 100),
                            'location_score_percent' => (int) round(((float) ($row['location_score'] ?? 0)) * 100),
                            'days_diff' => (int) ($row['days_diff'] ?? 0),
                        ];
                    })
                    ->values()
                    ->all();

                Log::info('CandidateMatchList cache miss', [
                    'upcoming_event_id' => $upcoming->id,
                    'window_days' => $window,
                    'candidate_count' => count($rows),
                    'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                ]);

                return $rows;
            });
        } catch (\Throwable $e) {
            Log::error('CandidateMatchList load failed', [
                'upcoming_event_id' => $upcomingId,
                'window_days' => $window,
                'exception' => get_class($e),
                'message' => $e->getMessage(),
            ]);
            $this->error = 'Failed to load candidate matches.';
            $this->candidates = [];
        }
    }

    public function render()
    {
        return view('livewire.retail.markets.candidate-match-list');
    }
}
