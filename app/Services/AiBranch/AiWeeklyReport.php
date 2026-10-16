<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchAccount;
use App\Models\CustomerLoopActivity;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTaskEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AiWeeklyReport
{
    private function authorize(Tenant $tenant, User $user): void
    {
        app(AiAssistantAccess::class)->authorize($tenant, $user);
        abort_unless(config('ai_branch.assistant_reports_enabled'), 404);
        app(AiWorkspaceContext::class)->authorize($tenant, $user);
    }

    private function recipients(Tenant $tenant, string $audience, array $ids): array
    {
        abort_unless(in_array($audience, ['executive', 'team'], true), 422);
        if ($audience === 'executive') {
            abort_unless($ids === [], 422);

            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids);
        abort_unless(count($ids) >= 1 && count($ids) <= 50, 422, 'Select the team audience.');
        $users = $tenant->users()->whereIn('users.id', $ids)->wherePivot('membership_active', true)->where('is_active', true)->orderBy('users.id')->get(['users.id', 'users.name', 'users.role', 'users.email_verified_at'])->all();
        abort_unless(count($users) === count($ids), 422, 'The report audience changed.');

        return $users;
    }

    private function stamp(Tenant $tenant, User $user, array $recipients): string
    {
        return hash('sha256', json_encode([
            app(AiWorkspaceContext::class)->stamp($tenant, $user),
            array_map(fn ($recipient) => [$recipient->id, app(FieldServiceAccessService::class)->role($recipient, $tenant)], $recipients),
        ], JSON_THROW_ON_ERROR));
    }

    private function path(Tenant $tenant, User $user, string $uuid): string
    {
        abort_unless(Str::isUuid($uuid), 422);

        return 'ai-weekly-reports/'.$tenant->id.'/'.$user->id.'/'.$uuid.'.enc';
    }

    public function prune(bool $apply): array
    {
        $disk = Storage::disk(config('filesystems.workspace_asset_disk', 'local'));
        $counts = ['expired' => 0, 'unreadable' => 0];
        foreach ($disk->allFiles('ai-weekly-reports') as $path) {
            if (! preg_match('~^ai-weekly-reports/(\d+)/(\d+)/([a-f0-9-]{36})\.enc$~i', $path, $match)) {
                continue;
            }
            try {
                $report = json_decode(Crypt::decryptString($disk->get($path)), true, 64, JSON_THROW_ON_ERROR);
                if ((int) $report['tenant_id'] !== (int) $match[1] || (int) $report['user_id'] !== (int) $match[2] || strcasecmp($report['request_id'], $match[3]) !== 0) {
                    $counts['unreadable']++;

                    continue;
                }
                if (CarbonImmutable::parse($report['expires_at'])->lte(now())) {
                    $counts['expired']++;
                    if ($apply) {
                        abort_unless($disk->delete($path), 503, 'A private report could not be pruned.');
                    }
                }
            } catch (\Throwable) {
                $counts['unreadable']++;
            }
        }

        return $counts;
    }

    public function capture(Tenant $tenant, User $user, string $weekStart, string $audience, array $recipientIds, string $uuid): array
    {
        $this->authorize($tenant, $user);
        $zone = app(AiWorkspaceContext::class)->timezone($tenant);
        abort_unless(preg_match('/^\d{4}-\d{2}-\d{2}$/', $weekStart) === 1, 422);
        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $weekStart, $zone);
        } catch (\Throwable) {
            abort(422, 'Choose a valid week start.');
        }
        abort_unless($start && $start->format('Y-m-d') === $weekStart, 422);
        $end = $start->addDays(7);
        $recipients = $this->recipients($tenant, $audience, $recipientIds);
        $recipientIds = array_map(fn ($recipient) => (int) $recipient->id, $recipients);
        $inputHash = hash('sha256', json_encode([$weekStart, $zone, $audience, $recipientIds], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $start, $end, $zone, $audience, $recipients, $recipientIds, $uuid, $inputHash): array {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $disk = Storage::disk(config('filesystems.workspace_asset_disk', 'local'));
            $path = $this->path($tenant, $user, $uuid);
            if ($disk->exists($path)) {
                $saved = $this->read($tenant, $user, $uuid);
                abort_unless(hash_equals($saved['input_hash'], $inputHash), 409);

                return $saved;
            }
            $permissions = app(AiWorkspaceContext::class)->permissions($tenant, $user);
            $entries = [];
            $coverage = [];
            $add = function (array $entry) use (&$entries): void {
                $entries[$entry['ref']] = $entry;
                abort_if(count($entries) > 10000, 422, 'This week exceeds the interactive report limit. No partial report was saved.');
            };
            if ($permissions['jobs']) {
                $jobs = app(AiWorkspaceSources::class)->jobsQuery($tenant, $user);
                foreach ($recipients as $recipient) {
                    $jobs = app(FieldServiceAccessService::class)->scopeVisibleJobs($jobs, $recipient, $tenant);
                }
                $ids = (clone $jobs)->select('id');
                $events = FieldServiceTaskEvent::query()->where('tenant_id', $tenant->id)
                    ->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())
                    ->whereHas('task', fn ($task) => $task->where('tenant_id', $tenant->id)->whereIn('field_service_job_id', clone $ids))
                    ->with('task.job')->orderBy('id');
                $taskCount = 0;
                $events->chunkById(250, function ($rows) use ($tenant, &$taskCount, $add): void {
                    foreach ($rows as $row) {
                        $add(['ref' => 'task_event:'.$row->id, 'source' => 'task_events', 'source_id' => $row->id,
                            'job_id' => $row->task->field_service_job_id, 'title' => mb_substr($row->task->title, 0, 160),
                            'event' => 'Task '.Str::headline($row->event_type), 'occurred_at' => $row->created_at->toIso8601String(),
                            'url' => route('field-service.jobs.show', ['job' => $row->task->field_service_job_id, 'tenant' => $tenant->slug])]);
                        $taskCount++;
                    }
                });
                $notes = FieldServiceJobNote::query()->where('tenant_id', $tenant->id)->whereIn('field_service_job_id', clone $ids)
                    ->where('created_at', '>=', $start->utc())->where('created_at', '<', $end->utc())
                    ->when($audience === 'team', fn ($query) => $query->where(fn ($q) => $q->whereNull('metadata->visibility')->orWhere('metadata->visibility', '!=', 'owner')))
                    ->with('job')->orderBy('id');
                $noteCount = 0;
                $notes->chunkById(250, function ($rows) use ($tenant, &$noteCount, $add): void {
                    foreach ($rows as $row) {
                        $add(['ref' => 'job_note:'.$row->id, 'source' => 'job_notes', 'source_id' => $row->id,
                            'job_id' => $row->field_service_job_id, 'title' => mb_substr($row->job->title, 0, 160), 'event' => 'Job note added',
                            'occurred_at' => $row->created_at->toIso8601String(),
                            'url' => route('field-service.jobs.show', ['job' => $row->field_service_job_id, 'tenant' => $tenant->slug])]);
                        $noteCount++;
                    }
                });
                $coverage['task_events'] = ['status' => 'registered_source_complete', 'count' => $taskCount];
                $coverage['job_notes'] = ['status' => 'current_authorized_notes_only', 'count' => $noteCount];
            } else {
                $coverage['field_service'] = ['status' => 'restricted'];
            }
            if ($audience === 'executive' && $permissions['customers']) {
                $count = 0;
                CustomerLoopActivity::query()->where('tenant_id', $tenant->id)
                    ->where('occurred_at', '>=', $start->utc())->where('occurred_at', '<', $end->utc())
                    ->whereHas('profile', fn ($profile) => $profile->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id'))
                    ->orderBy('id')->chunkById(250, function ($rows) use ($tenant, &$count, $add): void {
                        foreach ($rows as $row) {
                            $add(['ref' => 'customer_activity:'.$row->id, 'source' => 'customer_activity', 'source_id' => $row->id,
                                'customer_id' => $row->marketing_profile_id, 'title' => mb_substr($row->title, 0, 160),
                                'event' => 'Customer activity', 'occurred_at' => $row->occurred_at->toIso8601String(),
                                'url' => route('marketing.customers.show', ['marketingProfile' => $row->marketing_profile_id, 'tenant' => $tenant->slug])]);
                            $count++;
                        }
                    });
                $coverage['customer_activity'] = ['status' => 'registered_source_complete', 'count' => $count];
            } else {
                $coverage['customer_activity'] = ['status' => 'restricted'];
            }
            $entries = array_values($entries);
            usort($entries, fn ($a, $b) => [$a['occurred_at'], $a['ref']] <=> [$b['occurred_at'], $b['ref']]);
            $payload = ['version' => 1, 'request_id' => $uuid, 'tenant_id' => $tenant->id, 'user_id' => $user->id,
                'input_hash' => $inputHash, 'scope_hash' => $this->stamp($tenant, $user, $recipients), 'audience' => $audience, 'audience_user_ids' => $recipientIds,
                'timezone' => $zone, 'starts_at' => $start->toIso8601String(), 'ends_before' => $end->toIso8601String(),
                'generated_at' => now()->toIso8601String(), 'expires_at' => now()->addDays(30)->toIso8601String(),
                'entries' => $entries, 'count' => count($entries), 'coverage' => $coverage, 'coverage_complete' => false,
                'coverage_notice' => 'This reconciles registered task events, current job notes and permitted customer activity only. Other module history, mail, calls, finance and unrecorded edits are not included.'];
            $this->authorize($tenant, $user);
            abort_unless($disk->put($path, Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR))), 503, 'The private report could not be saved.');

            return $payload;
        }, 3);
    }

    public function read(Tenant $tenant, User $user, string $uuid): array
    {
        $this->authorize($tenant, $user);
        $disk = Storage::disk(config('filesystems.workspace_asset_disk', 'local'));
        $path = $this->path($tenant, $user, $uuid);
        abort_unless($disk->exists($path), 404);
        $report = json_decode(Crypt::decryptString($disk->get($path)), true, 64, JSON_THROW_ON_ERROR);
        abort_unless($report['version'] === 1 && $report['tenant_id'] === $tenant->id && $report['user_id'] === $user->id && CarbonImmutable::parse($report['expires_at'])->isFuture(), 404);
        $recipients = $this->recipients($tenant, $report['audience'], $report['audience_user_ids']);
        abort_unless(hash_equals($report['scope_hash'], $this->stamp($tenant, $user, $recipients)), 409, 'Audience or permissions changed. Generate a new report.');
        $jobs = collect($report['entries'])->pluck('job_id')->filter()->unique();
        foreach ($jobs as $id) {
            $job = app(AiBranchContext::class)->job($tenant, $user, (int) $id);
            foreach ($recipients as $recipient) {
                abort_unless(app(FieldServiceAccessService::class)->canAccessJob($recipient, $tenant, $job), 409);
            }
        }
        foreach (['task_events' => FieldServiceTaskEvent::class, 'job_notes' => FieldServiceJobNote::class, 'customer_activity' => CustomerLoopActivity::class] as $source => $model) {
            $ids = collect($report['entries'])->where('source', $source)->pluck('source_id')->unique();
            $query = $model::query()->where('tenant_id', $tenant->id)->whereIn('id', $ids);
            if ($source === 'task_events') {
                $query->whereHas('task', fn ($task) => $task->where('tenant_id', $tenant->id)->whereIn('field_service_job_id', $jobs));
            } elseif ($source === 'job_notes') {
                $query->whereIn('field_service_job_id', $jobs)->when($report['audience'] === 'team', fn ($q) => $q->where(fn ($notes) => $notes->whereNull('metadata->visibility')->orWhere('metadata->visibility', '!=', 'owner')));
            } else {
                $query->whereHas('profile', fn ($profile) => $profile->where('tenant_id', $tenant->id)->whereNull('archived_at')->whereNull('merged_into_profile_id'));
            }
            abort_unless($ids->isEmpty() || $query->count() === $ids->count(), 409, 'A report source changed. Generate a new report.');
        }

        return $report;
    }
}
