<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceEstimateLine extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'field_service_estimate_id', 'field_service_price_book_item_id', 'sort_order',
        'description', 'quantity', 'unit_price', 'line_total', 'source_snapshot',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_estimate_id' => 'integer',
        'field_service_price_book_item_id' => 'integer',
        'sort_order' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'line_total' => 'decimal:2',
        'source_snapshot' => 'array',
    ];

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(FieldServiceEstimate::class, 'field_service_estimate_id');
    }
}
