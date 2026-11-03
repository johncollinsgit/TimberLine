<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteShopPayConnection extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['storefront_access_token', 'admin_access_token', 'webhook_secret', 'setup'];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'shop_id' => 'integer',
            'enabled' => 'boolean',
            'storefront_access_token' => 'encrypted',
            'admin_access_token' => 'encrypted',
            'webhook_secret' => 'encrypted',
            'last_verified_at' => 'datetime',
            'setup' => 'encrypted:array',
        ];
    }
}
