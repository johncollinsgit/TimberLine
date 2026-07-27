<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteOrder extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'website_customer_id' => 'integer', 'subtotal_cents' => 'integer', 'tax_cents' => 'integer', 'total_cents' => 'integer', 'customer_snapshot' => 'array', 'service_request' => 'array', 'paid_at' => 'datetime', 'fulfilled_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WebsiteOrderLine::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(WebsitePayment::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(WebsiteFulfillment::class);
    }
}
