<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceWorkShift extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'user_id', 'field_service_job_id', 'created_by_user_id', 'status', 'starts_at', 'ends_at', 'unpaid_break_minutes', 'notes', 'canceled_at'];

    protected $casts = ['tenant_id' => 'integer', 'user_id' => 'integer', 'field_service_job_id' => 'integer', 'created_by_user_id' => 'integer', 'starts_at' => 'datetime', 'ends_at' => 'datetime', 'unpaid_break_minutes' => 'integer', 'canceled_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
