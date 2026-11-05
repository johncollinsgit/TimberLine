<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class StockistLocation extends Model
{
    use BelongsToTenant;

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'shopify_store_id' => 'integer',
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'featured' => 'boolean',
            'source_visible' => 'boolean',
            'published' => 'boolean',
            'sort_order' => 'integer',
            'categories' => 'array',
            'source_payload' => 'array',
        ];
    }
}
