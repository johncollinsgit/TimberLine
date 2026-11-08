<?php

namespace App\Services\Team\Operations;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Validator;

/** An explicit event range is distinct from the source task's reminder/deadline. */
class EventSchedule
{
    /** One row describes one local day. Bounds never create sessions on gap days. */
    public function validate(array $input): array
    {
        $data = Validator::make($input, [
            'timezone' => 'nullable|timezone:all', 'calendar_color' => 'nullable|regex:/^#[0-9a-fA-F]{6}$/D',
            'schedule_complete' => 'sometimes|boolean', 'sessions' => 'sometimes|array|list|max:732',
            'sessions.*' => 'array:id,kind,date,opens,closes,label',
            'sessions.*.id' => 'required|uuid|distinct:ignore_case', 'sessions.*.kind' => 'required|in:setup,selling,teardown,travel',
            'sessions.*.date' => 'present|nullable|date_format:Y-m-d',
            'sessions.*.opens' => 'nullable|date_format:H:i', 'sessions.*.closes' => 'nullable|date_format:H:i',
            'sessions.*.label' => 'nullable|string|max:150',
            'evidence_selections' => 'sometimes|array|list|max:732', 'evidence_selections.*' => 'array:session_id,evidence_id,comment_cursor',
            'evidence_selections.*.session_id' => 'required|uuid|distinct', 'evidence_selections.*.evidence_id' => 'required|string|size:64', 'evidence_selections.*.comment_cursor' => 'nullable|string|max:10000',
            'attestations' => 'sometimes|array|list|max:732', 'attestations.*' => 'array:session_id,passage,url',
            'attestations.*.session_id' => 'required|uuid|distinct', 'attestations.*.passage' => 'required|string|max:3000', 'attestations.*.url' => 'nullable|url:https|max:2000',
        ])->validate();
        $data['schedule_complete'] = (bool) ($data['schedule_complete'] ?? false);
        $ids = array_column($data['sessions'] ?? [], 'id');
        $evidenceIds = [...array_column($data['evidence_selections'] ?? [], 'session_id'), ...array_column($data['attestations'] ?? [], 'session_id')];
        abort_unless(count($evidenceIds) === count(array_unique($evidenceIds)) && ! array_diff($evidenceIds, $ids), 422, 'Use one evidence choice for an existing session.');
        foreach ($data['sessions'] ?? [] as $session) {
            $opens = $session['opens'] ?? null;
            $closes = $session['closes'] ?? null;
            abort_unless(($opens === null) === ($closes === null), 422, 'Supply both session hours or leave both unknown.');
            abort_if(($session['date'] ?? null) === null && $opens !== null, 422, 'Choose the local date before recording hours.');
            abort_if($opens !== null && $closes <= $opens, 422, 'Session hours must end later on the same local date. Split overnight activity into dated sessions.');
            if ($session['date'] ?? null) {
                abort_unless(! empty($data['timezone']), 422, 'Choose the local timezone for dated sessions.');
                foreach (array_filter([$opens, $closes]) as $time) {
                    $local = CarbonImmutable::createFromFormat('!Y-m-d H:i', $session['date'].' '.$time, $data['timezone']);
                    abort_unless($local->format('Y-m-d H:i') === $session['date'].' '.$time, 422, 'This local hour does not exist in the selected timezone.');
                    $wall = CarbonImmutable::createFromFormat('!Y-m-d H:i', $session['date'].' '.$time, 'UTC')->timestamp;
                    $transitions = (new \DateTimeZone($data['timezone']))->getTransitions($wall - 86400, $wall + 86400) ?: [['offset' => $local->offset]];
                    $offsets = array_unique(array_column($transitions, 'offset'));
                    $matches = array_filter($offsets, fn ($offset) => CarbonImmutable::createFromTimestampUTC($wall - $offset)->setTimezone($data['timezone'])->format('Y-m-d H:i') === $session['date'].' '.$time);
                    abort_if(count($matches) > 1, 422, 'This local hour occurs twice during a timezone transition. Leave hours unknown until the exact window is reviewed.');
                }
            }
        }
        $calendar = $this->calendar($data);
        abort_if($data['schedule_complete'] && (empty($data['sessions']) || $calendar['has_undated_sessions'] || ! $calendar['selling_dates']), 422, 'A complete dated schedule needs all session dates and at least one selling date.');
        abort_if($calendar['overall_starts_on'] && CarbonImmutable::parse($calendar['overall_starts_on'])->diffInDays(CarbonImmutable::parse($calendar['overall_ends_on'])) > 366, 422, 'An event schedule covers at most one year.');

        return $data;
    }

    /** Reviewed Teams calendar supplements the source; source due/start are independent. */
    public function calendar(array $data, ?string $legacyStart = null, ?string $legacyEnd = null): array
    {
        $review = $data['_teams_enrichment']['calendar'] ?? null;
        $calendar = is_array($review) ? $review : $data;
        $sessions = array_values($calendar['sessions'] ?? []);
        $dates = ['setup' => [], 'selling' => [], 'teardown' => [], 'travel' => []];
        $undated = false;
        $unknownHours = false;
        $windows = [];
        foreach ($sessions as $session) {
            $date = $session['date'] ?? null;
            if ($date && isset($dates[$session['kind'] ?? ''])) {
                $dates[$session['kind']][] = $date;
            } else {
                $undated = true;
            }
            if (($session['kind'] ?? '') === 'selling') {
                if (! isset($session['opens'], $session['closes'])) {
                    $unknownHours = true;
                } else {
                    if ($date && ! empty($calendar['timezone'])) {
                        $opens = CarbonImmutable::parse($date.' '.$session['opens'], $calendar['timezone']);
                        $closes = CarbonImmutable::parse($date.' '.$session['closes'], $calendar['timezone']);
                        $windows[$date][] = [$opens->timestamp, $closes->timestamp];
                    } else {
                        $unknownHours = true;
                    }
                }
            }
        }
        foreach ($dates as &$list) {
            $list = array_values(array_unique($list));
            sort($list);
        }
        unset($list);
        $minutes = 0;
        foreach ($windows as $intervals) {
            usort($intervals, fn ($a, $b) => $a[0] <=> $b[0]);
            $end = null;
            foreach ($intervals as [$opens, $closes]) {
                $minutes += max(0, $closes - max($opens, $end ?? $opens)) / 60;
                $end = max($end ?? $closes, $closes);
            }
        }
        $all = array_merge(...array_values($dates));
        sort($all);
        $complete = ($calendar['schedule_complete'] ?? false) === true && $sessions && ! $undated && $dates['selling'];
        $result = ['version' => 1, 'sessions' => $sessions, 'timezone' => $calendar['timezone'] ?? null,
            'calendar_color' => $calendar['calendar_color'] ?? null, 'schedule_complete' => (bool) $complete,
            'overall_starts_on' => $all[0] ?? null, 'overall_ends_on' => $all ? $all[count($all) - 1] : null,
            'selling_dates' => $dates['selling'], 'setup_dates' => $dates['setup'], 'teardown_dates' => $dates['teardown'], 'travel_dates' => $dates['travel'],
            'known_selling_day_count' => count($dates['selling']), 'selling_day_count' => $complete ? count($dates['selling']) : null,
            'operating_minutes' => $complete && ! $unknownHours ? (int) $minutes : null, 'has_undated_sessions' => $undated,
            'legacy_starts_on' => $legacyStart, 'legacy_ends_on' => $legacyEnd,
            'basis' => $sessions ? 'explicit_sessions' : ($legacyStart || $legacyEnd ? 'legacy_range' : 'unconfirmed'),
            'needs_review' => ! $complete || $unknownHours, 'source_schedule' => $data['source_schedule'] ?? [],
            'hours_basis' => 'Scheduled local availability/show windows, not staff shifts, attendance, paid hours or per diem qualification. Overlapping selling windows count once.',
            'session_evidence' => $calendar['session_evidence'] ?? array_map(fn ($session) => ['session_id' => $session['id'], 'status' => 'unconfirmed'], $sessions)];
        if (is_array($review)) {
            $result['provenance'] = array_intersect_key($review, array_flip(['reviewed_by', 'reviewed_at', 'source_version']));
            $result['provenance']['current_source_version'] = $data['source_version'] ?? null;
            $result['provenance']['source_changed_since_review'] = ($review['source_version'] ?? null) !== ($data['source_version'] ?? null);
            $result['needs_review'] = $result['needs_review'] || $result['provenance']['source_changed_since_review'];
            if ($result['provenance']['source_changed_since_review']) {
                $result['session_evidence'] = array_map(fn ($evidence) => array_replace($evidence, ['stale' => true]), $result['session_evidence']);
            }
        }

        return $result;
    }

    /** Keep legacy/budget occurrence bounds separate from setup/travel bounds. */
    public function occurrenceBounds(array $input, array $calendar): array
    {
        $selling = $calendar['selling_dates'];
        if ($selling) {
            $start = $selling[0];
            $end = $selling[count($selling) - 1];
            foreach (['starts_on' => $start, 'ends_on' => $end] as $field => $date) {
                if (! empty($input[$field])) {
                    abort_unless($calendar['schedule_complete'] ? $input[$field] === $date : ($field === 'starts_on' ? $input[$field] <= $date : $input[$field] >= $date), 422, 'Occurrence bounds must match the explicit selling dates; setup and travel have separate bounds.');
                } elseif ($calendar['schedule_complete']) {
                    $input[$field] = $date;
                }
            }
        }

        return $input;
    }

    public function source(array $task): array
    {
        $ranges = [];
        $pattern = '/(?<!\d)(\d{1,2})[.\/](\d{1,2})[.\/](\d{2}|\d{4})\s*(?:-|–|—|to)\s*(\d{1,2})[.\/](\d{1,2})[.\/](\d{2}|\d{4})(?!\d)/u';
        foreach (['name', 'notes'] as $field) {
            preg_match_all($pattern, $task[$field] ?? '', $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $start = $this->date($match[1], $match[2], $match[3]);
                $end = $this->date($match[4], $match[5], $match[6]);
                if ($start && $end && $end >= $start && CarbonImmutable::parse($start)->diffInDays(CarbonImmutable::parse($end)) <= 366) {
                    $ranges[$start.'|'.$end] = ['starts_on' => $start, 'ends_on' => $end, 'evidence' => ['field' => $field, 'passage' => $match[0]]];
                }
            }
        }
        $range = count($ranges) === 1 ? array_values($ranges)[0] : ['starts_on' => null, 'ends_on' => null];

        return [...$range, 'basis' => count($ranges) === 1 ? 'explicit_source_range' : (count($ranges) > 1 ? 'conflicting_source_ranges' : 'unconfirmed'), 'needs_review' => count($ranges) !== 1,
            'source_schedule' => array_intersect_key($task, array_flip(['start_on', 'due_on', 'start_at', 'due_at'])), 'source_candidates' => array_values($ranges)];
    }

    /** Bounded literal suggestions from source text, never from a task deadline. */
    public function dateEvidence(array $task, string $version, array $comments = []): array
    {
        $range = $this->source($task);
        $candidates = [];
        $reviewItems = [];
        $years = [];
        $text = $task['name'] ?? '';
        preg_match_all('/(?<!\d)(?:\d{1,2}[.\/]\d{1,2}[.\/])(\d{2}|\d{4})(?!\d)|\b(20\d{2})-\d{2}-\d{2}\b/u', $text, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $year = ($match[2] ?? '') ?: $match[1];
            $years[strlen($year) === 2 ? '20'.$year : $year] = true;
        }
        $year = count($years) === 1 ? (int) array_key_first($years) : null;
        if ($range['starts_on'] && substr($range['starts_on'], 0, 4) === substr($range['ends_on'], 0, 4)) {
            $year = (int) substr($range['starts_on'], 0, 4);
        }
        $passages = [['field' => 'name', 'text' => $task['name'] ?? ''], ['field' => 'notes', 'text' => $task['notes'] ?? '']];
        foreach ($comments as $comment) {
            if (($comment['resource_subtype'] ?? '') === 'comment_added') {
                $passages[] = ['field' => 'comment', 'story_gid' => (string) $comment['gid'], 'text' => $comment['text'] ?? ''];
            }
        }
        foreach ($passages as $source) {
            foreach (preg_split('/\R/u', $source['text']) as $line) {
                $line = trim($line);
                if ($line === '' || mb_strlen($line) > 3000) {
                    continue;
                }
                if (preg_match('/\b(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+\d{1,2}(?:st|nd|rd|th)?\s*(?:[-–—]|to\b|through\b|and\b|&|,)\s*(?:(?:January|February|March|April|May|June|July|August|September|October|November|December)\s+)?\d{1,2}(?!\d)/iu', $line)) {
                    $reviewItems[] = ['field' => $source['field'], 'passage' => $line, 'reason' => 'A textual date range or short date list needs manual interpretation of its dates and year. No date was inferred.'];

                    continue;
                }
                $phases = array_keys(array_filter([
                    'setup' => preg_match('/\bset[ -]?up\b/i', $line),
                    'teardown' => preg_match('/\b(?:tear[ -]?down|breakdown|load[ -]?out)\b/i', $line),
                    'travel' => preg_match('/\b(?:travel|driv(?:e|ing))\b/i', $line),
                    'selling' => preg_match('/\b(?:selling|show (?:dates|hours)|open to (?:the )?public)\b/i', $line),
                ]));
                $kind = count($phases) === 1 ? $phases[0] : null;
                // Do not attach every date/weekday on a mixed or qualified line to
                // its first phase. Such prose remains a staff review suggestion.
                $ambiguous = count($phases) > 1 || preg_match('/;|\b(?:not|no|only|closed|except|excluding|excludes|without|cancelled|canceled|rescheduled|previous|formerly|instead)\b|changed\s+from/i', $line);
                if ($ambiguous) {
                    $kind = null;
                }
                $lineRange = $this->source(['name' => $line]);
                $dates = [];
                preg_match_all('/(?<!\d)(\d{1,2})[.\/](\d{1,2})[.\/](\d{2}|\d{4})(?!\d)/u', $line, $literalDates, PREG_SET_ORDER);
                foreach ($literalDates as $literal) {
                    if ($date = $this->date($literal[1], $literal[2], $literal[3])) {
                        $dates[] = $date;
                    }
                }
                preg_match_all('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $line, $isoDates, PREG_SET_ORDER);
                foreach ($isoDates as $literal) {
                    if ($date = $this->date($literal[2], $literal[3], $literal[1])) {
                        $dates[] = $date;
                    }
                }
                preg_match_all('/\b(January|February|March|April|May|June|July|August|September|October|November|December)\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(20\d{2}))?\b/i', $line, $wordDates, PREG_SET_ORDER);
                foreach ($wordDates as $literal) {
                    $month = (int) CarbonImmutable::parse($literal[1].' 1 2000')->format('n');
                    $literalYear = ($literal[3] ?? '') ?: $year;
                    if ($literalYear && ($date = $this->date((string) $month, $literal[2], (string) $literalYear))) {
                        if (preg_match('/\b(Monday|Tuesday|Wednesday|Thursday|Friday|Saturday|Sunday)\b/i', $line, $weekday) && strcasecmp(CarbonImmutable::parse($date)->format('l'), $weekday[1]) !== 0) {
                            continue;
                        }
                        $dates[] = $date;
                    }
                }
                // A named weekday list within an explicit range supports those dates,
                // unlike a generic range, which supports only its boundaries.
                if ($kind === 'selling' && $range['starts_on'] && $range['ends_on'] && CarbonImmutable::parse($range['starts_on'])->diffInDays(CarbonImmutable::parse($range['ends_on'])) < 7 && preg_match_all('/\b(Mon(?:day)?|Tue(?:sday)?|Wed(?:nesday)?|Thu(?:rsday)?|Fri(?:day)?|Sat(?:urday)?|Sun(?:day)?)\b/i', $line, $weekdays)) {
                    $days = array_map(fn ($day) => strtolower(substr($day, 0, 3)), $weekdays[1]);
                    for ($date = CarbonImmutable::parse($range['starts_on']); $date->toDateString() <= $range['ends_on']; $date = $date->addDay()) {
                        if (in_array(strtolower($date->format('D')), $days, true)) {
                            $dates[] = $date->toDateString();
                        }
                    }
                }
                if (! $dates) {
                    continue;
                }
                if ($lineRange['starts_on'] && ! $ambiguous) {
                    $kind = 'range';
                }
                $dates = array_values(array_unique($dates));
                sort($dates);
                $candidate = ['task_gid' => (string) ($task['gid'] ?? ''), 'source_version' => $version, 'source_modified_at' => $task['modified_at'] ?? null,
                    'field' => $source['field'], 'section' => $kind ?? 'unclassified', 'passage' => $line, 'dates' => $dates, 'kind' => $kind,
                    'source_story_id' => $source['story_gid'] ?? null, 'year_context' => $year, 'year_evidence' => $range['evidence'] ?? ['field' => 'name', 'passage' => $task['name'] ?? ''], 'range_context' => $kind === 'selling' ? $range['source_candidates'] : [],
                    'verification_blockers' => $ambiguous ? ['Mixed phases, exclusions or qualified date clauses require staff interpretation.'] : []];
                $candidate['id'] = hash('sha256', json_encode($candidate, JSON_THROW_ON_ERROR));
                $candidates[] = $candidate;
            }
        }

        return ['candidates' => $candidates, 'review_items' => $reviewItems, 'issues' => $range['basis'] === 'conflicting_source_ranges' ? ['conflicting_source_ranges'] : [],
            'coverage' => ['notes_read' => true, 'comments_read' => count($comments), 'comments_complete' => false, 'complete_calendar' => false, 'notice' => 'Literal source passages support individual dates. Generic ranges verify boundaries only; task due dates, attachments and linked documents do not establish dated sessions.']];
    }

    private function date(string $month, string $day, string $year): ?string
    {
        $year = strlen($year) === 2 ? 2000 + (int) $year : (int) $year;
        if (! checkdate((int) $month, (int) $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
