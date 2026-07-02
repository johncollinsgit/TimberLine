<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceMaterial extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'field_service_job_id',
        'name',
        'quantity',
        'unit',
        'unit_cost',
        'status',
        'notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_job_id' => 'integer',
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }
}
