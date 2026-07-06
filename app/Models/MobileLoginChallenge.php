<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileLoginChallenge extends Model
{
    protected $fillable = [
        'user_id',
        'tenant_id',
        'email',
        'tenant_hint',
        'token_hash',
        'expires_at',
        'consumed_at',
        'requested_ip',
        'requested_user_agent',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'tenant_id' => 'integer',
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
