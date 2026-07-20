<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceTimeEntry extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'field_service_job_id', 'user_id', 'reviewed_by_user_id', 'work_date', 'started_at',
        'ended_at', 'break_minutes', 'duration_minutes', 'status', 'notes', 'reviewed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_job_id' => 'integer',
        'user_id' => 'integer',
        'reviewed_by_user_id' => 'integer',
        'work_date' => 'date',
        'break_minutes' => 'integer',
        'duration_minutes' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }
}
