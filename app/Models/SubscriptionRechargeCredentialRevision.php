<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\ProtectsSubscriptionIdentity;
use Illuminate\Database\Eloquent\Model;

class SubscriptionRechargeCredentialRevision extends Model
{
    use HasTenantScope, ProtectsSubscriptionIdentity;

    public const IDENTITY_FIELDS = ['tenant_id', 'connection_id', 'revision', 'credentials', 'credential_fingerprint', 'digest_key_id', 'token_id'];

    protected $guarded = [];

    protected $hidden = ['credentials', 'credential_fingerprint', 'digest_key_id', 'token_id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'connection_id' => 'integer', 'revision' => 'integer', 'credentials' => 'encrypted:array', 'scopes' => 'array', 'verified_at' => 'immutable_datetime', 'rotated_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime', 'expires_at' => 'immutable_datetime'];
    }
}
