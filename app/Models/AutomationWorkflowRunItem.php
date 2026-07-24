<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflowRunItem extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_DELAYED = 'delayed';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_FAILED = 'failed';

    public const STATUS_HELD = 'held';

    public const STATUS_DISCARDED = 'discarded';

    protected $fillable = [
        'tenant_id',
        'automation_workflow_id',
        'automation_workflow_run_id',
        'automation_workflow_version_id',
        'trigger_step_id',
        'source_system',
        'source_id',
        'source_fingerprint',
        'event_key',
        'status',
        'payload',
        'context',
        'execution_stack',
        'current_step_id',
        'available_at',
        'attempt_count',
        'error_summary',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'automation_workflow_id' => 'integer',
        'automation_workflow_run_id' => 'integer',
        'automation_workflow_version_id' => 'integer',
        'payload' => 'encrypted:array',
        'context' => 'encrypted:array',
        'execution_stack' => 'encrypted:array',
        'available_at' => 'datetime',
        'attempt_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected $hidden = [
        'payload',
        'context',
        'execution_stack',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'automation_workflow_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowRun::class, 'automation_workflow_run_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'automation_workflow_version_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationWorkflowRunStep::class, 'automation_workflow_run_item_id')
            ->orderBy('position')
            ->orderBy('attempt');
    }
}
