<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceCrewStatus extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'user_id', 'field_service_job_id', 'status', 'note', 'expires_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'field_service_job_id' => 'integer',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }
}
