<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceReminderSetting extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'enabled',
        'channel',
        'cadence',
        'send_time',
        'timezone',
        'provider_status',
        'job_update_sms',
        'customer_copy',
        'internal_notes',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'enabled' => 'boolean',
        'job_update_sms' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
