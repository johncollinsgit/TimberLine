<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceTask extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'field_service_job_id',
        'assigned_user_id',
        'title',
        'status',
        'due_at',
        'sort_order',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_job_id' => 'integer',
        'assigned_user_id' => 'integer',
        'due_at' => 'datetime',
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
}
