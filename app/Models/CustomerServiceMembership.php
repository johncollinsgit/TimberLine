<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerServiceMembership extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'marketing_profile_id', 'service_plan_offer_id', 'service_plan_version_id', 'activated_by_user_id', 'status', 'snapshot', 'selected_addons', 'external_invoice_reference', 'external_invoice_url', 'starts_on', 'renews_on', 'next_visit_due_on', 'priority', 'activated_at', 'cancelled_at', 'cancellation_reason'];

    protected $casts = ['tenant_id' => 'integer', 'marketing_profile_id' => 'integer', 'service_plan_offer_id' => 'integer', 'service_plan_version_id' => 'integer', 'activated_by_user_id' => 'integer', 'snapshot' => 'array', 'selected_addons' => 'array', 'starts_on' => 'date', 'renews_on' => 'date', 'next_visit_due_on' => 'date', 'activated_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(ServicePlanOffer::class, 'service_plan_offer_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePlanVersion::class, 'service_plan_version_id');
    }

    public function activator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by_user_id');
    }

    public function visits(): HasMany
    {
        return $this->hasMany(CustomerServiceMembershipVisit::class);
    }
}
