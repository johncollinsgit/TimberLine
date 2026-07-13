<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceFinancialDocumentAttachment extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'field_service_financial_document_id', 'external_id', 'file_name',
        'content_type', 'file_size', 'note', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'field_service_financial_document_id' => 'integer',
        'file_size' => 'integer',
        'metadata' => 'array',
    ];

    public function document(): BelongsTo
    {
        return $this->belongsTo(FieldServiceFinancialDocument::class, 'field_service_financial_document_id');
    }
}
