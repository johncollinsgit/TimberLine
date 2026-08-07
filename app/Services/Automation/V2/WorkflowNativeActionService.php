<?php

namespace App\Services\Automation\V2;

use App\Models\FieldServiceJob;
use App\Models\FieldServiceJobNote;
use App\Models\FieldServiceTask;
use App\Models\Tenant;
use App\Models\User;
use App\Services\CustomerLoop\CustomerLoopService;
use App\Services\FieldService\FieldServiceJobNotificationService;
use App\Services\FieldService\FieldServiceJobTransitionService;
use App\Services\FieldService\FieldServiceTaskAssignmentService;
use App\Services\Marketing\Email\TenantEmailDispatchService;
use Illuminate\Support\Facades\DB;

class WorkflowNativeActionService
{
    public function __construct(
        protected TenantEmailDispatchService $email,
        protected FieldServiceTaskAssignmentService $assignments,
        protected FieldServiceJobNotificationService $notifications,
        protected FieldServiceJobTransitionService $transitions,
        protected CustomerLoopService $customerLoop,
    ) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function sendEmail(
        int $tenantId,
        array $payload,
        bool $dryRun = false,
        ?string $idempotencyKey = null,
    ): array {
        $to = trim((string) ($payload['to'] ?? ''));
        $subject = trim((string) ($payload['subject'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $replyTo = $this->nullableString($payload['reply_to'] ?? null);
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false || $subject === '' || $body === '') {
            throw new \InvalidArgumentException('Everbranch Email requires a valid recipient, subject, and message.');
        }
        if ($replyTo !== null && filter_var($replyTo, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Everbranch Email requires a valid reply-to address.');
        }

        $result = $this->email->sendEmail($to, $subject, $body, [
            'tenant_id' => $tenantId,
            'dry_run' => $dryRun,
            'tracking_enabled' => false,
            'reply_to_email' => $replyTo,
            'headers' => array_filter([
                'X-Everbranch-Idempotency-Key' => $idempotencyKey,
            ]),
            'metadata' => array_filter([
                'workflow_idempotency_key' => $idempotencyKey,
            ]),
            'custom_args' => array_filter([
                'workflow_idempotency_key' => $idempotencyKey,
            ]),
        ]);
        if (! (bool) ($result['success'] ?? false)) {
            throw new WorkflowActionDispatchFailed(
                (string) ($result['error_message'] ?? 'Everbranch Email could not send this message.'),
                (bool) ($result['retryable'] ?? false),
            );
        }

        return [
            'provider' => (string) ($result['provider'] ?? 'email'),
            'status' => (string) ($result['status'] ?? 'sent'),
            'message_id' => $result['message_id'] ?? null,
            'recipient' => $to,
            'accepted_at' => $dryRun ? null : now()->toIso8601String(),
            'dry_run' => (bool) ($result['dry_run'] ?? $dryRun),
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function createTask(int $tenantId, User $actor, array $payload, bool $dryRun = false): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $job = $this->job($tenantId, (int) ($payload['job_id'] ?? 0));
        $title = trim((string) ($payload['title'] ?? ''));
        if ($title === '') {
            throw new \InvalidArgumentException('Create job task requires a task title.');
        }
        $assigneeIds = $this->assignments->tenantUserIds($tenant, (array) ($payload['assignee_ids'] ?? []));
        if ($dryRun) {
            return [
                'validated' => true,
                'job_id' => (int) $job->id,
                'title' => $title,
                'assignee_ids' => $assigneeIds->all(),
                'dry_run' => true,
            ];
        }

        return DB::transaction(function () use ($tenant, $job, $actor, $payload, $title, $assigneeIds): array {
            $task = FieldServiceTask::query()->create([
                'tenant_id' => (int) $tenant->id,
                'field_service_job_id' => (int) $job->id,
                'assigned_user_id' => $assigneeIds->first(),
                'created_by_user_id' => (int) $actor->id,
                'title' => $title,
                'description' => $this->nullableString($payload['description'] ?? null),
                'priority' => in_array(($payload['priority'] ?? null), ['low', 'normal', 'high', 'urgent'], true)
                    ? (string) $payload['priority']
                    : 'normal',
                'status' => 'open',
                'due_at' => $this->nullableString($payload['due_at'] ?? null),
            ]);
            $this->assignments->sync($task, $tenant, $actor, $assigneeIds->all());
            $notifyIds = $assigneeIds->reject(fn (int $id): bool => $id === (int) $actor->id)->all();
            if ($notifyIds !== []) {
                $this->notifications->notifyJobEvent(
                    $job,
                    $actor,
                    'task_assigned',
                    'New task: '.$task->title,
                    'workflow-task-created:'.$task->id,
                    $notifyIds,
                );
            }

            return [
                'task_id' => (int) $task->id,
                'job_id' => (int) $job->id,
                'title' => $task->title,
                'status' => $task->status,
            ];
        });
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function addJobNote(int $tenantId, User $actor, array $payload, bool $dryRun = false): array
    {
        $job = $this->job($tenantId, (int) ($payload['job_id'] ?? 0));
        $body = trim((string) ($payload['body'] ?? ''));
        if ($body === '') {
            throw new \InvalidArgumentException('Add job note requires note text.');
        }
        if ($dryRun) {
            return ['validated' => true, 'job_id' => (int) $job->id, 'body' => $body, 'dry_run' => true];
        }

        $note = FieldServiceJobNote::query()->create([
            'tenant_id' => $tenantId,
            'field_service_job_id' => (int) $job->id,
            'created_by_user_id' => (int) $actor->id,
            'body' => $body,
            'noted_at' => now(),
            'metadata' => ['source' => 'workflow_automation_v2'],
        ]);
        $this->notifications->notifyJobEvent(
            $job,
            $actor,
            'note_added',
            $body,
            'workflow-note:'.$note->id,
        );

        return ['note_id' => (int) $note->id, 'job_id' => (int) $job->id, 'body' => $body];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function changeJobStatus(int $tenantId, User $actor, array $payload, bool $dryRun = false): array
    {
        $tenant = Tenant::query()->whereKey($tenantId)->firstOrFail();
        $job = $this->job($tenantId, (int) ($payload['job_id'] ?? 0));
        $action = trim((string) ($payload['action'] ?? ''));
        if (! in_array($action, ['start', 'resume', 'block', 'complete', 'cancel', 'archive', 'reopen'], true)) {
            throw new \InvalidArgumentException('Choose a supported job status action.');
        }
        $reason = $this->nullableString($payload['reason'] ?? null);
        if ($action === 'block' && $reason === null) {
            throw new \InvalidArgumentException('Blocking a job requires a reason.');
        }
        if ($dryRun) {
            return [
                'validated' => true,
                'job_id' => (int) $job->id,
                'action' => $action,
                'dry_run' => true,
            ];
        }

        $result = $this->transitions->transition($tenant, $job, $actor, $action, $reason);

        return [
            'job_id' => (int) $result['job']->id,
            'status' => (string) $result['job']->operational_status,
            'note_id' => (int) $result['note']->id,
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function prepareCustomerLoopDraft(
        int $tenantId,
        User $actor,
        array $payload,
        string $idempotencyKey,
        bool $dryRun = false,
    ): array {
        $template = trim((string) ($payload['template'] ?? 'follow_up'));
        $title = trim((string) ($payload['title'] ?? 'Customer follow-up needs review'));
        $summary = $this->nullableString($payload['summary'] ?? null);
        if (! array_key_exists($template, $this->customerLoop->templates())) {
            throw new \InvalidArgumentException('Choose a supported Customer Loop draft template.');
        }
        if ($title === '') {
            throw new \InvalidArgumentException('Prepare Customer Loop draft requires a title.');
        }
        if ($dryRun) {
            return [
                'validated' => true,
                'template' => $template,
                'title' => $title,
                'draft_only' => true,
                'dry_run' => true,
            ];
        }

        $action = $this->customerLoop->prepareFromWorkflow(
            tenantId: $tenantId,
            actor: $actor,
            template: $template,
            title: $title,
            summary: $summary,
            eventKey: $idempotencyKey,
            safeContext: [
                'workflow_idempotency_key' => $idempotencyKey,
                'source' => 'workflow_automation_v2',
            ],
        );

        return [
            'customer_loop_action_id' => (int) $action->id,
            'template' => $action->action_type,
            'status' => $action->status,
            'draft_only' => true,
        ];
    }

    protected function job(int $tenantId, int $jobId): FieldServiceJob
    {
        $job = FieldServiceJob::query()->forTenantId($tenantId)->whereKey($jobId)->first();
        if (! $job instanceof FieldServiceJob) {
            throw new \InvalidArgumentException('Choose a job from this workspace.');
        }

        return $job;
    }

    protected function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
