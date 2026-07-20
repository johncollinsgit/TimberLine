<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerEquipment extends Model
{
    use HasTenantScope;

    protected $table = 'customer_equipment';

    protected $fillable = [
        'tenant_id', 'marketing_profile_id', 'assigned_user_id', 'equipment_type', 'name', 'manufacturer',
        'model_number', 'serial_number', 'installed_at', 'maintenance_interval_days', 'last_serviced_at',
        'next_service_due_at', 'status', 'notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'assigned_user_id' => 'integer',
        'installed_at' => 'date',
        'maintenance_interval_days' => 'integer',
        'last_serviced_at' => 'date',
        'next_service_due_at' => 'date',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function serviceJobs(): HasMany
    {
        return $this->hasMany(FieldServiceJob::class, 'customer_equipment_id');
    }
}
