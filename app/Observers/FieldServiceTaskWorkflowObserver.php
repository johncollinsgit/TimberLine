<?php

namespace App\Observers;

use App\Models\FieldServiceTask;
use App\Services\Automation\V2\WorkflowDomainEventRecorder;

class FieldServiceTaskWorkflowObserver
{
    public function __construct(protected WorkflowDomainEventRecorder $events) {}

    public function created(FieldServiceTask $task): void
    {
        if ($this->isComplete($task)) {
            $this->recordCompletion($task);
        }
    }

    public function updated(FieldServiceTask $task): void
    {
        if ($task->wasChanged(['status', 'completed_at']) && $this->isComplete($task)) {
            $this->recordCompletion($task);
        }
    }

    protected function recordCompletion(FieldServiceTask $task): void
    {
        $this->events->record(
            (int) $task->tenant_id,
            'everbranch.task.completed',
            $task,
            [
                'task_id' => (int) $task->id,
                'job_id' => (int) $task->field_service_job_id,
                'title' => $task->title,
                'description' => $task->description,
                'status' => $task->status,
                'priority' => $task->priority,
                'assigned_user_id' => $task->assigned_user_id ? (int) $task->assigned_user_id : null,
                'completed_by_user_id' => $task->completed_by_user_id ? (int) $task->completed_by_user_id : null,
                'due_at' => $task->due_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
            ],
        );
    }

    protected function isComplete(FieldServiceTask $task): bool
    {
        return $task->completed_at !== null
            || in_array(strtolower((string) $task->status), ['complete', 'completed', 'done'], true);
    }
}
