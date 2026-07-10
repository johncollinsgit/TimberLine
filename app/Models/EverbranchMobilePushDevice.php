<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EverbranchMobilePushDevice extends Model
{
    protected $fillable = [
        'user_id',
        'platform',
        'device_token',
        'device_token_hash',
        'app_version',
        'device_name',
        'notifications_enabled',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'device_token' => 'encrypted',
            'notifications_enabled' => 'boolean',
            'last_seen_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
