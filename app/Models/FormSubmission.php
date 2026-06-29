<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormSubmission extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'tenant_form_id',
        'customer_access_request_id',
        'user_id',
        'status',
        'source',
        'source_key',
        'submitted_at',
        'submitter_name',
        'submitter_email',
        'submitter_phone',
        'submitter_company',
        'payload',
        'normalized_payload',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'tenant_form_id' => 'integer',
            'customer_access_request_id' => 'integer',
            'user_id' => 'integer',
            'submitted_at' => 'datetime',
            'payload' => 'array',
            'normalized_payload' => 'array',
            'metadata' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function form(): BelongsTo
    {
        return $this->belongsTo(TenantForm::class, 'tenant_form_id');
    }

    public function accessRequest(): BelongsTo
    {
        return $this->belongsTo(CustomerAccessRequest::class, 'customer_access_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
