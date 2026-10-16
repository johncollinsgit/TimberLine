<?php

namespace App\Services\AiBranch;

use App\Models\AiBranchAccount;
use App\Models\AiBranchAction;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTask;
use App\Models\FieldServiceTaskEvent;
use App\Models\Tenant;
use App\Models\User;
use App\Services\FieldService\FieldServiceAccessService;
use App\Services\FieldService\FieldServiceTaskAssignmentService;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class AiCommitments
{
    private function authorize(Tenant $tenant, User $user): void
    {
        abort_unless(config('ai_branch.assistant_commitments_enabled'), 404);
        app(AiAssistantAccess::class)->authorize($tenant, $user);
        app(AiWorkspaceContext::class)->authorize($tenant, $user);
        $tier = app(AiBranchAccess::class)->authorize($tenant, $user);
        abort_unless(config('ai_branch.assistant_commitments_enabled') && config('ai_branch.actions_enabled') && $tier['ai_limits']['actions']
            && app(AiWorkspaceContext::class)->permissions($tenant, $user)['jobs'], 403);
    }

    public function allows(Tenant $tenant, User $user): bool
    {
        if (! config('ai_branch.assistant_commitments_enabled')) {
            return false;
        }
        try {
            $this->authorize($tenant, $user);

            return true;
        } catch (HttpException) {
            return false;
        }
    }

    private function plain(string $body): string
    {
        return trim(html_entity_decode(strip_tags(preg_replace('/<\/(?:p|div|li|h[1-6])>/i', "$0\n", $body)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    private function note(Tenant $tenant, int $jobId, int $id): FieldServiceJobNote
    {
        // Shared tasks must not quote an owner-private source to a wider audience.
        return FieldServiceJobNote::query()->where('tenant_id', $tenant->id)->where('field_service_job_id', $jobId)->whereKey($id)
            ->where(fn ($q) => $q->whereNull('metadata->visibility')->orWhere('metadata->visibility', '!=', 'owner'))->firstOrFail();
    }

    private function assignee(Tenant $tenant, int $id, $job): User
    {
        $member = $tenant->users()->where('users.id', $id)->wherePivot('membership_active', true)->where('is_active', true)->lockForUpdate()->firstOrFail();
        abort_unless(app(FieldServiceAccessService::class)->canAccessJob($member, $tenant, $job), 403);

        return $member;
    }

    public function sources(Tenant $tenant, User $user, int $jobId): array
    {
        $this->authorize($tenant, $user);
        $job = app(AiBranchContext::class)->job($tenant, $user, $jobId);
        $notes = FieldServiceJobNote::query()->where('tenant_id', $tenant->id)->where('field_service_job_id', $jobId)
            ->where(fn ($q) => $q->whereNull('metadata->visibility')->orWhere('metadata->visibility', '!=', 'owner'))->latest('id')->limit(50)->get();
        $members = $tenant->users()->wherePivot('membership_active', true)->where('is_active', true)->orderBy('users.name')->limit(100)->get();

        return ['version' => 1, 'job' => ['id' => $job->id, 'title' => $job->title], 'timezone' => app(AiWorkspaceContext::class)->timezone($tenant),
            'notes' => $notes->map(fn ($note) => ['id' => $note->id, 'text' => mb_substr($this->plain($note->body), 0, 3000), 'noted_at' => $note->noted_at?->toIso8601String()])->all(),
            'assignees' => $members->filter(fn ($member) => app(FieldServiceAccessService::class)->canAccessJob($member, $tenant, $job))->map(fn ($member) => ['id' => $member->id, 'name' => $member->name])->values()->all(),
            'coverage' => 'The latest 50 team-visible job notes and up to 100 active members. Mail, calls and owner-private notes are not sources here. Select an explicit promise; suggestions and conditional language need ordinary task review.'];
    }

    public function isPromiseCandidate(string $quote): bool
    {
        return preg_match('/\b(?:will|promised?|agreed? to|(?:i|we|they)[\x{2019}\x{27}]ll)\b/iu', $quote)
            && ! preg_match('/\b(?:maybe|might|could|should|suggest\w*|perhaps|if|unless|will not|won[\x{2019}\x{27}]t|not a promise)\b/iu', $quote);
    }

    public function listing(Tenant $tenant, User $user, string $view, int $after = 0): array
    {
        $this->authorize($tenant, $user);
        abort_unless(in_array($view, ['pending', 'overdue', 'completed'], true) && $after >= 0, 422);
        $ids = AiBranchAction::where('tenant_id', $tenant->id)->where('kind', 'internal_commitment_task')->where('status', 'executed')->select('id');
        $query = app(AiWorkspaceSources::class)->tasksQuery($tenant, $user, false)->where('id', '>', $after)
            ->whereHas('events', fn ($event) => $event->where('tenant_id', $tenant->id)->whereIn('metadata->ai_branch_action_id', $ids));
        if ($view === 'completed') {
            $query->where(fn ($q) => $q->whereNotNull('completed_at')->orWhereIn('status', ['done', 'completed']));
        } else {
            $query->whereNull('completed_at')->whereNotIn('status', ['done', 'completed', 'canceled', 'cancelled']);
            if ($view === 'overdue') {
                $query->where('due_at', '<', now());
            }
        }
        $tasks = $query->orderBy('id')->limit(51)->get();
        $rows = $tasks->take(50)->map(fn ($task) => app(AiWorkspaceSources::class)->taskRecord($task, $tenant))->values()->all();
        $this->authorize($tenant, $user);

        return ['version' => 1, 'tasks' => $rows, 'next_cursor' => $tasks->count() > 50 ? $tasks[49]->id : null,
            'timezone' => app(AiWorkspaceContext::class)->timezone($tenant),
            'coverage' => 'Reviewed job-note commitment tasks only. Other tasks remain in the job workspace; no automatic reminders or messages are sent.'];
    }

    public function propose(Tenant $tenant, User $user, array $input): AiBranchAction
    {
        $this->authorize($tenant, $user);
        $data = \Illuminate\Support\Facades\Validator::make($input, ['job_id' => 'required|integer|min:1', 'note_id' => 'required|integer|min:1',
            'quote' => 'required|string|max:600', 'title' => 'required|string|max:255', 'assignee_id' => 'required|integer|min:1',
            'due_local' => 'required|string|max:16', 'due_offset' => ['nullable', 'regex:/^[+-]\d{2}:\d{2}$/'], 'request_id' => 'required|uuid', 'confirmed_promise' => 'accepted'])->validate();
        $job = app(AiBranchContext::class)->job($tenant, $user, (int) $data['job_id']);
        $note = $this->note($tenant, $job->id, (int) $data['note_id']);
        $quote = trim($data['quote']);
        abort_unless(str_contains($this->plain($note->body), $quote) && $this->isPromiseCandidate($quote), 422, 'Select an exact explicit promise from this note. Suggestions and conditional language are not automatically commitments.');
        $member = $this->assignee($tenant, (int) $data['assignee_id'], $job);
        $due = app(WorkspaceWallTime::class)->resolve($data['due_local'], app(AiWorkspaceContext::class)->timezone($tenant), $data['due_offset'] ?? null);
        $payload = ['job_id' => $job->id, 'job_title' => $job->title, 'target_hash' => hash('sha256', json_encode($job->getRawOriginal(), JSON_THROW_ON_ERROR)),
            'note_id' => $note->id, 'source_hash' => hash('sha256', json_encode($note->getRawOriginal(), JSON_THROW_ON_ERROR)), 'quote' => $quote,
            'title' => trim($data['title']), 'assignee_id' => $member->id, 'assignee_name' => $member->name, 'due' => $due,
            'body' => trim($data['title'])."\nAssigned to: ".$member->name."\nDue: ".$due['local'].' '.$due['timezone']."\nSource: job note #".$note->id."\nSource quote: ".$quote];
        $digest = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($tenant, $user, $data, $payload, $digest) {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $this->authorize($tenant, $user);
            $existing = AiBranchAction::where('tenant_id', $tenant->id)->where('client_uuid', $data['request_id'])->first();
            if ($existing) {
                abort_unless($existing->kind === 'internal_commitment_task' && (int) $existing->user_id === (int) $user->id && hash_equals($existing->digest, $digest), 409);

                return $existing;
            }

            return AiBranchAction::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'client_uuid' => $data['request_id'], 'kind' => 'internal_commitment_task',
                'payload' => $payload, 'digest' => $digest, 'status' => 'pending', 'expires_at' => now()->addMinutes(15)]);
        }, 3);
    }

    public function decide(Tenant $tenant, User $user, int $id, string $digest, bool $approve): AiBranchAction
    {
        $this->authorize($tenant, $user);

        return DB::transaction(function () use ($tenant, $user, $id, $digest, $approve) {
            AiBranchAccount::query()->where('tenant_id', $tenant->id)->lockForUpdate()->firstOrFail();
            $action = AiBranchAction::where('tenant_id', $tenant->id)->where('user_id', $user->id)->whereKey($id)->lockForUpdate()->firstOrFail();
            abort_unless($action->kind === 'internal_commitment_task' && hash_equals($action->digest, $digest)
                && hash_equals($digest, hash('sha256', json_encode($action->payload, JSON_THROW_ON_ERROR))), 409);
            $job = app(AiBranchContext::class)->job($tenant, $user, (int) $action->payload['job_id']);
            $job = \App\Models\FieldServiceJob::where('tenant_id', $tenant->id)->whereKey($job->id)->lockForUpdate()->firstOrFail();
            if ($action->status !== 'pending') {
                abort_unless($action->status === ($approve ? 'executed' : 'rejected'), 409);

                return $action;
            }
            abort_unless($action->expires_at->isFuture(), 409, 'The task preview expired. Prepare another.');
            $note = $this->note($tenant, $job->id, (int) $action->payload['note_id']);
            $note = FieldServiceJobNote::where('tenant_id', $tenant->id)->whereKey($note->id)->lockForUpdate()->firstOrFail();
            abort_unless(hash_equals($action->payload['target_hash'], hash('sha256', json_encode($job->getRawOriginal(), JSON_THROW_ON_ERROR)))
                && hash_equals($action->payload['source_hash'], hash('sha256', json_encode($note->getRawOriginal(), JSON_THROW_ON_ERROR))), 409, 'The job or source note changed. Prepare another preview.');
            $member = $this->assignee($tenant, (int) $action->payload['assignee_id'], $job);
            abort_unless($member->name === $action->payload['assignee_name'] && app(AiWorkspaceContext::class)->timezone($tenant) === $action->payload['due']['timezone'], 409, 'Assignment or timezone changed. Prepare another preview.');
            $this->authorize($tenant, $user);
            if ($approve) {
                $task = FieldServiceTask::create(['tenant_id' => $tenant->id, 'field_service_job_id' => $job->id, 'created_by_user_id' => $user->id,
                    'title' => $action->payload['title'], 'description' => 'Reviewed commitment: '.$action->payload['quote']."\nSource: job note #".$note->id,
                    'status' => 'open', 'priority' => 'normal', 'due_at' => $action->payload['due']['utc']]);
                app(FieldServiceTaskAssignmentService::class)->sync($task, $tenant, $user, [$member->id]);
                FieldServiceTaskEvent::create(['tenant_id' => $tenant->id, 'field_service_task_id' => $task->id, 'actor_user_id' => $user->id,
                    'event_type' => 'created', 'to_status' => 'open', 'idempotency_key' => 'ai-commitment:'.$action->id,
                    'metadata' => ['ai_branch_action_id' => $action->id, 'source_note_id' => $note->id, 'approval_digest' => $digest]]);
                $action->result_id = $task->id;
            }
            $action->forceFill(['status' => $approve ? 'executed' : 'rejected', 'decided_at' => now()])->save();
            app(\App\Services\Tenancy\LandlordOperatorActionAuditService::class)->record($tenant->id, $user->id, 'ai_branch.commitment.'.$action->status,
                targetType: 'ai_branch_action', targetId: $action->id, confirmation: ['explicit' => true], result: ['task_id' => $action->result_id]);

            return $action;
        }, 3);
    }
}
