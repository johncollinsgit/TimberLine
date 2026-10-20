<?php

namespace App\Services\Team\Operations;

use App\Models\TeamArea;
use App\Models\TeamOperationAttempt;
use App\Models\TeamOperationRecord;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/** Deterministic internal reminders. External delivery is a separate reviewed outbox. */
class OperationsReminders
{
    public function triggers(string $profile): array
    {
        return $profile === 'events' ? ['morning_digest', 'day_before', 'missing_information', 'assignment_changed', 'stage_changed'] : ['morning_digest', 'followup_due', 'reorder_check', 'missing_information', 'assignment_changed', 'stage_changed'];
    }

    public function validate(TeamArea $area, array $data): void
    {
        validator($data, ['trigger' => 'required|in:'.implode(',', $this->triggers($area->profile)), 'channel' => 'sometimes|in:inbox', 'timezone' => 'sometimes|timezone', 'local_time' => 'sometimes|date_format:H:i', 'quiet_start' => 'sometimes|date_format:H:i', 'quiet_end' => 'sometimes|date_format:H:i', 'days_after' => 'nullable|integer|min:1|max:730', 'stage' => 'nullable|string|max:32', 'audience_ids' => 'sometimes|array|max:100', 'audience_ids.*' => 'integer|distinct'])->validate();
        if ($data['enabled'] ?? false) {
            abort_unless(count($data['audience_ids'] ?? []) > 0, 422, 'Choose at least one reminder recipient.');
        }
        if (($data['trigger'] ?? '') === 'stage_changed') {
            abort_unless(in_array($data['stage'] ?? '', $area->settings['stages'] ?? [], true), 422, 'Choose a current area stage.');
        }
        abort_if(($data['quiet_start'] ?? '22:00') === ($data['quiet_end'] ?? '07:00'), 422, 'Quiet hours must have distinct start and end times.');
        abort_if($this->quiet($data['local_time'] ?? '08:00', $data), 422, 'Choose a reminder time outside quiet hours.');
        if (! empty($data['template_id'])) {
            abort_unless(TeamOperationRecord::where(['tenant_id' => $area->tenant_id, 'area_id' => $area->id, 'kind' => 'email_template', 'status' => 'published'])->whereKey($data['template_id'])->exists(), 422, 'Choose a published template from this area.');
        }
    }

    private function quiet(string $time, array $data): bool
    {
        $start = $data['quiet_start'] ?? '22:00';
        $end = $data['quiet_end'] ?? '07:00';

        return $start < $end ? $time >= $start && $time < $end : $time >= $start || $time < $end;
    }

    private function matching(Tenant $tenant, User $recipient, TeamArea $area, array $data, CarbonImmutable $local): array
    {
        $access = app(OperationsAccess::class);
        $access->area($tenant, $recipient, $area->id);
        $items = [];
        $records = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $area->id])->whereIn('kind', ['event', 'followup', 'account', 'prospect', 'opportunity'])->whereNotIn('status', ['cancelled', 'withdrawn', 'completed', 'lost'])->orderBy('title');
        foreach ($records->cursor() as $record) {
            $d = $record->connection_id ? ($record->data['reviewed_fields'] ?? []) : $record->data;
            $due = $record->starts_on?->toDateString() ?? ($d['expected_on'] ?? null);
            $trigger = $data['trigger'];
            $match = match ($trigger) {
                'day_before' => $record->kind === 'event' && $due === $local->addDay()->toDateString(),
                'morning_digest' => $due && $due >= $local->toDateString() && $due <= $local->addDays(7)->toDateString(),
                'missing_information' => $area->profile === 'events' ? $record->kind === 'event' && (blank($d['venue'] ?? null) || blank($d['instructions'] ?? null)) : in_array($record->kind, ['account', 'prospect'], true) && (blank($d['contact'] ?? null) || blank($d['next_action'] ?? null)),
                'followup_due' => $record->kind === 'followup' && $due && $due <= $local->toDateString(),
                'reorder_check' => $record->kind === 'account' && preg_match('/^\d{4}-\d{2}-\d{2}$/D', $d['last_order_on'] ?? '') && $d['last_order_on'] <= $local->subDays((int) ($data['days_after'] ?? 60))->toDateString(),
                'assignment_changed' => $record->assigned_user_id && (int) $record->assigned_user_id === (int) $recipient->id && $record->updated_at->gte($local->subDay()) && $this->changed($record, 'assigned_user_id'),
                'stage_changed' => $record->status === ($data['stage'] ?? '') && $record->updated_at->gte($local->subDay()) && $this->changed($record, 'status'),
                default => false,
            };
            if (! $match) {
                continue;
            }
            try {
                $access->record($tenant, $recipient, $record);
                $items[] = $record;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                continue;
            }
        }

        return $items;
    }

    private function changed(TeamOperationRecord $record, string $field): bool
    {
        if ($record->version === 1) {
            return true;
        }
        $payload = \Illuminate\Support\Facades\DB::table('team_operation_revisions')->where(['tenant_id' => $record->tenant_id, 'record_id' => $record->id])->where('version', '<', $record->version)->orderByDesc('version')->value('payload');
        if (! $payload) {
            return false;
        }
        $previous = json_decode(\Illuminate\Support\Facades\Crypt::decryptString($payload), true, flags: JSON_THROW_ON_ERROR);

        return (string) ($previous[$field] ?? '') !== (string) $record->getAttribute($field);
    }

    public function preview(Tenant $tenant, User $owner, TeamArea $area, array $data): array
    {
        $access = app(OperationsAccess::class);
        $access->financial($tenant, $owner);
        $local = CarbonImmutable::now($data['timezone'] ?? 'America/New_York');
        $items = [];
        foreach ($data['audience_ids'] ?? [] as $id) {
            abort_unless(in_array((string) $id, $access->actor($tenant, $owner)['verified_user_ids'], true), 422);
            $recipient = User::findOrFail($id);
            // The owner must also be able to inspect any record included in a preview.
            $records = array_filter($this->matching($tenant, $recipient, $area, $data, $local), function ($r) use ($access, $tenant, $owner) {
                try {
                    $access->record($tenant, $owner, $r);

                    return true;
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    return false;
                }
            });
            $items[] = ['recipient' => $recipient->name, 'count' => count($records), 'records' => array_values(array_map(fn ($r) => ['id' => $r->id, 'title' => $r->title], array_slice($records, 0, 10)))];
        }

        return ['items' => $items, 'channel' => 'Teams Inbox', 'time' => $data['local_time'] ?? '08:00', 'timezone' => $local->timezoneName, 'notice' => 'Preview only; nothing was sent. These are currently matching authorized records. Matches and access are checked again when the rule runs.'];
    }

    public function run(): void
    {
        if (! config('team_operations.enabled')) {
            return;
        }
        foreach (TeamOperationRecord::where('kind', 'rule')->whereNotIn('status', ['paused', 'withdrawn', 'cancelled'])->cursor() as $rule) {
            $d = $rule->data;
            if (! ($d['enabled'] ?? false) || ! ($d['reviewed'] ?? false) || ($d['external'] ?? false)) {
                continue;
            }
            try {
                $tenant = Tenant::findOrFail($rule->tenant_id);
                $owner = User::findOrFail($d['_created_by'] ?? 0);
                $access = app(OperationsAccess::class);
                $access->financial($tenant, $owner);
                $area = $access->area($tenant, $owner, $rule->area_id, true);
                $this->validate($area, $d);
                $local = CarbonImmutable::now($d['timezone'] ?? 'America/New_York');
                $sendTime = $local->startOfDay()->setTimeFromTimeString($d['local_time'] ?? '08:00');
                if ($local->lt($sendTime) || $local->gte($sendTime->addHour()) || $this->quiet($local->format('H:i'), $d)) {
                    continue;
                }
                foreach ($d['audience_ids'] ?? [] as $recipientId) {
                    try {
                        $recipient = User::findOrFail($recipientId);
                        $records = $this->matching($tenant, $recipient, $area, $d, $local);
                        $ids = array_map(fn ($r) => $r->id, $records);
                        if (! $ids) {
                            continue;
                        }
                        $uuid = (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'teams-reminder:'.$rule->id.':'.$recipient->id.':'.$local->toDateString());
                        TeamOperationAttempt::firstOrCreate(['tenant_id' => $tenant->id, 'user_id' => $recipient->id, 'request_id' => $uuid], ['id' => (string) Str::uuid(), 'area_id' => $rule->area_id, 'record_id' => $ids[0], 'kind' => 'reminder', 'status' => 'unread', 'input_hash' => hash('sha256', $uuid), 'payload' => ['record_ids' => $ids, 'rule_id' => $rule->id, 'rule_version' => $rule->version, 'trigger' => $d['trigger'], 'local_date' => $local->toDateString(), 'template_id' => $d['template_id'] ?? null], 'scheduled_at' => $sendTime]);
                    } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                        continue;
                    }
                }
            } catch (\Throwable) {
                continue;
            }
        }
    }

    public function inbox(Tenant $tenant, User $user): array
    {
        if (! config('team_operations.enabled')) {
            return [];
        }
        $items = [];
        $access = app(OperationsAccess::class);
        foreach (TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'kind' => 'reminder'])->where('status', '!=', 'archived')->latest()->limit(100)->get() as $attempt) {
            $permitted = [];
            foreach ($attempt->payload['record_ids'] ?? [] as $id) {
                $record = TeamOperationRecord::where(['tenant_id' => $tenant->id, 'area_id' => $attempt->area_id])->whereNotIn('status', ['cancelled', 'withdrawn', 'completed', 'lost'])->find($id);
                if (! $record) {
                    continue;
                }
                try {
                    $access->record($tenant, $user, $record);
                    $permitted[] = $record;
                } catch (\Symfony\Component\HttpKernel\Exception\HttpException) {
                    continue;
                }
            }
            if ($permitted) {
                $items[] = ['id' => $attempt->id, 'task_id' => null, 'operation_id' => $permitted[0]->id, 'area_id' => $attempt->area_id, 'event_type' => 'Operations reminder', 'title' => $permitted[0]->title.(count($permitted) > 1 ? ' + '.(count($permitted) - 1).' more' : ''), 'read_at' => $attempt->status === 'read' ? $attempt->updated_at : null, 'created_at' => $attempt->created_at];
            }
        }

        return $items;
    }

    public function update(Tenant $tenant, User $user, string $id, array $changes): void
    {
        abort_unless(collect($this->inbox($tenant, $user))->contains('id', $id), 404);
        $attempt = TeamOperationAttempt::where(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'kind' => 'reminder'])->findOrFail($id);
        $attempt->update(['status' => ($changes['archived'] ?? false) ? 'archived' : (($changes['read'] ?? false) ? 'read' : 'unread')]);
    }
}
