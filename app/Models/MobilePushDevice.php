<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobilePushDevice extends Model
{
    use HasFactory;
    use HasTenantScope;

    protected $fillable = [
        'tenant_id',
        'marketing_profile_id',
        'platform',
        'device_token',
        'authorization_status',
        'push_enabled',
        'app_version',
        'app_build',
        'device_name',
        'device_model',
        'locale',
        'last_seen_at',
        'last_registered_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'marketing_profile_id' => 'integer',
        'push_enabled' => 'boolean',
        'last_seen_at' => 'datetime',
        'last_registered_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function marketingProfile(): BelongsTo
    {
        return $this->belongsTo(MarketingProfile::class, 'marketing_profile_id');
    }
}
