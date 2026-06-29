<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ModernForestryMobileBagSnapshot extends Model
{
    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'email',
        'currency_code',
        'item_count',
        'subtotal_amount',
        'items',
        'content_hash',
        'is_active',
        'reminder_count',
        'last_synced_at',
        'last_reminded_at',
        'next_reminder_at',
        'meta',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'item_count' => 'integer',
        'subtotal_amount' => 'decimal:2',
        'items' => 'array',
        'is_active' => 'boolean',
        'reminder_count' => 'integer',
        'last_synced_at' => 'datetime',
        'last_reminded_at' => 'datetime',
        'next_reminder_at' => 'datetime',
        'meta' => 'array',
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
