<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\ProtectsSubscriptionIdentity;
use Illuminate\Database\Eloquent\Model;

class SubscriptionWebhookInbox extends Model
{
    use HasTenantScope, ProtectsSubscriptionIdentity;

    public const IDENTITY_FIELDS = ['tenant_id', 'installation_id', 'delivery_id', 'event_id', 'topic', 'api_version', 'body_fingerprint', 'digest_key_id', 'raw_body', 'headers', 'verification'];

    protected $guarded = [];

    protected $hidden = ['raw_body', 'headers', 'verification', 'conflict_evidence', 'body_fingerprint', 'digest_key_id', 'lease_token'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'installation_id' => 'integer', 'raw_body' => 'encrypted', 'headers' => 'encrypted:array', 'verification' => 'encrypted:array', 'conflict_evidence' => 'encrypted:array', 'lease_expires_at' => 'immutable_datetime', 'next_attempt_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime', 'attempts' => 'integer', 'conflict_count' => 'integer'];
    }
}
