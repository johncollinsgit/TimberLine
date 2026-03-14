<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleBusinessProfileLocation extends Model
{
    protected $fillable = [
        'google_business_profile_connection_id',
        'account_name',
        'account_id',
        'account_display_name',
        'location_name',
        'location_id',
        'title',
        'store_code',
        'website_uri',
        'place_id',
        'maps_uri',
        'storefront_address',
        'is_selected',
        'selected_at',
        'last_seen_at',
        'metadata',
    ];

    protected $casts = [
        'storefront_address' => 'array',
        'is_selected' => 'boolean',
        'selected_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function connection(): BelongsTo
    {
        return $this->belongsTo(GoogleBusinessProfileConnection::class, 'google_business_profile_connection_id');
    }
}
