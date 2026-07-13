<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;

class FieldServicePriceBookCandidate extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'source', 'normalized_key', 'name', 'description', 'status', 'sample_count',
        'median_unit_price', 'minimum_unit_price', 'maximum_unit_price', 'recent_unit_price',
        'high_variance', 'last_invoiced_at', 'approved_price_book_item_id', 'reviewed_by_user_id',
        'reviewed_at', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'sample_count' => 'integer',
        'median_unit_price' => 'decimal:4',
        'minimum_unit_price' => 'decimal:4',
        'maximum_unit_price' => 'decimal:4',
        'recent_unit_price' => 'decimal:4',
        'high_variance' => 'boolean',
        'last_invoiced_at' => 'date',
        'approved_price_book_item_id' => 'integer',
        'reviewed_by_user_id' => 'integer',
        'reviewed_at' => 'datetime',
        'metadata' => 'array',
    ];
}
