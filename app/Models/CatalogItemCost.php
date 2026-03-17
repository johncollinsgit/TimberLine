<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CatalogItemCost extends Model
{
    use HasFactory;

    protected $fillable = [
        'shopify_store_key',
        'shopify_product_id',
        'shopify_variant_id',
        'sku',
        'scent_id',
        'size_id',
        'cost_amount',
        'currency_code',
        'is_active',
        'effective_at',
        'notes',
    ];

    protected $casts = [
        'shopify_product_id' => 'integer',
        'shopify_variant_id' => 'integer',
        'scent_id' => 'integer',
        'size_id' => 'integer',
        'cost_amount' => 'decimal:2',
        'is_active' => 'boolean',
        'effective_at' => 'datetime',
    ];

    public function scent(): BelongsTo
    {
        return $this->belongsTo(Scent::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }
}
