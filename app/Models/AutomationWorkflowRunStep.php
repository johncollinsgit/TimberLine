<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationWorkflowRunStep extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'automation_workflow_run_id', 'position', 'step_key', 'provider', 'kind', 'status', 'summary', 'error_message', 'duration_ms'];

    protected $casts = ['tenant_id' => 'integer', 'position' => 'integer', 'summary' => 'array', 'duration_ms' => 'integer'];

    public function run(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowRun::class, 'automation_workflow_run_id');
    }
}
