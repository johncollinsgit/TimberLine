<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceMembershipSetting extends Model
{
    use HasTenantScope;

    protected $fillable = ['tenant_id', 'terms_contact_email', 'terms_contact_phone', 'service_area_label', 'service_area', 'customer_experience'];

    protected $casts = ['tenant_id' => 'integer', 'service_area' => 'array', 'customer_experience' => 'array'];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
