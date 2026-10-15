<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\ProtectsSubscriptionIdentity;
use Illuminate\Database\Eloquent\Model;

class SubscriptionOperation extends Model
{
    use HasTenantScope, ProtectsSubscriptionIdentity;

    public const IDENTITY_FIELDS = ['tenant_id', 'installation_id', 'credential_revision_id', 'actor_user_id', 'request_uuid', 'kind', 'request', 'request_fingerprint', 'digest_key_id'];

    protected $guarded = [];

    protected $hidden = ['request', 'result', 'request_fingerprint', 'digest_key_id', 'lease_token'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'installation_id' => 'integer', 'credential_revision_id' => 'integer', 'actor_user_id' => 'integer', 'request' => 'encrypted:array', 'result' => 'encrypted:array', 'lease_expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'attempts' => 'integer'];
    }
}
