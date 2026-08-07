<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteShipment extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_order_id' => 'integer', 'website_fulfillment_id' => 'integer', 'website_fulfillment_location_id' => 'integer', 'label_cost_cents' => 'integer', 'destination' => 'encrypted:array', 'parcel' => 'array', 'purchased_at' => 'datetime', 'voided_at' => 'datetime'];
    }

    public function events(): HasMany
    {
        return $this->hasMany(WebsiteShipmentEvent::class);
    }
}
