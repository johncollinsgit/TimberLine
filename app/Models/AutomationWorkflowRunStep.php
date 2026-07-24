<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationWorkflowRunStep extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'automation_workflow_run_id',
        'automation_workflow_run_item_id',
        'position',
        'step_key',
        'parent_step_id',
        'branch_key',
        'attempt',
        'idempotency_key',
        'provider',
        'kind',
        'status',
        'summary',
        'input_summary',
        'output_summary',
        'error_message',
        'duration_ms',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'automation_workflow_run_item_id' => 'integer',
        'position' => 'integer',
        'attempt' => 'integer',
        'summary' => 'array',
        'input_summary' => 'array',
        'output_summary' => 'array',
        'duration_ms' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowRun::class, 'automation_workflow_run_id');
    }

    public function runItem(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowRunItem::class, 'automation_workflow_run_item_id');
    }
}
