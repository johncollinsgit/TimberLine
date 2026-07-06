<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileUserSession extends Model
{
    protected $fillable = [
        'user_id',
        'selected_tenant_id',
        'token_hash',
        'device_id',
        'device_name',
        'app_version',
        'last_used_at',
        'revoked_at',
    ];

    protected $casts = [
        'user_id' => 'integer',
        'selected_tenant_id' => 'integer',
        'last_used_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function selectedTenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class, 'selected_tenant_id');
    }
}
