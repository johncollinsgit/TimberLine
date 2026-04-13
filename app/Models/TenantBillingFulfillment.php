<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantBillingFulfillment extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'provider',
        'provider_customer_reference',
        'provider_subscription_reference',
        'provider_checkout_session_id',
        'state_hash',
        'desired_plan_key',
        'desired_addon_keys',
        'desired_operating_mode',
        'status',
        'message',
        'source_event_id',
        'source_event_type',
        'triggered_by',
        'actor_user_id',
        'attempted_at',
        'applied_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'desired_addon_keys' => 'array',
        'actor_user_id' => 'integer',
        'attempted_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}

