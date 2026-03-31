<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MarketingWishlistList extends Model
{
    use HasTenantScope;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'guest_token',
        'store_key',
        'name',
        'is_default',
        'status',
        'source',
        'last_activity_at',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'is_default' => 'boolean',
        'last_activity_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(MarketingProfileWishlistItem::class, 'wishlist_list_id');
    }
}
