<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderLine extends Model
{
    use HasFactory;

    protected $table = 'order_lines';

    /**
     * Allow mass assignment for typical seeding + editing.
     * Adjust if you are guarding intentionally.
     */
    protected $fillable = [
        'order_id',
        'scent_id',
        'size_id',
        'shopify_line_item_id',
        'shopify_product_id',
        'shopify_variant_id',
        'sku',
        'ordered_qty',
        'extra_qty',
        'quantity',
        'currency_code',
        'unit_price',
        'line_subtotal',
        'discount_total',
        'line_total',
        'scent_name',
        'size_code',
        'raw_title',
        'raw_variant',
        'image_url',
        'external_key',
        'wick_type',
        'wick_id',
        'pour_status',
    ];

    protected $casts = [
        'shopify_line_item_id' => 'integer',
        'shopify_product_id' => 'integer',
        'shopify_variant_id' => 'integer',
        'ordered_qty' => 'integer',
        'extra_qty' => 'integer',
        'quantity' => 'integer',
        'unit_price' => 'decimal:2',
        'line_subtotal' => 'decimal:2',
        'discount_total' => 'decimal:2',
        'line_total' => 'decimal:2',
    ];

    /**
     * Relationships
     */

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function scent(): BelongsTo
    {
        return $this->belongsTo(Scent::class);
    }

    public function size(): BelongsTo
    {
        return $this->belongsTo(Size::class);
    }

    public function scentSplits()
    {
        return $this->hasMany(OrderLineScentSplit::class)->orderBy('id');
    }
}
