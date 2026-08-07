<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteShippingPackage extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'length_inches' => 'integer', 'width_inches' => 'integer', 'height_inches' => 'integer', 'weight_ounces' => 'integer', 'is_default' => 'boolean', 'active' => 'boolean'];
    }
}
