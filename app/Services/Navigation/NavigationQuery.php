<?php

namespace App\Services\Navigation;

use Illuminate\Support\Str;

/** Interprets navigation language only; it never authorizes or executes actions. */
class NavigationQuery
{
    public function normalize(string $utterance): string
    {
        $query = Str::lower(Str::ascii(trim($utterance)));
        $query = preg_replace('/[?.!]+$/', '', $query) ?? $query;
        $query = preg_replace('/^(?:(?:hey\s+)?everbranch[, ]+)?(?:please\s+)?(?:can you\s+|could you\s+)?(?:please\s+)?/', '', $query) ?? $query;
        $query = preg_replace('/^(?:take me to|bring me to|navigate to|go to|open|show me|show|find|where (?:can|do) i (?:manage|find|see)|where (?:is|are))\s+/', '', $query) ?? $query;
        $query = preg_replace('/^(?:the|my|our)\s+/', '', $query) ?? $query;
        $query = preg_replace('/\s+(?:page|screen|section)(?:\s+please)?$/', '', $query) ?? $query;
        $query = preg_replace('/\s+please$/', '', $query) ?? $query;

        return trim(preg_replace('/\s+/', ' ', $query) ?? $query);
    }

    public function control(string $utterance): ?string
    {
        return match (Str::lower(trim($utterance, " \t\n\r\0\x0B.!?"))) {
            'go back', 'back', 'previous page', 'previous screen' => 'back',
            'cancel', 'stop', 'never mind', 'nevermind' => 'cancel',
            default => null,
        };
    }

    public function resolution(array $results): array
    {
        $first = $results[0] ?? null;
        if (! is_array($first)) {
            return ['status' => 'no_match'];
        }

        $exact = array_values(array_filter($results, fn (array $row): bool => ($row['type'] ?? null) === 'navigation' && ($row['meta']['exact_match'] ?? false) === true));
        $clearPage = count($exact) === 1 && $this->isDestination($exact[0]);

        return $clearPage
            ? ['status' => 'matched', 'destination' => $exact[0]]
            : ['status' => 'choose'];
    }

    /** Action rows sometimes have no action field. Check both representations. */
    public function isDestination(array $row): bool
    {
        return empty($row['action']) && ! in_array($row['type'] ?? '', ['action', 'command'], true)
            && is_string($row['url'] ?? null) && trim($row['url']) !== '' && $row['url'] !== '#';
    }
}
