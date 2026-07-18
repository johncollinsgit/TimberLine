<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflowRun extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'automation_workflow_id', 'automation_workflow_version_id', 'mode', 'status', 'counts', 'context', 'error_summary', 'idempotency_key', 'initiated_by_user_id', 'started_at', 'finished_at'];

    protected $casts = ['tenant_id' => 'integer', 'counts' => 'array', 'context' => 'array', 'started_at' => 'datetime', 'finished_at' => 'datetime'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'automation_workflow_id');
    }

    public function steps(): HasMany
    {
        return $this->hasMany(AutomationWorkflowRunStep::class)->orderBy('position');
    }
}
