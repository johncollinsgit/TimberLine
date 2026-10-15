<?php

namespace App\Models;

use App\Models\Concerns\HasTenantScope;
use App\Models\Concerns\ProtectsSubscriptionIdentity;
use Illuminate\Database\Eloquent\Model;

class SubscriptionProviderResource extends Model
{
    use HasTenantScope, ProtectsSubscriptionIdentity;

    public const IDENTITY_FIELDS = ['tenant_id', 'installation_id', 'resource_type', 'provider_gid'];

    protected $guarded = [];

    protected $hidden = ['snapshot'];

    protected $dateFormat = 'Y-m-d H:i:s.u';

    public function setProviderRevisionAttribute(mixed $value): void
    {
        $this->attributes['provider_revision'] = $value === null ? null : \App\Services\Subscriptions\SubscriptionUnsignedRevision::parse($value);
    }

    protected function casts(): array
    {
        return ['tenant_id' => 'integer', 'installation_id' => 'integer', 'sync_operation_id' => 'integer', 'provider_revision' => 'string', 'snapshot' => 'encrypted:array', 'provider_updated_at' => 'immutable_datetime', 'observed_at' => 'immutable_datetime'];
    }
}
