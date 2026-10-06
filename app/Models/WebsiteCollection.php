<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class WebsiteCollection extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'tenant_site_id' => 'integer', 'seo' => 'array'];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(WebsiteProduct::class, 'website_collection_products')->withPivot('tenant_id', 'position')->orderByPivot('position');
    }
}
