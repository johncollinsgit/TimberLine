<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantMemberPreference extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'user_id', 'phone', 'phone_verified_at', 'push_enabled', 'operational_sms_enabled',
        'operational_sms_opted_in_at', 'job_comment_notifications',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'user_id' => 'integer',
        'phone' => 'encrypted',
        'phone_verified_at' => 'datetime',
        'push_enabled' => 'boolean',
        'operational_sms_enabled' => 'boolean',
        'operational_sms_opted_in_at' => 'datetime',
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
