<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarketingWishlistOutreachQueue extends Model
{
    use HasTenantScope;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REDEEMED = 'redeemed';

    protected $table = 'marketing_wishlist_outreach_queue';

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'wishlist_list_id',
        'wishlist_item_id',
        'store_key',
        'product_id',
        'product_variant_id',
        'product_handle',
        'product_title',
        'channel',
        'queue_status',
        'offer_type',
        'offer_value',
        'offer_code',
        'provider',
        'provider_message_id',
        'message_body',
        'delivery_error',
        'created_by',
        'last_updated_by',
        'sent_at',
        'redeemed_at',
        'last_attempt_at',
        'metadata',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'wishlist_list_id' => 'integer',
        'wishlist_item_id' => 'integer',
        'offer_value' => 'decimal:2',
        'created_by' => 'integer',
        'last_updated_by' => 'integer',
        'sent_at' => 'datetime',
        'redeemed_at' => 'datetime',
        'last_attempt_at' => 'datetime',
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

    public function wishlistList(): BelongsTo
    {
        return $this->belongsTo(MarketingWishlistList::class, 'wishlist_list_id');
    }

    public function wishlistItem(): BelongsTo
    {
        return $this->belongsTo(MarketingProfileWishlistItem::class, 'wishlist_item_id');
    }
}
