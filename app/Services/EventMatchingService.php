<?php

namespace App\Services;

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
        $title = preg_replace('/\b\d{1,2}[\/-]\d{1,2}(?:[\/-]\d{2,4})?\b/u', ' ', $title) ?? $title;
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
