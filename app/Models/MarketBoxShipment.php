<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketBoxShipment extends Model
{
    protected $fillable = [
        'event_id',
        'item_type',
        'product_key',
        'sku',
        'scent',
        'size',
        'qty',
        'notes',
        'raw_row',
        'source_row_hash',
    ];

    protected $casts = [
        'raw_row' => 'array',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}

