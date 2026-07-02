<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceJobPhoto extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'field_service_job_id',
        'file_path',
        'caption',
        'uploaded_by_user_id',
        'captured_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_job_id' => 'integer',
        'uploaded_by_user_id' => 'integer',
        'captured_at' => 'datetime',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
