<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileAuthorizationCode extends Model
{
    protected $fillable = [
        'user_id',
        'code_hash',
        'code_challenge',
        'redirect_uri',
        'client_id',
        'device_name',
        'state',
        'expires_at',
        'consumed_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
