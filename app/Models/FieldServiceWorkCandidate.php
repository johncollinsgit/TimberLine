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
        'source', 'source_type', 'external_id', 'status', 'title', 'customer_name', 'customer_email', 'customer_phone', 'amount', 'balance',
        'description', 'service_address_line_1', 'service_address_line_2', 'service_city', 'service_state',
        'service_postal_code', 'service_country', 'priority', 'scheduled_for', 'scheduled_end_at', 'assigned_user_id',
        'participant_user_ids', 'project_manager_name', 'project_manager_company', 'project_manager_phone',
        'project_manager_email', 'payload', 'reviewed_at', 'archived_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer', 'field_service_financial_document_id' => 'integer', 'reviewed_by_user_id' => 'integer',
        'converted_job_id' => 'integer', 'assigned_user_id' => 'integer', 'amount' => 'decimal:2', 'balance' => 'decimal:2',
        'participant_user_ids' => 'array', 'payload' => 'array', 'scheduled_for' => 'datetime', 'scheduled_end_at' => 'datetime',
        'reviewed_at' => 'datetime', 'archived_at' => 'datetime',
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
