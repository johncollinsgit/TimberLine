<?php

namespace App\Services\FieldService;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantModuleAccessResolver;

/** Read canonical recorded totals; neither model arithmetic nor payroll writes. */
class FieldCrewHours
{
    public function answer(Tenant $tenant, User $user, string $question, array $history): ?array
    {
        $intent = fn (string $text): bool => (bool) preg_match('/\b(?:how many hours|how much time|total hours|hours (?:did|worked|for))\b/iu', $text);
        if (! $intent($question)) {
            $previous = collect($history)->where('role', 'user')->last()['text'] ?? '';
            if (! $intent($previous) || ! preg_match('/^(?:today|yesterday|this week|last week|this month|last month|this pay period)[?.!\s]*$/iu', trim($question))) {
                return null;
            }
            $question = $previous.' '.$question;
        }
        app(FieldCrewAssistant::class)->authorize($tenant, $user);
        abort_unless((bool) data_get(app(TenantModuleAccessResolver::class)->resolveForTenant($tenant->id, ['time_tracking']), 'modules.time_tracking.enabled', false), 403);
        $manager = app(FieldServiceAccessService::class)->canManageJobs($user, $tenant);
        $text = mb_strtolower($question);
        $self = (bool) preg_match('/\b(?:i|me|mine)\b|\bmy (?:hours|time)\b/u', $text);
        $all = (bool) preg_match('/\b(?:all|everyone|everybody|whole crew|the crew|the team|company)\b/u', $text);
        if (! $manager && (! $self || $all)) {
            return $this->reply('I can show your own hours. Crew and other employees’ hours are available to admins and managers.');
        }
        $employee = null;
        $label = 'The whole crew';
        if (! $all) {
            if (! $manager) {
                $employee = $user;
            } else {
                $members = $tenant->users()->orderBy('users.name')->get(['users.id', 'users.name']);
                $matchesName = fn (string $name): bool => (bool) preg_match('/(?<![\pL\pN])'.preg_quote(mb_strtolower($name), '/').'(?![\pL\pN])/u', $text);
                $exact = $members->filter(fn ($member) => $matchesName(trim($member->name)));
                $matches = $exact->isNotEmpty() ? $exact : $members->filter(function ($member) use ($matchesName): bool {
                    $parts = preg_split('/\s+/u', trim($member->name)) ?: [];

                    return collect($parts)->contains(fn ($part) => mb_strlen($part) > 1 && $matchesName($part))
                        || (mb_strtolower($parts[0] ?? '') === 'nathan' && $matchesName('nate'));
                });
                if ($matches->isEmpty() && $self) {
                    $employee = $user;
                } elseif ($matches->count() !== 1) {
                    return $this->reply($matches->count() > 1 ? 'Which person? Please use their full name and the time period.' : 'Whose hours—yours, the whole crew, or a person’s name? Include the time period.');
                } else {
                    $employee = $matches->first();
                }
            }
            $label = (int) $employee->id === (int) $user->id ? 'You' : $employee->name;
        }
        $periods = [
            'today' => ['range' => 'day', 'offset' => 0], 'yesterday' => ['range' => 'day', 'offset' => 1],
            'this week' => ['range' => 'week', 'offset' => 0], 'last week' => ['range' => 'week', 'offset' => 1],
            'this month' => ['range' => 'month', 'offset' => 0], 'last month' => ['range' => 'month', 'offset' => 1],
            'this pay period' => ['range' => 'pay_period', 'offset' => 0],
        ];
        $ranges = [];
        foreach ($periods as $phrase => $filters) {
            if (preg_match('/\b'.preg_quote($phrase, '/').'\b/u', $text)) {
                $ranges[] = $filters;
            }
        }
        if (preg_match('/\b(?:from\s+)?(\d{4}-\d{2}-\d{2})\s+(?:to|through)\s+(\d{4}-\d{2}-\d{2})\b/u', $text, $dates)) {
            $ranges[] = ['range' => 'custom', 'start_date' => $dates[1], 'end_date' => $dates[2]];
        } elseif (preg_match('/\bon\s+(\d{4}-\d{2}-\d{2})\b/u', $text, $date)) {
            $ranges[] = ['range' => 'custom', 'start_date' => $date[1], 'end_date' => $date[1]];
        }
        if (count($ranges) !== 1) {
            return $this->reply('Which period: today, yesterday, this week, last week, this month, or last month? You can also give dates as YYYY-MM-DD through YYYY-MM-DD.');
        }
        if ($ranges[0]['range'] === 'custom') {
            foreach (['start_date', 'end_date'] as $key) {
                $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $ranges[0][$key]);
                if (! $date || $date->format('Y-m-d') !== $ranges[0][$key]) {
                    return $this->reply('Please give valid start and end dates as YYYY-MM-DD through YYYY-MM-DD.');
                }
            }
        }
        $filters = [...$ranges[0], 'self_only' => ! $manager, ...($employee ? ['employee_id' => (int) $employee->id] : [])];
        $report = app(FieldServiceTimeHoursService::class)->analytics($tenant, $filters);
        // Recheck access after the read; never publish a stale broader scope.
        app(FieldCrewAssistant::class)->authorize($tenant, $user);
        abort_unless($manager === app(FieldServiceAccessService::class)->canManageJobs($user, $tenant), 403);
        $minutes = intdiv((int) $report['summary']['total_seconds'], 60);
        $hours = intdiv($minutes, 60);
        $duration = $hours.' hour'.($hours === 1 ? '' : 's').($minutes % 60 ? ' '.($minutes % 60).' minutes' : '');
        $range = $report['range'];
        $text = $label.((int) ($employee?->id ?? 0) === (int) $user->id ? ' have ' : ' has ').$duration.' recorded for '.$range['start_date'].' through '.$range['end_date'].' ('.$range['timezone'].').'
            .' Includes submitted and approved completed time.';
        if ($report['summary']['active_count'] > 0) {
            $text .= ' Active timers are not included.';
        }

        return $this->reply($text);
    }

    private function reply(string $text): array
    {
        return ['text' => $text, 'sources' => [], 'navigation' => null, 'captured_at' => now()->toIso8601String()];
    }
}
