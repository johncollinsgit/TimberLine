<?php

namespace App\Support\Markets;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

class SheetNameParser
{
    /** @var array<string,bool> */
    private array $stateCodes;

    public function __construct()
    {
        $states = [
            'AL','AK','AZ','AR','CA','CO','CT','DE','FL','GA','HI','IA','ID','IL','IN','KS','KY','LA',
            'MA','MD','ME','MI','MN','MO','MS','MT','NC','ND','NE','NH','NJ','NM','NV','NY','OH','OK',
            'OR','PA','RI','SC','SD','TN','TX','UT','VA','VT','WA','WI','WV','WY','DC',
        ];
        $this->stateCodes = array_fill_keys($states, true);
    }

    /**
     * @param array<int,string> $sheetContentHints
     * @return array<string,mixed>
     */
    public function parse(string $sheetName, int $workbookYear, array $sheetContentHints = []): array
    {
        $raw = trim($sheetName);
        $normalized = $this->normalize($raw);

        if ($raw === '' || $this->isIgnored($normalized)) {
            return [
                'ignored' => true,
                'raw_sheet_name' => $raw,
                'normalized_sheet_name' => $normalized,
                'confidence' => 'none',
                'notes' => ['ignored meta sheet'],
            ];
        }

        $notes = [];

        $date = $this->parseDate($normalized, $workbookYear);
        $dateFragment = $date['matched_fragment'] ?? null;
        if (!empty($date['notes'])) {
            array_push($notes, ...$date['notes']);
        }

        if (($date['starts_at'] ?? null) === null && !empty($sheetContentHints)) {
            $fallbackDate = $this->parseDateFromHints($sheetContentHints, $workbookYear);
            if (($fallbackDate['starts_at'] ?? null) !== null) {
                $date = array_merge($date, [
                    'starts_at' => $fallbackDate['starts_at'],
                    'ends_at' => $fallbackDate['ends_at'],
                    'date_confidence' => $fallbackDate['date_confidence'],
                    'date_parse_notes' => $fallbackDate['date_parse_notes'],
                ]);
                if (!empty($fallbackDate['notes'])) {
                    array_push($notes, ...$fallbackDate['notes']);
                }
            }
        }

        $withoutDate = $this->removeFragment($normalized, $dateFragment);
        $location = $this->parseLocation($withoutDate);
        $locationFragment = $location['matched_fragment'] ?? null;
        if (!empty($location['notes'])) {
            array_push($notes, ...$location['notes']);
        }

        $marketName = $this->extractMarketName($normalized, [$dateFragment, $locationFragment], $workbookYear);
        if (!empty($marketName['notes'])) {
            array_push($notes, ...$marketName['notes']);
        }

        if ($this->looksLikeDateKey((string) ($marketName['market_name'] ?? ''))) {
            $fallbackName = $this->fallbackMarketNameFromLocation($location);
            if ($fallbackName !== null) {
                $marketName['market_name'] = $fallbackName;
                $marketName['market_name_confidence'] = $this->looksLikeDateKey((string) ($marketName['market_name'] ?? '')) ? 'low' : 'medium';
                $notes[] = 'market name inferred from parsed location';
            }
        }

        $confidence = $this->overallConfidence(
            (string) ($marketName['market_name'] ?? ''),
            $date['starts_at'] ?? null,
            $location['city'] ?? null,
            $location['state'] ?? null,
            (string) ($date['date_confidence'] ?? 'none')
        );

        $needsReview = in_array($confidence, ['low', 'none'], true)
            || (($date['starts_at'] ?? null) === null)
            || (($marketName['market_name'] ?? '') === '');

        $notes = array_values(array_unique(array_filter(array_map('strval', $notes))));

        return [
            'ignored' => false,
            'raw_sheet_name' => $raw,
            'normalized_sheet_name' => $normalized,
            'year' => $workbookYear,
            'market_name' => $marketName['market_name'] ?? $normalized,
            'market_name_confidence' => $marketName['market_name_confidence'] ?? 'low',
            'canonical_slug' => Str::slug((string) ($marketName['market_name'] ?? $normalized)),
            'city' => $location['city'] ?? null,
            'state' => $location['state'] ?? null,
            'location_confidence' => $location['location_confidence'] ?? 'none',
            'location_parse_notes' => $location['location_parse_notes'] ?? null,
            'starts_at' => $date['starts_at'] ?? null,
            'ends_at' => $date['ends_at'] ?? null,
            'date_confidence' => $date['date_confidence'] ?? 'none',
            'date_parse_notes' => $date['date_parse_notes'] ?? null,
            'confidence' => $confidence,
            'needs_review' => $needsReview,
            'notes' => $notes,
        ];
    }

    private function normalize(string $sheetName): string
    {
        $s = str_replace(["\u{2013}", "\u{2014}", "\u{2212}"], '-', $sheetName);
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = trim($s);
        $s = rtrim($s, " \t\n\r\0\x0B.,-");

        return $s;
    }

    private function isIgnored(string $normalized): bool
    {
        $lower = Str::of($normalized)->lower()->squish()->value();
        if (in_array($lower, ['market box count & scent notes', 'tr room sprays sold'], true)) {
            return true;
        }

        return (bool) preg_match('/^(sheet\d+|notes?|summary)$/i', $normalized);
    }

    /**
     * @return array<string,mixed>
     */
    private function parseDate(string $s, int $workbookYear): array
    {
        // Handle common Excel tab truncation cases before generic date matching,
        // otherwise `MM.DD.YY` is incorrectly captured as a complete single date.
        if (preg_match('/(0[1-9]|1[0-2])[\.\/](0[1-9]|[12]\d|3[01])[\.\/](\d{2,4})\s*-\s*(0[1-9]|1[0-2])(?:[\.\/]?)$/', $s, $m)) {
            $start = $this->safeDate((int) $m[1], (int) $m[2], $this->normalizeYear((string) $m[3], $workbookYear));

            return [
                'starts_at' => $start?->toDateString(),
                'ends_at' => null,
                'date_confidence' => $start ? 'medium' : 'low',
                'date_parse_notes' => 'end date truncated after range dash',
                'matched_fragment' => $m[0],
                'notes' => ['end date truncated'],
            ];
        }

        $patterns = [
            [
                'type' => 'range_full',
                'regex' => '/(0[1-9]|1[0-2])[\.\/](0[1-9]|[12]\d|3[01])[\.\/](\d{2,4})\s*-\s*(0[1-9]|1[0-2])[\.\/](0[1-9]|[12]\d|3[01])[\.\/](\d{2,4})/',
            ],
            [
                'type' => 'range_end_missing_year',
                'regex' => '/(0[1-9]|1[0-2])[\.\/](0[1-9]|[12]\d|3[01])[\.\/](\d{2,4})\s*-\s*(0[1-9]|1[0-2])[\.\/](0[1-9]|[12]\d|3[01])\b/',
            ],
            [
                'type' => 'single_with_year',
                'regex' => '/(0[1-9]|1[0-2])[\.\/](0[1-9]|[12]\d|3[01])[\.\/](\d{2,4})/',
            ],
            [
                'type' => 'single_missing_year',
                'regex' => '/(0[1-9]|1[0-2])[\.\/](0[1-9]|[12]\d|3[01])\b/',
            ],
        ];

        $best = null;
        foreach ($patterns as $pattern) {
            if (!preg_match_all($pattern['regex'], $s, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            foreach ($matches[0] as $i => $whole) {
                $fragment = (string) $whole[0];
                $offset = (int) $whole[1];
                $nonEmptyGroups = 0;
                for ($g = 1; $g < count($matches); $g++) {
                    if (isset($matches[$g][$i][0]) && $matches[$g][$i][0] !== '') {
                        $nonEmptyGroups++;
                    }
                }
                $score = ($nonEmptyGroups * 1000) + $offset + strlen($fragment);
                if (!$best || $score > $best['score']) {
                    $best = [
                        'type' => $pattern['type'],
                        'fragment' => $fragment,
                        'offset' => $offset,
                        'score' => $score,
                        'groups' => array_map(fn ($group) => $group[$i][0] ?? null, array_slice($matches, 1)),
                    ];
                }
            }
        }

        if ($best) {
            return $this->buildDateResultFromMatch($best, $workbookYear);
        }

        if (preg_match('/\b(0?[1-9]|1[0-2])[\.\/](\d{1,2})[\.\/](\d{2,4})\b/', $s, $m)) {
            $year = $this->normalizeYear((string) $m[3], $workbookYear);
            $start = $this->safeDate((int) $m[1], (int) $m[2], $year);
            return [
                'starts_at' => $start?->toDateString(),
                'ends_at' => $start?->toDateString(),
                'date_confidence' => $start ? 'high' : 'low',
                'date_parse_notes' => 'single date found; end date set to start date',
                'matched_fragment' => $m[0],
                'notes' => [],
            ];
        }

        if (preg_match('/\b(0[1-9]|1[0-2])[\.\/](\d{1,2})$/', $s, $m)) {
            $day = (int) $m[2];
            if ($day >= 1 && $day <= 31) {
                $start = $this->safeDate((int) $m[1], $day, $workbookYear);
                return [
                    'starts_at' => $start?->toDateString(),
                    'ends_at' => $start?->toDateString(),
                    'date_confidence' => $start ? 'medium' : 'low',
                    'date_parse_notes' => 'single date inferred from month/day using workbook year',
                    'matched_fragment' => $m[0],
                    'notes' => ['year inferred from workbook year'],
                ];
            }
        }

        if (preg_match('/\b(0[1-9]|1[0-2])\.(0[1-9]|[12]\d|3[01])?\.$/', $s, $m)) {
            return [
                'starts_at' => null,
                'ends_at' => null,
                'date_confidence' => 'low',
                'date_parse_notes' => 'partial date fragment at end of sheet name',
                'matched_fragment' => $m[0],
                'notes' => ['partial/truncated date fragment detected'],
            ];
        }

        return [
            'starts_at' => null,
            'ends_at' => null,
            'date_confidence' => 'none',
            'date_parse_notes' => null,
            'matched_fragment' => null,
            'notes' => [],
        ];
    }

    /**
     * @param array<string,mixed> $best
     * @return array<string,mixed>
     */
    private function buildDateResultFromMatch(array $best, int $workbookYear): array
    {
        $g = $best['groups'];
        $notes = [];
        $startsAt = null;
        $endsAt = null;
        $confidence = 'none';
        $parseNote = null;

        try {
            if ($best['type'] === 'range_full') {
                $startYear = $this->normalizeYear((string) $g[2], $workbookYear);
                $endYear = $this->normalizeYear((string) $g[5], $workbookYear);
                $start = $this->safeDate((int) $g[0], (int) $g[1], $startYear);
                $end = $this->safeDate((int) $g[3], (int) $g[4], $endYear);
                $startsAt = $start?->toDateString();
                $endsAt = $end?->toDateString();
                $confidence = ($start && $end) ? 'high' : 'low';
            } elseif ($best['type'] === 'range_end_missing_year') {
                $year = $this->normalizeYear((string) $g[2], $workbookYear);
                $start = $this->safeDate((int) $g[0], (int) $g[1], $year);
                $end = $this->safeDate((int) $g[3], (int) $g[4], $year);
                $startsAt = $start?->toDateString();
                $endsAt = $end?->toDateString();
                $confidence = ($start && $end) ? 'high' : 'low';
                $parseNote = 'end year inferred from start year';
                $notes[] = 'end year inferred from start year';
            } elseif ($best['type'] === 'single_with_year') {
                $year = $this->normalizeYear((string) $g[2], $workbookYear);
                $start = $this->safeDate((int) $g[0], (int) $g[1], $year);
                $startsAt = $start?->toDateString();
                $endsAt = $start?->toDateString();
                $confidence = $start ? 'high' : 'low';
                $parseNote = 'single date found; end date set to start date';
            } elseif ($best['type'] === 'single_missing_year') {
                $start = $this->safeDate((int) $g[0], (int) $g[1], $workbookYear);
                $startsAt = $start?->toDateString();
                $endsAt = $start?->toDateString();
                $confidence = $start ? 'medium' : 'low';
                $parseNote = 'single date found; workbook year inferred';
                $notes[] = 'year inferred from workbook year';
            }
        } catch (\Throwable $e) {
            $confidence = 'low';
            $parseNote = 'date parse failed';
            $notes[] = 'date parse failed';
        }

        return [
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'date_confidence' => $confidence,
            'date_parse_notes' => $parseNote,
            'matched_fragment' => $best['fragment'],
            'notes' => $notes,
        ];
    }

    /**
     * @param array<int,string> $hints
     * @return array<string,mixed>
     */
    private function parseDateFromHints(array $hints, int $workbookYear): array
    {
        foreach (array_slice($hints, 0, 10) as $hint) {
            $hint = trim((string) $hint);
            if ($hint === '') {
                continue;
            }
            if (!preg_match('/date/i', $hint) && !preg_match('/(0[1-9]|1[0-2])[\.\/](0[1-9]|[12]\d|3[01])/', $hint)) {
                continue;
            }
            $parsed = $this->parseDate($hint, $workbookYear);
            if (($parsed['starts_at'] ?? null) !== null) {
                $parsed['notes'][] = 'date parsed from sheet content fallback';
                $parsed['date_confidence'] = $parsed['date_confidence'] === 'high' ? 'medium' : $parsed['date_confidence'];
                return $parsed;
            }
        }

        return [
            'starts_at' => null,
            'ends_at' => null,
            'date_confidence' => 'none',
            'date_parse_notes' => null,
            'matched_fragment' => null,
            'notes' => [],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function parseLocation(string $s): array
    {
        $notes = [];
        if (preg_match_all('/([^,]+),\s*([A-Z]{2})\b/', $s, $m, PREG_OFFSET_CAPTURE)) {
            $last = count($m[0]) - 1;
            $state = strtoupper((string) $m[2][$last][0]);
            if (isset($this->stateCodes[$state])) {
                $rawPrefix = trim((string) $m[1][$last][0]);
                $city = $this->inferCityFromCommaPrefix($rawPrefix);
                $fragment = $city ? ($city.', '.$state) : (', '.$state);
                return [
                    'city' => $city !== '' ? $city : null,
                    'state' => $state,
                    'location_confidence' => ($city !== '' ? 'high' : 'low'),
                    'location_parse_notes' => $city !== '' ? 'parsed city/state from comma pattern' : 'state parsed from comma suffix only',
                    'matched_fragment' => $fragment,
                    'notes' => [$city !== '' ? 'city/state parsed from comma pattern' : 'state parsed from comma suffix only'],
                ];
            }
        }

        if (preg_match('/(?:^|[\s,])([A-Z]{2})$/', $s, $m, PREG_OFFSET_CAPTURE)) {
            $state = strtoupper((string) $m[1][0]);
            if (isset($this->stateCodes[$state])) {
                $fragment = trim((string) $m[0][0]);
                $prefix = trim(substr($s, 0, (int) $m[0][1]));
                $cityGuess = null;
                if (preg_match('/([A-Za-z][A-Za-z\.\'\- ]+)$/', $prefix, $cityMatch)) {
                    $cityGuess = $this->cleanLocationFragment((string) $cityMatch[1]);
                    if ($cityGuess === '') {
                        $cityGuess = null;
                    }
                }
                $notes[] = $cityGuess ? 'state parsed at tail; city guessed from prefix' : 'state parsed at tail';
                return [
                    'city' => $cityGuess,
                    'state' => $state,
                    'location_confidence' => $cityGuess ? 'medium' : 'low',
                    'location_parse_notes' => $cityGuess ? 'tail state + inferred city' : 'tail state only',
                    'matched_fragment' => $fragment,
                    'notes' => $notes,
                ];
            }
        }

        return [
            'city' => null,
            'state' => null,
            'location_confidence' => 'none',
            'location_parse_notes' => null,
            'matched_fragment' => null,
            'notes' => [],
        ];
    }

    /**
     * @param array<int,string|null> $fragments
     * @return array<string,mixed>
     */
    private function extractMarketName(string $original, array $fragments, int $workbookYear): array
    {
        $s = $original;
        foreach ($fragments as $fragment) {
            $s = $this->removeFragment($s, $fragment);
        }

        $s = preg_replace('/\b20\d{2}\b/', ' ', $s) ?? $s;
        $s = preg_replace('/[-\/,]+$/', '', $s) ?? $s;
        $s = preg_replace('/\s+/', ' ', $s) ?? $s;
        $s = trim($s, " \t\n\r\0\x0B,/-.");

        // Remove trailing orphan numeric/date fragments left by truncation.
        $s = preg_replace('/[\s\-\/,]+\d{1,2}([\.\/]\d{0,2})?([\.\/]\d{0,4})?$/', '', $s) ?? $s;
        $s = trim($s, " \t\n\r\0\x0B,/-.");
        if (preg_match('/^\d+$/', $s)) {
            $s = '';
        }

        if ($s === '') {
            return [
                'market_name' => '',
                'market_name_confidence' => 'low',
                'notes' => ['market name could not be extracted'],
            ];
        }

        $words = preg_split('/\s+/', $s) ?: [];
        $normalizedWords = [];
        foreach ($words as $word) {
            $upper = strtoupper($word);
            if (in_array($upper, ['VMD', 'TR'], true)) {
                $normalizedWords[] = $upper;
                continue;
            }
            if (strlen($word) <= 3 && strtoupper($word) === $word && preg_match('/^[A-Z]+$/', $word)) {
                $normalizedWords[] = $word;
                continue;
            }
            $normalizedWords[] = Str::title(Str::lower($word));
        }

        $name = implode(' ', $normalizedWords);

        return [
            'market_name' => $name,
            'market_name_confidence' => 'high',
            'notes' => [],
        ];
    }

    private function overallConfidence(string $marketName, ?string $startsAt, ?string $city, ?string $state, string $dateConfidence): string
    {
        if ($marketName === '') {
            return 'none';
        }

        if ($startsAt && ($city || $state) && in_array($dateConfidence, ['high', 'medium'], true)) {
            return 'high';
        }

        if ($startsAt) {
            return in_array($dateConfidence, ['high', 'medium'], true) ? 'medium' : 'low';
        }

        return ($city || $state) ? 'low' : 'low';
    }

    private function normalizeYear(string $yearToken, int $workbookYear): int
    {
        $yearToken = trim($yearToken);
        if ($yearToken === '') {
            return $workbookYear;
        }
        if (strlen($yearToken) === 2) {
            $base = (int) substr((string) $workbookYear, 0, 2) * 100;
            return $base + (int) $yearToken;
        }
        return (int) $yearToken;
    }

    private function safeDate(int $month, int $day, int $year): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::create($year, $month, $day, 0, 0, 0);
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function removeFragment(string $haystack, ?string $fragment): string
    {
        if (!$fragment) {
            return $haystack;
        }

        $pos = stripos($haystack, $fragment);
        if ($pos === false) {
            return $haystack;
        }

        $result = substr($haystack, 0, $pos).substr($haystack, $pos + strlen($fragment));
        $result = preg_replace('/\s+/', ' ', $result) ?? $result;

        return trim($result);
    }

    private function cleanLocationFragment(string $city): string
    {
        $city = trim($city, " \t\n\r\0\x0B,-/");
        $city = preg_replace('/\d+$/', '', $city) ?? $city;
        $city = preg_replace('/\s+/', ' ', $city) ?? $city;
        return trim($city);
    }

    private function inferCityFromCommaPrefix(string $rawPrefix): ?string
    {
        $rawPrefix = $this->cleanLocationFragment($rawPrefix);
        if ($rawPrefix === '') {
            return null;
        }

        $tokens = preg_split('/\s+/', $rawPrefix) ?: [];
        if (empty($tokens)) {
            return null;
        }

        $lastToken = (string) end($tokens);
        $eventWords = ['fair', 'festival', 'classic', 'show', 'market', 'expo', 'parade'];
        if (in_array(Str::lower($lastToken), $eventWords, true)) {
            return null;
        }

        if (count($tokens) <= 2) {
            return $rawPrefix;
        }

        $penultimate = (string) ($tokens[count($tokens) - 2] ?? '');
        $twoWordCityPrefixes = ['st', 'st.', 'ft', 'ft.', 'fort', 'new', 'north', 'south', 'east', 'west', 'mount', 'mt', 'mt.', 'myrtle'];
        if (in_array(Str::lower($penultimate), $twoWordCityPrefixes, true)) {
            return $penultimate.' '.$lastToken;
        }

        return $lastToken;
    }

    private function looksLikeDateKey(string $value): bool
    {
        $value = trim($value);
        if ($value === '') {
            return true;
        }

        return (bool) preg_match('/^\d{1,2}(?:\.\d{1,2}){0,2}$/', $value);
    }

    /**
     * @param array<string,mixed> $location
     */
    private function fallbackMarketNameFromLocation(array $location): ?string
    {
        $city = trim((string) ($location['city'] ?? ''));
        $state = strtoupper(trim((string) ($location['state'] ?? '')));

        if ($city !== '' && $state !== '') {
            return $city;
        }

        if ($city !== '') {
            return $city;
        }

        if ($state !== '') {
            return $state;
        }

        return null;
    }
}
