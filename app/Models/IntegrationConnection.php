<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single tenant-owned connection to an external provider (Shopify, Square,
 * Google Business, QuickBooks, ...). Tokens are encrypted at rest via the casts,
 * mirroring TenantEmailSetting / GoogleBusinessProfileConnection.
 *
 * Tenant-scoped: uses HasTenantScope (->forTenant()) — every read MUST be scoped
 * to a tenant, and the enforced global scope (when armed) covers this too.
 */
class IntegrationConnection extends Model
{
    use HasTenantScope;

    public const STATUS_PENDING = 'pending';

    public const STATUS_CONNECTED = 'connected';

    public const STATUS_ERROR = 'error';

    public const STATUS_DISCONNECTED = 'disconnected';

    protected $fillable = [
        'tenant_id',
        'provider',
        'external_account_id',
        'external_account_label',
        'status',
        'access_token',
        'refresh_token',
        'token_type',
        'expires_at',
        'scopes',
        'metadata',
        'connected_by_user_id',
        'connected_at',
        'last_synced_at',
        'last_error_code',
        'last_error_message',
        'last_error_at',
    ];

    protected $casts = [
        'tenant_id' => 'integer',
        'access_token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'scopes' => 'array',
        'metadata' => 'array',
        'expires_at' => 'datetime',
        'connected_at' => 'datetime',
        'last_synced_at' => 'datetime',
        'last_error_at' => 'datetime',
    ];

    protected $hidden = [
        'access_token',
        'refresh_token',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function connectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'connected_by_user_id');
    }

    public function isConnected(): bool
    {
        return $this->status === self::STATUS_CONNECTED;
    }

    public function isExpired(?Carbon $now = null): bool
    {
        if ($this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isBefore($now ?? now());
    }

    /**
     * A connection is "due for refresh" when it has a refresh token and its access
     * token is expired or expiring within the given lead time. Used by the
     * connections:refresh command to select work.
     */
    public function needsRefresh(int $leadSeconds = 300, ?Carbon $now = null): bool
    {
        if (blank($this->refresh_token) || $this->expires_at === null) {
            return false;
        }

        $now = $now ?? now();

        return $this->expires_at->isBefore($now->copy()->addSeconds($leadSeconds));
    }
}
