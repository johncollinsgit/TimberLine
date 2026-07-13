<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceFinancialDocumentLine extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'field_service_financial_document_id', 'source_line_id', 'sort_order', 'detail_type',
        'item_external_id', 'item_name', 'description', 'quantity', 'unit_price', 'amount', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_financial_document_id' => 'integer',
        'sort_order' => 'integer',
        'quantity' => 'decimal:4',
        'unit_price' => 'decimal:4',
        'amount' => 'decimal:2',
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(FieldServiceFinancialDocument::class, 'field_service_financial_document_id');
    }
}
