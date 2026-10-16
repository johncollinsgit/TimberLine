<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\ProtectsSubscriptionIdentity;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class SubscriptionRechargeConnection extends Model
{
    use HasTenantScope, ProtectsSubscriptionIdentity;

    public const IDENTITY_FIELDS = ['public_id', 'tenant_id', 'provider_store_id', 'platform', 'identifier', 'source_integration_connection_id', 'shopify_store_id', 'shop_domain', 'api_version'];

    protected $guarded = [];

    protected $hidden = ['evidence', 'sync_state'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->public_id ??= (string) Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'source_integration_connection_id' => 'integer', 'shopify_store_id' => 'integer', 'active_credential_revision_id' => 'integer', 'scopes' => 'array', 'evidence' => 'encrypted:array', 'sync_state' => 'encrypted:array', 'verified_at' => 'immutable_datetime', 'last_complete_sync_at' => 'immutable_datetime'];
    }
}
