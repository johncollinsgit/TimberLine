<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteCustomerAddress extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_customer_id' => 'integer', 'is_default' => 'boolean'];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(WebsiteCustomer::class, 'website_customer_id');
    }
}
