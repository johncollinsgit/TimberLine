<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

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
        'sku',
        'ordered_qty',
        'extra_qty',
        'quantity',
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

    /**
     * Relationships
     */

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function scent()
    {
        return $this->belongsTo(Scent::class);
    }

    public function size()
    {
        return $this->belongsTo(Size::class);
    }
}
