<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\ProtectsSubscriptionIdentity;
use Illuminate\Database\Eloquent\Model;

class SubscriptionRechargeResource extends Model
{
    use HasTenantScope, ProtectsSubscriptionIdentity;

    public const IDENTITY_FIELDS = ['tenant_id', 'connection_id', 'sync_operation_id', 'generation_id', 'resource_type', 'provider_id', 'parent_provider_id', 'customer_provider_id', 'status', 'source_updated_at', 'canonical_digest', 'digest_key_id', 'observed_at', 'snapshot'];

    protected $guarded = [];

    protected $hidden = ['snapshot', 'mapping', 'canonical_digest', 'digest_key_id'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'connection_id' => 'integer', 'sync_operation_id' => 'integer', 'snapshot' => 'encrypted:array', 'mapping' => 'encrypted:array', 'observed_at' => 'immutable_datetime'];
    }
}
