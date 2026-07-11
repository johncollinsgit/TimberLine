<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShopifyProductOptionRuleset extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'name',
        'option_count',
        'allowed_values',
        'require_distinct_values',
        'enabled',
        'source',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'option_count' => 'integer',
        'allowed_values' => 'array',
        'require_distinct_values' => 'boolean',
        'enabled' => 'boolean',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ShopifyProductOptionAssignment::class, 'ruleset_id');
    }
}
