<?php

namespace App\Services\FieldService;

use App\Models\FieldServiceTimeSession;
use App\Models\Tenant;
use App\Models\User;

class FieldCrewClockStatus
{
    public function capture(Tenant $tenant, User $user): array
    {
        app(FieldCrewAssistant::class)->authorize($tenant, $user);
        $crew = app(FieldServiceAccessService::class)->canManageJobs($user, $tenant);
        $members = $tenant->users()->where('users.is_active', true)->wherePivot('membership_active', true)
            ->when(! $crew, fn ($q) => $q->where('users.id', $user->id))->orderBy('users.name')->get(['users.id', 'users.name']);
        $sessions = FieldServiceTimeSession::where('tenant_id', $tenant->id)->whereIn('user_id', $members->pluck('id'))
            ->whereIn('status', ['running', 'paused'])->whereNull('clocked_out_at')->latest('clocked_in_at')->get()->unique('user_id')->keyBy('user_id');

        return ['scope' => $crew ? 'crew' : 'self', 'captured_at' => now()->toIso8601String(),
            'people' => $members->map(fn ($member) => ['name' => $member->name, 'status' => $sessions->get($member->id)?->status ?? 'clocked_out'])->all()];
    }

    public function answer(Tenant $tenant, User $user, string $question): ?array
    {
        if (! preg_match('/\b(?:(?:who|anyone|everybody|everyone|am i|is .+?)\b.*(?:clocked in|clocked out|on break)|clock status|crew clock)\b/iu', $question)) {
            return null;
        }
        $snapshot = $this->capture($tenant, $user);
        $people = collect($snapshot['people']);
        if ($snapshot['scope'] === 'self') {
            $status = $people->first()['status'] ?? 'clocked_out';
            $text = match ($status) {
                'running' => 'You are clocked in.', 'paused' => 'You are on break.', default => 'You are clocked out.'
            };
            if (! preg_match('/\bam i\b/iu', $question)) {
                $text .= ' Crew clock status is available to admins and managers.';
            }
        } else {
            $working = $people->where('status', 'running')->pluck('name');
            $breaks = $people->where('status', 'paused')->pluck('name');
            $out = $people->where('status', 'clocked_out')->pluck('name');
            $text = ($working->isEmpty() ? 'No one is clocked in and working right now.' : 'Clocked in: '.$working->implode(', ').'.')
                .($breaks->isEmpty() ? '' : ' On break: '.$breaks->implode(', ').'.');
            if (preg_match('/clocked out/iu', $question)) {
                $text .= $out->isEmpty() ? ' No one is clocked out.' : ' Clocked out: '.$out->implode(', ').'.';
            }
        }

        return ['text' => $text, 'sources' => [], 'navigation' => null, 'captured_at' => $snapshot['captured_at']];
    }
}
