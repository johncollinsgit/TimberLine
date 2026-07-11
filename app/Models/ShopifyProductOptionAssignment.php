<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShopifyProductOptionAssignment extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'ruleset_id',
        'shopify_product_id',
        'product_handle',
        'product_url',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'ruleset_id' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ruleset(): BelongsTo
    {
        return $this->belongsTo(ShopifyProductOptionRuleset::class, 'ruleset_id');
    }
}
