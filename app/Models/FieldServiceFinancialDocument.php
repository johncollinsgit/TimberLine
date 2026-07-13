<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldServiceFinancialDocument extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'marketing_profile_id', 'field_service_job_id', 'source', 'document_type',
        'external_id', 'document_number', 'status', 'transaction_date', 'due_date', 'total_amount',
        'balance', 'currency', 'private_note', 'customer_memo', 'linked_transactions', 'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'field_service_job_id' => 'integer',
        'transaction_date' => 'date',
        'due_date' => 'date',
        'total_amount' => 'decimal:2',
        'balance' => 'decimal:2',
        'linked_transactions' => 'array',
        'metadata' => 'array',
    ];

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FieldServiceFinancialDocumentLine::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FieldServiceFinancialDocumentAttachment::class);
    }

    public function assets(): BelongsToMany
    {
        return $this->belongsToMany(WorkspaceAsset::class, 'financial_document_workspace_asset')
            ->withPivot('tenant_id')
            ->withTimestamps();
    }
}
