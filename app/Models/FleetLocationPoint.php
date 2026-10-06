<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FleetLocationPoint extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'fleet_tracking_device_id', 'field_service_vehicle_id', 'user_id', 'field_service_time_session_id', 'source', 'event_key', 'event_type', 'latitude', 'longitude', 'accuracy_meters', 'recorded_at', 'received_at', 'safe_payload'];

    protected $casts = ['tenant_id' => 'integer', 'fleet_tracking_device_id' => 'integer', 'field_service_vehicle_id' => 'integer', 'user_id' => 'integer', 'field_service_time_session_id' => 'integer', 'latitude' => 'decimal:7', 'longitude' => 'decimal:7', 'accuracy_meters' => 'integer', 'recorded_at' => 'datetime', 'received_at' => 'datetime', 'safe_payload' => 'array'];
}
