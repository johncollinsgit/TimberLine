<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class IntegrationHealthEvent extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'shopify_store_id',
        'store_key',
        'provider',
        'event_type',
        'severity',
        'status',
        'dedupe_key',
        'related_model_type',
        'related_model_id',
        'context',
        'occurred_at',
        'resolved_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'shopify_store_id' => 'integer',
        'related_model_id' => 'integer',
        'context' => 'array',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shopifyStore(): BelongsTo
    {
        return $this->belongsTo(ShopifyStore::class);
    }

    public function relatedModel(): MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForProvider(Builder $query, string $provider): Builder
    {
        return $query->where('provider', strtolower(trim($provider)));
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', 'open');
    }
}

