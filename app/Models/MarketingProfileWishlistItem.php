<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingProfileWishlistItem extends Model
{
    use HasTenantScope;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_REMOVED = 'removed';

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'wishlist_list_id',
        'guest_token',
        'provider',
        'integration',
        'store_key',
        'product_id',
        'product_variant_id',
        'product_handle',
        'product_title',
        'product_url',
        'status',
        'source',
        'source_surface',
        'source_ref',
        'added_at',
        'last_added_at',
        'removed_at',
        'source_synced_at',
        'raw_payload',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'wishlist_list_id' => 'integer',
        'added_at' => 'datetime',
        'last_added_at' => 'datetime',
        'removed_at' => 'datetime',
        'source_synced_at' => 'datetime',
        'raw_payload' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function wishlistList(): BelongsTo
    {
        return $this->belongsTo(MarketingWishlistList::class, 'wishlist_list_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function isActive(): bool
    {
        return (string) $this->status === self::STATUS_ACTIVE;
    }
}
