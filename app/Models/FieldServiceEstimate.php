<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FieldServiceEstimate extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'marketing_profile_id', 'field_service_job_id', 'created_by_user_id',
        'estimate_number', 'status', 'title', 'notes', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'field_service_job_id' => 'integer',
        'created_by_user_id' => 'integer',
        'subtotal' => 'decimal:2',
        'discount_amount' => 'decimal:2',
        'tax_amount' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FieldServiceJob::class, 'field_service_job_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(FieldServiceEstimateLine::class)->orderBy('sort_order');
    }
}
