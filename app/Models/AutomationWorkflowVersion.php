<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationWorkflowVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'automation_workflow_id', 'version', 'definition_hash', 'definition', 'published_by_user_id', 'published_at'];

    protected $casts = ['tenant_id' => 'integer', 'version' => 'integer', 'definition' => 'array', 'published_at' => 'datetime'];

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflow::class, 'automation_workflow_id');
    }
}
