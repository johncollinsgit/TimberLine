<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceMembershipEvent extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'customer_service_membership_id', 'service_plan_offer_id', 'actor_user_id', 'event_type', 'context'];

    protected $casts = ['tenant_id' => 'integer', 'customer_service_membership_id' => 'integer', 'service_plan_offer_id' => 'integer', 'actor_user_id' => 'integer', 'context' => 'array'];

    public function membership(): BelongsTo
    {
        return $this->belongsTo(CustomerServiceMembership::class, 'customer_service_membership_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ServicePlanOffer::class, 'service_plan_offer_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
