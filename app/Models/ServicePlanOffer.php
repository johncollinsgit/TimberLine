<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServicePlanOffer extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'marketing_profile_id', 'service_plan_version_id', 'created_by_user_id', 'portal_token_hash', 'status', 'snapshot', 'selected_addons', 'expires_at', 'sent_at', 'accepted_at', 'accepted_name', 'accepted_ip', 'accepted_user_agent', 'invoice_requested_at', 'revoked_at'];

    protected $casts = ['tenant_id' => 'integer', 'marketing_profile_id' => 'integer', 'service_plan_version_id' => 'integer', 'created_by_user_id' => 'integer', 'snapshot' => 'array', 'selected_addons' => 'array', 'expires_at' => 'datetime', 'sent_at' => 'datetime', 'accepted_at' => 'datetime', 'invoice_requested_at' => 'datetime', 'revoked_at' => 'datetime'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(ServicePlanVersion::class, 'service_plan_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}
