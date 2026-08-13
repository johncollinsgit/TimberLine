<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceTechnicianProfile extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'user_id', 'skills', 'daily_capacity_minutes', 'service_area_ids', 'vehicle_ids', 'dispatch_active'];

    protected $casts = ['tenant_id' => 'integer', 'user_id' => 'integer', 'skills' => 'array', 'daily_capacity_minutes' => 'integer', 'service_area_ids' => 'array', 'vehicle_ids' => 'array', 'dispatch_active' => 'boolean'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
