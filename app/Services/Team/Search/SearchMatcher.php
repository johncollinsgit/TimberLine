<?php

namespace App\Services\Team\Search;

use Illuminate\Support\Str;

/** Ranking contract shared with public/js/everbranch-search.js and its golden fixtures. */
class SearchMatcher
{
    public const VERSION = 1;

    private array $normalized = [];

    public function normalize(string $value): string
    {
        if (isset($this->normalized[$value])) {
            return $this->normalized[$value];
        }
        $original = $value;
        $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
        $value = mb_strtolower(preg_replace('/\p{M}/u', '', $value));

        $result = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value));
        if (count($this->normalized) < 50000) {
            $this->normalized[$original] = $result;
        }

        return $result;
    }

    public function words(string $value): array
    {
        return array_values(array_filter(explode(' ', $this->normalize($value)), fn ($v) => $v !== ''));
    }

    public function parse(string $input): array
    {
        $raw = mb_substr(trim($input), 0, 500);
        $commands = str_starts_with($raw, '>');
        $text = $commands ? trim(substr($raw, 1)) : $raw;
        preg_match_all('/"([^"]+)"/u', $text, $matches);

        return ['text' => $this->normalize(str_replace('"', '', $text)), 'terms' => $this->words($text),
            'phrases' => array_filter(array_map($this->normalize(...), $matches[1])), 'commandsOnly' => $commands];
    }

    public function distance(string $a, string $b, int $maximum): int
    {
        $a = mb_str_split($a);
        $b = mb_str_split($b);
        if (abs(count($a) - count($b)) > $maximum) {
            return $maximum + 1;
        }
        $previous = range(0, count($b));
        $beforePrevious = null;
        foreach ($a as $i => $left) {
            $row = [$i + 1];
            foreach ($b as $j => $right) {
                $edit = min($row[$j] + 1, $previous[$j + 1] + 1, $previous[$j] + ($left === $right ? 0 : 1));
                if ($i > 0 && $j > 0 && $left === $b[$j - 1] && $a[$i - 1] === $right) {
                    $edit = min($edit, $beforePrevious[$j - 1] + 1);
                }
                $row[] = $edit;
            }
            $beforePrevious = $previous;
            $previous = $row;
        }

        return $previous[count($b)];
    }

    public function score(array $document, array $query): ?array
    {
        if ($query['commandsOnly'] && $document['type'] !== 'command') {
            return null;
        }
        $title = $this->normalize($document['title']);
        $body = $this->normalize($document['body'] ?? '');
        $id = $this->normalize($document['identifier'] ?? $document['id']);
        $combined = $title.' '.$body;
        $compactId = str_replace(' ', '', $id);
        $compactQuery = str_replace(' ', '', $query['text']);
        if ($compactQuery !== '' && $compactId === $compactQuery) {
            return [0, 0, ! empty($document['completed']) || ! empty($document['archived']) ? 1 : 0,
                -((strtotime($document['updated_at'] ?? '') ?: 0) * 1000), $document['id']];
        }
        foreach ($query['phrases'] as $phrase) {
            if (! str_contains($combined, $phrase)) {
                return null;
            }
        }
        if (! $query['terms']) {
            return [0, 0, 0, 0, $document['id']];
        }
        $titleWords = array_values(array_filter(explode(' ', $title), fn ($v) => $v !== ''));
        $allWords = array_values(array_filter(explode(' ', $combined), fn ($v) => $v !== ''));
        $idWords = array_values(array_filter(explode(' ', $id), fn ($v) => $v !== ''));
        $typos = $bodyMatches = 0;
        foreach ($query['terms'] as $i => $term) {
            $last = $i === count($query['terms']) - 1;
            $match = function ($words) use ($term, $last) {
                foreach ($words as $word) {
                    if ($word === $term || ($last && str_starts_with($word, $term))) {
                        return true;
                    }
                }

                return false;
            };
            if ($match($titleWords) || $match($idWords) || (count($query['terms']) === 1 && str_starts_with($compactId, $compactQuery))) {
                continue;
            }
            if ($match($allWords)) {
                $bodyMatches++;

                continue;
            }
            $allowed = mb_strlen($term) >= 9 ? 2 : (mb_strlen($term) >= 5 ? 1 : 0);
            if (! $allowed) {
                return null;
            }
            $best = $allowed + 1;
            $inTitle = false;
            foreach ($allWords as $word) {
                $variants = [$word];
                if ($last) {
                    foreach ([mb_strlen($term) - 1, mb_strlen($term), mb_strlen($term) + 1] as $length) {
                        if ($length > 0 && $length < mb_strlen($word)) {
                            $variants[] = mb_substr($word, 0, $length);
                        }
                    }
                }
                $edit = min(array_map(fn ($v) => $this->distance($term, $v, $allowed), $variants));
                if ($edit < $best) {
                    $best = $edit;
                    $inTitle = in_array($word, $titleWords, true);
                }
            }
            if ($best > $allowed) {
                return null;
            }
            $typos += $best;
            $bodyMatches += $inTitle ? 0 : 1;
        }
        $compact = fn ($value) => str_replace(' ', '', $value);
        $tier = $compact($id) === $compact($query['text']) ? 0 : ($title === $query['text'] ? 1 : (str_starts_with($title, $query['text']) ? 2 : (! $bodyMatches ? 3 : 4)));

        return [$tier, $typos, ! empty($document['completed']) || ! empty($document['archived']) ? 1 : 0,
            -((strtotime($document['updated_at'] ?? '') ?: 0) * 1000), $document['id']];
    }

    public function snippet(string $body, string $query): string
    {
        $terms = $this->parse($query)['terms'];
        $position = 0;
        foreach ($terms as $term) {
            $found = mb_stripos($body, $term);
            if ($found !== false) {
                $position = max(0, $found - 50);
                break;
            }
        }

        return ($position ? '…' : '').Str::limit(mb_substr($body, $position), 180);
    }
}
