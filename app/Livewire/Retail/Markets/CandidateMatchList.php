<?php

namespace App\Livewire\Retail\Markets;

use App\Models\Event;
use App\Models\EventBoxPlan;
use App\Models\EventInstance;
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
        $this->selectedCandidateEventId = null;
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

        $upcoming = Event::query()
            ->select(['id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'source_ref'])
            ->find($upcomingId);
        if (! $upcoming || ! $upcoming->starts_at) {
            return;
        }

        $window = max(14, min(60, (int) $this->matchWindowDays));
        $version = app(MarketEventSyncCoordinator::class)->matchingCacheVersion();
        $cacheKey = "markets:event-instances:v1:{$version}:{$upcoming->id}:{$window}";

        try {
            $this->candidates = Cache::remember($cacheKey, now()->addMinutes(15), function () use ($upcoming, $window): array {
                $startedAt = microtime(true);
                $rows = $this->rankCandidatesForUpcoming($upcoming, $window);

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

    /**
     * @return array<int,array<string,mixed>>
     */
    protected function rankCandidatesForUpcoming(Event $upcoming, int $window): array
    {
        $upcomingTitle = (string) ($upcoming->display_name ?: $upcoming->name);
        $upcomingSeriesKey = EventInstance::seriesKey($upcomingTitle);
        $upcomingTokens = array_values(array_filter(explode(' ', $upcomingSeriesKey), fn (string $token): bool => strlen($token) >= 2));
        $upcomingState = trim((string) ($upcoming->state ?? ''));

        $baseQuery = EventInstance::query()
            ->whereNotNull('starts_at')
            ->whereDate('starts_at', '<', $upcoming->starts_at->toDateString());

        if ($upcomingState !== '' && (clone $baseQuery)->where('state', $upcomingState)->exists()) {
            $baseQuery->where('state', $upcomingState);
        }

        $tokensForSql = array_slice(array_values(array_unique($upcomingTokens)), 0, 2);
        foreach ($tokensForSql as $token) {
            $baseQuery->where('title', 'like', '%'.$token.'%');
        }

        $pool = $baseQuery
            ->orderByDesc('starts_at')
            ->limit(400)
            ->get(['id', 'title', 'starts_at', 'ends_at', 'state', 'notes']);

        if ($pool->isEmpty()) {
            return [];
        }

        $ranked = $pool
            ->map(function (EventInstance $instance) use ($upcoming, $upcomingState, $upcomingSeriesKey, $upcomingTokens, $window): ?array {
                $seriesKey = EventInstance::seriesKey($instance->title);
                $daysDiff = EventInstance::dayDistance($upcoming->starts_at, $instance->starts_at);
                if ($daysDiff === null) {
                    return null;
                }

                $titleScore = $this->titleScore($upcomingSeriesKey, $seriesKey, $upcomingTokens);
                $dateScore = max(0.0, 1 - (min($daysDiff, $window * 2) / max(1, $window * 2)));
                $stateScore = ($upcomingState !== '' && trim((string) ($instance->state ?? '')) === $upcomingState) ? 1.0 : 0.6;
                $matchScore = ($titleScore * 0.6) + ($dateScore * 0.3) + ($stateScore * 0.1);

                if ($titleScore < 0.2 && $daysDiff > $window) {
                    return null;
                }

                return [
                    'event_id' => (int) $instance->id,
                    'title' => (string) $instance->title,
                    'starts_at' => $instance->starts_at?->toDateString(),
                    'ends_at' => $instance->ends_at?->toDateString(),
                    'state' => (string) ($instance->state ?? ''),
                    'match_score' => $matchScore,
                    'match_score_percent' => (int) round($matchScore * 100),
                    'title_score_percent' => (int) round($titleScore * 100),
                    'date_score_percent' => (int) round($dateScore * 100),
                    'location_score_percent' => (int) round($stateScore * 100),
                    'days_diff' => $daysDiff,
                    'notes_snippet' => $instance->notes ? mb_strimwidth((string) $instance->notes, 0, 140, '...') : '',
                ];
            })
            ->filter()
            ->sortByDesc('match_score')
            ->take(10)
            ->values();

        if ($ranked->isEmpty()) {
            return [];
        }

        $topIds = $ranked->pluck('event_id')->all();
        $instancesWithPlans = EventInstance::query()
            ->with(['boxPlans' => fn ($query) => $query->orderByDesc('box_count_sent')->orderBy('id')])
            ->whereIn('id', $topIds)
            ->get(['id', 'title', 'notes', 'starts_at', 'ends_at', 'state'])
            ->keyBy('id');

        return $ranked
            ->map(function (array $row) use ($instancesWithPlans): array {
                /** @var EventInstance|null $instance */
                $instance = $instancesWithPlans->get((int) $row['event_id']);

                if (! $instance) {
                    $row['box_plan_count'] = 0;
                    $row['box_preview'] = [];
                    $row['top_scent'] = null;

                    return $row;
                }

                /** @var EventBoxPlan|null $firstLine */
                $firstLine = $instance->boxPlans->first();

                $row['box_plan_count'] = $instance->boxPlans->count();
                $row['box_preview'] = $instance->boxPlans->take(4)->map(function (EventBoxPlan $line): array {
                    return [
                        'scent_raw' => (string) $line->scent_raw,
                        'box_count_sent' => $line->box_count_sent !== null ? (float) $line->box_count_sent : null,
                        'is_split_box' => (bool) $line->is_split_box,
                    ];
                })->all();
                $row['top_scent'] = $firstLine?->scent_raw;

                return $row;
            })
            ->values()
            ->all();
    }

    protected function titleScore(string $upcomingSeriesKey, string $seriesKey, array $upcomingTokens): float
    {
        if ($upcomingSeriesKey === '' || $seriesKey === '') {
            return 0.0;
        }

        similar_text($upcomingSeriesKey, $seriesKey, $similarityPercent);
        $similarity = $similarityPercent / 100;

        $matchingTokens = 0;
        foreach ($upcomingTokens as $token) {
            if (str_contains($seriesKey, $token)) {
                $matchingTokens++;
            }
        }
        $tokenScore = count($upcomingTokens) > 0 ? ($matchingTokens / count($upcomingTokens)) : 0;

        return min(1.0, ($similarity * 0.7) + ($tokenScore * 0.3));
    }

    public function render()
    {
        return view('livewire.retail.markets.candidate-match-list');
    }
}
