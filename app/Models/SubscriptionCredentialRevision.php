<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\ProtectsSubscriptionIdentity;
use Illuminate\Database\Eloquent\Model;

class SubscriptionCredentialRevision extends Model
{
    use HasTenantScope, ProtectsSubscriptionIdentity;

    public const IDENTITY_FIELDS = ['tenant_id', 'installation_id', 'revision', 'credentials', 'credential_fingerprint', 'digest_key_id', 'grant_type', 'app_secret_reference', 'app_secret_revision', 'app_secret', 'app_secret_expires_at'];

    protected $guarded = [];

    protected $hidden = ['app_secret', 'credentials', 'credential_fingerprint', 'digest_key_id', 'app_secret_reference', 'app_secret_revision'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'installation_id' => 'integer', 'revision' => 'integer', 'app_secret' => 'encrypted', 'app_secret_expires_at' => 'immutable_datetime', 'credentials' => 'encrypted:array', 'expires_at' => 'immutable_datetime', 'refresh_expires_at' => 'immutable_datetime', 'verified_at' => 'immutable_datetime', 'rotated_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];
    }
}
