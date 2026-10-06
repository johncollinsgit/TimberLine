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
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'website_customer_id' => 'integer', 'subtotal_cents' => 'integer', 'discount_cents' => 'integer', 'tax_cents' => 'integer', 'shipping_cents' => 'integer', 'refunded_cents' => 'integer', 'total_cents' => 'integer', 'customer_snapshot' => 'encrypted:array', 'shipping_address' => 'encrypted:array', 'billing_address' => 'encrypted:array', 'shipping_rate_snapshot' => 'array', 'service_request' => 'encrypted:array', 'paid_at' => 'datetime', 'fulfilled_at' => 'datetime', 'cancelled_at' => 'datetime', 'closed_at' => 'datetime'];
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

    public function shipments(): HasMany
    {
        return $this->hasMany(WebsiteShipment::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(WebsiteOrderEvent::class)->latest();
    }
}
