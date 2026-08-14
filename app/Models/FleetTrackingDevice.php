<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FleetTrackingDevice extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'field_service_vehicle_id', 'provider', 'external_device_id', 'label', 'status', 'installed_at', 'uninstalled_at'];

    protected $casts = ['tenant_id' => 'integer', 'field_service_vehicle_id' => 'integer', 'installed_at' => 'datetime', 'uninstalled_at' => 'datetime'];

    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(FieldServiceVehicle::class, 'field_service_vehicle_id');
    }
}
