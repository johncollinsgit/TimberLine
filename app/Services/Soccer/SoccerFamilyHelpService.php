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
        $result = $known ?? $this->modelAnswer($tenant, $season, $message);
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
            abort_if($this->store->query('registrations', $tenant)->where('player_id', $playerId)->whereIn('status', ['registered', 'offered', 'waitlisted', 'payment_pending'])->exists(), 409, 'This player already has a season registration. Ask Wren to review the birthday and team assignment together.');

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
        $question = str_replace(['’', '‘'], "'", $question);
        if (preg_match('/\b(human|real person|talk to (?:a |some)?person|speak to (?:someone|somebody|a person)|who can i contact|contact (?:sarah|someone)|(?:text|call|phone) (?:me|sarah)|sarah\'?s (?:number|phone)|question for sarah|need help with registration)\b/u', $question)) {
            return ['answer' => 'Absolutely! Text or call Sarah Collins at 678-644-1829. Texting is usually the fastest way to reach her; she will respond as soon as she can.', 'needs_review' => false];
        }
        if (preg_match('/\b(birth\s*date|birthday|dob|age)\b/u', $question) && preg_match('/\b(wrong|incorrect|change|update|correct|fix)\b/u', $question)) {
            return ['answer' => 'I can help correct a birthday. Choose the child below, enter the correct full date, and review it before saving. A signed medical release stays as originally submitted; you will review and sign a new release for this season.', 'needs_review' => false, 'action' => 'correct_birthday'];
        }
        if (preg_match('/\b(2\s*yo|two[- ]year[- ]old|2[- ]year[- ]old|toddler|almost (?:3|three)|minimum age|younger than (?:3|three)|under (?:3|three))\b|turns?\s+(?:3|three)\s+(?:after|in)\b/u', $question)) {
            return ['answer' => 'We would love to welcome your little one! Children must be at least 3 by '.$this->cutoffLabel($season).' to play in Spring 2027; there are no exceptions to the minimum age. If your child turns 3 after that date, we would love to welcome them in a future season once eligible. You can still save their profile in your family account now, without enrolling or paying for Spring.', 'needs_review' => false];
        }
        if (preg_match('/\b(move|moving|play|playing) up\b|\b(older (?:age group|kids|team)|advanced|siblings?.{0,25}same team)\b/u', $question)) {
            return ['answer' => 'Children initially register in their assigned age groups. Please connect with John or Sarah Collins on opening night. After the first practice, they can discuss your child’s readiness and whether moving up would be a good fit. A change is not guaranteed, and the minimum age still applies.', 'needs_review' => false];
        }
        if (preg_match('/\b(?:registration|sign[- ]?ups?)\b.{0,35}\b(?:open|closed|begin|start|yet)\b|\b(?:when|can i)\b.{0,35}\b(?:register|sign up)\b|\bregister yet\b/u', $question)) {
            return ['answer' => $this->registrationStatus($season).' Register through the Wren Family Soccer website. Text Sarah Collins at 678-644-1829 if you need help.', 'needs_review' => false];
        }
        if (preg_match('/\b(address|location|fields?|church)\b|\bwhere\b.{0,30}\b(meet|practice|soccer|find)\b|\bhow do i find you\b/u', $question)) {
            return ['answer' => 'We meet at Community Bible Church, 8001 Highway 81, Easley, SC 29642. All three age groups meet at the same location on Monday evenings, 5:30–6:30 PM.', 'needs_review' => false];
        }
        if (preg_match('/\b(schedule|practice|opening night|season dates|weekends?)\b|\b(?:season|soccer)\b.{0,25}\b(?:start|begin|end|run)\b|\bfirst practice\b/u', $question)) {
            return ['answer' => $this->seasonSchedule($season), 'needs_review' => false];
        }
        if (preg_match('/\b(coach|coaching|volunteer)\b/u', $question)) {
            return ['answer' => 'Mom or Dad can volunteer as an assistant coach. Choose every age group you could coach and confirm that you can be available for at least 8 of 10 weeks. Assistant coaches may receive up to two free child registrations, including jerseys.', 'needs_review' => false];
        }
        if (preg_match('/\b(team|age|old|eligible|eligibility|alumni|returning|older)\b|add.{0,30}(child|player)|\b(?:child|player) (?:is|turns) (?:11|12)\b|\bcan.{0,20}(?:11|12)[- ]year|\b(?:birth\s*date|birthday)\b.{0,25}(?:accepted|rejected)|\bregistration (?:form|system)\b.{0,25}(?:work|let|accept)/u', $question) && $season) {
            $cutoff = $season->age_as_of ?? $season->starts_on;
            $teams = json_decode($season->teams, true) ?: config('soccer.teams');
            $lines = [];
            if ($cutoff) {
                foreach ($this->store->query('players', $tenant)->where('family_id', $family->id)->orderBy('name')->get() as $player) {
                    if (! $player->birthday) {
                        continue;
                    }
                    $age = (int) CarbonImmutable::parse($player->birthday)->diffInYears(CarbonImmutable::parse($cutoff));
                    $eligibility = app(SoccerEligibilityService::class)->assess($tenant, $player, $season);
                    $team = collect($teams)->first(fn ($row) => $age >= $row['min_age'] && ($row['max_age'] === null || $age <= $row['max_age']));
                    $lines[] = $player->name.': age '.$age.' — '.($eligibility['eligible'] ? 'expected team '.($team['name'] ?? 'pending') : $eligibility['reason']).'.';
                }
            }
            $answer = 'Eligibility uses each child’s age on '.$this->cutoffLabel($season).'. New players must be ages 3–10; returning players with prior participation on file may play through age 12, subject to available places. Green Bears are ages 3–4, Blue Dragons 5–7, and Red Falcons for eligible players age 8 and up. Children under 3 cannot enroll in Spring, but younger children can still be saved in your family account for a future season without registering or paying now. If a returning child’s history needs review, or the form is not working for an eligible child, text Sarah Collins at 678-644-1829.'.($lines ? ' Your family: '.implode(' ', $lines) : ' Add a child’s birthday to see their expected team.');

            return ['answer' => $answer, 'needs_review' => false];
        }
        if (preg_match('/\b(price|cost|fee|jersey|pay|payment)\b|how much/u', $question)) {
            return ['answer' => 'Registration is $30 for a player reusing a jersey or $50 with a new jersey. Assistant coaches may use up to two free child registrations, including jerseys. Families pay for available places at submission; waitlisted children are not charged until a spot is offered.', 'needs_review' => false];
        }
        if (preg_match('/\b(waitlist|waitlisted|spot|offer)\b/u', $question)) {
            return ['answer' => 'If a child is waitlisted, there is no charge for that child yet. When a place opens, Wren sends an offer through the configured notification channel. For an expired unpaid reservation, sign in and use Resume application / Complete payment; saved information remains available and capacity is checked again.', 'needs_review' => false];
        }
        if (preg_match('/\b(medical|release|allerg|health)\b/u', $question)) {
            return ['answer' => 'Each selected player needs a reviewed and signed medical release for Spring 2027. Previous answers can help prefill the form, but please check them before signing. Already signed releases stay as originally submitted.', 'needs_review' => false];
        }

        return null;
    }

    private function cutoffLabel(?object $season): string
    {
        $cutoff = $season?->age_as_of ?? $season?->starts_on;

        return $cutoff ? CarbonImmutable::parse($cutoff)->format('F j, Y') : 'March 15, 2027';
    }

    private function registrationStatus(?object $season): string
    {
        if ($season?->registration_open && $season?->starts_on) {
            return 'Spring 2027 registration is currently open.';
        }
        $status = 'Spring 2027 registration is currently closed.';
        if ($season?->registration_opens_at && CarbonImmutable::parse($season->registration_opens_at, 'UTC')->isFuture()) {
            $status .= ' The scheduled opening is '.CarbonImmutable::parse($season->registration_opens_at, 'UTC')->timezone('America/New_York')->format('F j, Y, g:i A T').'.';
        } else {
            $status .= ' Please check the website for updates.';
        }

        return $status;
    }

    private function seasonSchedule(?object $season): string
    {
        $start = $season?->starts_on ? CarbonImmutable::parse($season->starts_on)->format('l, F j, Y') : 'Monday, March 15, 2027';

        return 'Spring 2027 begins '.$start.' and runs through Monday, May 17, 2027. We meet every Monday evening, 5:30–6:30 PM, at Community Bible Church in Easley. There are no weekend games or practices.';
    }

    private function modelAnswer(int $tenant, ?object $season, string $message): array
    {
        $settings = $this->store->settings($tenant);
        $key = trim((string) ($settings['help_ai_key'] ?? config('services.openai.api_key')));
        if (! ($settings['help_ai_enabled'] ?? config('soccer.help_ai_enabled')) || $key === '') {
            return $this->unanswered();
        }

        $facts = 'Wren Family Soccer is a Christian, family-centered recreational soccer program. Location: Community Bible Church, 8001 Highway 81, Easley, SC 29642. '.$this->seasonSchedule($season).' '.$this->registrationStatus($season).' Season dates are not registration opening dates. Green Bears ages 3-4; Blue Dragons ages 5-7; Red Falcons for eligible players 8 and up. New players must be ages 3-10; returning players with prior participation on file may play through age 12, subject to capacity. Age cutoff: '.$this->cutoffLabel($season).'. Children under 3 can be saved as family profiles for a future season but cannot enroll or be charged for Spring. Never suggest exceptions to the minimum age, and do not promise fall eligibility without a confirmed fall cutoff. Children initially register in assigned age groups; John or Sarah can discuss readiness for moving up after the first practice. Changes are not guaranteed. For a human, immediately provide Sarah Collins at 678-644-1829, text or call; texting is usually fastest. $30 reusing a jersey, $50 with a new jersey. Assistant coaches may receive up to two free child registrations and must be available for at least 8 of 10 weeks. Waitlisted children are not charged until offered a spot. The family portal uses card payments with automatic confirmation. Expired unpaid reservations become payment pending and can be resumed from the saved family application. Reminders stop after payment or withdrawal. Families review and sign a new medical release each season. GroupMe remains the soccer group.';
        try {
            $response = Http::withToken($key)->acceptJson()->timeout(15)->post('https://api.openai.com/v1/responses', [
                'model' => config('soccer.help_ai_model'),
                'reasoning' => ['effort' => 'none'],
                'store' => false,
                'max_output_tokens' => 300,
                'instructions' => 'You are the Wren Family Soccer registration help assistant. Answer only from these verified facts: '.$facts.' If the answer requires private account information, policy not stated here, or any fact you cannot verify, set can_answer to false and leave answer empty. Do not invent dates, policy, or promises. Answer confirmed facts directly; only escalate individual decisions or information you cannot verify. When uncertain, direct the parent to Sarah Collins at 678-644-1829; never promise an exception. Never claim to update a record or save a question for review; the website handles record changes and review storage separately. Keep answers short and friendly.',
                'input' => json_encode(['question' => $message], JSON_THROW_ON_ERROR),
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
            if (preg_match('/\b(?:i|we)(?:[\x{2019}\']ve| have)?\s+(?:saved|recorded|forwarded|sent|submitted|updated|changed)\b|\b(?:question|conversation|request)\b.{0,35}\b(?:saved|recorded|forwarded|sent|submitted)\b/ui', $result['answer'])) {
                return $this->unanswered();
            }

            return ['answer' => mb_substr(trim($result['answer']), 0, 1200), 'needs_review' => false];
        } catch (\Throwable) {
            return $this->unanswered();
        }
    }

    private function unanswered(): array
    {
        return ['answer' => 'I’m not sure of that policy. Please text or call Sarah Collins at 678-644-1829; she can review it with you. I cannot promise an exception.', 'needs_review' => true];
    }
}
