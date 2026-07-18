<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class AutomationWorkflowAuditEvent extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'automation_workflow_id', 'actor_user_id', 'event_type', 'before_state', 'after_state', 'context', 'occurred_at'];

    protected $casts = ['tenant_id' => 'integer', 'before_state' => 'array', 'after_state' => 'array', 'context' => 'array', 'occurred_at' => 'datetime'];
}
