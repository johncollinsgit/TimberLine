<?php

namespace App\Services;

use App\Models\Scent;

class ScentGuessEngine
{
    /**
     * @param string|null $rawTitle
     * @param string|null $rawVariant
     * @param array<int, array<string, mixed>>|array<string, mixed>|null $properties
     * @return array<int, array{ id:int, name:string, score:float }>
     */
    public function guess(?string $rawTitle, ?string $rawVariant, $properties = null, int $limit = 3): array
    {
        $haystack = $this->normalizeText(
            trim((string) ($rawTitle ?? '') . ' ' . (string) ($rawVariant ?? '') . ' ' . $this->propertiesToText($properties))
        );

        $scents = Scent::query()->select(['id', 'name', 'display_name'])->get();
        $scores = [];

        foreach ($scents as $scent) {
            $name = $scent->display_name ?: $scent->name;
            $needle = $this->normalizeText($name);
            if ($needle === '') {
                continue;
            }

            $score = 0.0;
            if ($haystack !== '' && str_contains($haystack, $needle)) {
                $score = 0.9;
            }

            if ($score < 0.9 && $haystack !== '') {
                $sim = 0.0;
                similar_text($needle, $haystack, $sim);
                $score = max($score, $sim / 100.0);
            }

            if ($score > 0.0) {
                $scores[] = [
                    'id' => $scent->id,
                    'name' => $name,
                    'score' => round($score * 100, 0),
                ];
            }
        }

        usort($scores, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice($scores, 0, $limit);
    }

    protected function normalizeText(string $value): string
    {
        $clean = strtolower(trim($value));
        $clean = preg_replace('/\bwholesale\b/i', '', $clean) ?? $clean;
        $clean = str_replace('&', 'and', $clean);
        $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
        return trim($clean);
    }

    /**
     * @param array<int, array<string, mixed>>|array<string, mixed>|null $properties
     */
    protected function propertiesToText($properties): string
    {
        if (!is_array($properties)) {
            return '';
        }

        $parts = [];
        foreach ($properties as $key => $prop) {
            if (is_array($prop)) {
                $parts[] = (string) ($prop['name'] ?? '');
                $parts[] = (string) ($prop['value'] ?? '');
            } else {
                $parts[] = (string) $prop;
                $parts[] = (string) $key;
            }
        }

        return trim(implode(' ', array_filter($parts)));
    }
}
