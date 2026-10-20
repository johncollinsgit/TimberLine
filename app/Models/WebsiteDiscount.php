<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class WebsiteDiscount extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'value' => 'integer', 'minimum_subtotal_cents' => 'integer', 'maximum_issues' => 'integer', 'issued_count' => 'integer', 'enabled' => 'boolean', 'eligible_variant_ids' => 'array', 'starts_at' => 'datetime', 'expires_at' => 'datetime'];
    }
}
