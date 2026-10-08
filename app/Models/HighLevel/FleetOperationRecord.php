<?php

namespace App\Models\HighLevel;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FleetOperationRecord extends Model
{
    use HasTenantScope;

    protected $table = 'fleet_operation_records';

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'encrypted:array', 'event_at' => 'datetime', 'device_id' => 'integer', 'tenant_id' => 'integer'];
}
