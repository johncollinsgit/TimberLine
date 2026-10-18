<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

/** Native catalog identity only; never a provider connection or sync cursor. */
class WebsiteCatalogSourceMapping extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $hidden = ['provenance'];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'website_product_id' => 'integer',
            'website_product_variant_id' => 'integer', 'website_collection_id' => 'integer', 'provenance' => 'encrypted:array'];
    }
}
