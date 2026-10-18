<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\ProtectsSubscriptionIdentity;
use Illuminate\Database\Eloquent\Model;

class SubscriptionInstallation extends Model
{
    use HasTenantScope, ProtectsSubscriptionIdentity;

    public const IDENTITY_FIELDS = ['public_id', 'tenant_id', 'shopify_store_id', 'shop_domain', 'shop_gid', 'app_gid', 'app_installation_gid', 'public_client_id', 'api_version'];

    protected $guarded = [];

    protected $hidden = ['evidence', 'sync_state'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected static function booted(): void
    {
        static::creating(function (self $row): void {
            $row->public_id ??= (string) \Illuminate\Support\Str::uuid();
        });
    }

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'shopify_store_id' => 'integer', 'active_credential_revision_id' => 'integer', 'scopes' => 'array', 'evidence' => 'encrypted:array', 'sync_state' => 'encrypted:array', 'verified_at' => 'immutable_datetime', 'last_synced_at' => 'immutable_datetime'];
    }
}
