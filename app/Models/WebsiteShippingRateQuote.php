<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteShippingRateQuote extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'website_cart_id' => 'integer', 'website_fulfillment_location_id' => 'integer', 'amount_cents' => 'integer', 'delivery_days' => 'integer', 'destination' => 'encrypted:array', 'parcel' => 'array', 'expires_at' => 'datetime'];
    }
}
