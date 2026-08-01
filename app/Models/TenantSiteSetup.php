<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSiteSetup extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'tenant_site_id', 'business_mode', 'offering_mode', 'visitor_actions', 'design_key', 'domain_choice',
        'contact_name', 'contact_email', 'contact_phone', 'hours', 'service_area', 'completed_steps', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'visitor_actions' => 'array', 'completed_steps' => 'array', 'created_by_user_id' => 'integer', 'updated_by_user_id' => 'integer'];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(TenantSite::class, 'tenant_site_id');
    }
}
