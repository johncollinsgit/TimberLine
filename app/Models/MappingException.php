<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MappingException extends Model
{
    use HasFactory;

    protected $fillable = [
        'store_key',
        'shopify_order_id',
        'account_name',
        'raw_scent_name',
        'canonical_scent_id',
        'shopify_line_item_id',
        'order_id',
        'order_line_id',
        'raw_title',
        'raw_variant',
        'sku',
        'reason',
        'payload_json',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'resolved_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(OrderLine::class, 'order_line_id');
    }

    public function canonicalScent(): BelongsTo
    {
        return $this->belongsTo(Scent::class, 'canonical_scent_id');
    }

    public function scentSplits(): HasMany
    {
        return $this->hasMany(OrderLineScentSplit::class);
    }
}
