<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FieldServiceWorkCandidate extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'field_service_financial_document_id', 'reviewed_by_user_id', 'converted_job_id',
        'source', 'source_type', 'external_id', 'status', 'title', 'customer_name', 'amount', 'balance',
        'description', 'payload', 'reviewed_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer', 'field_service_financial_document_id' => 'integer', 'reviewed_by_user_id' => 'integer',
        'converted_job_id' => 'integer', 'amount' => 'decimal:2', 'balance' => 'decimal:2', 'payload' => 'array',
        'reviewed_at' => 'datetime',
    ];

    public function financialDocument(): BelongsTo
    {
        return $this->belongsTo(FieldServiceFinancialDocument::class, 'field_service_financial_document_id');
    }

    public function convertedJob(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'converted_job_id');
    }
}
