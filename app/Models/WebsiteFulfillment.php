<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteFulfillment extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_order_id' => 'integer', 'fulfilled_by_user_id' => 'integer', 'fulfilled_at' => 'datetime'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(WebsiteFulfillmentLine::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(WebsiteShipment::class);
    }
}
