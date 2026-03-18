<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingProfileLink extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'source_type',
        'source_id',
        'source_meta',
        'match_method',
        'confidence',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'source_meta' => 'array',
        'confidence' => 'decimal:2',
    ];

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
