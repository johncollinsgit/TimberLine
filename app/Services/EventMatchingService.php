<?php

namespace App\Services;

use App\Models\Event;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EventMatchingService
{
    /**
     * Normalize titles for fuzzy matching.
     */
    public function normalizeTitle(string $title): string
    {
        $title = Str::lower($title);

        $title = preg_replace('/\b20\d{2}\b/u', ' ', $title) ?? $title;
        $title = preg_replace('/\b\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?\b/u', ' ', $title) ?? $title;
        $title = preg_replace('/\b(?:january|jan|february|feb|march|mar|april|apr|may|june|jun|july|jul|august|aug|september|sept|sep|october|oct|november|nov|december|dec)\.?\b/u', ' ', $title) ?? $title;
        $title = preg_replace('/\b(?:mon|tue|tues|wed|thu|thur|thurs|fri|sat|sun)\b/u', ' ', $title) ?? $title;
        $title = preg_replace('/[^a-z0-9\s]/u', ' ', $title) ?? $title;
        $title = preg_replace('/\s+/u', ' ', $title) ?? $title;

        return trim($title);
    }

    /**
     * @param  iterable<mixed>  $candidates
     * @return array{candidate:mixed,score:float,normalized_input:string,normalized_candidate:string}|null
     */
    public function bestMatch(string $inputTitle, iterable $candidates, callable|string|null $titleAccessor = null, float $threshold = 0.68): ?array
    {
        $normalizedInput = $this->normalizeTitle($inputTitle);
        if ($normalizedInput === '') {
            return null;
        }

        $best = null;

        foreach ($candidates as $candidate) {
            $candidateTitle = $this->extractTitle($candidate, $titleAccessor);
            $normalizedCandidate = $this->normalizeTitle($candidateTitle);

            if ($normalizedCandidate === '') {
                continue;
            }

            $score = $this->similarity($normalizedInput, $normalizedCandidate);
            if ($best === null || $score > $best['score']) {
                $best = [
                    'candidate' => $candidate,
                    'score' => $score,
                    'normalized_input' => $normalizedInput,
                    'normalized_candidate' => $normalizedCandidate,
                ];
            }
        }

        if ($best === null || $best['score'] < $threshold) {
            return null;
        }

        return $best;
    }

    public function similarity(string $normalizedLeft, string $normalizedRight): float
    {
        if ($normalizedLeft === '' || $normalizedRight === '') {
            return 0.0;
        }

        if ($normalizedLeft === $normalizedRight) {
            return 1.0;
        }

        similar_text($normalizedLeft, $normalizedRight, $percent);
        $similarity = max(0.0, min(1.0, $percent / 100));

        $leftTokens = array_values(array_unique(array_filter(explode(' ', $normalizedLeft))));
        $rightTokens = array_values(array_unique(array_filter(explode(' ', $normalizedRight))));

        $intersection = count(array_intersect($leftTokens, $rightTokens));
        $union = count(array_unique(array_merge($leftTokens, $rightTokens)));
        $jaccard = $union > 0 ? ($intersection / $union) : 0.0;

        return round(($similarity * 0.7) + ($jaccard * 0.3), 4);
    }

    /**
     * Deterministic candidate finder for an upcoming event using prior-year date windows.
     *
     * @return Collection<int,array<string,mixed>>
     */
    public function candidatesForUpcoming(Event $upcoming, int $windowDays = 30, int $yearsBack = 5): Collection
    {
        $startedAt = microtime(true);
        $startsAt = $upcoming->starts_at;
        if (! $startsAt) {
            return collect();
        }
        $endsAt = $upcoming->ends_at;

        $windowDays = max(1, min(120, $windowDays));
        $yearsBack = max(1, min(10, $yearsBack));
        $selectedTitle = (string) ($upcoming->display_name ?: $upcoming->name ?: '');
        $selectedTitleNormalized = $this->normalizeTitle($selectedTitle);
        $selectedCity = $this->normalizeLocationPart((string) ($upcoming->city ?? ''));
        $selectedState = $this->normalizeLocationPart((string) ($upcoming->state ?? ''));
        $selectedSourceRef = trim((string) ($upcoming->source_ref ?? ''));

        $results = collect();

        for ($i = 1; $i <= $yearsBack; $i++) {
            $matchYear = (int) $startsAt->year - $i;

            try {
                $target = $startsAt->copy()->setYear($matchYear);
            } catch (\Throwable $e) {
                continue;
            }

            $start = $target->copy()->subDays($windowDays)->toDateString();
            $end = $target->copy()->addDays($windowDays)->toDateString();
            $targetEnd = null;

            if ($endsAt) {
                try {
                    $targetEnd = $endsAt->copy()->setYear($matchYear);
                } catch (\Throwable $e) {
                    $targetEnd = null;
                }
            }

            $candidates = Event::query()
                ->select(['id', 'market_id', 'name', 'display_name', 'starts_at', 'ends_at', 'city', 'state', 'source', 'source_ref'])
                ->whereNotNull('starts_at')
                ->where('id', '!=', $upcoming->id)
                ->where(function (Builder $query) use ($start, $end, $targetEnd, $windowDays): void {
                    $query->whereBetween('starts_at', [$start, $end]);

                    if ($targetEnd) {
                        $endStart = $targetEnd->copy()->subDays($windowDays)->toDateString();
                        $endEnd = $targetEnd->copy()->addDays($windowDays)->toDateString();

                        $query->orWhereBetween('ends_at', [$endStart, $endEnd]);
                    }
                })
                ->orderBy('starts_at')
                ->orderBy('display_name')
                ->with('market:id,name')
                ->get();

            foreach ($candidates as $candidate) {
                $candidateStartsAt = $candidate->starts_at;
                if (! $candidateStartsAt) {
                    continue;
                }

                $daysDiffSigned = (int) $candidateStartsAt->diffInDays($target, false);
                $daysDiff = (int) abs($daysDiffSigned);
                $titleScore = $this->candidateTitleSimilarity($selectedTitleNormalized, $candidate);
                $dateScore = $this->dateProximityScore($daysDiff, $windowDays);
                $locationScore = $this->candidateLocationScore($selectedCity, $selectedState, $candidate);
                $sourceRefScore = $this->candidateSourceRefScore($selectedSourceRef, (string) ($candidate->source_ref ?? ''));
                $matchScore = round(
                    ($titleScore * 0.65)
                    + ($dateScore * 0.25)
                    + $locationScore
                    + $sourceRefScore,
                    4
                );

                $results->push([
                    'event' => $candidate,
                    'candidate_event_id' => (int) $candidate->id,
                    'match_year' => $matchYear,
                    'target_date' => $target->toDateString(),
                    'match_score' => $matchScore,
                    'title_score' => round($titleScore, 4),
                    'date_score' => round($dateScore, 4),
                    'location_score' => round($locationScore, 4),
                    'source_ref_score' => round($sourceRefScore, 4),
                    'days_diff' => $daysDiff,
                    'days_diff_signed' => $daysDiffSigned,
                ]);
            }
        }

        $final = $results
            ->unique(fn (array $row) => (int) ($row['candidate_event_id'] ?? 0))
            ->sort(function (array $a, array $b): int {
                return [
                    -1 * (int) round(((float) ($a['match_score'] ?? 0.0)) * 10000),
                    -1 * (int) ($a['match_year'] ?? 0),
                    (int) ($a['days_diff'] ?? 9999),
                    (string) (data_get($a, 'event.starts_at')?->toDateString() ?? '9999-12-31'),
                    Str::lower((string) (data_get($a, 'event.display_name') ?: data_get($a, 'event.name') ?: '')),
                ] <=> [
                    -1 * (int) round(((float) ($b['match_score'] ?? 0.0)) * 10000),
                    -1 * (int) ($b['match_year'] ?? 0),
                    (int) ($b['days_diff'] ?? 9999),
                    (string) (data_get($b, 'event.starts_at')?->toDateString() ?? '9999-12-31'),
                    Str::lower((string) (data_get($b, 'event.display_name') ?: data_get($b, 'event.name') ?: '')),
                ];
            })
            ->values();

        Log::info('EventMatchingService candidatesForUpcoming', [
            'upcoming_event_id' => $upcoming->id,
            'window_days' => $windowDays,
            'years_back' => $yearsBack,
            'candidate_count' => $final->count(),
            'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
        ]);

        return $final;
    }

    protected function candidateTitleSimilarity(string $selectedTitleNormalized, Event $candidate): float
    {
        $scores = [];

        if ($selectedTitleNormalized !== '') {
            foreach (array_filter([
                (string) ($candidate->display_name ?? ''),
                (string) ($candidate->name ?? ''),
            ]) as $candidateTitle) {
                $normalized = $this->normalizeTitle($candidateTitle);
                if ($normalized === '') {
                    continue;
                }

                $scores[] = $this->similarity($selectedTitleNormalized, $normalized);
            }
        }

        return $scores === [] ? 0.0 : max($scores);
    }

    protected function dateProximityScore(int $daysDiff, int $windowDays): float
    {
        if ($windowDays <= 0) {
            return 0.0;
        }

        return max(0.0, min(1.0, 1 - ($daysDiff / $windowDays)));
    }

    protected function candidateLocationScore(string $selectedCity, string $selectedState, Event $candidate): float
    {
        $candidateCity = $this->normalizeLocationPart((string) ($candidate->city ?? ''));
        $candidateState = $this->normalizeLocationPart((string) ($candidate->state ?? ''));

        $score = 0.0;

        if ($selectedCity !== '' && $candidateCity !== '' && $selectedCity === $candidateCity) {
            $score += 0.07;
        }

        if ($selectedState !== '' && $candidateState !== '' && $selectedState === $candidateState) {
            $score += 0.03;
        }

        return $score;
    }

    protected function candidateSourceRefScore(string $selectedSourceRef, string $candidateSourceRef): float
    {
        $selectedSourceRef = trim($selectedSourceRef);
        $candidateSourceRef = trim($candidateSourceRef);

        if ($selectedSourceRef === '' || $candidateSourceRef === '') {
            return 0.0;
        }

        return $selectedSourceRef === $candidateSourceRef ? 0.05 : 0.0;
    }

    protected function normalizeLocationPart(string $value): string
    {
        $value = Str::lower(trim($value));
        $value = preg_replace('/[^a-z0-9\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    protected function extractTitle(mixed $candidate, callable|string|null $titleAccessor): string
    {
        if (is_callable($titleAccessor)) {
            return (string) $titleAccessor($candidate);
        }

        if (is_string($candidate)) {
            return $candidate;
        }

        if (is_array($candidate)) {
            if ($titleAccessor && isset($candidate[$titleAccessor])) {
                return (string) $candidate[$titleAccessor];
            }

            foreach (['event_title', 'display_name', 'name', 'title'] as $key) {
                if (isset($candidate[$key])) {
                    return (string) $candidate[$key];
                }
            }
        }

        if (is_object($candidate)) {
            if ($titleAccessor && isset($candidate->{$titleAccessor})) {
                return (string) $candidate->{$titleAccessor};
            }

            foreach (['event_title', 'display_name', 'name', 'title'] as $key) {
                if (isset($candidate->{$key})) {
                    return (string) $candidate->{$key};
                }
            }
        }

        return '';
    }
}
