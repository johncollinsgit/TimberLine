<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FieldServiceDispatchSetting extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'business_hours', 'default_travel_buffer_minutes', 'customer_notification_settings', 'escalation_settings'];

    protected $casts = ['tenant_id' => 'integer', 'business_hours' => 'array', 'default_travel_buffer_minutes' => 'integer', 'customer_notification_settings' => 'array', 'escalation_settings' => 'array'];
}
