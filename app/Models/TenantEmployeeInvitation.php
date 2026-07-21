<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantEmployeeInvitation extends Model
{
    use HasTenantScope;

    protected $fillable = [
        'tenant_id', 'invited_by_user_id', 'accepted_by_user_id', 'phone', 'email', 'role', 'token_hash',
        'status', 'delivery_status', 'provider_message_id', 'delivery_error', 'expires_at', 'accepted_at', 'revoked_at',
    ];

    protected $hidden = ['token_hash'];

    protected $casts = [
        'tenant_id' => 'integer', 'invited_by_user_id' => 'integer', 'accepted_by_user_id' => 'integer',
        'expires_at' => 'datetime', 'accepted_at' => 'datetime', 'revoked_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
