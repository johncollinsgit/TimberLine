<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class FieldServiceVehicle extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'name',
        'identifier',
        'status',
        'notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function jobs(): BelongsToMany
    {
        return $this->belongsToMany(FieldServiceJob::class, 'field_service_job_vehicle_assignments')
            ->withPivot(['tenant_id', 'assigned_by_user_id'])
            ->withTimestamps();
    }
}
