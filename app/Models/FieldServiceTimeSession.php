<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldServiceTimeSession extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'field_service_job_id', 'user_id', 'reviewed_by_user_id', 'client_uuid', 'active_user_key',
        'status', 'clocked_in_at', 'clocked_out_at', 'break_seconds', 'duration_seconds',
        'clock_out_notes', 'source', 'device_context', 'reviewed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer', 'field_service_job_id' => 'integer', 'user_id' => 'integer',
        'reviewed_by_user_id' => 'integer', 'active_user_key' => 'integer', 'clocked_in_at' => 'datetime', 'clocked_out_at' => 'datetime',
        'break_seconds' => 'integer', 'duration_seconds' => 'integer', 'device_context' => 'array',
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

    public function breaks(): HasMany
    {
        return $this->hasMany(FieldServiceTimeBreak::class);
    }
}
