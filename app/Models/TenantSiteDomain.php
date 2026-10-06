<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantSiteDomain extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'tenant_site_id', 'hostname', 'status', 'is_primary',
        'verification_token', 'verification_checked_at', 'verified_at', 'activated_at',
        'last_error', 'created_by_user_id', 'updated_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'tenant_id' => 'integer',
            'tenant_site_id' => 'integer',
            'is_primary' => 'boolean',
            'verification_token' => 'encrypted',
            'verification_checked_at' => 'datetime',
            'verified_at' => 'datetime',
            'activated_at' => 'datetime',
        ];
    }

    public function site(): BelongsTo
    {
        return $this->belongsTo(TenantSite::class, 'tenant_site_id');
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
