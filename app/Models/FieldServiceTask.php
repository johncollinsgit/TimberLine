<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldServiceTask extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'field_service_job_id',
        'assigned_user_id',
        'created_by_user_id',
        'completed_by_user_id',
        'title',
        'description',
        'status',
        'priority',
        'due_at',
        'completed_at',
        'sort_order',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_job_id' => 'integer',
        'assigned_user_id' => 'integer',
        'created_by_user_id' => 'integer',
        'completed_by_user_id' => 'integer',
        'due_at' => 'datetime',
        'completed_at' => 'datetime',
        'sort_order' => 'integer',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by_user_id');
    }

    public function assignees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'field_service_task_assignees')
            ->withPivot(['tenant_id', 'assigned_by_user_id'])
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(FieldServiceTaskEvent::class);
    }
}
