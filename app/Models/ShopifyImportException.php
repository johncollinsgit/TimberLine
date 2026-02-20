<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShopifyImportException extends Model
{
    protected $fillable = [
        'shop',
        'shopify_order_id',
        'shopify_line_item_id',
        'title',
        'reason',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
