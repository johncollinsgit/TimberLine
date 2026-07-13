<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerMergeOperation extends Model
{
    use HasTenantScope;

    protected $guarded = [];

    protected $casts = [
        'tenant_id' => 'integer',
        'survivor_profile_id' => 'integer',
        'field_choices' => 'array',
        'reward_resolution' => 'array',
        'shopify_preview' => 'array',
        'before_state' => 'array',
        'after_state' => 'array',
        'errors' => 'array',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(CustomerMergeOperationMember::class);
    }

    public function initiator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
