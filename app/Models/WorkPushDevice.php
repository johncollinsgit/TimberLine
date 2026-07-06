<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkPushDevice extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'platform',
        'device_token',
        'device_id',
        'authorization_status',
        'push_enabled',
        'app_version',
        'app_build',
        'device_name',
        'device_model',
        'locale',
        'last_seen_at',
        'last_registered_at',
        'revoked_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'push_enabled' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_registered_at' => 'datetime',
        'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
