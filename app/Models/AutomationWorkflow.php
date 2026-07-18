<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AutomationWorkflow extends Model
{
    use BelongsToTenant;

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_PAUSED = 'paused';

    protected $fillable = [
        'tenant_id', 'template_key', 'name', 'status', 'draft_definition',
        'published_version_id', 'test_state', 'created_by_user_id', 'updated_by_user_id',
        'published_at', 'last_run_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'published_version_id' => 'integer',
        'draft_definition' => 'array',
        'test_state' => 'array',
        'published_at' => 'datetime',
        'last_run_at' => 'datetime',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(AutomationWorkflowVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(AutomationWorkflowVersion::class, 'published_version_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(AutomationWorkflowRun::class);
    }
}
