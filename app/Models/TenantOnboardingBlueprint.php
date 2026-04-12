<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantOnboardingBlueprint extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'created_by_user_id',
        'status',
        'account_mode',
        'rail',
        'blueprint_version',
        'payload',
        'origin',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'created_by_user_id' => 'integer',
        'payload' => 'array',
        'origin' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }
}

