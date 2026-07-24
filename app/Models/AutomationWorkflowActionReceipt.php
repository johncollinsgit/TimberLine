<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationWorkflowActionReceipt extends Model
{
    use BelongsToTenant;

    public const STATUS_DISPATCHING = 'dispatching';

    public const STATUS_SUCCEEDED = 'succeeded';

    public const STATUS_UNCERTAIN = 'uncertain';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id',
        'automation_workflow_id',
        'automation_workflow_version_id',
        'automation_workflow_run_item_id',
        'step_id',
        'component_key',
        'idempotency_key',
        'payload_hash',
        'status',
        'target_type',
        'target_id',
        'result',
        'error_summary',
        'reserved_at',
        'succeeded_at',
        'failed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'automation_workflow_id' => 'integer',
        'automation_workflow_version_id' => 'integer',
        'automation_workflow_run_item_id' => 'integer',
        'result' => 'encrypted:array',
        'reserved_at' => 'datetime',
        'succeeded_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected $hidden = [
        'result',
    ];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'automation_workflow_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'automation_workflow_version_id');
    }

    public function runItem(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowRunItem::class, 'automation_workflow_run_item_id');
    }
}
