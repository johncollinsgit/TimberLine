<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoogleBusinessProfileConnection extends Model
{
    protected $fillable = [
        'provider_key',
        'connection_status',
        'connected_by_user_id',
        'google_subject',
        'google_account_label',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_at',
        'granted_scopes',
        'linked_account_name',
        'linked_account_id',
        'linked_account_display_name',
        'linked_location_name',
        'linked_location_id',
        'linked_location_title',
        'linked_location_place_id',
        'linked_location_maps_uri',
        'project_approval_status',
        'connected_at',
        'last_synced_at',
        'last_error_code',
        'last_error_message',
        'last_error_at',
        'metadata',
    ];

    protected $casts = [
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'granted_scopes' => 'array',
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_error_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function connector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(GoogleBusinessProfileLocation::class, 'google_business_profile_connection_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(GoogleBusinessProfileReview::class, 'google_business_profile_connection_id');
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(GoogleBusinessProfileSyncRun::class, 'google_business_profile_connection_id');
    }
}
