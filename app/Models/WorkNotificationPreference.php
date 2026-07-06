<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkNotificationPreference extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'category',
        'email_enabled',
        'in_app_enabled',
        'push_enabled',
        'muted_until',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'email_enabled' => 'boolean',
        'in_app_enabled' => 'boolean',
        'push_enabled' => 'boolean',
        'muted_until' => 'datetime',
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
