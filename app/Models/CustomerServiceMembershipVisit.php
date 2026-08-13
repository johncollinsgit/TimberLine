<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerServiceMembershipVisit extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'customer_service_membership_id', 'customer_equipment_id', 'field_service_job_id', 'period_key', 'due_on', 'status', 'credited_at', 'skipped_at', 'exception_reason'];

    protected $casts = ['tenant_id' => 'integer', 'customer_service_membership_id' => 'integer', 'customer_equipment_id' => 'integer', 'field_service_job_id' => 'integer', 'due_on' => 'date', 'credited_at' => 'datetime', 'skipped_at' => 'datetime'];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(CustomerServiceMembership::class, 'customer_service_membership_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(CustomerEquipment::class, 'customer_equipment_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }
}
