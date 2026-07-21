<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceJobVehicleCrew extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'field_service_job_id', 'field_service_vehicle_id', 'user_id'];

    protected $casts = ['tenant_id' => 'integer', 'field_service_job_id' => 'integer', 'field_service_vehicle_id' => 'integer', 'user_id' => 'integer'];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FieldServiceVehicle::class, 'field_service_vehicle_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
