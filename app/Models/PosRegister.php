<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosRegister extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'website_fulfillment_location_id' => 'integer',
            'active' => 'boolean', 'cashier_user_ids' => 'array', 'tax_rate_bps' => 'integer', 'tax_confirmed_at' => 'datetime'];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(TenantSite::class, 'tenant_site_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(WebsiteFulfillmentLocation::class, 'website_fulfillment_location_id');
    }

    public function attempts(): HasMany
    {
        return $this->hasMany(PosPaymentAttempt::class);
    }
}
