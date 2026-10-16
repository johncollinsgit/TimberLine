<?php

namespace App\Services\Soccer;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class SoccerFamilyHelpService
{
    public function __construct(private SoccerStore $store, private SoccerEvents $events) {}

    public function history(int $tenant, string $familyId): array
    {
        return $this->store->query('events', $tenant)
            ->where('family_id', $familyId)
            ->where('action', 'family_help_exchange')
            ->orderByDesc('event_key')
            ->limit(50)
            ->get()
            ->reverse()
            ->values()
            ->map(function ($event) {
                $entry = $this->store->decrypt($event->data);

                return ['question' => $entry['question'] ?? '', 'answer' => $entry['answer'] ?? '', 'needs_review' => (bool) ($entry['needs_review'] ?? false), 'action' => $entry['suggested_action'] ?? null];
            })->all();
    }

    public function ask(int $tenant, object $family, string $message): array
    {
        $key = "soccer-help:{$tenant}:{$family->id}";
        abort_if(RateLimiter::tooManyAttempts($key, 20), 429, 'Please wait before sending more questions.');
        RateLimiter::hit($key, 3600);

        $message = trim($message);
        $prior = $this->history($tenant, $family->id);
        $season = $this->store->query('seasons', $tenant)->where('season_key', 'spring-2027')->first();
        $known = $this->knownAnswer($tenant, $family, $season, $message);
        $result = $known ?? $this->modelAnswer($tenant, $season, $message, $prior);
        $answer = $result['answer'];
        $needsReview = (bool) $result['needs_review'];
        $action = $result['action'] ?? null;

        $this->events->record($tenant, $season?->id, $family->id, 'family_help_exchange', [
            'question' => $message, 'answer' => $answer, 'needs_review' => $needsReview, 'suggested_action' => $action,
        ], key: (string) Str::ulid());
        if ($needsReview) {
            $transcript = [...array_slice($prior, -10), ['question' => $message, 'answer' => $answer, 'needs_review' => true]];
            $this->events->record($tenant, $season?->id, $family->id, 'family_help_unanswered', [
                'summary' => $message, 'transcript' => $transcript,
            ], key: (string) Str::ulid());
        }

        return ['answer' => $answer, 'needs_review' => $needsReview, 'action' => $action, 'messages' => $this->history($tenant, $family->id)];
    }

    public function correctBirthday(int $tenant, object $family, string $playerId, string $birthday): void
    {
        DB::transaction(function () use ($tenant, $family, $playerId, $birthday) {
            $player = $this->store->query('players', $tenant)->where('id', $playerId)->lockForUpdate()->firstOrFail();
            abort_unless($player->family_id === $family->id, 403);
            abort_if($player->birthday === $birthday, 422, 'That is already the saved birthday.');
            abort_if($this->store->query('registrations', $tenant)->where('player_id', $playerId)->whereIn('status', ['registered', 'offered', 'waitlisted'])->exists(), 409, 'This player already has a season registration. Ask Wren to review the birthday and team assignment together.');

            $this->store->query('players', $tenant)->where('id', $playerId)->update(['birthday' => $birthday, 'updated_at' => now()]);
            $this->events->record($tenant, null, $family->id, 'player_birthdate_corrected', [
                'summary' => 'Family corrected '.$player->name.' birth date after confirmation.',
                'player_id' => $playerId,
                'old_date' => $player->birthday,
                'new_date' => $birthday,
                'source' => 'signed_in_family_help',
            ]);
            $this->events->record($tenant, null, $family->id, 'family_help_exchange', [
                'question' => 'Correct '.$player->name.' birthday',
                'answer' => 'Saved '.$player->name.' birthday as '.CarbonImmutable::parse($birthday)->format('F j, Y').'. Previously signed releases remain as submitted; review and sign a new release for this season.',
                'needs_review' => false,
            ], key: (string) Str::ulid());
        }, 3);
    }

    private function knownAnswer(int $tenant, object $family, ?object $season, string $message): ?array
    {
        $question = mb_strtolower($message);
        if (preg_match('/\b(birth\s*date|birthday|dob|age)\b/u', $question) && preg_match('/\b(wrong|incorrect|change|update|correct|fix)\b/u', $question)) {
            return ['answer' => 'I can help correct a birthday. Choose the child below, enter the correct full date, and review it before saving. A signed medical release stays as originally submitted; you will review and sign a new release for this season.', 'needs_review' => false, 'action' => 'correct_birthday'];
        }
        if (preg_match('/\b(coach|coaching|volunteer)\b/u', $question)) {
            return ['answer' => 'Mom or Dad can volunteer as an assistant coach. Choose every age group you could coach and confirm that you can be available for at least 8 of 10 weeks. Assistant coaches may receive up to two free child registrations, including jerseys.', 'needs_review' => false];
        }
        if (preg_match('/\b(team|age group|how old|age cutoff)\b/u', $question) && $season) {
            $cutoff = $season->age_as_of ?? $season->starts_on;
            $teams = json_decode($season->teams, true) ?: config('soccer.teams');
            $lines = [];
            if ($cutoff) {
                foreach ($this->store->query('players', $tenant)->where('family_id', $family->id)->orderBy('name')->get() as $player) {
                    if (! $player->birthday) {
                        continue;
                    }
                    $age = (int) CarbonImmutable::parse($player->birthday)->diffInYears(CarbonImmutable::parse($cutoff));
                    $team = collect($teams)->first(fn ($row) => $age >= $row['min_age'] && ($row['max_age'] === null || $age <= $row['max_age']));
                    $lines[] = $player->name.': age '.$age.' — expected team '.($team['name'] ?? 'not available for this age').'.';
                }
            }
            $answer = 'Teams use each child’s age on '.($cutoff ? CarbonImmutable::parse($cutoff)->format('F j, Y') : 'the season cutoff date').'. Green Bears are ages 3–4, Blue Dragons 5–7, and Red Falcons 8+.'.($lines ? ' Your family: '.implode(' ', $lines) : ' Add a child’s birthday to see their expected team.');

            return ['answer' => $answer, 'needs_review' => false];
        }
        if (preg_match('/\b(price|cost|fee|jersey|pay|payment)\b|how much/u', $question)) {
            return ['answer' => 'Registration is $30 for a player reusing a jersey or $50 with a new jersey. Assistant coaches may use up to two free child registrations, including jerseys. Families pay for available places at submission; waitlisted children are not charged until a spot is offered.', 'needs_review' => false];
        }
        if (preg_match('/\b(waitlist|waitlisted|spot|offer)\b/u', $question)) {
            return ['answer' => 'If a child is waitlisted, there is no charge for that child yet. When a place opens, Wren sends a payment link by text during 8 a.m.–8 p.m. and the family can complete payment for the offered spot.', 'needs_review' => false];
        }
        if (preg_match('/\b(medical|release|allerg|health)\b/u', $question)) {
            return ['answer' => 'Each selected player needs a reviewed and signed medical release for Spring 2027. Previous answers can help prefill the form, but please check them before signing. Already signed releases stay as originally submitted.', 'needs_review' => false];
        }
        if (preg_match('/\b(where|location|field|church)\b/u', $question)) {
            return ['answer' => 'Wren Family Soccer meets at Community Bible Church in Easley, South Carolina. Your family page will show the confirmed season schedule.', 'needs_review' => false];
        }
        if (preg_match('/\b(schedule|when|practice|start date|registration open)\b/u', $question)) {
            $date = $season?->starts_on ? CarbonImmutable::parse($season->starts_on)->format('F j, Y') : 'not yet confirmed';
            $status = $season?->registration_open ? 'Registration is currently open.' : 'Registration is currently closed.';

            return ['answer' => $status.' The first practice date shown in Wren’s system is '.$date.'. Check the schedule on your family page for updates.', 'needs_review' => false];
        }

        return null;
    }

    private function modelAnswer(int $tenant, ?object $season, string $message, array $prior): array
    {
        $settings = $this->store->settings($tenant);
        $key = trim((string) ($settings['help_ai_key'] ?? config('services.openai.api_key')));
        if (! ($settings['help_ai_enabled'] ?? config('soccer.help_ai_enabled')) || $key === '') {
            return $this->unanswered();
        }

        $cutoff = $season?->age_as_of ?? $season?->starts_on;
        $facts = 'Wren Family Soccer Spring 2027 at Community Bible Church in Easley, SC. Green Bears ages 3-4; Blue Dragons ages 5-7; Red Falcons ages 8+. Age cutoff: '.($cutoff ?: 'not confirmed').'. Registration currently '.($season?->registration_open ? 'open' : 'closed').'. $30 reusing a jersey, $50 with a new jersey. Assistant coaches may receive up to two free child registrations and must be available for at least 8 of 10 weeks. Waitlisted children are not charged until offered a spot. Text payment links go out 8 a.m.-8 p.m. Families review and sign a new medical release each season. GroupMe remains the soccer group.';
        $context = collect(array_slice($prior, -4))->map(fn ($row) => ['question' => $row['question'], 'answer' => $row['answer']])->all();

        try {
            $response = Http::withToken($key)->acceptJson()->timeout(15)->post('https://api.openai.com/v1/responses', [
                'model' => config('soccer.help_ai_model'),
                'reasoning' => ['effort' => 'none'],
                'store' => false,
                'max_output_tokens' => 300,
                'instructions' => 'You are the Wren Family Soccer registration help assistant. Answer only from these verified facts: '.$facts.' If the answer requires private account information, policy not stated here, or any fact you cannot verify, set can_answer to false and leave answer empty. Do not invent dates, policy, or promises. Never claim to update a record; the website handles record changes separately. Keep answers short and friendly.',
                'input' => json_encode(['recent_conversation' => $context, 'question' => $message], JSON_THROW_ON_ERROR),
                'text' => ['format' => ['type' => 'json_schema', 'name' => 'wren_help_answer', 'strict' => true, 'schema' => [
                    'type' => 'object', 'properties' => ['can_answer' => ['type' => 'boolean'], 'answer' => ['type' => 'string']], 'required' => ['can_answer', 'answer'], 'additionalProperties' => false,
                ]]],
            ]);
            if (! $response->successful() || $response->json('status') !== 'completed') {
                return $this->unanswered();
            }
            $output = collect($response->json('output', []))->first(fn ($item) => ($item['type'] ?? '') === 'message');
            $text = collect($output['content'] ?? [])->first(fn ($item) => ($item['type'] ?? '') === 'output_text')['text'] ?? null;
            $result = is_string($text) ? json_decode($text, true) : null;
            if (! is_array($result) || ! ($result['can_answer'] ?? false) || ! is_string($result['answer'] ?? null) || trim($result['answer']) === '') {
                return $this->unanswered();
            }

            return ['answer' => mb_substr(trim($result['answer']), 0, 1200), 'needs_review' => false];
        } catch (\Throwable) {
            return $this->unanswered();
        }
    }

    private function unanswered(): array
    {
        return ['answer' => 'I’m not sure of the answer, so I’ve saved your question and the conversation for the Wren team to review in Everbranch.', 'needs_review' => true];
    }
}
