<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WebsiteInventoryMovement extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'website_product_variant_id' => 'integer', 'actor_user_id' => 'integer'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(WebsiteProductVariant::class, 'website_product_variant_id');
    }
}
