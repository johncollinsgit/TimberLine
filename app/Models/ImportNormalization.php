<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ImportNormalization extends Model
{
    protected $fillable = [
        'store_key',
        'shopify_order_id',
        'shopify_line_item_id',
        'order_id',
        'field',
        'raw_value',
        'normalized_value',
        'context_json',
    ];

    protected $casts = [
        'context_json' => 'array',
    ];
}
