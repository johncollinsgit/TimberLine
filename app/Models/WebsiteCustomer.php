<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WebsiteCustomer extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'notes' => 'array'];
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(WebsiteCustomerAddress::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(WebsiteOrder::class);
    }
}
